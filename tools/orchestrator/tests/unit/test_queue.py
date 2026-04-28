"""Unit tests para TaskQueue + MessageBus."""
from __future__ import annotations

from pathlib import Path

import pytest

from orchestrator.config import Settings
from orchestrator.tasks.models import (
    AgentRole,
    Message,
    Task,
    TaskPriority,
    TaskStatus,
)
from orchestrator.tasks.queue import MessageBus, TaskQueue


@pytest.fixture()
def tmp_settings(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Settings:
    """Override settings to use tmp_path data dir."""
    s = Settings(data_dir=tmp_path / "data")
    s.ensure_data_dir()

    # Patch global singleton
    from orchestrator import config

    monkeypatch.setattr(config, "_settings", s)
    return s


@pytest.fixture()
def queue(tmp_settings: Settings) -> TaskQueue:
    return TaskQueue()


@pytest.fixture()
def bus(tmp_settings: Settings) -> MessageBus:
    return MessageBus()


# ─── Task lifecycle ─────────────────────────────────────────────────


def test_enqueue_creates_file(queue: TaskQueue) -> None:
    task = Task(
        role=AgentRole.MIND_MASTER,
        title="Test task",
        description="…",
    )
    path = queue.enqueue(task)
    assert path.exists()
    assert task.role.value in str(path)


def test_claim_returns_oldest_highest_priority(queue: TaskQueue) -> None:
    # Enqueue 3 tasks — different priorities
    p2 = Task(role=AgentRole.MIND_MASTER, title="P2", description="", priority=TaskPriority.P2)
    p0 = Task(role=AgentRole.MIND_MASTER, title="P0", description="", priority=TaskPriority.P0)
    p1 = Task(role=AgentRole.MIND_MASTER, title="P1", description="", priority=TaskPriority.P1)
    queue.enqueue(p2)
    queue.enqueue(p0)
    queue.enqueue(p1)

    # Claim should return P0 first
    claimed = queue.claim_next(AgentRole.MIND_MASTER, "agent-1")
    assert claimed is not None
    assert claimed.priority == TaskPriority.P0
    assert claimed.status == TaskStatus.CLAIMED
    assert claimed.claimed_by == "agent-1"


def test_claim_atomic_no_double_claim(queue: TaskQueue) -> None:
    task = Task(role=AgentRole.MIND_MASTER, title="Single", description="")
    queue.enqueue(task)

    # First claim succeeds
    a = queue.claim_next(AgentRole.MIND_MASTER, "agent-A")
    assert a is not None

    # Second claim returns None (queue empty)
    b = queue.claim_next(AgentRole.MIND_MASTER, "agent-B")
    assert b is None


def test_finalize_moves_to_done(queue: TaskQueue) -> None:
    task = Task(role=AgentRole.MIND_MASTER, title="Done test", description="")
    queue.enqueue(task)
    claimed = queue.claim_next(AgentRole.MIND_MASTER, "agent-X")
    assert claimed is not None

    claimed.complete({"output": "ok"})
    queue.finalize(claimed)

    done = queue.list_done(AgentRole.MIND_MASTER)
    assert len(done) == 1
    assert done[0].status == TaskStatus.DONE
    assert done[0].result == {"output": "ok"}


def test_failed_task_retries(queue: TaskQueue) -> None:
    task = Task(role=AgentRole.MIND_MASTER, title="Fail", description="", max_retries=2)
    task.fail("first error")
    assert task.status == TaskStatus.QUEUED  # retry pending
    assert task.retry_count == 1

    task.fail("second error")
    assert task.retry_count == 2

    task.fail("third error")
    assert task.status == TaskStatus.FAILED  # exhausted


# ─── Message bus ────────────────────────────────────────────────────


def test_send_to_specific_agent(bus: MessageBus) -> None:
    msg = Message(
        from_agent=AgentRole.QA,
        to_agent=AgentRole.MIND_MASTER,
        subject="Review please",
        body="…",
        requires_response=True,
    )
    bus.send(msg)

    received = bus.fetch_unread(AgentRole.MIND_MASTER)
    assert len(received) == 1
    assert received[0].subject == "Review please"

    # QA should NOT receive its own message
    qa_inbox = bus.fetch_unread(AgentRole.QA)
    assert len(qa_inbox) == 0


def test_broadcast_to_all_agents(bus: MessageBus) -> None:
    msg = Message(
        from_agent=AgentRole.MIND_MASTER,
        to_agent=None,  # broadcast
        subject="Sprint 6 starting",
        body="…",
    )
    bus.send(msg)

    # All agents should receive
    for role in AgentRole:
        inbox = bus.fetch_unread(role)
        assert len(inbox) == 1, f"{role} did not receive broadcast"


def test_mark_read_excludes_from_unread(bus: MessageBus) -> None:
    msg = Message(
        from_agent=AgentRole.SEO,
        to_agent=AgentRole.MIND_MASTER,
        subject="Test",
        body="",
    )
    bus.send(msg)

    unread_before = bus.fetch_unread(AgentRole.MIND_MASTER)
    assert len(unread_before) == 1

    bus.mark_read(AgentRole.MIND_MASTER, msg.id)
    unread_after = bus.fetch_unread(AgentRole.MIND_MASTER)
    assert len(unread_after) == 0


# ─── Stuck detection ────────────────────────────────────────────────


def test_stuck_task_detected() -> None:
    from datetime import datetime, timedelta, timezone

    task = Task(role=AgentRole.MIND_MASTER, title="Stuck", description="")
    task.status = TaskStatus.RUNNING
    task.claimed_at = datetime.now(timezone.utc) - timedelta(hours=1)
    assert task.is_stuck


def test_running_task_not_stuck_if_recent() -> None:
    from datetime import datetime, timezone

    task = Task(role=AgentRole.MIND_MASTER, title="Active", description="")
    task.status = TaskStatus.RUNNING
    task.claimed_at = datetime.now(timezone.utc)
    assert not task.is_stuck
