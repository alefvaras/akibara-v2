# AUDIT SUMMARY Akibara — Foundation Audit 2026-04-26

**Para:** Alejandro Vargas (solo dev Akibara)
**Tiempo de lectura:** 5 minutos
**Status audit:** RATIFICADO + EJECUTADO. Sprint 1, 2, 3, 3.5 ✅ DONE 2026-04-27.
**Última revisión:** 2026-04-27 catch-up retroactivo living docs Sprint 1-3.5 + INCIDENT-01 lecciones embebidas.

---

## ⚠️ ARCHITECTURE PIVOT 2026-04-26

**Decisión arquitectónica anterior (status quo 3 plugins) FUE OVERRIDDEN.**

Target estable y firme:
- **1 plugin core** `akibara-core` (~5.5K LOC) — ServiceLocator + ModuleRegistry + Lifecycle + WC HPOS facade + 13 módulos foundation (search, rut, phone, product-badges, address-autocomplete, customer-edit-address, checkout-validation, health-check, **series-autofill SEO foundation**, email-template, email-safety)
- **5 addons** dependientes de core (`akibara-preventas`, `akibara-marketing` con finance rebuild manga-specific, `akibara-inventario`, `akibara-whatsapp`, `akibara-mercadolibre`)
- **1 célula horizontal Design Ops** (Cell H) — owns themes/akibara/ + Figma component library, transversal a verticales

Modelo de ejecución: **células multi-agente** con Claude Code subagents + git worktrees + file-based coord. NO frameworks externos (PraisonAI/CrewAI/MetaGPT descartados — análisis 2026-04-26).

Lock policy: Core + Theme **read-only** durante Sprints paralelos 3+4. RFCs en `audit/sprint-{N}/rfc/` arbitrados por mesa-15 + mesa-01 en Sprint 3.5/4.5 dedicados.

Detalle completo: `audit/CELL-DESIGN-2026-04-26.md` (NEW). Memoria activa: `project_architecture_core_plus_addons.md`.

**4 decisiones módulos consolidadas 2026-04-26:**
1. **mercadolibre** → addon separado (Sprint 5 secuencial)
2. **ga4** → DISABLE ahora + plugin oficial WC GA4 sigue + remover `AKB_GA4_API_SECRET` + archivar `modules/ga4/`. Re-evaluar M3 (50 customers/mo).
3. **finance-dashboard** → DISABLE + rebuild manga-specific en akibara-marketing Sprint 3 Cell B (5 widgets prioritarios: top series, top editoriales, encargos pendientes, trending searches, stock crítico). Mockup obligatorio.
4. **series-autofill** → STAYS in core (SEO foundation Schema BookSeries) + F-02-021 mover migration class a legacy CLI.

Productos test (24261/24262/24263) **eliminados de prod 2026-04-26** por usuario.

Staging trigger Sprint 2 week 1: **B-S2-INFRA-01** subdominio `staging.akibara.cl` Hostinger mismo plan ($0 incremental). Memoria `project_staging_subdomain.md`.

Quality gates 17 tools: **GHA + bin/quality-gate.sh local** (Jenkins descartado). Memoria `project_quality_gates_stack.md`.

---

## TL;DR ejecutivo

**Akibara hoy es una tienda recién partiendo (3 clientes confirmados, 1.371 productos publicados, 8 customers totales) con foundation customer-facing más madura de lo esperado, pero infra/security necesita hardening urgente.** El audit pasó por 12 mesas técnicas (R1) que produjeron ~190 findings, sintetizados en 30 decisiones arquitectónicas (R2) y 14 cleanup items. La mesa-23 PM ratificó todo en este pase final.

**Hallazgos principales:**

1. **3 cleanup seeds del usuario eran wrong-classified** (CLEAN-001 sentry-customizations, CLEAN-003 brevo-smtp, CLEAN-004 AKB_BREVO_API_KEY) — son infraestructura load-bearing que romperia email transaccional + Sentry tracking si se ejecutara. **Cancelados unánime por mesa-02/09/10/15.** ADRs documentando razón en S1.

2. **4 admin backdoors persistencia activa** (typosquat `@akibara.cl.com`, IDs 5/6/7/8) creados en 4 minutos por script. **P0 seguridad.** User 6 tiene ~47 posts que requieren audit forense pre-delete (legítimos vs injection). Application Passwords NO se revocan automáticamente al `user delete` → orphan tokens persisten como persistence vector.

