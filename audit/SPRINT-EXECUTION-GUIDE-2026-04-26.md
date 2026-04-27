# SPRINT EXECUTION GUIDE Akibara

**Fecha:** 2026-04-26 (last updated 2026-04-27)
**Para:** Alejandro Vargas (solo dev)
**Cómo se usa:** copy-paste cada prompt en una **nueva conversación de Claude Desktop** cuando arranques el sprint correspondiente. Las memorias auto-cargan + Claude lee files autoritativos del repo.

---

## ⚠️ POLICY OBLIGATORIA — Living docs update continuo

**Memoria activa:** `project_living_docs_update_policy.md`

Este guide + BACKLOG + CLEANUP-PLAN + AUDIT-SUMMARY son **living docs**. **TODOS los agentes** (main session + 22 mesa-NN-* + cells) DEBEN mantenerlos updated en tiempo real:

- Item BACKLOG/CLEAN marcado DONE → Edit surgical inmediatamente con `✅ DONE YYYY-MM-DD (commit XXX)` + esfuerzo real
- Lección aprendida aplicable a futuros sprints → append a sección correspondiente
- Decisión arquitectónica nueva → AUDIT-SUMMARY tabla decisiones
- Memoria nueva creada → MEMORY.md index
- Prompt con error/gap → refinar + nota "Updated YYYY-MM-DD: razón"

**Workflow:** Edit surgical → commit pequeño + frecuente → push inmediato (NO acumular). Otros agentes en sesiones paralelas necesitan el update.

**Sprint X.5 closeout:** mesa-23 PM verifica que TODO esté al día (catch-up si gaps), antes de cerrar sprint.

---

## TL;DR — 7 sprints en orden estricto

| # | Sprint | Tipo | Cells | Esfuerzo | Wall-clock | Pre-requisito |
|---|---|---|---|---|---|---|
| 1 | Foundation cleanup | Secuencial | Main + Cell H low | 30-32h | 1.5 sem | (ninguno — primer sprint) |
| 2 | Cell Core extraction + staging | Secuencial | Cell Core + Cell H med | 30-35h | 1.5 sem | Sprint 1 DONE + DNS Brevo propagado 24-48h |
| 3 | Paralelo addons (preventas + marketing) | **Paralelo** | A + B + H high | 60h equiv | 1 sem | Sprint 2 DONE + staging.akibara.cl funcionando |
| 3.5 | Lock release + LambdaTest + retro | Secuencial | mesa-15+01 + Cell H | 6-8h | 2 días | Sprint 3 cells DONE |
| 4 | Paralelo addons (inventario + whatsapp) | **Paralelo** | C + D + H med | 35h equiv | 1 sem | Sprint 3.5 DONE |
| 4.5 | Lock release + retro | Secuencial | mesa-15+01 + Cell H | 4-6h | 1 día | Sprint 4 cells DONE |
| 5 | mercadolibre extraction | Secuencial | Cell E + Cell H low | 15-20h | 1 sem | Sprint 4.5 DONE |

**Total wall-clock:** ~7 semanas calendar a 25-30h capacity/semana.

---

## Antes de cada sprint — checklist universal

Antes de pegar el prompt en una nueva conversación de Claude Desktop:

```bash
# 1. Estar en repo + branch main
cd /Users/alefvaras/Documents/akibara-v2
git checkout main
git pull

# 2. Working tree clean
git status
# Expected: "nothing to commit, working tree clean"

# 3. Sentry 24h check del sprint anterior
# Abre Sentry dashboard → últimas 24h → verificar 0 nuevos error types

# 4. Smoke prod
bin/smoke-prod.sh   # cuando exista (Sprint 1 lo crea)
# o manualmente:
curl -I https://akibara.cl  # HTTP 200
```

Si algo falla, pausa y resuelve antes de arrancar el siguiente sprint.

---

# Sprint 1 — Foundation cleanup ✅ DONE 2026-04-27 (commit e8463dc)

**Status:** ✅ DONE 2026-04-27 — 17/24 items verificados end-to-end + 7 redistribuidos a S3 Cell H. Smoke 20/20 PASS. Sentry GREEN.

**Esfuerzo real:** ~30h (vs estimate 30-32h) — within target.

## Lecciones aprendidas Sprint 1
- **DOBLE OK protocol funcionó:** 6 items destructivos (CLEAN-005/010/012 + SEC-02/03/04) ejecutados sin incidentes con backups + smoke posts.
- **DNS propagation async OK:** B-S1-EMAIL-01 SPF/DKIM/DMARC merged en Cloudflare durante sprint, propagation passive.
- **Visual items requieren mockup → redistribuir a Sprint 3 Cell H** (FRONT-01/02/03 + COMP-01/02/03 + SETUP-06): 7 items movidos correctamente.
- **mu-plugins defensive cross-cutting funcionan bien:** 4 mu-plugins agregados (security-headers, bluex-logs-purge, seo-breadcrumb-fix, no-translate-guard) sin colisiones.
- **PAY-02 MP PCI ADR es decisión PM, NO Claude work:** correctamente DEFERRED.

**Esfuerzo:** ~30-32h (1.5 semanas)

**Items principales:**
- B-S1-SETUP-00 RUNBOOK-DESTRUCTIVO.md (1h, prerequisito BLOQUEANTE)
- B-S1-SEC-01 verify-checksums (30 min)
- B-S1-EMAIL-01 Brevo SPF/DKIM/DMARC (Day 1, async DNS propagation 24-48h)
- B-S1-SETUP-01 quality-gate.sh wrapper Docker + 12 tools install (~6h)
- B-S1-SEC-02 Delete admin backdoors expanded (3h, DOBLE OK)
- B-S1-SEC-03 Cleanup vendor/coverage/dev tooling (2.5h)
- B-S1-SEC-04 wp_bluex_logs TRUNCATE + key migration wp-config-private.php (3h)
- B-S1-SEC-05/06/07 security headers + BlueX webhook + rate limits
- B-S1-EMAIL-02/03 Hostinger crontab + wp-config-private migration
- B-S1-PAY-01 BACS RUT empresa
- B-S1-COMP-01/02 Encargos opt-in fix + Bootstrap legal pages
- B-S1-SEO-01/02 robots + sitemap
- B-S1-CLEAN-01/02/03/04 leftover cleanup + ADRs
- B-S1-SETUP-02/03/04 smoke-prod.sh + verify-via-mcp.ts + deploy.sh

