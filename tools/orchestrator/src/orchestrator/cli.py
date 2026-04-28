"""
Akibara Orchestrator CLI — entry points para gestión:

    akibara-orch enqueue --role theme --title "fix sticky aria"
    akibara-orch agent run --role mind-master
    akibara-orch status
    akibara-orch list-tasks --role qa
"""
from __future__ import annotations

import logging

import typer
from rich.console import Console
from rich.table import Table

from orchestrator.agents import get_agent
from orchestrator.config import get_settings
from orchestrator.tasks.models import AgentRole, Task, TaskPriority
from orchestrator.tasks.queue import MessageBus, TaskQueue

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)

app = typer.Typer(help="Akibara Multi-Agent Orchestrator CLI")
console = Console()


# ─── Task commands ──────────────────────────────────────────────────

@app.command("enqueue")
def enqueue_task(
    role: str = typer.Option(..., help="Agent role: mind-master | theme | seo | qa"),
    title: str = typer.Option(..., help="Task title"),
    description: str = typer.Option("", help="Task details"),
    priority: str = typer.Option("p2", help="p0 | p1 | p2 | p3"),
    task_type: str = typer.Option("generic", help="Task type for routing"),
) -> None:
    """Enqueue a new task for an agent."""
    queue = TaskQueue()
    task = Task(
        role=AgentRole(role),
        title=title,
        description=description,
        priority=TaskPriority(priority),
        metadata={"type": task_type},
    )
    path = queue.enqueue(task)
    console.print(f"[green]✓[/green] Task enqueued: {task.id}")
    console.print(f"  Path: {path}")
    console.print(f"  Role: {role} | Priority: {priority} | Type: {task_type}")


@app.command("list-tasks")
def list_tasks(
    role: str | None = typer.Option(None, help="Filter by role"),
    status: str = typer.Option("queued", help="queued | running | done"),
    limit: int = typer.Option(20),
) -> None:
    """List tasks by status."""
    queue = TaskQueue()
    role_enum = AgentRole(role) if role else None

    table = Table(title=f"Tasks — {status}")
    table.add_column("ID", style="cyan", no_wrap=True)
    table.add_column("Role", style="magenta")
    table.add_column("Pri", style="yellow")
    table.add_column("Title")
    table.add_column("Age", style="dim")
    table.add_column("Agent", style="dim")

    if status == "queued":
        tasks = list(queue.list_pending(role_enum))
    elif status == "running":
        tasks = list(queue.list_running(role_enum))
    elif status == "done":
        tasks = queue.list_done(role_enum, limit=limit)
    else:
        console.print(f"[red]Invalid status: {status}[/red]")
        raise typer.Exit(1)

    for task in tasks[:limit]:
        age = f"{int(task.age_seconds // 60)}m" if task.age_seconds < 3600 else f"{int(task.age_seconds // 3600)}h"
        table.add_row(
            str(task.id)[:8],
            task.role.value,
            task.priority.value,
            task.title[:50],
            age,
            task.claimed_by or "-",
        )

    console.print(table)
    console.print(f"\n[dim]Total: {len(tasks)} tasks[/dim]")


# ─── Agent commands ─────────────────────────────────────────────────

agent_app = typer.Typer(help="Agent lifecycle commands")
app.add_typer(agent_app, name="agent")


@agent_app.command("run")
def run_agent(
    role: str = typer.Option(..., help="mind-master | theme | seo | qa"),
    instance_id: str | None = typer.Option(None, help="Custom instance ID"),
    max_iterations: int | None = typer.Option(None, help="Stop after N iterations (testing)"),
) -> None:
    """Run an agent loop until interrupted."""
    role_enum = AgentRole(role)
    agent = get_agent(role_enum, instance_id=instance_id)

    console.print(f"[green]▶[/green] Starting agent: {agent.instance_id} ({role})")
    console.print(f"  Poll interval: {agent.poll_interval_seconds}s")
    console.print(f"  Required connectors: {', '.join(agent.required_connectors)}")
    console.print()

    try:
        agent.run_forever(max_iterations=max_iterations)
    except KeyboardInterrupt:
        console.print("\n[yellow]⏸  Agent interrupted by user[/yellow]")

    console.print()
    console.print(f"[dim]Tasks completed: {agent.status.tasks_completed}[/dim]")
    console.print(f"[dim]Tasks failed: {agent.status.tasks_failed}[/dim]")
    console.print(f"[dim]Total cost: ${agent.status.total_cost_usd:.4f}[/dim]")


@agent_app.command("once")
def run_once(
    role: str = typer.Option(..., help="mind-master | theme | seo | qa"),
) -> None:
    """Run a single iteration (claim + execute one task) — useful for testing."""
    agent = get_agent(AgentRole(role))
    did_work = agent.run_once()
    if did_work:
        console.print(f"[green]✓[/green] Processed 1 task")
    else:
        console.print(f"[yellow]∅[/yellow] Queue empty")


# ─── Status ──────────────────────────────────────────────────────────

@app.command("status")
def status() -> None:
    """Overview of orchestrator state."""
    settings = get_settings()
    queue = TaskQueue()
    bus = MessageBus()

    console.print(f"[bold]Akibara Orchestrator[/bold]")
    console.print(f"  Data dir: {settings.data_dir.absolute()}")
    console.print(f"  Repo: {settings.repo_root.absolute()}")
    console.print()

    # Tasks per role
    table = Table(title="Tasks")
    table.add_column("Role", style="magenta")
    table.add_column("Queued", style="yellow", justify="right")
    table.add_column("Running", style="cyan", justify="right")
    table.add_column("Done (recent)", style="green", justify="right")
    table.add_column("Unread msgs", style="blue", justify="right")

    for role in AgentRole:
        queued = sum(1 for _ in queue.list_pending(role))
        running = sum(1 for _ in queue.list_running(role))
        done = len(queue.list_done(role, limit=100))
        msgs = len(bus.fetch_unread(role))
        table.add_row(role.value, str(queued), str(running), str(done), str(msgs))

    console.print(table)


# ─── Maintenance ────────────────────────────────────────────────────

@app.command("reclaim-stuck")
def reclaim_stuck() -> None:
    """Find and re-queue tasks stuck in running state >30min."""
    queue = TaskQueue()
    count = queue.reclaim_stuck()
    if count:
        console.print(f"[yellow]↻[/yellow] Reclaimed {count} stuck tasks")
    else:
        console.print("[green]✓[/green] No stuck tasks found")


if __name__ == "__main__":
    app()