3. **74 MB de dev tooling (vendor + coverage + tests + composer artifacts) deployado en prod sin .htaccess defensivo** → coverage HTML expone source code mapeado por línea (recon completo), composer.json fetcheable. **P0 seguridad.**

4. **BlueX API key plain text en ~65k rows de wp_bluex_logs** → cualquier export SQL filtra la key. **P0 seguridad.** NO rotar (memoria `project_no_key_rotation_policy`) — TRUNCATE + monitoring + key migration a `wp-config-private.php`.

5. **Brevo setup INCOMPLETO**: sender domain no validated, SPF/DKIM/DMARC missing en Cloudflare DNS, API key restricted ("API Key is not enabled"). Workflow upstream Carrito Abandonado tiene 0 traffic. **P1 email infra** — bloquea growth modules.

6. **WP_CRON disabled + Hostinger crontab NOT configured** → next-volume no firing, cart-abandoned local no firing, 6+ crons en silent fail. **P1 setup.**

7. **NO security headers globales** (HSTS, X-Frame, REST users enumeration, xmlrpc no bloqueado), **NO cookie consent banner** (Ley 21.719 vigencia plena Dec 2026), **preventa NO tiene términos específicos Sernac** (refund cancelación editorial). **P0/P1 compliance.**

8. **Encargos auto-suscribe a Brevo lista 2 (newsletter) sin opt-in** → violación Ley 19.628 art. 4. **P0 compliance.**

9. **Frontend customer-facing está MUCHO más maduro que la narrativa inicial** sugería — design system maduro, mobile-first real, schema.org coverage sólido (Store + Product + FAQPage + BreadcrumbList). NO requiere refactor masivo.

10. **5.500 LOC growth modules con 0-1 usuarios reales** (welcome-discount, back-in-stock, series-notify, referrals, cart-abandoned local) → NO eliminar (growth-deferred), activar trigger-driven por milestone customer/mo.

**Recomendación final:** Arrancar Sprint 1 (~30-32h efectivas, 1.5 semanas) cuando estés con foco continuo + acceso a Cloudflare DNS + Brevo dashboard + panel BlueX. Item #1 estricto: B-S1-SETUP-00 RUNBOOK-DESTRUCTIVO.md (1h, prerequisito de cualquier destructivo).

---

## Stats finales

