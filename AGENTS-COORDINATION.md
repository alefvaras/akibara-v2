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
[2026-04-28 04:05:00 UTC] [RESPONSIVE-DESIGNER] — Visual audit 2026-04-27 P0-01 + P2-02 fixes deployed + DB cleaned. Files: theme/template-parts/single-product/gallery.php, theme/inc/gallery-cleanup.php (NEW), theme/functions.php (require), plugin/akibara-whatsapp/akibara-whatsapp.{js,css}, bin/cleanup-orphan-gallery-ids.php (NEW), mesa-output/responsive-audit-2026-04-27/**. Commits 3dc822c + 956441b ya en origin/main. Cleanup ejecutado en prod: 29 productos / 46 orphan IDs removidos. NO tocar akibara-whatsapp.php (territorio MIND-MASTER) — version bump 1.4.0→1.4.1 reverted.
[2026-04-28 04:10:00 UTC] [RESPONSIVE-DESIGNER] — Iter 2 verification: P0-01 + P2-02 working en prod (cache-bust URL). Capturando 64 screenshots post-fix (8 viewports × 8 pages) en background para validate responsive cross-device. Branch audit/visual-responsive-fixes pushed (PR #13). HTML page cache canonical URL expira ~1h CF / ~24h LSWS — TTL natural completará rollout para todos los users.
[2026-04-28 04:35:00 UTC] [SEO-SPECIALIST] — Sprint 4 SEO autonomous loop COMPLETO. Commit c0afdab ya en main remoto. Files mi territorio (`inc/seo/**` + `seo_optimization_log.md`). PROD DB-level changes vía MCP/wp-cli (sin deploy de código): 13 pages noindex,follow, 8 brand pages title/desc/keyword optimizados, 5 product_cat optimizados (manhwa/seinen/shonen/shojo/comics), home + /nosotros/ defocused, 1368 productos rich_snippet=book, 1401 productos focus_keyword, 5 redirects 301 nuevos. Branches: feat/seo-sprint-4-clean (sólo mi commit). Loop tick 1 verificado live con cache-bust.
[2026-04-28 04:45:00 UTC] [SEO-SPECIALIST] — Re-aplicando B-S4-SEO-03 filter en `inc/seo/noindex.php` que se perdió en sesión anterior. Nuevo bloque `add_filter("rank_math/sitemap/exclude_post")` (14 page IDs: legal, transactional, dashboard) + defense-in-depth `rank_math/frontend/robots`. Mi territorio (no afecta gallery/whatsapp). About to git pull --rebase + commit + push.
[2026-04-28 04:50:00 UTC] [SEO-SPECIALIST] — DONE PUSH. main remoto en c5c3fb7. Conflict en este file resolved (heartbeats merged). Theme code change pendiente de deploy via Docker-first workflow; DB-level coverage equivalente ya activa en PROD para los 13 pages. Continuando con monitoreo + iter 2 (autores schema, blog FAQ, image alt audit).
[2026-04-28 11:38:00 UTC] [RESPONSIVE-DESIGNER] — PR #14 abierto (heartbeat). PR #13 CERRADO (commits ya en main, version bump rejected). Capture batch responsive bz5x3zsio: 39/64 (61%). Esperando finalización para multimodal review cross-viewport.
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
