# ADR 004 — Architect review per sprint (PRE / MID / POST gates + RACI)

**Status:** Accepted (2026-04-26)
**Origin:** B-S1-CLEAN-04 · `audit/round2/ARCHITECTURE-ROBUSTNESS-MESA.md` § 5
**Decision-makers:** mesa-15 (architect-reviewer) + mesa-22 (wordpress-master) + mesa-02 (tech-debt) + mesa-23 (PM)
**Supersedes:** ninguno
**Superseded-by:** ninguno

---

## Contexto

Akibara es solo dev (Alejandro Vargas), sin code review formal, sin staging dedicado (Sprint 2 introduce `staging.akibara.cl`). El audit foundation 2026-04-26 detectó **deuda arquitectónica acumulada** que no surgió de un solo commit malo, sino de la falta de gates regulares:

- 12+ items de tech debt con `class_exists`/`function_exists` cross-plugin sin convención (mesa-02)
- Mu-plugins load-bearing (Sentry, Brevo SMTP) sin documentación (ADR 001/002)
- Hooks WC con `priority` sin constantes (`AKB_FILTER_PRIORITY_*` no existían)
- 3 plugins separados sin convention de boundaries (ADR 003 path superseded)
- Browser support drift (assumptions sobre features sin validar)

Sin un mecanismo recurrente de review, esta deuda crecería sprint sobre sprint hasta forzar un re-audit completo (costoso).

## Decisión

Cada Sprint pasa por **3 architect review gates** + 1 quarterly:

| Gate | Cuándo | Responsible (R) | Output |
|---|---|---|---|
| **PRE-review** | Día 1 sprint, antes de implementar | mesa-15-architect-reviewer | `audit/sprint-{N}/ARCHITECTURE-PRE-REVIEW.md` |
| **MID-checkpoint** | Mid-sprint si dura >5 días | mesa-15 | `audit/sprint-{N}/ARCHITECTURE-MID-CHECKPOINT.md` |
| **POST-validation** | Último día sprint, antes de cerrar | mesa-15 + mesa-22 + mesa-02 | `audit/sprint-{N}/ARCHITECTURE-POST-VALIDATION.md` |
| **Quarterly audit** | Cada 3 sprints | mesa-15 (audit completo) | `audit/quarterly/ARCHITECTURE-AUDIT-Q{N}.md` |

### RACI

| Actividad | mesa-15 | mesa-22 | mesa-02 | mesa-23 PM | Solo dev |
|---|---|---|---|---|---|
| PRE-review checklist | **R** | C | C | A | I |
| MID-checkpoint | **R** | I | I | I | I |
| POST-validation | **R** | **R** | **R** | A | I |
| Quarterly audit | **R** | C | C | A | I |
| Implementación de findings | I | I | I | C | **R** |

R = Responsible · A = Accountable · C = Consulted · I = Informed

### PRE-review checklist (8 puntos)

1. ¿Algún item del sprint impacta más de 1 plugin?
2. ¿Algún item introduce nueva dependencia cross-plugin? (`class_exists`/`function_exists` mandatory)
3. ¿Algún item modifica mu-plugins load-bearing (brevo-smtp, sentry-customizations, email-testing-guard)? Smoke test obligatorio
4. ¿Algún item modifica hooks WC con priorities (race conditions)? Aplicar constantes `AKB_FILTER_PRIORITY_*`
5. ¿Algún item agrega un módulo nuevo? Aplicar regla "no nuevo módulo sin justificar"
6. ¿Algún item viola alguna memoria del usuario? (`feedback_no_over_engineering`, `feedback_minimize_behavior_change`, `project_no_key_rotation_policy`, etc.)
7. ¿Riesgo de regresión por cambio? Smoke test obligatorio post-deploy
8. ¿Item requiere mockup Figma? Verificar approval ANTES de empezar (memoria `project_figma_mockup_before_visual`)

### POST-validation checklist (9 puntos)

