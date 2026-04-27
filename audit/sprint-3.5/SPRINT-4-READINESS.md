# Sprint 4 Readiness Assessment

**Fecha:** 2026-04-27
**Author:** mesa-23-pm-sprint-planner (síntesis cross-mesa)
**Inputs:** RFC-DECISIONS.md + LAMBDATEST-REPORT.md + RETROSPECTIVE.md + QA-SMOKE-REPORT.md

---

## TL;DR

| Criterio | Status |
|---|---|
| Sprint 3 mergeable | ⚠ CONDICIONADO — 5 P1/P2 findings pre-merge |
| Sentry 24h checkpoint plan | ✅ READY (formal en QA-SMOKE-REPORT §2) |
| Capacity Sprint 3 real | 77-92h equiv vs 60h estimado (+28% creep, Cell B driver) |
| LambdaTest baseline | ❌ DEFERRED Sprint 4.5 (no Sprint 1 baseline) |
| Sprint 4 trigger | T+7d Sentry estabilización + 5 fixes P1 aplicados |
| Sprint 4 cells priority | **Cell D primero (greenfield), Cell C en paralelo** |

---

## 1. Bloqueadores pre-merge Sprint 3

5 findings P1/P2 deben atenderse antes de abrir PRs (ver QA-SMOKE-REPORT.md):

| Finding | Severity | Fix recomendado | Branch | Esfuerzo |
|---|---|---|---|---|
| F-03: smoke prod sin cobertura /encargos/, /mis-reservas/, health REST | P1 | Agregar 3 `check_http` en `scripts/smoke-prod.sh` `run_core_checks()` | feat/sprint-3-5 (commit pre-PR) | S |
| F-05: deploy secuenciación encargos shim no documentada | P1 | Agregar runbook `audit/sprint-3.5/DEPLOY-RUNBOOK-S3.md` con orden: (1) tema, (2) plugin | feat/sprint-3-5 | S |
| F-01: double-cron-hook silente next-volume | P1 | Verificar staging que `akibara_next_volume_check` apunta a función plugin, no legacy | (verificación staging) | S |
| F-02: `akibara.php` registra módulos sin guard `AKB_MARKETING_LOADED` | P2 | Opcional: agregar guards condicionales en akibara.php pre-merge. Alternativa: confiar en deploy package elimina archivos físicos | feat/sprint-3-5 (opcional) | S |
| Tokens contrast 3 P1 (LAMBDATEST-REPORT §2) | P1 | Decidir: fixear tokens.css ahora (tipos texto sobre badges), o documentar como debt y aplicar Sprint 4.5 | feat/theme-design-s3 | S-M |

**Recomendación:** atender F-03 + F-05 + tokens contrast en feat/sprint-3-5 antes de abrir PRs. F-01 verificación staging post-deploy. F-02 a Sprint 4.

---

## 2. Capacity Sprint 3 real

Per RETROSPECTIVE.md:
- 29,111 LOC totales, 113 archivos, 7 commits.
- Estimado: ~60h equiv. Real: ~77-92h equiv (+28% creep).
- Driver del creep: Cell B liftó 12 modules completos (vs scope original ~8). akibara-marketing terminó con 12,400 LOC vs ~8,000 estimado.
- Cell A en target (~6,800 LOC, dentro del rango estimado).
- Cell H bajo target (10 mockups + 175 tokens ≈ 12-15h reales vs ~20h estimado).

**Multiplicador real cells vs sequential:** 5x (per CELL-DESIGN-2026-04-26 línea 677). Sprint 3 ejecutado en ~1 día con paralelización subagents. Sin cells: ~5 días.

---

## 3. Sprint 4 trigger criteria

Sprint 4 NO arranca hasta:

1. ✅ Sprint 3 PRs mergeados a main (3 PRs).
2. ✅ Sentry T+24h checkpoint formal **GREEN** (2026-04-28 ~13:37).
3. ✅ Smoke prod post-merge T+0/T+15min/T+1h **GREEN**.
4. ⚠ Recomendado: T+7d Sentry estabilización antes de arrancar Sprint 4 (window 2026-04-28 → 2026-05-04). Permite descubrir issues de cron diarios o batch jobs.
5. ⚠ Pre-condición Cell C (inventory): plan de extracción documentado (similar al RFC THEME-CHANGE-01 process — anticipar mid-sprint RFC).
6. ⚠ Pre-condición Cell D (whatsapp): API credentials Twilio/360dialog confirmados, sandbox account activo.

**Earliest arranque Sprint 4:** 2026-04-29 (T+24h checkpoint verde).
**Recomendado arranque Sprint 4:** 2026-05-04 (T+7d estabilización).

---

## 4. Sprint 4 cells priority

Per RETROSPECTIVE.md §4 + LAMBDATEST-REPORT consolidación:

### Cell D (whatsapp) — PRIMERA en arrancar

**Rationale:**
- Greenfield (no extraction de legacy).
- Independiente — no depende de otras cells.
- Capacity baja: 5-8h equiv estimado.
- Risk bajo — feature opt-in, no afecta runtime customer-facing si falla.
- Permite a Cell C arrancar con plan más sólido (mientras Cell D ejecuta).

