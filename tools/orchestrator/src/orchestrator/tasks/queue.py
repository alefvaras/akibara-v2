"""
Task queue — filesystem-backed (durable) + SQLite state mirror.

Filesystem layout:
    data/
      queue/{role}/{task_id}.json     — pending tasks
      inbox/{role}/{message_id}.json  — agent messages
      done/{role}/{task_id}.json      — completed tasks (audit)

Atomic operations via fcntl file locks (POSIX) or pathlib.rename().
"""
from __future__ import annotations

import json
import logging
from pathlib import Path
from typing import Iterator
from uuid import UUID

from orchestrator.config import get_settings
from orchestrator.tasks.models import (
    AgentRole,
    Message,
    Task,
    TaskPriority,
    TaskStatus,
)

logger = logging.getLogger(__name__)


# Priority ordering for FIFO-by-priority claim.
PRIORITY_ORDER = {
    TaskPriority.P0: 0,
    TaskPriority.P1: 1,
    TaskPriority.P2: 2,
    TaskPriority.P3: 3,
}


class TaskQueue:
    """Filesystem-backed task queue per agent role."""

    def __init__(self) -> None:
        self.settings = get_settings()
        self.data_dir = self.settings.data_dir

    # ── Path helpers ─────────────────────────────────────────────────

    def _queue_dir(self, role: AgentRole) -> Path:
        path = self.data_dir / "queue" / role.value
        path.mkdir(parents=True, exist_ok=True)
        return path

    def _done_dir(self, role: AgentRole) -> Path:
        path = self.data_dir / "done" / role.value
        path.mkdir(parents=True, exist_ok=True)
        return path

    def _inbox_dir(self, role: AgentRole) -> Path:
        path = self.data_dir / "inbox" / role.value
        path.mkdir(parents=True, exist_ok=True)
        return path

    # ── Enqueue ──────────────────────────────────────────────────────

    def enqueue(self, task: Task) -> Path:
        """Add task to queue/{role}/. Returns file path."""
        target = self._queue_dir(task.role) / f"{task.priority.value}-{task.id}.json"
        target.write_text(task.model_dump_json(indent=2))
        logger.info(
            "task_enqueued",
            extra={
                "task_id": str(task.id),
                "role": task.role.value,
                "priority": task.priority.value,
                "title": task.title,
            },
        )
        return target

    # ── Claim (atomic) ───────────────────────────────────────────────

    def claim_next(self, role: AgentRole, agent_id: str) -> Task | None:
        """
        Atomically claim oldest highest-priority task for given role.

        Returns None if queue empty.
        Uses pathlib.rename (atomic on POSIX) to claim file → no race.
        """
        queue_dir = self._queue_dir(role)
        candidates = sorted(
            queue_dir.glob("*.json"),
            key=lambda p: (PRIORITY_ORDER.get(self._extract_priority(p), 99), p.stat().st_mtime),
        )

        for candidate in candidates:
            claimed_path = candidate.with_suffix(".claimed")
            try:
                # Atomic rename — fails if file already claimed by another agent
                candidate.rename(claimed_path)
            except FileNotFoundError:
                continue  # raced, try next

            try:
                task = Task.model_validate_json(claimed_path.read_text())
                task.claim(agent_id)
                claimed_path.write_text(task.model_dump_json(indent=2))
                logger.info(
                    "task_claimed",
                    extra={"task_id": str(task.id), "agent": agent_id, "role": role.value},
                )
                return task
            except Exception as e:
                logger.exception("task_claim_failed", extra={"path": str(claimed_path), "error": str(e)})
                # Restore on parse error
                claimed_path.rename(candidate)
                continue

        return None

    # ── Update ──────────────────────────────────────────────────────

    def update(self, task: Task) -> None:
        """Persist task state changes."""
        # During lifecycle, file lives in queue/ as .claimed
        queue_path = self._queue_dir(task.role) / f"{task.priority.value}-{task.id}.json.claimed"
        if queue_path.exists():
            queue_path.write_text(task.model_dump_json(indent=2))
            return
        logger.warning("task_update_no_path", extra={"task_id": str(task.id)})

    def finalize(self, task: Task) -> None:
        """Move completed task from queue/ to done/."""
        queue_path = self._queue_dir(task.role) / f"{task.priority.value}-{task.id}.json.claimed"
        done_path = self._done_dir(task.role) / f"{task.id}.json"
        done_path.write_text(task.model_dump_json(indent=2))
        if queue_path.exists():
            queue_path.unlink()
        logger.info(
            "task_finalized",
            extra={"task_id": str(task.id), "status": task.status.value, "role": task.role.value},
        )

    # ── Inspection ──────────────────────────────────────────────────

    def list_pending(self, role: AgentRole | None = None) -> Iterator[Task]:
        """Yield all queued (un-claimed) tasks."""
        if role:
            roles = [role]
        else:
            roles = list(AgentRole)

        for r in roles:
            for path in self._queue_dir(r).glob("*.json"):
                if path.name.endswith(".claimed"):
                    continue
                try:
                    yield Task.model_validate_json(path.read_text())
                except Exception:
                    logger.exception("invalid_task_file", extra={"path": str(path)})

    def list_running(self, role: AgentRole | None = None) -> Iterator[Task]:
        """Yield all claimed/running tasks."""
        roles = [role] if role else list(AgentRole)
        for r in roles:
            for path in self._queue_dir(r).glob("*.claimed"):
                try:
                    yield Task.model_validate_json(path.read_text())
                except Exception:
                    logger.exception("invalid_claimed_task", extra={"path": str(path)})

    def list_done(self, role: AgentRole | None = None, limit: int = 50) -> list[Task]:
        """List recently completed tasks."""
        roles = [role] if role else list(AgentRole)
        all_done = []
        for r in roles:
            for path in self._done_dir(r).glob("*.json"):
                try:
                    all_done.append((path.stat().st_mtime, Task.model_validate_json(path.read_text())))
                except Exception:
                    pass
        all_done.sort(key=lambda x: x[0], reverse=True)
        return [t for _, t in all_done[:limit]]

    def get_by_id(self, task_id: UUID) -> Task | None:
        """Find a task across all states."""
        for role in AgentRole:
            for pattern in (f"*-{task_id}.json", f"*-{task_id}.json.claimed"):
                for path in self._queue_dir(role).glob(pattern):
                    return Task.model_validate_json(path.read_text())
            done_path = self._done_dir(role) / f"{task_id}.json"
            if done_path.exists():
                return Task.model_validate_json(done_path.read_text())
        return None

    # ── Stuck detection ─────────────────────────────────────────────

    def find_stuck_tasks(self) -> list[Task]:
        """Tasks running >30min without update — candidates for re-claim."""
        return [t for t in self.list_running() if t.is_stuck]

    def reclaim_stuck(self) -> int:
        """Move stuck claimed tasks back to queue. Returns count."""
        count = 0
        for task in self.find_stuck_tasks():
            queue_path = self._queue_dir(task.role) / f"{task.priority.value}-{task.id}.json.claimed"
            new_path = self._queue_dir(task.role) / f"{task.priority.value}-{task.id}.json"
            if queue_path.exists():
                task.status = TaskStatus.QUEUED
                task.claimed_at = None
                task.claimed_by = None
                task.retry_count += 1
                queue_path.rename(new_path)
                new_path.write_text(task.model_dump_json(indent=2))
                count += 1
                logger.warning("task_reclaimed_stuck", extra={"task_id": str(task.id)})
        return count

    @staticmethod
    def _extract_priority(path: Path) -> TaskPriority:
        """Parse priority from filename (e.g. 'p0-uuid.json' → P0)."""
        prefix = path.stem.split("-")[0]
        try:
            return TaskPriority(prefix)
        except ValueError:
            return TaskPriority.P2


