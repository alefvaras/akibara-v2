# Agents Coordination — Branch `main` shared workspace

**Purpose:** 3+ agents trabajan en paralelo sobre `main`. Este archivo evita pisarse cambios mediante:
1. Territorios explícitos (file paths)
2. Heartbeats con timestamp (qué está haciendo cada agente AHORA)
3. Pull rules (siempre `git pull --rebase` antes de `git push`)

---

## 🗺️ Territory Map (NO crossings)

### Agent: Principal Architect + Senior QA ("MIND MASTER")
- ✅ `wp-content/plugins/akibara-core/**` (backend + admin)
- ✅ `wp-content/plugins/akibara-marketing/**` (excluye CSS frontend que toca theme)
- ✅ `wp-content/plugins/akibara-preventas/**`
- ✅ `wp-content/plugins/akibara-inventario/**`
- ✅ `wp-content/plugins/akibara-mercadolibre/**`
- ✅ `wp-content/plugins/akibara-whatsapp/src/**` + `**.php` (PHP backend)
- ✅ `wp-content/mu-plugins/**`
- ✅ `tests/e2e/**` (Playwright suites)
- ✅ `tests/selenium/**` (Selenium suites)
- ✅ `qa_log.md`
- ✅ `package.json` / `package-lock.json` (dev dependencies)
- ✅ `playwright.config.ts`

### Agent: Responsive / Theme Designer
- ✅ `wp-content/themes/akibara/**` (PHP templates + CSS + JS)
- ✅ `wp-content/plugins/akibara-whatsapp/akibara-whatsapp.css` (frontend bubble)
- ✅ `wp-content/plugins/akibara-whatsapp/akibara-whatsapp.js`
- ✅ `mesa-output/responsive-audit-*/`
- ✅ `bin/cleanup-orphan-gallery-ids.php`

### Agent: SEO Specialist
- ✅ `wp-content/themes/akibara/inc/seo/**`
- ✅ `seo_optimization_log.md`
- ✅ `audit/sprint-*/cell-*/seo-*.md`

### Shared (read-only por defecto, coordinar antes de modificar)
- ⚠️ `BACKLOG-2026-04-26.md` — log de backlog, edita SOLO si tu task añade ítem nuevo
- ⚠️ `audit/**` (ex docs) — append-only docs, NO sobrescribir
- ⚠️ `wp-content/themes/akibara/functions.php` — coordinar (theme agent owns)

---

## ⚠️ Pull/Push Rules

**ANTES de cada `git push`:**
```bash
git fetch origin main
git pull --rebase origin main
# resolve conflicts si los hay
git push origin main
```

**SI hay conflict en archivos NO de tu territorio:**
- NO resolverlo, hacer `git rebase --abort`
- Stashear tus changes: `git stash`
- Avisar en este archivo (sección Heartbeats)
- Esperar 1 minuto + retry

**SI hay conflict en TU territorio:**
- Resolver favoreciendo tu cambio (eres el dueño del file)
- Continuar rebase: `git rebase --continue`

---

## 💓 Heartbeats (escribir cada 30s)

Format: `[YYYY-MM-DD HH:MM:SS UTC] [AGENT-NAME] — actividad actual + files que tocás`

```
[2026-04-28 03:55:00 UTC] [MIND-MASTER] — Sprint 5.5+ QA total. Files staged: tests/e2e/**, tests/selenium/**, akibara-core/admin/modules-control.{php,js}, akibara-core.php, admin.css, qa_log.md. About to git pull --rebase + commit + push. Selenium 4/4 + Playwright 82/83 passing. Module Control dashboard funcional.
[2026-04-28 03:58:00 UTC] [MIND-MASTER] — Cleanup completado: test-qa user deleted, 0 stock changes verified, 0 orders created, Gmail MCP searched (no triggered emails). qa_log.md written. AGENTS-COORDINATION.md creado.
[2026-04-28 04:00:00 UTC] [MIND-MASTER] — Pushing commit final. Sprint 5.5+ deliverables: módulo control + 41 nuevos E2E tests + Selenium smoke + qa_log + finance dashboard real UI + emojis sidebar consistency.
[2026-04-28 11:45:00 UTC] [SEO-SPECIALIST] — DONE merge to main: c5c3fb7 (B-S4-SEO-03 noindex.php filter restored) + 1ab9bc4 (heartbeat). Iter 2 IN PROGRESS — top 10 author pages optimized via SSH wp term meta (DB-level): Eiichiro Oda, Togashi, Inoue, Horikoshi, ONE, Akutami, Tabata, Furudate, Noda, Yukimura. Mi territorio (`inc/seo/**` + DB term meta). Próximo: blog FAQ schema audit + bulk image alt audit + low-score products meta improvement.
[2026-04-28 11:46:00 UTC] [MIND-MASTER] — DEPLOY PROD COMPLETO + SMOKE GREEN. Rsync 6 plugins akibara-* + theme akibara. wp cache flush + opcache reset + LiteSpeed purge OK. Smoke: HOME/TIENDA/MI_CUENTA/MIS_RESERVAS/CART = 200, CHECKOUT 301, ADMIN 302, HEALTH/SEARCH 200. debug.log clean. Sentry últimos 5min: 0 errores akibara.
[2026-04-28 11:47:00 UTC] [MIND-MASTER] — ✅ LIDER MODE DONE. 3 PRs merged (#12 + #13 + #14), prod LIVE en akibara.cl. Sprint 5.5+ deliverables: Module Control toggles + 41 E2E tests + Selenium + Finance Dashboard real UI + emojis sidebar + admin.css comprehensive + visual fixes (responsive) + SEO autonomous loop. Ciclo cerrado.
```

---

## 📋 Conflict Avoidance Checklist

Cuando empiezas una task:
1. [ ] Lee este archivo, identifica tu territorio
2. [ ] `git fetch origin main && git status` para ver estado actual
3. [ ] Escribí heartbeat con file paths que vas a tocar
4. [ ] Trabajá SOLO en tu territorio
5. [ ] Antes de push: `git pull --rebase origin main`
6. [ ] Si conflict en otro territorio: stash + esperar + retry
7. [ ] Después de push: actualiza heartbeat con "DONE"

---

## 🔒 Files NEVER touch (regardless of agent)

- `.git/**`
- `wp-content/uploads/**` (user-generated content)
- `wp-content/wflogs/**` (security logs)
- `node_modules/**`
- `vendor/**`
- `.env*` (secrets)
- `wp-config*.php` (system config — solo deploy scripts pueden tocar)

---

*AGENTS-COORDINATION.md creado 2026-04-28T04:00Z por MIND-MASTER (Principal Architect + Senior QA)*
