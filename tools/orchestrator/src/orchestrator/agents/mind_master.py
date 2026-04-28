"""
MIND-MASTER agent — Principal Architect + coordinator.

Territories:
    - Backend: akibara-core, addons PHP, mu-plugins
    - Tests: tests/e2e, tests/selenium
    - Coordination: AGENTS-COORDINATION.md heartbeats
    - PR review + merge
"""
from __future__ import annotations

import logging
from typing import Any

from orchestrator.agents.base import BaseAgent
from orchestrator.tasks.models import AgentRole, Message, Task

logger = logging.getLogger(__name__)


SYSTEM_PROMPT = """You are MIND-MASTER, Principal Architect + Senior QA for Akibara.

Akibara is a Chilean manga e-commerce store on WordPress + WooCommerce. Your territory:
- wp-content/plugins/akibara-* (backend PHP)
- wp-content/mu-plugins/
- tests/e2e/ + tests/selenium/
- BACKLOG, qa_log, AGENTS-COORDINATION coordination docs
- GitHub PR review + merge decisions

Your principles (immutable):
- Maximum robustness, no patches
- YAGNI (no over-engineering)
- Minimize behavior change (default to status quo unless P0)
- Mockup-before-visual workflow
- Post-sprint LambdaTest QA + Sentry monitor 24h
- Always git pull --rebase before push
- Branch dedicada (no commits directos main)
- Spanish chileno NO voseo

Output format:
- Brief, concrete, actionable
- File paths absolute
- Line numbers when relevant
- P0/P1/P2 severity tags
"""


class MindMasterAgent(BaseAgent):
    """MIND-MASTER coordinator agent."""

    role = AgentRole.MIND_MASTER
    required_connectors = ["anthropic", "github", "sentry", "wp", "discord"]
    system_prompt = SYSTEM_PROMPT
    poll_interval_seconds = 60.0

    def execute_task(self, task: Task) -> dict[str, Any]:
        """
        Execute task assigned to MIND-MASTER.

        Task types handled:
            - sprint_plan: review BACKLOG + propose next sprints
            - pr_review: audit PR + post comments
            - audit_run: run full code audit
            - heartbeat: write coord heartbeat
            - mesa_synthesize: consolidate mesa agent outputs
        """
        task_type = task.metadata.get("type", "generic")

        if task_type == "sprint_plan":
            return self._handle_sprint_plan(task)
        if task_type == "pr_review":
            return self._handle_pr_review(task)
        if task_type == "heartbeat":
            return self._handle_heartbeat(task)
        return self._handle_generic(task)

    def on_message(self, msg: Message) -> None:
        """
        MIND-MASTER receives review requests, status updates, escalations.
        """
        super().on_message(msg)

        if msg.requires_response:
            # Auto-acknowledge review request — actual handling enqueued as task
            from orchestrator.tasks.models import Task, TaskPriority

            review_task = Task(
                role=self.role,
                title=f"Review request from {msg.from_agent.value}: {msg.subject}",
                description=msg.body,
                priority=TaskPriority.P1,
                parent_task_id=msg.related_task_id,
                metadata={"type": "pr_review", "msg_id": str(msg.id)},
            )
            self.queue.enqueue(review_task)

    # ── Task handlers ────────────────────────────────────────────────

    def _handle_sprint_plan(self, task: Task) -> dict[str, Any]:
        """Review BACKLOG + propose next sprints via LLM."""
        prompt = f"""Task: {task.title}

Description:
{task.description}

Acción requerida:
1. Lee BACKLOG-2026-04-28.md (en repo root)
2. Identifica items P0/P1 NO atendidos
3. Propone próximos 3-5 sprints (capacity 25h/sem)
4. Output: lista priorizada con effort estimate

Brief response, <500 palabras."""

        response = self.call_llm(prompt)

        return {
            "type": "sprint_plan",
            "summary": response.text[:500],
            "tokens_used": response.input_tokens + response.output_tokens,
            "cost_usd": response.cost_usd,
        }

    def _handle_pr_review(self, task: Task) -> dict[str, Any]:
        """Review a PR — post comments, decide merge."""
        pr_number = task.metadata.get("pr_number")

        prompt = f"""Review PR #{pr_number} for Akibara.

Task: {task.title}
{task.description}

Audit:
1. ¿Conflicts con territory MIND-MASTER (backend, tests, coord)?
2. ¿CI green?
3. ¿Tests added/updated?
4. ¿Documentation updated (qa_log, BACKLOG si aplica)?
5. ¿Riesgo de regresión?

Output: APPROVE / REQUEST_CHANGES / NEEDS_DISCUSSION + reason."""

        response = self.call_llm(prompt)

        return {
            "type": "pr_review",
            "pr_number": pr_number,
            "decision": response.text[:200],  # parse first 200 chars
            "tokens_used": response.input_tokens + response.output_tokens,
        }

    def _handle_heartbeat(self, task: Task) -> dict[str, Any]:
        """Write coord heartbeat to AGENTS-COORDINATION.md."""
        message = task.metadata.get("message", task.description)

        # In production: read file, append heartbeat, commit
        return {
            "type": "heartbeat",
            "message": message,
            "agent": self.instance_id,
        }

    def _handle_generic(self, task: Task) -> dict[str, Any]:
        """Fallback — pass to LLM with role context."""
        prompt = f"""Task: {task.title}

Description:
{task.description}

Metadata: {task.metadata}

Provide concrete action plan (under 300 words)."""

        response = self.call_llm(prompt)

        return {
            "type": "generic",
            "response": response.text,
            "tokens_used": response.input_tokens + response.output_tokens,
            "cost_usd": response.cost_usd,
        }