**Tu tarea durante Sprint 1:**

| Acción | Cuándo | Tiempo |
|---|---|---|
| Aprobar `RUNBOOK-DESTRUCTIVO.md` template | Día 1 | 5 min |
| **DOBLE OK** delete users 5/6/7/8 | Día 2 (B-S1-SEC-02) | 15 min (audit forense user 6 ~47 posts) |
| **DOBLE OK** rm -rf vendor/coverage/tests | Día 2 (B-S1-SEC-03) | 5 min |
| **DOBLE OK** TRUNCATE wp_bluex_logs (65k rows) | Día 2 (B-S1-SEC-04) | 10 min (backup verify) |
| Aprobar wp-config-private.php move keys | Día 3 | 5 min |
| Aprobar productos test private (24261/24262/24263) | Día 4 | **N/A — ya eliminados 2026-04-26** |
| Aprobar deploy.sh execution Día 5 | Día 5 | 10 min |
| **Esperar** DNS propagation Brevo SPF/DKIM | Día 6 (24-48h async) | passive wait |
| Smoke completo prod + Sentry 24h start | Día 5 final + Día 6-7 | 30 min |
| **Total tu tiempo Sprint 1** | | ~3-4h |

**DoD Sprint 1:**
- [ ] 24 items DONE en BACKLOG-2026-04-26.md
- [ ] bin/quality-gate.sh corre verde local
- [ ] Brevo SPF/DKIM/DMARC propagados (verificable: `dig TXT akibara.cl`)
- [ ] 4 admin backdoors eliminados (Application Passwords purgadas)
- [ ] vendor/coverage/dev tooling NO accesible vía HTTP en prod
- [ ] wp_bluex_logs vacía + AKB_BLUEX_API_KEY en wp-config-private.php
- [ ] Sentry 24h sin nuevos error types

**Output Sprint 1:**
- `audit/sprint-1/PROGRESS.md` con items DONE + retrospective
- `audit/sprint-1/cell-h/UI-SPECS.md` con baseline LambdaTest screenshots

---

# Sprint 2 — Cell Core extraction + staging ✅ DONE 2026-04-27 (PR #1)

**Status:** ✅ DONE 2026-04-27 — Phase 1 cell-core released + GHA + staging setup. Commits 3a86150 + 7aab600 + 782d80a + ad3c60f + 90fd20b.

**Esfuerzo real:** ~10h transcript (vs ~25-30h estimate manual). Phase 1 only — 6 cero-refs modules migrados (search/category-urls/order/email-safety/customer-edit-address/address-autocomplete). Phase 2+ (rut/phone/product-badges/checkout-validation/health-check/series-autofill/email-template) pendiente Sprint 4+.

## Lecciones aprendidas Sprint 2
- **PHP function hoisting al parse time:** sentinel guards `if (function_exists()) return;` NO previenen redeclare cross-file. Solución: group wrap pattern (REDESIGN.md §9) — wrap top-level functions + hooks dentro de `if ( ! function_exists( 'sentinel' ) )` block.
- **3-mesa adversarial review pre-deploy detectó P0/P1:** mesa-15 + mesa-22 + mesa-02 review (commit 7aab600) bloqueó deploy con bugs latentes. Patrón vale la pena replicar Sprint 3+.
- **Phase 1 release de subset reduce blast radius:** 6 cero-refs modules vs 13 modules monolítico fue decisión correcta. Postmortem `cell-core/REDESIGN.md`.
- **GHA fix iterativo:** 2 commits (0a42ce9 + d3c4068) post-launch para content-gates filtering, gitleaks permissions, playwright sequential. Esperar quality-gate red en primer push.
- **Cell Core Phase 1 HANDOFF.md drift-impossible (post INCIDENT-01):** doc NO contiene signatures. Source-of-truth = `Bootstrap.php` directo.

**Pre-requisito:** Sprint 1 DONE + Sentry 24h verde + DNS Brevo propagado.

**Esfuerzo:** 30-35h (1.5 semanas)

**Cuándo arrancar:** cuando Sprint 1 esté cerrado + tengas accesos Hostinger + Cloudflare DNS.

## PROMPT a pegar en Claude Desktop nueva conversación

