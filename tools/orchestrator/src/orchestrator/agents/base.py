"""
Abstract base agent — runs a self-pacing loop:
    1. Fetch unread messages
    2. Claim next task in role queue
    3. Execute (LLM call + tool use)
    4. Persist result + send completion message
    5. Sleep / poll
"""
from __future__ import annotations

import logging
import time
from abc import ABC, abstractmethod
from datetime import datetime, timezone
from typing import Any
from uuid import uuid4

from orchestrator.config import get_settings
from orchestrator.llm.client import AnthropicClient, LLMResponse
from orchestrator.tasks.models import (
    AgentRole,
    AgentStatus,
    Message,
    Task,
    TaskStatus,
)
from orchestrator.tasks.queue import MessageBus, TaskQueue

logger = logging.getLogger(__name__)


class BaseAgent(ABC):
    """Abstract agent — implement role-specific logic in subclasses."""

    role: AgentRole = AgentRole.MIND_MASTER  # override in subclass
    required_connectors: list[str] = ["anthropic"]
    poll_interval_seconds: float = 30.0
    system_prompt: str = ""

    def __init__(self, instance_id: str | None = None) -> None:
        self.instance_id = instance_id or f"{self.role.value}-{uuid4().hex[:8]}"
        self.settings = get_settings()
        self.queue = TaskQueue()
        self.bus = MessageBus()
        self.llm = AnthropicClient()
        self.status = AgentStatus(role=self.role, instance_id=self.instance_id)
        self._running = False

    # ── Hooks (subclass override) ───────────────────────────────────

    @abstractmethod
    def execute_task(self, task: Task) -> dict[str, Any]:
        """
        Execute a single task. Return result dict (will be persisted).

        Raise on unrecoverable error → triggers retry/fail.
        """
        ...

    def on_message(self, msg: Message) -> None:
        """Hook for handling incoming messages. Default = log only."""
        logger.info(
            "message_received",
            extra={
                "agent": self.instance_id,
                "from": msg.from_agent.value,
                "subject": msg.subject,
            },
        )

    def heartbeat(self) -> None:
        """Update agent status timestamp + persist."""
        self.status.last_heartbeat = datetime.now(timezone.utc)
        # TODO: persist to DB or broadcast via webhook

    # ── LLM helper ───────────────────────────────────────────────────

    def call_llm(self, prompt: str, **kwargs: Any) -> LLMResponse:
        """Call LLM with role's system prompt + cost tracking."""
        response = self.llm.call(prompt=prompt, system=self.system_prompt, **kwargs)
        self.status.total_cost_usd += response.cost_usd
        self.status.total_tokens_used += response.input_tokens + response.output_tokens
        return response

    # ── Inter-agent comms ────────────────────────────────────────────

    def send_message(
        self,
        to: AgentRole | None,
        subject: str,
        body: str,
        related_task_id: Any = None,
        requires_response: bool = False,
    ) -> None:
        """Post message to another agent's inbox (or broadcast)."""
        msg = Message(
            from_agent=self.role,
            to_agent=to,
            subject=subject,
            body=body,
            related_task_id=related_task_id,
            requires_response=requires_response,
        )
        self.bus.send(msg)

    # ── Main loop ────────────────────────────────────────────────────

    def run_once(self) -> bool:
        """
        Run one iteration: process messages + claim+execute task if available.

        Returns True if work was done, False if queue empty.
        """
        # Process unread messages first
        for msg in self.bus.fetch_unread(self.role):
            try:
                self.on_message(msg)
                self.bus.mark_read(self.role, msg.id)
            except Exception:
                logger.exception("message_handler_failed", extra={"msg_id": str(msg.id)})

        # Claim and execute next task
        task = self.queue.claim_next(self.role, self.instance_id)
        if not task:
            self.heartbeat()
            return False

        self.status.current_task_id = task.id
        task.start()
        self.queue.update(task)

        logger.info(
            "task_started",
            extra={"task_id": str(task.id), "agent": self.instance_id, "title": task.title},
        )

        try:
            result = self.execute_task(task)
            task.complete(result)
            self.status.tasks_completed += 1
        except Exception as e:
            logger.exception("task_execution_failed", extra={"task_id": str(task.id)})
            task.fail(str(e))
            self.status.tasks_failed += 1
        finally:
            self.queue.finalize(task)
            self.status.current_task_id = None
            self.heartbeat()

        return True

    def run_forever(self, max_iterations: int | None = None) -> None:
        """
        Run loop indefinitely (or up to max_iterations for testing).

        Sleeps poll_interval_seconds between empty queues.
        """
        self._running = True
        iteration = 0

        logger.info(
            "agent_started",
            extra={"agent": self.instance_id, "role": self.role.value},
        )

        while self._running:
            try:
                did_work = self.run_once()
            except KeyboardInterrupt:
                logger.info("agent_interrupted", extra={"agent": self.instance_id})
                break
            except Exception:
                logger.exception("agent_loop_error", extra={"agent": self.instance_id})
                did_work = False

            iteration += 1
            if max_iterations is not None and iteration >= max_iterations:
                break

            if not did_work:
                time.sleep(self.poll_interval_seconds)

        logger.info(
            "agent_stopped",
            extra={
                "agent": self.instance_id,
                "tasks_completed": self.status.tasks_completed,
                "tasks_failed": self.status.tasks_failed,
                "total_cost_usd": round(self.status.total_cost_usd, 4),
            },
        )

    def stop(self) -> None:
        """Signal agent to stop on next iteration."""
        self._running = False
