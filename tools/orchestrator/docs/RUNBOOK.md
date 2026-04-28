# Akibara Orchestrator — Runbook

Guía operativa para correr el orchestrator en local + producción.

---

## Setup inicial (una sola vez)

```bash
cd tools/orchestrator

# Python 3.11+
python -m venv .venv
source .venv/bin/activate

# Install deps
pip install -e ".[dev]"

# Config
cp .env.example .env
# Edit .env — ANTHROPIC_API_KEY mínimo
# Si querés Discord notif: set AKIBARA_ORCH_DISCORD_WEBHOOK_URL

# Verify config
akibara-orch status
```

Esperado:
```
Akibara Orchestrator
  Data dir: /Users/.../tools/orchestrator/data
  Repo: /Users/.../akibara-v2

Tasks
  Role         Queued  Running  Done (recent)  Unread msgs
  mind-master       0        0              0            0
  theme             0        0              0            0
  ...
```

---

## Operación diaria — escenario solo dev

### Escenario A: trabajar autónomo durante 1h

Terminal 1 (control):
```bash
# Encolar 3 tasks high-priority
akibara-orch enqueue --role mind-master --title "Review Sprint 6 BACKLOG" --priority p1
akibara-orch enqueue --role mind-master --title "Code review PR #15" --priority p1
akibara-orch enqueue --role mind-master --title "Sentry digest últimas 24h" --priority p2
```

Terminal 2 (agent loop):
```bash
akibara-orch agent run --role mind-master
```

→ Agent procesa tasks autónomo, logs JSON estructurados, escribe results en `data/done/`.

Volver al Terminal 1:
```bash
akibara-orch list-tasks --role mind-master --status done
akibara-orch list-tasks --role mind-master --status queued
```

### Escenario B: testing 1 task sin loop

```bash
akibara-orch enqueue --role mind-master --title "Test prompt" --description "Test" --priority p3
akibara-orch agent once --role mind-master
```

→ Procesa exactamente 1 task y exit.

### Escenario C: 4 agents paralelos (Phase 2 futuro)

```bash
# Terminal 1
akibara-orch agent run --role mind-master

# Terminal 2
akibara-orch agent run --role theme

# Terminal 3
akibara-orch agent run --role seo

# Terminal 4
akibara-orch agent run --role qa
```

Cada terminal procesa SOLO tasks de su role (territory isolation).
Inter-agent comm via `data/inbox/{role}/`.

---

## Maintenance

### Tasks stuck (running >30min sin update)

```bash
akibara-orch reclaim-stuck
```

Devuelve tasks bloqueadas a queue con `retry_count++`. Si excede `max_retries` (3) → status FAILED permanente.

### Limpieza done logs (audit trail)

```bash
# Borrar tasks done >30 días (manual, cron en Phase 2)
find tools/orchestrator/data/done -name "*.json" -mtime +30 -delete
```

### DB vacuum (Phase 2 cuando SQLite activo)

```bash
sqlite3 tools/orchestrator/data/orchestrator.db "VACUUM;"
```

### Cost audit

Phase 1: revisar logs JSON `extra.cost_usd` por task.
Phase 2: dashboard `/cost` endpoint.

```bash
# Quick grep
grep '"cost_usd"' tools/orchestrator/data/logs/*.json | python -c "
import json, sys
total = sum(json.loads(line.split(': ', 1)[1]).get('cost_usd', 0) for line in sys.stdin)
print(f'Total cost: \${total:.4f}')
"
```

---

## Troubleshooting

### Agent no claim tasks
```bash
# 1. Verifica queue
akibara-orch list-tasks --role mind-master

# 2. Verifica si hay claimed orphan
akibara-orch list-tasks --role mind-master --status running

# 3. Reclaim if stuck
akibara-orch reclaim-stuck
```

### Anthropic rate limit
Esperar backoff automático (5s, 10s, 20s, fail). Si persiste:
- Reducir `AKIBARA_ORCH_ANTHROPIC_MAX_TOKENS`
- Espaciar tasks vía priority delay
- Considerar `claude-sonnet-4-5` (5x más barato) para tasks no-críticos

### Cost spike
```bash
# Ver historia agent stats
ls -la tools/orchestrator/data/done/*/

# Reducir model default en .env
AKIBARA_ORCH_ANTHROPIC_MODEL=claude-haiku-4-5
```

### Filesystem corruption (poco probable)
```bash
# Backup primero
cp -r tools/orchestrator/data tools/orchestrator/data.bak.$(date +%s)

# Inspect manual
ls tools/orchestrator/data/queue/mind-master/
cat tools/orchestrator/data/queue/mind-master/p1-{uuid}.json | jq

# Reclaim stuck
akibara-orch reclaim-stuck
```

---

## Production deploy (Phase 3 futuro)

### systemd unit example

```ini
# /etc/systemd/system/akibara-mind-master.service
[Unit]
Description=Akibara Orchestrator — MIND-MASTER agent
After=network.target

[Service]
Type=simple
User=akibara
WorkingDirectory=/opt/akibara/tools/orchestrator
EnvironmentFile=/opt/akibara/tools/orchestrator/.env
ExecStart=/opt/akibara/tools/orchestrator/.venv/bin/akibara-orch agent run --role mind-master
Restart=on-failure
RestartSec=30s

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable akibara-mind-master
sudo systemctl start akibara-mind-master
sudo journalctl -u akibara-mind-master -f
```

### Docker compose (future)

Ver `deploy/docker/docker-compose.yml` (Phase 3 deliverable).

---

## Migration paths

### Phase 2 (when ready)
- Add THEME, SEO, QA agent classes
- MCP bridge para Sentry, Gmail, GSC, Cloudflare, WP
- FastAPI dashboard en `localhost:8765`
- APScheduler cron jobs

### Phase 3 (production)
- SQLite → Postgres si multi-machine
- systemd → Docker compose
- Cost guardrails enforcement
- Sentry SDK + AgentOps evaluation

### M2 milestone (>50 customers/mo)
- Considerar managed cron (GitHub Actions cron suficiente)
- Real-time agent dashboard (WebSocket)
- Multi-instance per role (Redis lock)
