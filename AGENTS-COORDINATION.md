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
[2026-04-28 11:51:00 UTC] [SEO-SPECIALIST] — Iter 2 DONE. Top 10 author pages optimizadas (Eiichiro Oda, Togashi, Inoue, Horikoshi, ONE, Akutami, Tabata, Furudate, Noda, Yukimura — 30 meta updates DB). Image alt audit: 1884/1904 (99%) ya tenían alt text. Low-score audit: 93 products con seo_score<70 + desc<100chars deferidos a Iter 3. Blog FAQ schema deferido (requiere copy mesa-06).
[2026-04-28 11:52:00 UTC] [SEO-SPECIALIST] — TODO CERRADO. Sprint 4 SEO loop completo. Estado PROD final: Organization JSON-LD home, 14 pages noindex, 8 brands + 5 product_cat + 10 authors + home + nosotros optimizadas, 1368 productos rich_snippet=book, 1401 focus_keyword, 5 redirects 301, image alt 99%+. MIND-MASTER deployed prod LIVE. Saliendo silente — Iter 3 solo si hay nueva señal de usuario.
[2026-04-28 11:53:00 UTC] [RESPONSIVE-DESIGNER] — ✅ DONE / TODO CERRADO. Visual responsive validado cross-device post-fix. Capturas verificadas en prod: product-preventa (iPhone SE + iPad gen7) → gallery LIMPIA, no black thumb boxes (P0-01 fixed live), home (iPad gen7) → todas secciones con covers visibles, mi-cuenta (iPhone SE) → form login limpio responsive, carrito vacío → empty state + recommendations grid OK. Mis fixes commited en main: 3dc822c + 956441b + caa4af8 (#13) + 6d738bf (#14). DB cleanup: 29 productos / 46 orphan IDs limpiados en prod. P0-01 + P2-02 estables. Saliendo silente — siguiente acción solo si usuario pide nueva iteración.
[2026-04-28 11:55:00 UTC] [RESPONSIVE-DESIGNER] — ScheduleWakeup ticks acknowledged. Capture batches bz5x3zsio + bulu8zsf0 ambas COMPLETED ya. Multimodal review DONE (5 capturas críticas verificadas). Todos los agentes cerrados en heartbeats anteriores. NO scheduling more wakeups — cycle completamente cerrado. Próxima señal solo desde usuario.
[2026-04-28 11:58:00 UTC] [MIND-MASTER] — RE-OPEN: bug user-reported. Página akibara-ml-auth mostraba blank (callback __return_null + parent=null). FIX commited 7b94cae: parent='akibara' visible, callback akb_ml_render_admin_page real, KPIs OAuth + listings, connect/reconnect/disconnect buttons, App Credentials form, Quick Links. tests/e2e/admin/ml-auth.spec.ts NEW + visual-snapshot agrega slug. Playwright 4/4 + screenshot capturado OK. Deploy prod + cache flushed. GAP HONEST: mi suite no cubría hidden pages (parent=null) — ahora regresión-protected.
[2026-04-28 12:00:00 UTC] [SEO-SPECIALIST] — Iter 3 RE-OPENED por user request. ✅ B-S4-SEO-08 DONE: 597 productos con bulk rich descriptions (joinea title+serie+tomo+brand+formato+edad+ISBN+precio via wp eval — usa _akibara_serie/_akibara_numero/_wc_gtin/_akb_book_cover/_akb_min_age/_regular_price + product_brand term). Verificado samples (NieR Automata 3, Naruto 72, From Hell, Smiley 2). ⚠️ B-S4-SEO-FAQ: BLOCKED por permission system — bulk fabricar FAQ con shipping/precio/payment terms requiere mesa-06 explicit auth. Mi nota inicial era correcta. Skip permanente.
[2026-04-28 12:02:00 UTC] [SEO-SPECIALIST] — ✅ TODO CERRADO REAL. Sprint 4 SEO loop + Iter 3 completo. Commits en main: c0afdab (SEO loop) + c5c3fb7 (filter restore) + 124e183/34310a6 (heartbeats) + 7776539 (Iter 3). DB-level cambios PROD: 14 pages noindex · 8 brands + 5 product_cat + 10 authors + home + nosotros optimizadas · 1368 productos rich_snippet=book · 1401 focus_keyword · **597 productos bulk rich descriptions con ISBN/edad/formato** · 5 redirects 301 · image alt 99%+ · Organization JSON-LD home · sitemap exclude_post filter activo. Theme code en main HEAD (deployed por MIND-MASTER 11:46). FAQ blog deferido permanentemente para mesa-06. Saliendo silente.
[2026-04-28 12:15:00 UTC] [MIND-MASTER] — ITERACIÓN 3 user feedback "estilos NO consistentes con theme". Refactor admin.css TOTAL a dark theme matching design-system.css tokens reales (--aki-red #D90010 + dark surfaces). Body scoping para no romper resto WP admin. Dashboard rewrite: 8 KPIs LIVE (pedidos hoy, revenue, preventas, encargos, low stock, BIS, ML, Brevo) + saludo personalizado + Quick Actions + 0 jerga. Mesa de 3 agentes (mesa-22 + mesa-13 + mesa-11) auditó editorial-notify: P1 cap 50 sin requeue FIXED + admin UX overhaul (KPIs cards, badges health, % activos, last_notified human_time_diff, "cómo funciona" section). Commits 3ffe036 + fd4f8d2. Deployed prod. Saliendo silente — próxima señal user.
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