1. ¿Boundaries plugin se respetaron? (no nuevos hard requires cross-plugin)
2. ¿Mu-plugins load-bearing intactos?
3. ¿ADRs actualizados si la arquitectura cambió?
4. ¿Diagramas plugin interaction necesitan update?
5. ¿Dead code introducido? (mesa-02 sweep)
6. ¿WP/WC idioms respetados? (mesa-22 sweep — nonces, capabilities, hooks priorities)
7. ¿Regresiones potenciales identificadas para smoke test?
8. ¿LambdaTest visual regression passed (si sprint visual)?
9. ¿Sentry 24h post-deploy sin errores nuevos relacionados?

### Quarterly re-audit triggers

Re-correr la mesa de 22+ × 7 rondas (escalar de mini-foundation a audit completo) cuando se cumpla CUALQUIERA de:

1. Tráfico estable >50 clientes activos/mes (data runtime para mesa-25 runtime-truth)
2. Revenue $1M CLP+/mes (justifica mesa-24 business value scoring)
3. Aparece 4to o 5to plugin custom (señal fragmentación arquitectónica)
4. Decisiones arquitectónicas controversiales aparecen
5. Akibara contrata 2do dev
6. Backlog de features grande pre-sprint planning

## Consecuencias

### Positivas
- Deuda arquitectónica detectada pronto, no acumulada hasta full re-audit costoso.
- Memoria institucional persistente vía ADRs (este pattern) — el solo dev no tiene que recordar todas las decisiones.
- Onboarding 2do dev acelerado: lee ADRs + RACI workflow en lugar de pull requests viejos.
- mesa-23 PM tiene visibilidad arquitectónica para sprint planning sin necesidad de leer cada commit.

### Negativas / costo
- ~3-4h de overhead per sprint en gates (PRE 1h + MID 30min + POST 2h).
- Si el solo dev IGNORA findings de los gates, todo el costo es desperdiciado.
- Si los gates se vuelven rubber-stamp (siempre OK sin findings reales), su valor decae.

### Mitigación a la captura del proceso
- Each gate emite output documentado en `audit/sprint-{N}/...md` — accountability via paper trail.
- mesa-23 PM responsable de validar que findings se traduzcan en items del próximo sprint (NOT ignore-and-forget).
- Quarterly trigger #4 ("decisiones controversiales") incluye "POST-validation tres sprints seguidos sin findings" como señal de captura → escalate a quarterly full re-audit.

## Trigger para re-evaluar este pattern

- Akibara contrata 2do dev: re-evaluar si las 3 gates son apropiadas o se reducen a 1 (POST-validation only) con código review tradicional reemplazando PRE/MID.
- Sprint 5+ sin findings significativos en 3 sprints consecutivos: re-evaluar si el costo justifica el beneficio o se relaja a quarterly only.
- Si la mesa-15 (auto) deja de ejecutar el rol bien (drift en agentes): re-evaluar si los gates necesitan human review en lugar de agente.

## Archivos relacionados

- `audit/round2/ARCHITECTURE-ROBUSTNESS-MESA.md` § 5 (workflow original)
- `audit/round2/ARCHITECTURE-ROBUSTNESS-WP-IDIOMS.md` (mesa-22 round 1 input)
- `audit/round2/ARCHITECTURE-ROBUSTNESS-REFACTOR-COST.md` (mesa-02 round 1 input)
- `audit/sprint-1/` (Sprint 1 actual — primer sprint con este workflow)
- `BACKLOG-2026-04-26.md` § "Architects review per sprint (RACI)"

## Trigger Sprint 1 (este sprint, hoy)

Sprint 1 NO tuvo PRE-review formal porque arrancó como continuación directa del audit foundation (los 3 gates se "ejecutaron" durante la mesa técnica round 1 + round 2). El PRE-review formal arranca **Sprint 2 día 1** (extracción Cell Core).

POST-validation Sprint 1 = ejecutar checklist 9 puntos al cierre de hoy. ADRs 001-004 (este file) son uno de los outputs requeridos.

## Referencias

- Memoria `project_audit_right_sizing.md`
- Nygard, Michael — "Documenting Architecture Decisions" (2011)
- `docs/RUNBOOK-DESTRUCTIVO.md` § escalation path (cuando POST-validation detecta regresión post-deploy)