```
Sprint 2 secuencial. Lee:
- audit/HANDOFF-2026-04-26.md
- audit/AUDIT-SUMMARY-2026-04-26.md
- audit/CELL-DESIGN-2026-04-26.md sección "Cell Core"
- audit/SPRINT-EXECUTION-GUIDE-2026-04-26.md sección Sprint 2
- audit/sprint-1/PROGRESS.md (verificar Sprint 1 DONE)
- BACKLOG-2026-04-26.md sección Sprint 2
- Memorias: project_architecture_core_plus_addons + project_cell_based_execution + project_staging_subdomain + project_quality_gates_stack

OBJETIVO: extraer akibara-core como plugin separado + setup staging.akibara.cl Hostinger subdominio.

WEEK 1 día 1-2 — B-S2-INFRA-01 Setup staging.akibara.cl:
- Hostinger subdomain staging.akibara.cl → /public_html/staging/
- Mismo MariaDB instance u_akibara, prefix wpstg_ (NO crear DB nueva — Opción A memoria project_staging_subdomain)
- WP install fresh con $table_prefix='wpstg_';
- Importar prod data sed s/wp_/wpstg_/g + anonimizar PII (emails staging+ID@akibara.cl, phones +56 9 0000 0000, api_keys → sandbox-key)
- wp-config staging: AKIBARA_EMAIL_TESTING_MODE=true, MP sandbox keys, Brevo staging API key, WP_DEBUG=true
- Robots.txt Disallow: /
- HTTP basic auth .htpasswd
- Cloudflare Page Rule cache bypass
- bin/sync-staging.sh script
- MySQL user akb_staging con GRANT solo wpstg_*
- Smoke: producto reservar staging → email a alejandro.fvaras@gmail.com

WEEK 1 día 3-5 — B-S2-SETUP-01 GHA + Playwright @critical + plugin-check:
- .github/workflows/quality.yml (corre 12 tools en push/PR)
- Playwright config @critical tag (golden flow checkout, login, search, reservar)
- plugin-check (PCP) instalado
- Pre-commit hook gitleaks + voseo + secrets grep
- GHA verde en push test branch

WEEK 2-3 — Cell Core extraction:
Lanza subagents en paralelo Agent tool calls:
1. mesa-15-architect-reviewer: diseño Service Locator + ModuleRegistry + Lifecycle hooks
2. mesa-22-wordpress-master: WP idioms (plugin headers Requires Plugins WP 6.5+, hooks priority, HPOS facade)
3. mesa-16-php-pro: PSR-4 autoload Akibara\Core\*, namespace migration
4. mesa-17-database-optimizer: schema layer helpers
5. mesa-11-qa-testing: test harness PHPUnit reusable
6. mesa-23-pm-sprint-planner: backlog Cell Core + DoD
7. mesa-01-lead-arquitecto: árbitro

Migración progresiva 13 módulos a akibara-core (mantener plugin akibara antiguo activo en paralelo, eliminar módulos uno a uno con backward compat):
- search (FULLTEXT AJAX)
- category-urls (rewrites)
- order (ordenamiento por tomo)
- email-template + email-safety
- rut + phone validators
- product-badges
- address-autocomplete (Google Places)
- customer-edit-address
- checkout-validation
- health-check
- series-autofill (con CLEAN-017 migration class → legacy/migration-cli.php)

OUTPUT:
- plugins/akibara-core/ funcionando independiente
- composer.json PSR-4 autoload Akibara\Core\*
- Tests passing en tests/akibara-core/ (PHPUnit + Playwright @critical)
- API público documentado audit/sprint-2/cell-core/HANDOFF.md
- Plugin akibara antiguo se mantiene activo con módulos pendientes Sprint 3
- Branch feat/akibara-core merged a main vía PR

LOCK POLICY:
- Cell Core es la ÚNICA cell que toca akibara-core en Sprint 2 (sin paralelo)
- Sprint 3 cells consumirán API público vía ServiceLocator (read-only)

QUALITY GATES:
- bin/quality-gate.sh local debe pasar
- GHA workflow verde en PR
- Smoke test en staging.akibara.cl post-merge
- Sentry 24h check post-deploy prod

CELL H paralelo Sprint 2 medium intensity (~12-15h):
- Component library v1 en Figma (button, card, form, modal, navigation)
- Define design tokens (theme.json + assets/css/tokens.css)
- Theme architecture refactor (block templates, hooks layer)
- Mockups Sprint 3 items pending: encargos checkbox, popup styling, finance widgets

REGLAS DURAS:
- NO romper customer-facing flows (Akibara prod tiene clientes reales)
- Cada commit pasa quality-gate.sh
- DOBLE OK explícito Alejandro para destructivos
- Smoke prod después de cada deploy
- Sentry 24h check post-deploy

REPORTA:
- Items completados / blockers
- API público akibara-core (specs)
- Issues encontrados (workarounds aplicados)
- RFC pendientes para Sprint 3.5

Avísame cada destructivo, mockup decisión, RFC.
```

## Tu tarea durante Sprint 2

| Acción | Cuándo | Tiempo |
|---|---|---|
| Aprobar Hostinger subdomain create (panel manual) | Día 1 | 5 min |
| Aprobar `bin/sync-staging.sh` execution | Día 2 | 10 min |
| Verificar staging.akibara.cl carga (basic auth) | Día 2 finale | 5 min |
| Aprobar GHA workflow primera ejecución | Día 4 | 5 min |
| Aprobar Cell Core extraction PR cada milestone | Week 2-3 | 30 min total |
| Verificar tests passing en CI | Cada PR | 5 min × N PRs |
| Sentry 24h check post-Cell-Core deploy | Final week 3 | 10 min |
| **Total tu tiempo Sprint 2** | | ~2-3h |

## DoD Sprint 2

- [ ] staging.akibara.cl funcionando con basic auth
- [ ] DB wpstg_* poblada y anonimizada
- [ ] Email guard `alejandro.fvaras@gmail.com` testeado en staging
- [ ] GHA workflow verde en main
- [ ] Plugin akibara-core extraído + 13 módulos migrados
- [ ] composer.json PSR-4 autoload funcionando
- [ ] Tests passing local + GHA
- [ ] Plugin akibara antiguo sigue funcionando (con módulos restantes)
- [ ] audit/sprint-2/cell-core/HANDOFF.md con specs API público
- [ ] Sentry 24h sin nuevos error types

---

# Sprint 3 — PARALELO Cell A + Cell B + Cell H high ✅ DONE 2026-04-27 (PRs #5/#6/#7/#8)

