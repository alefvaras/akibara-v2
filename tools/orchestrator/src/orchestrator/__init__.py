"""
Akibara Orchestrator — multi-agent autonomous coordinator.

Architecture:
    - Agents: MIND-MASTER, THEME, SEO, QA (each role has its own loop)
    - Tasks: queued via filesystem + SQLite-backed state machine
    - Connectors: Anthropic + MCP bridge + custom clients (GitHub, Brevo, etc.)
    - Scheduler: APScheduler for cron-style autonomous jobs
    - Dashboard: FastAPI + WebSocket live updates

See docs/ARCHITECTURE.md for design rationale.
"""

__version__ = "0.1.0"
