"""
Task + message models — pydantic schemas.

Tasks flow through state machine: queued → claimed → running → done|failed
"""
from datetime import datetime, timezone
from enum import Enum
from typing import Any
from uuid import UUID, uuid4

from pydantic import BaseModel, Field


class AgentRole(str, Enum):
    """Agent roles — territory-mapped."""

    MIND_MASTER = "mind-master"
    THEME = "theme"
    SEO = "seo"
    QA = "qa"


class TaskStatus(str, Enum):
    """Task lifecycle states."""

    QUEUED = "queued"
    CLAIMED = "claimed"
    RUNNING = "running"
    DONE = "done"
    FAILED = "failed"
    CANCELLED = "cancelled"


class TaskPriority(str, Enum):
    """Priority levels — higher priority tasks claimed first."""

    P0 = "p0"  # critical
    P1 = "p1"  # high
    P2 = "p2"  # medium
    P3 = "p3"  # low


class Task(BaseModel):
    """
    Task — unit of work assigned to an agent.

    Tasks live in queue/{role}/ as JSON files until claimed,
    moved to inbox/{role}/{task_id}/ during execution,
    then to done/{role}/{task_id}/ on completion.
    """

    id: UUID = Field(default_factory=uuid4)
    role: AgentRole
    title: str
    description: str
    priority: TaskPriority = TaskPriority.P2
    status: TaskStatus = TaskStatus.QUEUED
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    claimed_at: datetime | None = None
    completed_at: datetime | None = None
    claimed_by: str | None = None
    parent_task_id: UUID | None = None
    metadata: dict[str, Any] = Field(default_factory=dict)
    result: dict[str, Any] | None = None
    error: str | None = None
    retry_count: int = 0
    max_retries: int = 3

    @property
    def age_seconds(self) -> float:
        """Seconds since created."""
        return (datetime.now(timezone.utc) - self.created_at).total_seconds()

    @property
    def is_stuck(self) -> bool:
        """Task claimed but running >30min without update."""
        if self.status != TaskStatus.RUNNING or not self.claimed_at:
            return False
        elapsed = (datetime.now(timezone.utc) - self.claimed_at).total_seconds()
        return elapsed > 1800  # 30min

    def claim(self, agent_id: str) -> None:
        """Mark task as claimed by an agent."""
        self.status = TaskStatus.CLAIMED
        self.claimed_at = datetime.now(timezone.utc)
        self.claimed_by = agent_id

    def start(self) -> None:
        """Mark task as running (post-claim)."""
        self.status = TaskStatus.RUNNING

    def complete(self, result: dict[str, Any] | None = None) -> None:
        """Mark task as done."""
        self.status = TaskStatus.DONE
        self.completed_at = datetime.now(timezone.utc)
        self.result = result

    def fail(self, error: str) -> None:
        """Mark task as failed (or schedule retry)."""
        self.error = error
        if self.retry_count < self.max_retries:
            self.retry_count += 1
            self.status = TaskStatus.QUEUED
            self.claimed_at = None
            self.claimed_by = None
        else:
            self.status = TaskStatus.FAILED
            self.completed_at = datetime.now(timezone.utc)


class Message(BaseModel):
    """
    Inter-agent message — agents post to inbox/{recipient}/.

    Used for: review requests, status updates, broadcast notifications.
    """

    id: UUID = Field(default_factory=uuid4)
    from_agent: AgentRole
    to_agent: AgentRole | None = None  # None = broadcast
    subject: str
    body: str
    related_task_id: UUID | None = None
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    read_at: datetime | None = None
    requires_response: bool = False


class AgentStatus(BaseModel):
    """Agent runtime status — heartbeat snapshot."""

    role: AgentRole
    instance_id: str
    is_alive: bool = True
    current_task_id: UUID | None = None
    last_heartbeat: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    started_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    tasks_completed: int = 0
    tasks_failed: int = 0
    total_cost_usd: float = 0.0
    total_tokens_used: int = 0