**Status:** ✅ DONE 2026-04-27 — 3 cells paralelo + closeout. Cell A `akibara-preventas` v1.0.0 (PR #6, commit e8a88e6), Cell B `akibara-marketing` v1.0.0 (PR #8, commits 5a22245 + 0a1c65f), Cell H Design Ops (PR #7, commits 1a49665 + a5c2e22), closeout (PR #5).

**Esfuerzo real:** ~10-12h transcript (vs ~60h estimate equiv) — multiplicador real cells vs sequential ~5-7x. Equivalente manual ~77-92h (+28% creep, Cell B driver — completó 12 modules en lugar de subset).

## Lecciones aprendidas Sprint 3 (CRÍTICAS — incluyen INCIDENT-01)

**INCIDENT-01 — Sprint 3 plugins TypeError fatal (sitio caído ~3-4h):**
- **L-01 Doc drift es síntoma, NO causa raíz.** HANDOFF mostraba 1-arg `$bootstrap`, código pasaba 2 args desde Sprint 2 commit inicial. Cell A escribió type hint estricto siguiendo el HANDOFF → TypeError. Solución estructural: doc NO contiene signatures, source-of-truth = código.
- **L-02 Type system > tests + docs.** Tests pueden olvidarse, docs pueden driftear, custom PHPStan rules requieren mantenimiento. AddonContract interface fail compile-time, zero maintenance burden.
- **L-03 Per-addon failure isolation > shared hook.** `do_action('akibara_core_init')` con try/catch único bloqueaba TODOS los addons cuando uno crasheaba. `register_addon(AddonContract)` envuelve init() en try/catch dedicado — falla aislada, otros addons siguen UP.
- **L-04 "Backward compat" sin external consumers reales = trampa.** Mantener hook 2-arg "por backward compat" duplicaba signatures + abría camino a re-drift.
- **L-05 Activations de plugins NO testean en CI.** GHA corre PHPCS/PHPStan/ESLint pero nunca activa plugins en WP boot real. Sprint 4 prevention: Phase D `addon-activation-test` job en GHA + PHPUnit Bootstrap auto-recovery (commits 2b614db + 4d4c14e).

**Otras lecciones Sprint 3:**
- **HANDOFF.md como contrato API entre cells funcionó** — permite Lock release sin re-read de código completo.
- **STUBS.md + REQUESTS-FROM-{X}.md como queue inter-cell funcionaron** — Cell H pudo priorizar 3 mockups Cell A + 2 Cell B sin bloqueos.
- **DECISION-*.md para choices que se desvían del plan funcionaron** — cart-abandoned + customer-milestones tienen audit trail con evidencia (Gmail MCP, Brevo panel).
- **RFC mid-sprint via guard zero-risk funcionó** — THEME-CHANGE-01 NO bloqueó Cell A, workaround in-line + RFC asíncrono.
- **Scope creep vigilante:** Cell B liftó 12 modules en lugar de subset — estimate creep +28%. Sprint 4 mantener buffer 20%.
- **Theme akibara-v2 INCOMPLETO** descubierto solo en Sprint 3.5 review — 322 LOC `functions.php` viven en server-snapshot, no en repo. **Pre-condición Sprint 4: sync controlado.**
- **LambdaTest baseline NUNCA creado en Sprint 1** — visual regression Sprint 3.5 no tiene comparable. DEFERRED Sprint 4.5 setup tooling completo.
- **Tests E2E coverage gap 75%:** 4 specs vs 16 target. Cell B no agregó specs. Sprint 4 mín 1 spec/cell vertical.

**Pre-requisito:** Sprint 2 DONE + staging.akibara.cl + akibara-core extraído + Sentry 24h verde.

**Esfuerzo:** 60h equivalente (2-3 cells trabajando paralelo) → 1 semana wall-clock.

**Modo recomendado solo dev:** **1 sesión main** que lanza 3 subagents paralelos via Agent tool. NO necesitas worktrees aún.

## PROMPT a pegar en Claude Desktop nueva conversación

```
Sprint 3 PARALELO (Cells A + B + H). Lee:
- audit/HANDOFF-2026-04-26.md
- audit/CELL-DESIGN-2026-04-26.md secciones "Cell A", "Cell B", "Cell H"
- audit/SPRINT-EXECUTION-GUIDE-2026-04-26.md sección Sprint 3
- audit/sprint-2/cell-core/HANDOFF.md (API público akibara-core que las cells consumen)
- BACKLOG-2026-04-26.md
- Memorias: project_architecture_core_plus_addons + project_cell_based_execution

LANZA en 1 mensaje (3 Agent tool calls paralelos):

═══════════════════════════════════════════════════
1. CELL A — akibara-preventas
═══════════════════════════════════════════════════
Subagents: mesa-16-php-pro, mesa-22-wordpress-master, mesa-15-architect-reviewer, mesa-11-qa-testing, mesa-06-content-voz, mesa-23-pm-sprint-planner, mesa-01-lead-arquitecto

Branch: feat/akibara-preventas

Consolida en akibara-preventas:
- Plugin actual akibara-reservas (1.0.0) — completo
- Módulos del plugin akibara: next-volume + series-notify + editorial-notify
- theme/akibara/inc/encargos.php form (refactor a addon API)
- ENCARGOS UNIFIED como subtype (respetar Akibara_Reserva_Migration::maybe_unify_types existente)

Tablas: wp_akb_preorders + wp_akb_preorder_batches + wp_akb_special_orders (encargos subtype)

Plugin headers WP 6.5+:
/**
 * Plugin Name: Akibara Preventas
 * Requires Plugins: akibara-core
 * Requires at least: 6.5
 */

Dependencies Cell H:
- Mockup encargos checkbox styling
- Mockup preventa card 4 estados (pending/confirmed/shipping/delivered)
- Mockup auto-OOS preventa "fecha por confirmar"

Tests E2E: reservar producto → admin fulfill → cliente recibe email Akibara_Email_Confirmada/Lista/Cancelada

═══════════════════════════════════════════════════
2. CELL B — akibara-marketing + finance rebuild manga-specific
═══════════════════════════════════════════════════
Subagents: mesa-16-php-pro, mesa-09-email-qa (DOMAIN-SPECIFIC), mesa-22-wordpress-master, mesa-15-architect-reviewer, mesa-06-content-voz, mesa-23-pm-sprint-planner, mesa-01-lead-arquitecto

Branch: feat/akibara-marketing

Consolida 13 módulos marketing en akibara-marketing:
- banner, popup, brevo (segmentación 8 listas editoriales: Ivrea AR=24, Panini AR=25, Planeta ES=26, Milky Way=27, Ovni Press=28, Ivrea ES=29, Panini ES=30, Arechi=31)
- cart-abandoned (CONDICIONAL — validar Brevo upstream firing 24-48h post DNS Sprint 1)
- review-request, review-incentive, referrals
- marketing-campaigns (welcome-series, tracking)
- customer-milestones (cumpleaños/aniversario)
- welcome-discount + class-wd-* helpers
- descuentos + descuentos-tramos

REBUILD finance-dashboard manga-specific (CLEAN-016) — NO migrar las 1,453 LOC over-engineered. Build NEW con 5 widgets prioritarios:
1. Top series por volumen vendido (consume _akibara_serie de core)
2. Top editoriales (consume akibara_brevo_editorial_lists 8 listas)
3. Encargos pendientes (consume akibara_encargos_log — 2 activos: Jujutsu kaisen 24/26)
4. Trending searches (consume akibara_trending_searches — One Piece 196k, Jujutsu 34, Berserk 9)
5. Stock crítico <3 unidades (queries wc_orders + postmeta _stock)

Mockup Cell H requerido ANTES de implementar finance widgets.

Tablas: wp_akb_campaigns + wp_akb_email_log + wp_akb_referrals

Dependencies Cell H:
- Mockup cookie banner UI (B-S2-COMP-01 ahora aquí)
- Mockup popup styling refinements
- Mockup finance dashboard manga widgets (5 widgets prioritarios)
- Mockup cart-abandoned email template
- Mockup trust badges treatment

═══════════════════════════════════════════════════
3. CELL H — Design Ops HIGH intensity Sprint 3
═══════════════════════════════════════════════════
Subagents: mesa-13-branding-observador (lead), mesa-07-responsive, mesa-08-design-tokens, mesa-05-accessibility, mesa-22-wordpress-master, mesa-06-content-voz, mesa-23-pm-sprint-planner

Branch: feat/theme-design-s3

Provee a Cells A+B:
- Mockups Figma per item (component library + frame específico)
- UI specs en audit/sprint-3/cell-h/UI-SPECS-{preventas,marketing}.md
- Theme deltas (themes/akibara/ updates page-encargos.php, page-bienvenida.php, etc.)

Sprint 3 priority (10 items en queue):
- Encargos checkbox styling
- Cookie consent banner UI
- Preventa card 4 estados
- Newsletter footer double opt-in
- Auto-OOS preventa "fecha por confirmar"
- Welcome bienvenida transactional notice
- Popup styling refinements
- Cart-abandoned email template
- Finance dashboard manga widgets (5 widgets)
- Trust badges treatment

LOCK POLICY:
- plugins/akibara-core/ y themes/akibara/ son READ-ONLY desde Cells A+B
- Si Cell A o B necesita cambio en Core → abre RFC en audit/sprint-3/rfc/CORE-CHANGE-{NN}.md
- Si necesita cambio en theme → audit/sprint-3/rfc/THEME-CHANGE-{NN}.md
- mesa-15 + mesa-01 lead arbitran RFCs en Sprint 3.5 dedicado

QUALITY GATES por cada cell antes de merge:
- bin/quality-gate.sh local debe pasar (PHPCS WPCS, PHPStan L6, ESLint, Stylelint, Prettier, PHPUnit, Playwright @critical, knip, purgecss, composer-unused, Trivy, gitleaks, composer/npm audit, voseo/claims/secrets grep)
- GHA workflow verde en PR
- Smoke en staging.akibara.cl post-merge cada cell
- Sentry 24h check post-deploy prod

REGLAS DURAS:
- NO modificar precios (_sale_price/_regular_price/_price)
- NO migrar a MailPoet/Klaviyo (Brevo upstream definitivo)
- NO romper preventa flow para 2 encargos activos en prod (Jujutsu kaisen 24/26)
- Email testing redirige a alejandro.fvaras@gmail.com via mu-plugin email-testing-guard
- 8 listas editoriales Brevo preservar IDs exactos
- NO voseo rioplatense en copy (chileno tuteo neutro)

REPORTA por cell:
- Items completados / blockers
- RFCs pendientes
- Mockups solicitados a Cell H
- Tests passing / failing
- Quality gate status

Avísame cada destructivo, RFC, decisión PM, mockup approval.
```

## Tu tarea durante Sprint 3

| Acción | Cuándo | Tiempo |
|---|---|---|
| Compartir/aprobar mockups Figma per item | Día 1-3 (Cell H requests) | 1-2h total |
| Aprobar RFCs Core changes (deferrar a 3.5) | Day 2-4 | 25 min |
| Aprobar merge PR Cell A (preventas) | End Cell A | 10 min |
| Aprobar merge PR Cell B (marketing) | End Cell B | 10 min |
| Aprobar merge PR Cell H (theme) | End Cell H | 10 min |
| Verificar staging post-cada-merge | Continuo | 30 min total |
| **Total tu tiempo Sprint 3** | | ~3-4h |

## DoD Sprint 3

- [ ] Plugin akibara-preventas funcionando (con encargos unified)
- [ ] Plugin akibara-marketing funcionando (13 módulos consolidados)
- [ ] finance-dashboard rebuild manga-specific con 5 widgets (mockup approved)
- [ ] Theme themes/akibara/ updated con component library v2
- [ ] LambdaTest baseline actualizada
- [ ] Tests passing local + GHA todas las cells
- [ ] Smoke staging.akibara.cl OK
- [ ] Plugin akibara antiguo eliminado módulos migrados (preventas + marketing)
- [ ] RFCs documentados en audit/sprint-3/rfc/
- [ ] Sentry 24h sin nuevos error types

---

# Sprint 3.5 — Lock release + RFC consolidation + LambdaTest ✅ DONE 2026-04-27 (incluye INCIDENT-01 + 2 hotfixes)

**Status:** ✅ DONE 2026-04-27 — Lock release + 5 reports + INCIDENT-01 + 3 hotfix PRs. Commits 8f1b947 + afdccdd + d97223c + 29168e5 + (PR #9 refactor robust + PR #10 email collision + PR #11 legacy skip).

**Esfuerzo real:** ~7h (vs estimate 6-8h) — incluye INCIDENT-01 recovery (~3h refactor estructural).

## Lecciones aprendidas Sprint 3.5 (Lock release + INCIDENT-01)

- **L-06 Smoke prod automatizado funcionó (parcialmente).** F-03 fix (3 nuevos checks `/encargos/`, `/mis-reservas/`, `/wp-json/akibara/v1/health`) detectó `/mis-reservas/ 404` post-rsync. Pero NO detectó el fatal porque pre-activate el plugin no corre.
- **L-07 Status quo bias es trampa cognitiva.** Mi razonamiento inicial "respetar la decisión de Sprint 2" era hablar de "respetar" un commit que NO TENÍA design doc justificándolo. Cuestionar status quo cuando hay justification arquitectural fuerte para refactor.
- **L-08 User explicit "robustez máxima" supersede defaults pragmáticos.** Memoria grabada `feedback_max_robustness.md`: estructural > pragmatic, type-system > tests, per-addon isolation > shared catch.
- **Sprint X.5 Lock release pattern funciona como diseñado:** 5 reports formales (RFC-DECISIONS + LAMBDATEST-REPORT + RETROSPECTIVE + QA-SMOKE-REPORT + SPRINT-4-READINESS) producen audit trail completo + recomendación arrancar Sprint 4.
- **Email classes namespace collision (PR #10):** `Akibara_Email_*` colisionaba con `akibara-reservas` legacy plugin que sigue activo en prod hasta Sprint 5. Lección: addon plugins con names compartidos requieren prefix únicos (`AKB_Preventas_Email_*`, `AKB_Marketing_*`).
- **Legacy plugin coexistencia (PR #11):** plugin `akibara` legacy carga modules ya migrados → guard `AKB_MARKETING_LOADED || AKB_PREVENTAS_LOADED` en `akibara.php` skip migrated modules. Lección: durante migración progresiva, plugin source siempre necesita guard de plugin destination.
- **Living docs update policy grabada (commit 29168e5):** memoria `project_living_docs_update_policy.md` para que TODOS los agentes mantengan BACKLOG/CLEANUP-PLAN/AUDIT-SUMMARY/SPRINT-EXECUTION-GUIDE updated en tiempo real (NO acumular).

**Pre-requisito:** Sprint 3 cells DONE (3 PRs merged a main).

**Esfuerzo:** 6-8h (~2 días).

## PROMPT a pegar en Claude Desktop nueva conversación

```
Sprint 3.5 Lock release. Lee:
- audit/sprint-3/rfc/CORE-CHANGE-*.md (RFCs pendientes)
- audit/sprint-3/rfc/THEME-CHANGE-*.md
- audit/sprint-3/cell-{a,b,h}/HANDOFF.md
- audit/CELL-DESIGN-2026-04-26.md sección "Sprint X.5"

OBJETIVO: arbitrar RFCs + consolidate theme deltas + LambdaTest visual regression + retrospective.

Lanza:

1. mesa-15-architect-reviewer + mesa-01-lead-arquitecto:
   - Lee cada RFC pendiente
   - Decisión APPROVED / REJECTED / DEFERRED a Sprint 4.5
   - Output: audit/sprint-3.5/RFC-DECISIONS.md
   - Para APPROVED: implementación en plugins/akibara-core/ vía branch feat/core-s3.5
   - Para REJECTED: notificar cell origen para workaround permanente

2. Cell H mesa-13/07/08/05 + mesa-22:
   - Consolidate theme deltas accumulated Sprint 3
   - Actualiza tokens.css con nuevos design tokens
   - Ejecuta LambdaTest visual regression vs baseline Sprint 1
   - Devices: mobile 375/430/393, desktop 1280/1440/1920
   - Browsers: Chrome, Safari, Firefox
   - Threshold: 0% diff píxeles aceptable en componentes críticos
   - Output: audit/sprint-3.5/LAMBDATEST-REPORT.md

3. mesa-23-pm-sprint-planner:
   - Retrospective Sprint 3
   - Items completados, capacity real vs estimada, blockers identificados
   - Lecciones para Sprint 4 paralelo
   - Output: audit/sprint-3.5/RETROSPECTIVE.md

4. mesa-11-qa-testing:
   - Smoke prod completo (todos critical paths)
   - Sentry 24h check
   - Verificar plugin akibara antiguo se desactivó modulos migrados
   - Output: audit/sprint-3.5/QA-SMOKE-REPORT.md

QUALITY GATES post-RFC merges:
- bin/quality-gate.sh + GHA verde
- Smoke staging
- Sentry 24h post-deploy Core changes

REPORTA:
- RFCs decisions tabulados
- LambdaTest pass/fail per device-browser
- Capacity Sprint 3 real
- Recomendación: arrancar Sprint 4 cuando + qué cells primero

Avísame para approve RFCs APPROVED + merge a Core + LambdaTest issues.
```

## Tu tarea durante Sprint 3.5

| Acción | Cuándo | Tiempo |
|---|---|---|
| Aprobar RFC decisions per RFC | Día 1 | 25 min total |
| Verificar LambdaTest baseline pass | Día 1-2 | 10 min |
| Aprobar merge Core changes (RFCs APPROVED) | Día 2 | 5 min |
| Sentry 24h check post-Core merge | Día 2 | 5 min |
| **Total tu tiempo Sprint 3.5** | | ~45 min |

## DoD Sprint 3.5

- [ ] RFCs decisions documentadas (audit/sprint-3.5/RFC-DECISIONS.md)
- [ ] Core API actualizada con APPROVED RFCs
- [ ] LambdaTest baseline actualizada (audit/sprint-3.5/LAMBDATEST-REPORT.md)
- [ ] Retrospective documentada (audit/sprint-3.5/RETROSPECTIVE.md)
- [ ] Smoke prod verde + Sentry 24h sin nuevos errors

---

# Sprint 4 — PARALELO Cell C + Cell D + Cell H medium

**Pre-requisito:** Sprint 3.5 DONE + Sentry 24h verde + **CATCH-UP living docs Sprint 1-3.5 ejecutado** (ver pre-step abajo).

**Esfuerzo:** 35h equivalente → ~1 semana + 1-2h catch-up.

## ⚠️ PRE-STEP OBLIGATORIO — Catch-up living docs Sprint 1-3.5

Detectado 2026-04-27: las sesiones de Sprint 1, 2, 3, 3.5 NO updatearon BACKLOG/CLEANUP-PLAN/AUDIT-SUMMARY con DONE marks. Antes de arrancar Sprint 4, hacer catch-up:

```
Pre-Sprint 4 catch-up. Lee:
- audit/sprint-1/PROGRESS.md (qué se hizo Sprint 1)
- audit/sprint-2/cell-core/HANDOFF.md
- audit/sprint-3/cell-{a,b,h}/HANDOFF.md
- audit/sprint-3.5/RETROSPECTIVE.md
- audit/sprint-3.5/SPRINT-4-READINESS.md
- git log --oneline para extraer commit hashes per item

Lanza mesa-23-pm-sprint-planner para:

1. Update BACKLOG-2026-04-26.md:
   - Para cada item ejecutado en Sprint 1/2/3/3.5: marcar ✅ DONE YYYY-MM-DD (commit XXX)
   - Agregar esfuerzo real cuando esté disponible
   - Mantener DoD original como referencia

2. Update CLEANUP-PLAN-2026-04-26.md:
   - Para cada CLEAN-NN ejecutado: marcar ✅ DONE + LOC reales + backup path
   - CLEAN items NO ejecutados quedan PENDING

3. Update audit/AUDIT-SUMMARY-2026-04-26.md:
   - Stats finales table: Sprint 1/2/3/3.5 DONE con fecha y commit
   - Mover items resueltos de "DEFERRED" a "DONE"
   - Update P0/P1/P2/P3 ratio post-resolutions

4. Update audit/SPRINT-EXECUTION-GUIDE-2026-04-26.md:
   - Sprint 1/2/3/3.5 secciones: marcar Status: ✅ DONE YYYY-MM-DD + lecciones aprendidas
   - Lecciones de Sprint 3.5 RETROSPECTIVE.md → sección "Lecciones aprendidas" del Sprint correspondiente
   - Si emergieron failure modes → tabla Failure modes

5. Commit + push:
   docs(audit): catch-up living docs Sprint 1-3.5 retroactive
   - BACKLOG: X items marked DONE (Sprint 1/2/3/3.5)
   - CLEANUP-PLAN: Y CLEAN items DONE
   - AUDIT-SUMMARY: stats + decisions updated
   - SPRINT-EXECUTION-GUIDE: Sprint 1-3.5 marked DONE + lecciones

Esfuerzo target: 1-2h.

Reporta: items en gap, items que quedaron pendientes, recomendación arrancar Sprint 4 sí/no.
```

**Tu tarea catch-up:** ~10 min revisar el commit final del catch-up antes de arrancar Sprint 4.

---

## PROMPT a pegar en Claude Desktop nueva conversación (POST catch-up)

```
Sprint 4 PARALELO (Cells C + D + H). Lee:
- audit/CELL-DESIGN-2026-04-26.md secciones "Cell C", "Cell D", "Cell H"
- audit/SPRINT-EXECUTION-GUIDE-2026-04-26.md sección Sprint 4
- audit/sprint-3.5/RFC-DECISIONS.md (Core API actualizada)
- audit/sprint-3.5/RETROSPECTIVE.md (lecciones Sprint 3)
- BACKLOG-2026-04-26.md

LANZA en 1 mensaje (3 Agent tool calls paralelos):

═══════════════════════════════════════════════════
1. CELL C — akibara-inventario
═══════════════════════════════════════════════════
Subagents: mesa-16-php-pro, mesa-17-database-optimizer (DOMAIN-SPECIFIC), mesa-22-wordpress-master, mesa-15-architect-reviewer, mesa-11-qa-testing, mesa-23-pm-sprint-planner

Branch: feat/akibara-inventario

Consolida en akibara-inventario:
- inventory module (admin tools)
- shipping (BlueX + 12 Horas couriers integrados)
- back-in-stock (avísame restock)

Tablas: wp_akb_stock_rules + wp_akb_back_in_stock_subs

Plugin headers WP 6.5+:
/**
 * Plugin Name: Akibara Inventario
 * Requires Plugins: akibara-core
 */

Dependencies Cell H:
- Mockup stock alerts UI
- Mockup back-in-stock subscription form

Tests E2E: checkout BlueX courier + checkout 12 Horas courier + back-in-stock subscribe + restock notify

═══════════════════════════════════════════════════
2. CELL D — akibara-whatsapp
═══════════════════════════════════════════════════
Subagents: mesa-16-php-pro, mesa-22-wordpress-master, mesa-15-architect-reviewer, mesa-11-qa-testing, mesa-23-pm-sprint-planner

Branch: feat/akibara-whatsapp

Refactor mínimo plugin actual akibara-whatsapp v1.3.1:
- Plugin header Requires Plugins: akibara-core (WP 6.5+)
- ServiceLocator integration (consume Core utilities)
- Function akibara_whatsapp_get_business_number() preservada (default 56944242844)

Dependencies Cell H:
- Mockup WhatsApp button placement variants (mobile vs desktop)

Tests: WhatsApp link funciona desde producto + footer

═══════════════════════════════════════════════════
3. CELL H — Design Ops MEDIUM intensity Sprint 4
═══════════════════════════════════════════════════
Subagents: mesa-13-branding-observador, mesa-07-responsive, mesa-08-design-tokens, mesa-05-accessibility, mesa-22-wordpress-master, mesa-06-content-voz, mesa-23-pm-sprint-planner

Branch: feat/theme-design-s4

Sprint 4 priority (6 items en queue):
- Stock alerts UI (inventario)
- Back-in-stock subscription form
- WhatsApp button placement variants
- Editorial color coding palette
- Customer milestones email cumpleaños
- Logo source canonical

LOCK POLICY: igual a Sprint 3 (Core + theme read-only desde verticales, RFCs en audit/sprint-4/rfc/).

QUALITY GATES + REGLAS DURAS: igual a Sprint 3.

REPORTA:
- Items completados / blockers per cell
- RFCs pendientes
- Tests passing / failing
- Recomendación arrancar Sprint 4.5

Avísame destructivos + RFCs + mockups.
```

## Tu tarea durante Sprint 4

| Acción | Tiempo |
|---|---|
| Mockups Figma Cell H requests | 1h total |
| Aprobar RFCs deferral 4.5 | 15 min |
| Aprobar merge PRs Cell C + D + H | 30 min |
| Verificar staging post-merge | 20 min |
| **Total** | ~2h |

## DoD Sprint 4

- [ ] akibara-inventario funcionando (BlueX + 12 Horas couriers preservados)
- [ ] akibara-whatsapp refactored con dependency core
- [ ] Theme actualizado componentes Sprint 4
- [ ] Tests passing local + GHA
- [ ] Smoke staging OK
- [ ] Sentry 24h sin nuevos errors

---

# Sprint 4.5 — Lock release

**Pre-requisito:** Sprint 4 cells DONE.

**Esfuerzo:** 4-6h.

## PROMPT a pegar en Claude Desktop nueva conversación

```
Sprint 4.5 Lock release. Mismo patrón Sprint 3.5 — lee:
- audit/sprint-4/rfc/*.md (RFCs pendientes)
- audit/sprint-4/cell-*/HANDOFF.md
- audit/sprint-3.5/RFC-DECISIONS.md (precedente)

Lanza mesa-15 + mesa-01 lead RFC arbitration + Cell H consolidate theme + 
LambdaTest visual regression + mesa-23 retrospective + mesa-11 QA smoke.

Output: audit/sprint-4.5/{RFC-DECISIONS,LAMBDATEST-REPORT,RETROSPECTIVE,QA-SMOKE-REPORT}.md

Avísame approve RFCs + merges Core.
```

## Tu tarea durante Sprint 4.5

~30 min total (mismo patrón que Sprint 3.5).

---

# Sprint 5 — Cell E mercadolibre (secuencial)

**Pre-requisito:** Sprint 4.5 DONE.

**Esfuerzo:** 15-20h (~1 semana).

## PROMPT a pegar en Claude Desktop nueva conversación

```
Sprint 5 secuencial. Lee:
- audit/CELL-DESIGN-2026-04-26.md sección "Cell E"
- audit/SPRINT-EXECUTION-GUIDE-2026-04-26.md sección Sprint 5
- audit/sprint-4.5/RETROSPECTIVE.md

OBJETIVO: extraer akibara-mercadolibre como addon separado del plugin akibara monolítico.

Subagents: mesa-16-php-pro, mesa-22-wordpress-master, mesa-15-architect-reviewer, mesa-11-qa-testing, mesa-04-mercadopago (DOMAIN-SPECIFIC oauth/webhook patterns reutilizables ML), mesa-23-pm-sprint-planner

Branch: feat/akibara-mercadolibre

Migración:
- 4,250 LOC desde plugins/akibara/modules/mercadolibre/ → plugins/akibara-mercadolibre/
- Tabla wp_akb_ml_items preservada (4 rows, 1 listing real activo One Punch Man 3 Ivrea España $15,465 MLC)
- OAuth flow + webhook handler + sync engine + publisher (764 LOC) + pricing + orders

Plugin headers WP 6.5+:
/**
 * Plugin Name: Akibara MercadoLibre
 * Requires Plugins: akibara-core
 */

Tests sandbox MLC API + smoke listing One Punch Man.

DECISIÓN PM previa: ¿re-activar sync para 3 rows incompletos (product_id 21761, 15326)? Pregúntame antes de implementar.

CELL H paralelo Sprint 5 LOW intensity (~3-4h):
- Admin UI mercadolibre minimal (no customer-facing)

QUALITY GATES + REGLAS DURAS: igual a sprints anteriores.

REPORTA:
- Items completados
- Listing One Punch Man status verificado
- 3 rows incompletos: re-activar sync sí/no (pregunta a Alejandro)
- Tests passing
- Sentry 24h check

Avísame destructivos + decisión PM 3 rows + sandbox MLC.
```

## Tu tarea durante Sprint 5

| Acción | Tiempo |
|---|---|
| Decisión PM: re-activar sync 3 rows incompletos? | 5 min |
| Aprobar sandbox MLC API connection | 5 min |
| Aprobar merge PR Cell E | 10 min |
| Verificar listing One Punch Man activo prod | 5 min |
| Sentry 24h check | 10 min |
| **Total** | ~35 min |

## DoD Sprint 5

- [ ] akibara-mercadolibre extraído y funcionando
- [ ] Listing One Punch Man preservado activo
- [ ] Tabla wp_akb_ml_items intacta
- [ ] Plugin akibara antiguo módulo mercadolibre/ eliminado
- [ ] Tests sandbox MLC passing
- [ ] Sentry 24h sin nuevos errors

---

# Failure modes + recovery (per sprint)

| Falla | Recovery |
|---|---|
| Cell crashea mid-sprint sin output | Re-launch cell con prompt correctivo + último HANDOFF.md |
| Quality gate falla en GHA pero pasa local | Sync versiones tools (Docker base image vs GHA runner) |
| LambdaTest falla Sprint X.5 visual regression | Cell H investiga deltas → si intencional update baseline; si no → fix CSS |
| Sentry 24h muestra nuevo error type | Investigar origen + rollback si severo |
| Branch divergence severa con main | git merge main → resolver conflicts con mesa-15 |
| RFC rechazado, cell ya implementó workaround | Workaround se mantiene; RFC re-considerado en Sprint X+1.5 |
| Staging.akibara.cl down | bin/sync-staging.sh re-genera + verify Hostinger panel |
| User backdoor encontrado mid-Sprint | Pausa sprint + IR forensic + escalate Sentry |
| **TypeError fatal post-deploy plugin activate (INCIDENT-01)** | wp-cli `plugin deactivate` falla cuando WP boot está roto. Usar `bin/emergency-disable-plugin.sh` SSH+mv (creado post-INCIDENT-01). Doc drift root cause → refactor estructural (NO hotfix forward). |
| **Doc drift HANDOFF vs código (INCIDENT-01)** | HANDOFF.md NO debe duplicar signatures de hooks. Source-of-truth = código. Política grabada en `audit/sprint-2/cell-core/HANDOFF.md` post-INCIDENT-01. |
| **Plugin namespace collision (akibara-reservas legacy + akibara-preventas)** | Rename addon classes a prefix único (`AKB_Preventas_*`, `AKB_Marketing_*`). PR #10 c47f2b5 ejemplo. |
| **Legacy plugin carga modules migrados cuando addons activos** | Guard en plugin source: `if defined('AKB_<DEST>_LOADED') skip module`. PR #11 19ecaf7 ejemplo. |
| **Smoke prod NO detecta fatal en plugin activate** | Plugin pre-activate no corre código. Sprint 4 prevention: addon-activation-test job en GHA + staging activación pre-prod (commit 2b614db). |
| **Theme akibara-v2 incompleto (descubierto Sprint 3.5 review)** | functions.php (322 LOC) vive en server-snapshot, NO en repo. Crear functions.php en akibara-v2 sobreescribiría prod → tienda caída. Sprint 4 TASK-S4-THEME-01 sync controlado pre-cualquier theme delta. |
| **PHP function hoisting cross-file redeclare** | sentinel guards `if (function_exists()) return;` NO previenen. Aplicar group wrap pattern (REDESIGN.md §9). |
| **Living docs no updateados durante execution** | Memoria `project_living_docs_update_policy.md` (post-Sprint 3.5): TODOS los agentes mantienen BACKLOG/CLEANUP-PLAN/AUDIT-SUMMARY/SPRINT-EXECUTION-GUIDE updated en tiempo real. Sprint X.5 closeout incluye verify catch-up. |

---

# Reglas duras universales (todos los sprints)

- ✅ Tuteo chileno neutro (NO voseo rioplatense)
- ✅ Email testing solo a `alejandro.fvaras@gmail.com` (mu-plugin email-testing-guard)
- ✅ NO modificar precios (`_sale_price`/`_regular_price`/`_price`)
- ✅ NO instalar plugins third-party
- ✅ DOBLE OK explícito para destructivos
- ✅ Mockup-before-visual obligatorio (Cell H + Figma MCP)
- ✅ Mobile-first (375/430/768/1024 breakpoints)
- ✅ Brevo plataforma email (NO migrar)
- ✅ NO rotar keys (BlueX, MP, Brevo, GA4, Maps, Sentry, DB)
- ✅ Deploy SIEMPRE Docker workflow (bin/quality-gate.sh + GHA + smoke + Sentry 24h)
- ✅ NO subir a prod: tests, docs, vendor, coverage, node_modules

---

**FIN DEL SPRINT EXECUTION GUIDE.**

Próxima sesión nueva en cualquier sprint:
1. Abre Claude Desktop nueva conversación
2. Identifica qué sprint te toca según orden estricto
3. Pega el prompt correspondiente de este guide
4. Apruebas en transcript: destructivos + RFCs + merges
5. Verificas DoD checklist al cerrar sprint
