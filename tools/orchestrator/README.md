# Akibara Orchestrator

Multi-agent autonomous coordinator para Akibara — Python orchestrator que coordina
4 agentes (MIND-MASTER, THEME, SEO, QA) usando Anthropic API + filesystem queue +
APScheduler + FastAPI dashboard.

> **Status:** Phase 1 MVP — core functional, agentes adicionales + dashboard pendientes.

---

## Quick start

```bash
# 1. Setup (Python 3.11+)
cd tools/orchestrator
python -m venv .venv
source .venv/bin/activate
pip install -e ".[dev]"

# 2. Config
cp .env.example .env
# Edit .env — set ANTHROPIC_API_KEY mínimo

# 3. CLI usage
akibara-orch status                              # overview
akibara-orch enqueue --role mind-master --title "Review BACKLOG" --priority p1
akibara-orch list-tasks --role mind-master
akibara-orch agent run --role mind-master       # start agent loop
akibara-orch agent once --role mind-master      # single iteration (test)
akibara-orch reclaim-stuck                      # recover stuck tasks
```

## Architecture

```
┌─ Anthropic API ──────────────────────────────────────┐
│  Claude Opus/Sonnet/Haiku via Python SDK            │
└──────────────────────────────────────────────────────┘
                    ▲
                    │ LLM calls
┌─ Agents (Python) ────────────────────────────────────┐
│  MindMasterAgent  ThemeAgent  SEOAgent  QAAgent     │
│         ▲              ▲          ▲          ▲       │
│         └──────────────┴──────────┴──────────┘       │
│                        │                              │
│                  BaseAgent.run_forever()              │
└──────────────────────────────────────────────────────┘
                    │
                    ▼
┌─ TaskQueue (filesystem + SQLite) ───────────────────┐
│  data/queue/{role}/  ← pending tasks                │
│  data/inbox/{role}/  ← inter-agent messages         │
│  data/done/{role}/   ← audit trail                  │
└──────────────────────────────────────────────────────┘
                    │
                    ▼
┌─ Connectors ─────────────────────────────────────────┐
│  Anthropic SDK · GitHub (gh CLI) · Discord webhook  │
│  Brevo · MercadoLibre · MercadoPago · BlueX         │
│  MCP bridge → Sentry, Gmail, GSC, Cloudflare, WP    │
└──────────────────────────────────────────────────────┘
```

## Phase roadmap

### Phase 1 — MVP (DONE)
- [x] Project structure + pyproject.toml
- [x] Config via pydantic-settings
- [x] Task + Message models
- [x] Filesystem-backed queue with atomic claim
- [x] Base agent loop (run_once / run_forever)
- [x] MindMasterAgent reference implementation
- [x] AnthropicClient con cost tracking + retry
- [x] Discord webhook
- [x] CLI con typer + rich
- [x] .env.example + gitignore + README

### Phase 2 — Agents + Dashboard (NEXT)
- [ ] ThemeAgent + SEOAgent + QAAgent implementations
- [ ] MCP bridge (JSON-RPC stdio client)
- [ ] FastAPI dashboard + WebSocket live updates
- [ ] APScheduler cron jobs (daily SEO, Sentry digest, deps check)
- [ ] SQLite migrations + state persistence
- [ ] Connector pool con dependency injection

### Phase 3 — Production hardening
- [ ] Tests pytest >80% coverage
- [ ] Docker compose deploy
- [ ] systemd unit files
- [ ] Cost guardrails enforcement (rate limit, daily cap)
- [ ] Observability: Sentry SDK + structured logs
- [ ] AgentOps integration evaluation

## Agent roles

| Role | Territory | Connectors |
|---|---|---|
| **MIND-MASTER** | Backend PHP, tests, coord, PR review | anthropic, github, sentry, wp, discord |
| **THEME** | Theme akibara, frontend CSS/JS | anthropic, figma, wp, cloudflare, github |
| **SEO** | SEO config, sitemaps, redirects | anthropic, gsc, wp, github |
| **QA** | E2E tests, smoke prod, regression | anthropic, gmail, wp, sentry, github |

## Task lifecycle

```
queued → claimed → running → done | failed | cancelled
                                        ↓
                                     retry (max 3) → queued
```

Filesystem layout:
```
data/
├── queue/
│   ├── mind-master/
│   │   ├── p0-{uuid}.json          ← pending
│   │   └── p1-{uuid}.json.claimed  ← in progress
│   ├── theme/
│   ├── seo/
│   └── qa/
├── inbox/
│   ├── mind-master/{uuid}.json     ← unread messages
│   └── ...
└── done/
    ├── mind-master/{uuid}.json     ← completed audit
    └── ...
```

## Cost model

Per iteration (LLM call) costs tracked. Default model `claude-opus-4-5` pricing:
- Input: $15 / 1M tokens
- Output: $75 / 1M tokens
- Cache write: $18.75 / 1M (90% reduction next call)
- Cache read: $1.50 / 1M

Daily budget cap configurable via `AKIBARA_ORCH_MAX_COST_PER_DAY_USD` (default $10).

## Contributing

Branch dedicated: `feat/orchestrator-python`. PR to main when Phase 2 complete.