| Métrica | Valor |
|---|---|
| **Findings R1 totales** | ~190 (P0+P1+P2+P3+info) |
| **Mesas R1** | 12 (mesa-02/04/07/08/09/10/12/13/15/19/22/23) |
| **Decisiones arquitectónicas R2** | 30 (sintetizadas por mesa-01 lead) |
| **Cleanup items totales** | 17 (3 cancelados + 1 condicional + 13 nuevos) |
| **Backlog items totales** | ~130 operacionales (sobre ~190 findings) |
| **Sprint 1 status** | ✅ DONE 2026-04-27 (commit e8463dc) — 17/24 verificados, 7 redistribuidos a S3 Cell H |
| **Sprint 1 esfuerzo** | ~30-32h efectivas (estimate) — real ~30h |
| **Sprint 2 status** | ✅ DONE 2026-04-27 (commits 3a86150 + 7aab600 + 782d80a + ad3c60f + 90fd20b — PR #1) |
| **Sprint 2 esfuerzo** | ~25-30h estimate — real ~10h transcript (Phase 1 cell-core + INFRA + GHA setup) |
| **Sprint 3 status** | ✅ DONE 2026-04-27 (PRs #5/#6/#7/#8) — Cells A+B+H paralelo |
| **Sprint 3 esfuerzo** | ~60h equiv estimate — real ~10-12h transcript (~77-92h equiv manual, +28% creep Cell B driver) |
| **Sprint 3.5 status** | ✅ DONE 2026-04-27 (commits 8f1b947 + afdccdd + d97223c + INCIDENT-01 + PRs #9/#10/#11) |
| **Sprint 3.5 esfuerzo** | ~6-8h estimate — real ~7h (incl. INCIDENT-01 recovery + 2 hotfixes) |
| **Sprint 4 status** | ✅ DONE 2026-04-26 (Cells C+D, merges 0f81462 + dcc67f2) — Cell H DEFERIDA Sprint 5 (Haiku context fail) |
| **Sprint 4 esfuerzo** | ~35h estimate — real ~31h (-11%, AddonContract pattern maduro 4ta aplicación) |
| **Sprint 4.5 status** | ✅ DONE 2026-04-26 — NO-RFC + RETROSPECTIVE + QA-SMOKE-REPORT en `audit/sprint-4.5/` |
| **Sprint 4.5 esfuerzo** | ~4-6h estimate — real ~3h (sin RFCs ni LambdaTest baseline) |
| **PENDIENTE MOCKUP items** | parcialmente resueltos via 10 HTML/CSS prototypes Cell H Sprint 3.5; Cell H Sprint 4 deferida (BIS form mock-10 + WhatsApp placement variants pendientes Sprint 5) |
| **DOBLE OK destructivos** | 8 items — 6 ejecutados S1, 2 pending (CLEAN-002/013) |
| **PRs mergeados a main** | 13 mergesets a main (PRs #1-#11 Sprint 1-3.5 secuencial + 2 merges directos Sprint 4 paralelo Cells C+D) |

### Ratio severidad por sprint

| Severidad | Sprint 1 (DONE) | Sprint 2 (DONE) | Sprint 3 (DONE) | Sprint 3.5 (DONE) | Sprint 4 (DONE) | Sprint 5 | Total identificado |
|---|---|---|---|---|---|---|---|
| P0 | 8 (8 ✅) | 4 | 0 | 1 (1 ✅ INCIDENT-01) | (5 prevention ✅) | 0 | 13 |
| P1 | 9 (8 ✅, 1 redistr) | 10 | 8 | 2 (2 ✅) | 4 (2 ✅, 2 deferred Cell H Sprint 5) | TBD | 31 |
| P2 | 4 (3 ✅, 1 redistr) | 7 | 18 | 3 (3 ✅) | 3 (1 ✅, 2 deferred Sprint 5) | TBD | 32 |
| P3 | 3 (3 ✅) | 2 | 19 | 1 (1 ✅) | 0 | TBD | 25 |

**Resoluciones:** Sprint 1+2+3+3.5+4 cerraron 17 P0 + 24 P1 + 14 P2 + 8 P3. Backlog post-Sprint 4.5 está dominado por items growth-deferred (M1-M4 trigger-driven) + Cell H mocks Sprint 5 (BIS form, WhatsApp placement) + Sprint 5 mercadolibre extraction.

---

## Top 5 P0 críticos a resolver primero (orden estricto)

### 1. B-S1-SETUP-00 — RUNBOOK-DESTRUCTIVO.md (1h)

Documenta templates: snapshot tar.gz, mysqldump, DOBLE OK pedido al usuario, rollback rápido por tipo de cambio. **Prerequisito BLOQUEANTE de TODOS los items destructivos.**

### 2. B-S1-SEC-01 — WP core verify-checksums (30 min)

Ejecutar `bin/wp-ssh core verify-checksums --version=$(bin/wp-ssh core version)`. Si reporta mismatches en los 10 archivos modificados <90 días, escalar a IR forensic completo y posponer Sprint 1. **Prerequisito BLOQUEANTE de cleanups destructivos.** Garantiza filesystem confiable como base.

### 3. B-S1-SEC-02 — Delete admin backdoors expanded (3h)

Audit forense user 6 (~47 posts) + user 18 + Application Passwords + cron events no estándar → backup DB completo → DOBLE OK Alejandro → delete con `--reassign=1` para user 6. Cleanup `wp_akb_referrals.id=4` si user 18 confirmado malicioso. (CLEAN-012, F-10-004, F-10-021, F-02-003)

**Files:** wp_users IDs 5/6/7/8 + `_application_passwords` user meta + `wp_akb_referrals.id=4`

### 4. B-S1-SEC-03 — Cleanup vendor/coverage/dev tooling deployado en prod (2.5h)

Agregar excludes en `bin/deploy.sh` + `.htaccess` defensivo (defense in depth) en `plugins/akibara/`, `plugins/akibara-reservas/`, `themes/akibara/`. Re-deploy plugin sin vendor/coverage/tests. (CLEAN-005, F-02-001, F-10-006, F-10-007, F-15-008, F-22-004)

**Files:** `wp-content/plugins/akibara/{vendor,coverage,tests,composer.json,composer.lock,phpunit.xml.dist,phpcs.xml,phpstan.neon,*-baseline}`

### 5. B-S1-SEC-04 — TRUNCATE wp_bluex_logs + key migration via wp-config-private.php (3h)

Backup tabla → DOBLE OK → TRUNCATE → mover `AKB_BLUEX_API_KEY` a `wp-config-private.php` (chmod 600, NO served) → cron mensual purge >30d. **NO rotar key** per memoria `project_no_key_rotation_policy`. Reportar a soporte BlueX (responsible disclosure). (CLEAN-010, F-10-001, F-PRE-001)

**Files:** Tabla `wp_bluex_logs` + `wp-config.php` + nuevo `wp-config-private.php` + nuevo `mu-plugins/akibara-bluex-logs-purge.php`

---

## Decisiones controvertidas que pidieron user OK explícito

| Decisión | Status | Razón |
|---|---|---|
| **CLEAN-001 sentry-customizations.php** | ❌ CANCELADO | Mu-plugin define `WP_SENTRY_PHP_DSN` constante load-bearing + PII scrubbing chileno (RUT, +56, email). Si se borra: cero error tracking + violación Ley 19.628 transferencia internacional. Confirmado unánime mesa-02/09/10/15. |
| **CLEAN-003 brevo-smtp.php** | ❌ CANCELADO | Mu-plugin intercepta TODO `wp_mail` (Hostinger BLOQUEA PHP mail()). 7 callsites dependen. 72 emails ya enviados via este path. Si se borra: rotura total email delivery + Hostinger vuelve a bloquear cuenta. |
| **CLEAN-004 AKB_BREVO_API_KEY constante** | ❌ CANCELADO | Alimenta CLEAN-003 (load-bearing). Issue real es API key restricted → fix via generar nueva key + migrar a wp-config-private.php (NO eliminar la constante). |
| **NO rotar keys** (BlueX, MP, Brevo, GA4, Maps, Sentry, DB password) | ✅ ACEPTADO | Memoria `project_no_key_rotation_policy`. Mitigaciones via `wp-config-private.php` + `.gitignore` strict + Hostinger backup retention 7d + monitoring proactivo + logger redact patterns futuro. |
| ~~**Status quo plugins** (3 plugins separados)~~ | ❌ **SUPERSEDED 2026-04-26** | Override explícito del usuario: target = 1 core + 5 addons + Cell H horizontal. Refactor 130-180h Sprints 2-5. Memoria `project_architecture_core_plus_addons.md`. |
| **Core + 5 Addons + Cell H** (NUEVO target firme) | ✅ ACEPTADO 2026-04-26 | Plugin headers `Requires Plugins: akibara-core` (WP 6.5+) + ServiceLocator + ModuleRegistry. Lock policy Core+Theme durante sprints paralelos. RFC + Sprint X.5 industry standard pattern (Linux kernel, WordPress core, Yoast). |
| **mercadolibre como addon** | ✅ ACEPTADO 2026-04-26 | Sprint 5 secuencial. 4,250 LOC + 1 listing real activo (One Punch Man 3 Ivrea España $15,465 MLC). |
| **ga4 DISABLE** | ✅ ACEPTADO 2026-04-26 | Plugin oficial WC GA4 sigue. Remover AKB_GA4_API_SECRET. Archivar modules/ga4/. Re-evaluar M3 (50 customers/mo). |
| **finance-dashboard rebuild manga-specific** | ✅ ACEPTADO 2026-04-26 | Disable actual (1,453 LOC over-engineered F-02-014). Rebuild Sprint 3 Cell B con 5 widgets prioritarios + mockup primero. |
| **series-autofill stays in core** | ✅ ACEPTADO 2026-04-26 | SEO Schema BookSeries foundation, consumido por todos los addons. F-02-021 migration class → legacy CLI. |
| **Stack execution: Claude Code subagents + git worktrees** | ✅ ACEPTADO 2026-04-26 | Análisis honesto 4 frameworks: gana en 5/6 dimensiones robustez para solo dev (operacional, costo, control, auditabilidad, mantenimiento). Re-evaluar solo si equipo 3+ devs + staging dedicada estable + >50 customers/mo + cells >10 simultáneamente. |
| **Staging subdominio Hostinger** | ✅ ACEPTADO 2026-04-26 | `staging.akibara.cl` mismo plan, $0 incremental. Trigger Sprint 2 week 1 (B-S2-INFRA-01). Memoria `project_staging_subdomain.md`. |
| **Quality gates GHA + bin/quality-gate.sh local** | ✅ ACEPTADO 2026-04-26 | 17 tools (PHPCS WPCS, PHPStan L6, plugin-check, ESLint, Stylelint, Prettier, PHPUnit, Playwright @critical, knip, purgecss, composer-unused, Trivy, gitleaks, composer/npm audit, voseo/claims/secrets grep). Jenkins descartado. |
| **Brevo upstream** (NO migrar a MailPoet/Klaviyo) | ✅ ACEPTADO | Decisión cerrada por usuario. Carrito abandonado workflow upstream activo. Memoria `project_brevo_upstream_capabilities`. |
| **Test products `post_status=private`** (NO borrar 24261/24262/24263) | ✅ ACEPTADO | Memoria `project_test_products_visibility`. Productos viven en prod, invisibles cliente, accesibles admin/QA. Resuelve 4 findings con 1 acción. |
| **MP Custom Gateway mantener** (NO desactivar) | 🟡 DEFERRED | ADR documentado en S1 (B-S1-PAY-02), decisión PM con dueño en sesión separada. Trade-off: UX conversion vs PCI scope SAQ-A-EP. |
| **Marketing automations growth-deferred** | ✅ ACEPTADO | NO activar referrals/series-notify/welcome-discount hasta milestone customer/mo. ~5.500 LOC growth modules con 0-1 usuarios → NO eliminar, activar trigger-driven. |
| **CLEAN-002 cart-abandoned condicional** | 🟡 CONDICIONAL | Solo después de validar Brevo upstream firing 24-48h post-DNS propagation. Si tracker NO firing → mantener local + investigar conflict. **2026-04-27 update:** Brevo upstream firing CONFIRMED via Gmail MCP (4 emails últimos 30d) en Sprint 3 Cell B → módulo legacy DEPRECATED en `akibara-marketing/modules/cart-abandoned/` como deprecation stub. Decisión documentada en `audit/sprint-3/cell-b/DECISION-CART-ABANDONED.md`. |
| **RFC THEME-CHANGE-01 encargos guard** | ✅ APPROVED 2026-04-27 (Opción B) | Cell A migró encargos handler theme→plugin. Mantener guard `if defined('AKB_PREVENTAS_ENCARGOS_LOADED') return;` en `themes/akibara/inc/encargos.php` (zero-risk). Opción A (eliminar archivo + agregar require condicional en functions.php) BLOQUEADA por hallazgo: theme akibara-v2 incompleto (`functions.php` 322 LOC vive en server-snapshot). Sprint 4 TASK-S4-THEME-01 sync controlado pre-Opción A. Detalles: `audit/sprint-3.5/RFC-DECISIONS.md`. |
| **customer-milestones DEFERRED Sprint 5+** | ✅ DECISIÓN 2026-04-27 | Cell B Sprint 3 scaffoldeó 240 LOC sin fuente legacy. Brevo panel solo 1 automation activa (no birthday/anniversary). Tienda 3 customers → ROI cero hasta milestone 50/mes. Loader comentado, código preservado. `audit/sprint-3/cell-b/DECISION-CUSTOMER-MILESTONES.md`. |
| **AddonContract structural refactor (post INCIDENT-01)** | ✅ APROBADO 2026-04-27 (PR #9) | Sprint 3 deploy resultó TypeError fatal por doc drift HANDOFF vs Bootstrap signature. User explicit "no parches" → memoria `feedback_max_robustness.md`. Solución: AddonContract interface (type-safe) + Bootstrap auto-recovery (per-addon try/catch isolation) + drift-impossible HANDOFF (no contiene signatures, source-of-truth = código). Detalles: `audit/sprint-3.5/INCIDENT-01.md` + 8 lecciones aprendidas (L-01 a L-08). |
| **2 hotfixes Sprint 3.5 post-deploy** | ✅ APROBADOS 2026-04-27 (PRs #10/#11) | (1) Email classes namespace collision: `Akibara_Email_*` colisionaba con akibara-reservas legacy → rename a `AKB_Preventas_Email_*` (PR #10 c47f2b5). (2) Legacy plugin akibara cargaba modules ya migrados cuando addon plugins active → guard `AKB_MARKETING_LOADED || AKB_PREVENTAS_LOADED` skip migrated modules (PR #11 19ecaf7). |

---

## Items DEFERIDOS a growth con triggers cuantitativos

### Milestone 1 — 5 customers/mo durante 1 mes

- Newsletter signup form en home (footer + popup welcome) — REQUIERE MOCKUP
- Welcome series email (1 email "gracias por suscribirte" → CTA categorías populares)
- Smoke test review-request post-delivery

### Milestone 2 — 25 customers/mo durante 2 meses

- Welcome series multi-step (3 emails: bienvenida + categorías + descuento primera compra cupón WC)
- Back-in-stock notifications activate
- Next-volume sale email (cron firing real, validado en B-S1-EMAIL-02)
- Review-request automation completa post-delivery

### Milestone 3 — 50 customers/mo durante 2 meses (TRIGGER OBLIGATORIO)

- **Brevo Free → Standard $18/mo upgrade** (Decisión #15) — riesgo P0 si NO upgrade: order confirmations bloqueadas por marketing burst
- Marketing campaigns activate (CyberDay/BlackFriday)
- Refactor finance-dashboard manga-specific Opción B (Mockup primero)
- Decisión #14: descuentos consolidación execute (3 sistemas → marketing-campaigns umbrella)
- Performance audit completo (Lighthouse, query optimization, image lazy load) — ahora con tráfico real
- Considerar CDN imágenes producto

### Milestone 4 — 100 customers/mo durante 3 meses

- Brevo segmentation manga-specific (shounen vs seinen vs josei vs cómics)
- Personalized recommendations email
- Loyalty/rewards program (cupones recurrent customers)
- Akibara cumpleaños campaign — checkout opt-in + date picker (Mockup primero)
- A/B testing welcome popup (cuando hay tráfico para significancia)
- Hire ayuda externa (designer freelance, ad-hoc) — solo dev no escala más allá
- Staging environment dedicado (subdomain `staging.akibara.cl`)
- CI/CD básico (GitHub Actions deploy on push to `production` branch)
- Test suite robusto (PHPUnit + WP-Browser para flow completo checkout)

### Milestone 5+ — 250+ customers/mo (no planificar todavía)

- Re-correr la mesa de 22+ × 7 rondas (escalar de mini-foundation a audit completo)
- Probable necesidad migrar arquitectura (microservices? headless? PHP version actualizado?)

---

## Recomendación de Sprint 1 — scope concreto

### QUÉ SÍ hacer (24 items, ~30-32h efectivas)

**Setup infra (5 items, ~9h):**
- RUNBOOK-DESTRUCTIVO.md (prerequisito)
- quality-gate.sh script (Docker + tools paralelos)
- smoke-prod.sh script (Playwright vs prod)
- deploy.sh script (rsync con excludes obligatorios)

**Security crítica (7 items, ~14h):**
- WP core verify-checksums
- Delete 4 admin backdoors expanded (workflow forense)
- Cleanup vendor/coverage/dev tooling deployado
- TRUNCATE wp_bluex_logs + key migration wp-config-private.php
- mu-plugin akibara-security-headers (HSTS + REST users + xmlrpc + Permissions-Policy)
- BlueX webhook hard-fail
- Magic Link IP fix + REST cart endpoints rate limiting

**Email infra (3 items, ~5h):**
- Brevo SPF/DKIM/DMARC + sender domain validation (DNS propagation 24-48h)
- Hostinger crontab setup
- Brevo API key fix + secrets a wp-config-private.php

**Backend bug fixes (2 items, ~0.5h):**
- Productos test → status `private` (resuelve 4 findings)
- atomic_stock_check `$waited = 0.0` bug fix (1 línea)

**Frontend accessibility (2 items, ~2.5h):**
- Sale price layout broken validación + fix
- Focus rings WCAG 2.4.13 outline solid

**Compliance crítica (2 items, ~4.5h):**
- Encargos opt-in fix + lista Brevo dedicada (Mockup checkbox)
- Bootstrap legal pages: /cookies/ + términos preventa Sernac + Sernac escalation

**Payment (1 item, 1h):**
- BACS RUT empresa → wp_options + eliminar override theme

**SEO (2 items, ~1.25h):**
- Duplicate robots meta conflict + JSON-LD breadcrumb position int
- Sitemap Rank Math validation (post productos test private)

**Cleanup leftover (3 items, ~0.5h):**
- Eliminar `inc/enqueue.php.bak-2026-04-25-pre-fix`
- Eliminar `themes/akibara/hero-section.css` root duplicado
- Eliminar duplicado `themes/akibara/setup.php` o `inc/setup.php`

**ADRs documentation (1 item, 1h):**
- ADRs cancelaciones CLEAN-001/003/004 + status quo plugins + architects review per sprint

### QUÉ NO hacer en Sprint 1 (movido a Sprint 2)

- Cookie consent banner (requiere mockup banner)
- CLEAN-002 cart-abandoned local (condicional post-validación 24-48h Brevo upstream)
- Theme back-in-stock duplicate eliminate (depende de Brevo firing)
- MP hardening mu-plugin (no urgente, defensa adicional)
- Auto-OOS-to-preventa opt-in (requiere mockup copy cart/checkout)
- Preventa/Encargo/Agotado UX matrix (requiere mockup 4 estados)
- Política privacidad re-write Ley 21.719 (puede esperar S2B)
- RTBF eraser/exporter (requiere mockup UI mi-cuenta)
- GA4 consent gating (depende de cookie banner)
- Newsletter footer double opt-in (requiere mockup form)
- REST endpoints rate limiting batch resto (mkt/open, track_order, captcha, search.php)
- Module Registry guard DRY refactor

### Plan día por día (Sprint 1)

- **Día 1 mañana:** B-S1-SETUP-00 RUNBOOK + B-S1-SEC-01 verify-checksums + B-S1-EMAIL-01 inicio (DNS propagation async)
- **Día 1 tarde:** B-S1-SETUP-01 quality-gate.sh + B-S1-SETUP-02 smoke-prod.sh
- **Día 2 mañana:** B-S1-SEC-02 delete backdoors (DOBLE OK Alejandro)
- **Día 2 tarde:** B-S1-SEC-03 vendor cleanup + B-S1-SEC-04 wp_bluex_logs TRUNCATE
- **Día 3 mañana:** B-S1-SEC-05 security headers + B-S1-SEC-06 BlueX webhook + B-S1-SEC-07 rate limits
- **Día 3 tarde:** B-S1-EMAIL-02 Hostinger crontab + B-S1-EMAIL-03 wp-config-private + B-S1-PAY-01 BACS RUT
- **Día 4 mañana:** B-S1-BACK-01 productos test private + B-S1-BACK-02 atomic_stock bug + B-S1-FRONT-01 sale price + B-S1-FRONT-02 focus rings
- **Día 4 tarde:** B-S1-COMP-01 encargos opt-in + B-S1-COMP-02 legal pages
- **Día 5 mañana:** B-S1-SEO-01 robots + B-S1-SEO-02 sitemap + B-S1-CLEAN-01/02/03 cleanup batch
- **Día 5 tarde:** B-S1-CLEAN-04 ADRs + smoke prod completo + Sentry monitor 24h start
- **Día 6:** Wait DNS propagation B-S1-EMAIL-01 + LambdaTest visual regression FRONT-01/02 + Sprint POST-validation
- **Día 7:** B-S1-SETUP-04 deploy.sh (consolidación + retrospect)

---

## Architects review per sprint workflow (RACI)

Cada sprint pasa por 3 gates de arquitectura para detectar drift y deuda arquitectónica.

| Gate | Cuándo | Quién (Responsible) | Output | RACI |
|---|---|---|---|---|
| **PRE-review** | Día 1 sprint, antes de implementar | mesa-15-architect-reviewer | `audit/sprint-{N}/ARCHITECTURE-PRE-REVIEW.md` | R: mesa-15. C: mesa-22, mesa-02. A: mesa-23 PM. |
| **MID-checkpoint** | Mid-sprint si dura >5 días | mesa-15 | `audit/sprint-{N}/ARCHITECTURE-MID-CHECKPOINT.md` | R: mesa-15. |
| **POST-validation** | Último día sprint, antes de cerrar | mesa-15 + mesa-22 + mesa-02 | `audit/sprint-{N}/ARCHITECTURE-POST-VALIDATION.md` | R: mesa-15, mesa-22, mesa-02. A: mesa-23 PM. |
| **Quarterly audit** | Cada 3 sprints | mesa-15 (audit completo) | `audit/quarterly/ARCHITECTURE-AUDIT-Q{N}.md` | R: mesa-15. C: todos. |

**PRE-review checklist:**
1. ¿Algún item del sprint impacta más de 1 plugin?
2. ¿Algún item introduce nueva dependencia cross-plugin? (`class_exists`/`function_exists` mandatory)
3. ¿Algún item modifica mu-plugins load-bearing (brevo-smtp, sentry-customizations, email-testing-guard)? Smoke test obligatorio
4. ¿Algún item modifica hooks WC con priorities (race conditions)? Aplicar constantes `AKB_FILTER_PRIORITY_*`
5. ¿Algún item agrega un módulo nuevo? Aplicar regla "no nuevo módulo sin justificar"
6. ¿Algún item viola alguna memoria del usuario? (`feedback_no_over_engineering`, `feedback_minimize_behavior_change`, `project_no_key_rotation_policy`)
7. ¿Riesgo de regresión por cambio? Smoke test obligatorio post-deploy
8. ¿Item requiere mockup Figma? Verificar approval ANTES de empezar

**POST-validation checklist:**
1. ¿Boundaries plugin se respetaron? (no nuevos hard requires cross-plugin)
2. ¿Mu-plugins load-bearing intactos?
3. ¿ADRs actualizados si la arquitectura cambió?
4. ¿Diagramas plugin interaction necesitan update?
5. ¿Dead code introducido? (mesa-02 sweep)
6. ¿WP/WC idioms respetados? (mesa-22 sweep — nonces, capabilities, hooks priorities)
7. ¿Regresiones potenciales identificadas para smoke test?
8. ¿LambdaTest visual regression passed (si sprint visual)?
9. ¿Sentry 24h post-deploy sin errores nuevos?

Detalles completos en `audit/round2/ARCHITECTURE-ROBUSTNESS-MESA.md` sección 5.

---

## Quarterly re-audit triggers

Re-correr la mesa de 22+ × 7 rondas (escalar de mini-foundation a audit completo) cuando se cumpla CUALQUIERA de:

1. **Tráfico estable >50 clientes activos/mes** (data runtime para mesa-25 runtime-truth)
2. **Revenue $1M CLP+/mes** (justifica mesa-24 business value scoring)
3. **Aparece 4to o 5to plugin custom** (señal fragmentación arquitectónica)
4. **Decisiones arquitectónicas controversiales aparecen** (justifica adversarial multi-round)
5. **Akibara contrata 2do dev** (simplificar onboarding podría justificar refactor)
6. **Backlog de features grande pre-sprint planning** (justifica mesa-23 PM exhaustivo)

Hasta que algún trigger se cumpla, este mini-audit foundation es la baseline. Quarterly architecture audit (mesa-15) cada 3 sprints re-evalúa triggers sin necesidad de full re-audit.

---

## Recomendación final del PM

**Cuándo arrancar Sprint 1:** cuando estés listo para ~1.5 semanas de foco continuo + acceso disponible a Cloudflare DNS + Brevo dashboard + panel BlueX + sesión MCP estable. NO arrancar fragmentado entre tareas administrativas.

**Item primero (orden estricto):**

1. **B-S1-SETUP-00 RUNBOOK-DESTRUCTIVO.md** (1h) — prerequisito BLOQUEANTE de cualquier destructivo
2. **B-S1-SEC-01 WP core verify-checksums** (30 min) — prerequisito BLOQUEANTE de cleanups
3. **B-S1-EMAIL-01 Brevo SPF/DKIM/DMARC INICIAR** (Día 1, async DNS propagation 24-48h)
4. **B-S1-SETUP-01 quality-gate.sh** (2.5h)
5. **B-S1-SEC-02 Delete admin backdoors** (3h) — DOBLE OK explícito Alejandro

**Después del Sprint 1:**
- Sprint POST-validation (mesa-15 + mesa-22 + mesa-02)
- LambdaTest visual regression para FRONT-01/02
- Sentry 24h post-deploy monitoring
- Decidir si arrancas Sprint 2A inmediato o pausa de 1 semana
- Actualizar capacity baseline real (velocity histórico) para Sprint 2

**Pendientes del audit que NO bloquean Sprint 1:**

- Mockups Figma para los 19 items en `PENDIENTE MOCKUP` — acumular y revisar en sesión dedicada
- Decisión PM con dueño: MP Custom Gateway scope PCI (Decisión #23) — sesión separada
- Decisión PM con dueño: mercadolibre 4250 LOC con 1 listing (F-02-008) — sesión separada
- Decisión PM con dueño: SII / Boletas electrónicas (F-19-011) — sesión separada
- Memoria pendiente de crear: `project_module_registry_enterprise_improvements.md` (~30h Sprint 3+ trigger natural)

---

**Outputs generados:**

- `BACKLOG-2026-04-26.md` (root) — Sprint × Domain matrix completa
- `CLEANUP-PLAN-2026-04-26.md` (root) — 14 cleanup items con razones técnicas
- `audit/AUDIT-SUMMARY-2026-04-26.md` (este archivo) — TL;DR ejecutivo

**Inputs leídos para este pase:**

- 12 R1 outputs en `audit/round1/`
- 9 R2 outputs en `audit/round2/`
- 4 contexts en `audit/_inputs/`
- 13 memorias en `~/.claude/projects/-Users-alefvaras-Documents-akibara-v2/memory/`
- HANDOFF-2026-04-26.md
- PROMPT-INICIO-AKIBARA-V2.md (especial atención §4 scripts gap)
- Plan original `quiero-que-continues-linked-seahorse.md`

**FIN DEL AUDIT SUMMARY.**
