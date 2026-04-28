# Akibara Orchestrator — Architecture

## Design rationale

### Why Python?
- Anthropic Python SDK más maduro
- FastAPI excelente para dashboard
- APScheduler estable para cron
- Easy deploy (systemd, Docker, GHA)
- Ecosystem AgentOps/LangSmith disponible para observability futuro

### Why filesystem queue (no Redis)?
- Solo dev (Alejandro) — no necesita HA
- Filesystem es durable + git-trackable (audit trail)
- pathlib.rename atómico en POSIX (no race conditions)
- Sin infra adicional (ya hay disk en VPS / local)
- Si crece a M2 (>100 customers/mes) → migrar a Redis/Postgres

### Why SQLite + filesystem (no Postgres)?
- Single-writer en orchestrator (no concurrent writes problemáticos)
- aiosqlite suficiente para dashboard queries
- Backup trivial (cp file)
- Migración a Postgres es 1 cambio en `db_url` config

### Why pydantic + structlog?
- Type safety end-to-end (mypy strict)
- JSON serialization built-in (pydantic models → filesystem)
- Structured logging facilita debugging multi-agent
- Compatibility con FastAPI

---

## Component breakdown

### `config.py` — Settings
Pydantic Settings con env vars `AKIBARA_ORCH_*`. Singleton lazy-loaded.
Validates all configurable values: API keys, paths, cost limits, cron schedules.

### `tasks/models.py` — Schemas
Pydantic models:
- `Task` — unidad de trabajo con state machine
- `Message` — comunicación inter-agent
- `AgentStatus` — heartbeat snapshot

State transitions enforced via methods (`claim()`, `complete()`, `fail()`).

### `tasks/queue.py` — Persistence layer
- `TaskQueue`: claim_next() es atómico vía pathlib.rename
- `MessageBus`: deliver to inbox, broadcast, fetch_unread
- Stuck detection: tasks RUNNING >30min sin update → reclaim

### `agents/base.py` — Loop runner
- Abstract `BaseAgent` con `run_forever()`
- Hooks: `execute_task()` (override), `on_message()` (default log)
- LLM cost tracking integrado en `call_llm()`
- Heartbeat per iteration

### `agents/mind_master.py` — Reference impl
- system_prompt con principios Akibara (robustness, YAGNI, no voseo, etc.)
- Task type routing: sprint_plan, pr_review, heartbeat, generic
- Auto-creates review tasks from messages requiring response

### `llm/client.py` — Anthropic wrapper
- Retry exponential backoff (rate limit / timeout)
- Cost calculation per pricing table
- Cache token tracking (90% reduction on cache hits)
- Returns LLMResponse dataclass (text + metadata)

### `messaging/discord.py` — Webhook
- Simple httpx POST
- Pre-built embeds: deploy_notification, alert
- Disabled if no webhook URL set (fails open, no errors)

### `cli.py` — typer + rich
Commands:
- `enqueue`: encolar task manual
- `list-tasks`: ver queue por role/status
- `status`: overview
- `agent run/once`: ejecutar loop
- `reclaim-stuck`: maintenance

---

## Concurrency model

**Single agent per role en máquina actual.** Multi-instance support requiere:
- DB-backed queue (postgres con SELECT ... FOR UPDATE SKIP LOCKED)
- Distributed lock (Redis SETNX) para claim_next

Para escala solo dev (1 máquina, ~25h/sem capacity), filesystem queue es suficiente.

**Stuck detection**: tasks claimed >30min sin update → reclaim_stuck() lo devuelve a queued.
Run `akibara-orch reclaim-stuck` manualmente o vía cron cada 15min.

---

## Cost model

Cada agent track propio `total_cost_usd` + `total_tokens_used` en `AgentStatus`.
Daily aggregation (futuro Phase 2) via SQLite query.

Pricing inline en `llm/client.py:PRICING` — actualizar cuando Anthropic cambia.

Default budget cap: $10/día por orchestrator total. Si excede:
- Phase 1: log warning (no enforce)
- Phase 2: agent throttling automático
- Phase 3: hard stop con alert Discord

---

## Connector matrix

| Connector | Implementation | Status |
|---|---|---|
| Anthropic | `llm/client.py` (SDK directo) | ✅ Phase 1 |
| Discord webhook | `messaging/discord.py` (httpx) | ✅ Phase 1 |
| GitHub | `gh` CLI subprocess wrapper | 📋 Phase 2 |
| MCP bridge | JSON-RPC stdio client → Sentry/Gmail/GSC/CF/WP MCPs | 📋 Phase 2 |
| Brevo | Custom REST client (httpx) | 📋 Phase 2 |
| MercadoLibre | OAuth2 + REST client | 📋 Phase 3 |
| MercadoPago | REST + webhook signature verify | 📋 Phase 3 |
| BlueX | REST client | 📋 Phase 3 |

---

## Observability roadmap

**Phase 1**: structlog JSON output → stdout (capture vía systemd journal).
**Phase 2**: Sentry SDK integration (errors + performance).
**Phase 3**: AgentOps evaluation (LLM call tracing + replay) si M2 milestone.

---

## Deploy targets

**Local dev**: `akibara-orch agent run --role mind-master` en terminal.
**Production (Phase 3)**: systemd units en VPS Hostinger / Cloudflare Workers (no soportado por orchestrator stateful).
**CI**: GHA jobs que llaman `akibara-orch enqueue` desde workflows post-merge.

---

## Security

**Secrets**: solo via env vars / .env (gitignored). Nunca hardcoded.
**API keys rotation**: per memoria `project_no_key_rotation_policy.md` — NO rotar a menos que leak confirmado.
**Sandbox**: tasks ejecutadas en repo local (NOT en prod akibara.cl). Cualquier mutation prod via SSH explicit con confirmación.
**Cost guardrails**: daily cap + per-minute token rate limit (Phase 2 enforcement).