**Scope estimado:**
- WhatsApp Business API integration (Twilio o 360dialog).
- 3 message variants (post-purchase, abandoned-cart fallback, customer-service link).
- Admin panel toggle + template editor.

### Cell C (inventario) — paralelo con Cell D

**Rationale:**
- Depende de extracción `inventory` module de `wp-content/plugins/akibara/` legacy.
- Capacity alta: 25-30h equiv estimado.
- Risk medio — touches stock management que afecta orders.
- Anticipar RFC mid-sprint (similar al THEME-CHANGE-01) si descubre dependencies en migración.

**Pre-condición específica:** plan de migración stock-restore documentado en `audit/sprint-4/cell-c/MIGRATION-PLAN.md` antes de empezar.

**Scope estimado:**
- Plugin `akibara-inventario` extraído de legacy.
- 5 features: stock alerts, low-stock badges, back-in-stock notifications, multi-location inventory, supplier order recommendations.

### Cell H (Design Ops) — concurrente, low scope

Per CELL-DESIGN-2026-04-26 línea 618: "Sprint 4 (MEDIUM): Mockups Cell C+D (stock alerts, back-in-stock form, whatsapp button variants)".

**Scope:** 6 mockups priority (BACKLOG mencionado).

---

## 5. Tasks Sprint 4 follow-up identificadas

### From RFC-DECISIONS.md
- TASK-S4-THEME-01: sync controlado `functions.php` server-snapshot → akibara-v2.
- TASK-S4-THEME-02: post sync, evaluar Opción A (eliminar `inc/encargos.php`) gated por T+7d verde.
- TASK-S4-THEME-03: cleanup legacy hooks remaining en `inc/encargos.php` post-Opción-A.

### From QA-SMOKE-REPORT.md
- TASK-S4-QA-01: 6 specs E2E nuevas (checkout, BACS, login, RUT, address, Brevo).
- TASK-S4-LEGACY-01: F-02 cleanup — guards condicionales en `akibara.php`.
- TASK-S4-LEGACY-02: F-06 — decidir destino `ga4` module (akibara-marketing analytics group vs akibara-analytics).
- TASK-S4-LEGACY-03: deactivate hooks `wp_clear_scheduled_hook` para series_notify + editorial_notify (ya migrados).
- TASK-S4-LEGACY-04: migrar rut, phone, installments, checkout-validation, back-in-stock, product-badges, health-check (per QA-SMOKE-REPORT §3.2).

### From LAMBDATEST-REPORT.md
- TASK-S4.5-LAMBDATEST-SETUP: crear `scripts/lambdatest-prod.sh` + capturar baseline Sprint 4.5.
- TASK-S4-TOKENS-01: enqueue `tokens.css` en `inc/enqueue.php` (theme prod).
- TASK-S4-TOKENS-02: fix 3 P1 contrast fails:
  - `--aki-red` text → usar `--aki-error` para errors (5:1+ ratio guarantee).
  - White on `--aki-success` badge → cambiar a black on success badge.
  - White on `--aki-gray-400` badge → cambiar a black on gray-400 badge.

### From RETROSPECTIVE.md
- TASK-S4-INFRA-01: pre-condition Cell C plan de extracción documentado.
- TASK-S4-INFRA-02: API credentials Twilio/360dialog confirmados pre-Cell D.

---

## 6. Recomendación final

**Decision:**

1. **Atender 3 fixes pre-merge** en feat/sprint-3-5 (F-03 smoke + F-05 runbook + 3 contrast fails opcional).
2. **Abrir PRs Sprint 3** con orden: theme-design-s3 → akibara-preventas → akibara-marketing.
3. **Merge secuencial** post-quality-gate-verde + smoke-staging-verde + user approve cada PR.
4. **Deploy a prod** post-merge con runbook DEPLOY-RUNBOOK-S3 (orden tema→plugin).
5. **Sentry T+24h checkpoint** 2026-04-28 ~13:37 → output SENTRY-24H-RESULT.md.
6. **T+7d estabilización window** hasta 2026-05-04.
7. **Sprint 4 kickoff** 2026-05-04 con Cell D primero + Cell C paralelo + Cell H concurrente.

**Pause points para Akibara Owner approve:**
- Approve Sprint 3 PR open (3 PRs).
- Approve merge cada PR (post quality-gate verde).
- Approve Opción A escalation post T+7d verde (hotfix branch separado).
- Approve Sprint 4 kickoff (post estabilización).

---

## Sign-off

- mesa-23-pm-sprint-planner: APPROVED — Sprint 3 closeable con 3 fixes pre-merge.
- mesa-15-architect-reviewer: APPROVED — RFC-DECISIONS publicada.
- mesa-11-qa-testing: APPROVED CONDICIONAL — F-03/F-05 fixes pre-merge.
- mesa-22-wordpress-master (Cell H lead): APPROVED CONDICIONAL — 3 P1 contrast fails decisión usuario.
- Akibara Owner: PENDING approve.