class MessageBus:
    """Inter-agent message bus — inbox/{recipient}/."""

    def __init__(self) -> None:
        self.settings = get_settings()
        self.data_dir = self.settings.data_dir

    def _inbox(self, role: AgentRole) -> Path:
        path = self.data_dir / "inbox" / role.value
        path.mkdir(parents=True, exist_ok=True)
        return path

    def send(self, msg: Message) -> None:
        """Deliver to recipient inbox (or broadcast all)."""
        if msg.to_agent is None:
            recipients = list(AgentRole)
        else:
            recipients = [msg.to_agent]

        for r in recipients:
            target = self._inbox(r) / f"{msg.created_at.timestamp():.0f}-{msg.id}.json"
            target.write_text(msg.model_dump_json(indent=2))
            logger.info(
                "message_sent",
                extra={"msg_id": str(msg.id), "from": msg.from_agent.value, "to": r.value},
            )

    def fetch_unread(self, role: AgentRole) -> list[Message]:
        """List unread messages for role (sorted by created_at asc)."""
        msgs = []
        for path in sorted(self._inbox(role).glob("*.json")):
            try:
                msg = Message.model_validate_json(path.read_text())
                if msg.read_at is None:
                    msgs.append(msg)
            except Exception:
                logger.exception("invalid_message", extra={"path": str(path)})
        return msgs

    def mark_read(self, role: AgentRole, msg_id: UUID) -> None:
        """Mark message as read."""
        for path in self._inbox(role).glob(f"*-{msg_id}.json"):
            try:
                msg = Message.model_validate_json(path.read_text())
                from datetime import datetime, timezone
                msg.read_at = datetime.now(timezone.utc)
                path.write_text(msg.model_dump_json(indent=2))
                return
            except Exception:
                logger.exception("mark_read_failed", extra={"path": str(path)})
