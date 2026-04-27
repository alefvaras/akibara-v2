# ADR 003 — Plugin architecture evolution: status quo 3 plugins → SUPERSEDED por Core + 5 Addons + Cell H

**Status:** Superseded (2026-04-26 13:00 → 2026-04-26 23:00)
**Origin:** B-S1-CLEAN-04 · audit foundation 2026-04-26 · architecture pivot 2026-04-26
**Decision-makers:** mesa-15 / mesa-22 / mesa-02 (round 1 status quo) → user override + memoria `project_architecture_core_plus_addons` (final pivot)
**Supersedes:** ninguno (es la primera decisión arquitectónica documentada)
**Superseded-by:** este mismo ADR documenta la sucesión

---

## Contexto

Este ADR documenta DOS decisiones arquitectónicas tomadas el mismo día 2026-04-26, en orden cronológico, con la primera SUPERSEDED por la segunda.

### Estado pre-audit

Akibara opera con 3 plugins custom + 11 mu-plugins akibara-* + theme custom akibara:

```
wp-content/plugins/
├── akibara/                    (~12K LOC — plugin "monolítico" con módulos)
│   └── modules/                (badge, search, ml, payments, marketing, etc.)
├── akibara-reservas/           (~3.5K LOC — preventa / reservar)
├── akibara-whatsapp/           (~1K LOC — WhatsApp Business integration)

wp-content/mu-plugins/
├── akibara-bootstrap-legal-pages.php
├── akibara-brevo-smtp.php       (load-bearing, ADR 002)
├── akibara-core-helpers.php     (logger + circuit breaker + rate-limit)
├── akibara-defer-analytics.php
├── akibara-email-testing-guard.php
├── akibara-flow-hardening.php
├── akibara-gla-optimize.php
├── akibara-indexnow.php
├── akibara-lscwp-hardening.php
├── akibara-redirect-guard.php
├── akibara-sentry-customizations.php (load-bearing, ADR 001)

wp-content/themes/akibara/      (~8K LOC — theme custom)
```

## Decisión #1 (2026-04-26 round 1) — Status quo 3 plugins separados

mesa-15 (architect-reviewer) + mesa-22 (wordpress-master) + mesa-02 (tech-debt) evaluaron 3 opciones:

| Opción | Score (de 165) | Esfuerzo refactor | Riesgo |
|---|---|---|---|
| A. **Status quo** (3 plugins) | 149/165 | 0h | bajo |
| B. **Consolidar a 1 monolito** | 110/165 | 94h | medio |
| C. **Splittear monolito en N plugins módulares** | 104/165 | 169h | alto |

**Decisión #1: mantener status quo** — score más alto (149/165), zero refactor cost, baja deuda relativa al beneficio. Documentado como Decisión #29 en `audit/round2/MESA-TECNICA-PROPUESTAS.md`.

**Mejora propuesta:** Module Registry interno en plugin `akibara` (~30h, Sprint 3+) — manifests + lifecycle + dependency graph + health endpoint + test harness + observability tags. Aplicar retroactivamente cuando se agregue el próximo módulo nuevo.

## Decisión #2 (2026-04-26 13:00 — user override final) — Core + 5 Addons + Cell H

Después del round 1 + reflection, el usuario explícitamente OVERRIDED la decisión #1 con un target más ambicioso. Memoria `project_architecture_core_plus_addons` registra el cambio.

### Target firme post-pivot

```
wp-content/plugins/
├── akibara-core/                       (~5.5K LOC — extracted de akibara monolítico)
│   ├── ServiceLocator.php
│   ├── ModuleRegistry.php
│   ├── Lifecycle.php (activate/deactivate/uninstall hooks)
│   ├── HPOSFacade.php (WC HPOS unified API)
│   └── modules/
│       ├── search/ (cubre admin + frontend)
│       ├── rut/ (validación chilena)
│       ├── phone/ (validación CL +56)
│       ├── product-badges/
│       ├── address-autocomplete/ (Maps API)
│       ├── customer-edit-address/
│       ├── checkout-validation/
│       ├── health-check/
│       ├── series-autofill/ (SEO Schema BookSeries — STAYS in core, decisión consolidada 2026-04-26)
│       ├── email-template/
│       └── email-safety/

├── akibara-preventas/                  (extracted de akibara-reservas)
│   Requires Plugins: akibara-core      (WP 6.5+ header)
├── akibara-marketing/                  (rebuild con finance-dashboard manga-specific)
│   Requires Plugins: akibara-core
├── akibara-inventario/                 (NEW — Sprint 4)
│   Requires Plugins: akibara-core
├── akibara-whatsapp/                   (existing, light refactor)
│   Requires Plugins: akibara-core
├── akibara-mercadolibre/               (extracted de akibara monolítico, Sprint 5)
│   Requires Plugins: akibara-core
```

Plus 1 célula horizontal:

```
themes/akibara/                          (= "Cell H Design Ops")
                                         + Figma component library
```

### Por qué el override

mesa-15 round 1 score 149/165 era válido para el momento. Pero el usuario priorizó:

1. **Boundaries claras por dominio** — preventas ≠ marketing ≠ inventario. Status quo mezclaba todo en el plugin "akibara" monolítico.
2. **Lock policy** — durante Sprints paralelos (3+4), el core debe estar read-only para evitar merge conflicts. Status quo no permitía esto sin coordinación manual.
3. **WP 6.5+ `Requires Plugins` header** — disponible nativo, permite dependency declarations sin código custom. Status quo desperdiciaba este feature.
4. **Industry standard** — Linux kernel, WordPress core, Yoast Premium siguen este pattern (core + addons que requieren el core).

### Costo aceptado

- Refactor 130-180h Sprints 2-5 (vs 0h status quo).
- Sprint X.5 dedicados a "Lock release + RFC arbitration" (industry standard pattern).

## Decisión final

**Status quo 3 plugins SUPERSEDED por Core + 5 Addons + Cell H.**

Sprint 1 mantiene el status quo en runtime (cero impact a usuarios) — la migración arranca Sprint 2 con la extracción del Core.

## Consecuencias

### Positivas (Core + 5 Addons)
- Módulos por dominio claro → onboarding más rápido cuando llegue el 2do dev.
- Lock policy permite paralelización Sprints 3+4 (preventas + marketing + inventario simultáneos).
- WP `Requires Plugins` header asegura dependency satisfaction antes de activación.
- Composer autoload PSR-4 por addon (Sprint 2 introduce composer runtime con dev tooling EXCLUDED en deploy.sh).

### Negativas
- 130-180h refactor — bloquea features durante Sprint 2-5.
- Riesgo de regresiones durante extracción si tests E2E no cubren bien (parcialmente mitigado por Sprint 1 SETUP-02 smoke-prod.sh).
- Cell-based execution con git worktrees + Claude Code subagents introduce overhead de coordinación.

### Mitigación
- Sprint 2 = extracción Core con feature freeze para addons (cero changes en preventas/marketing/etc durante extraction).
- Sprint 2 también establece `staging.akibara.cl` (B-S2-INFRA-01) — testing dedicado antes de hit prod.
- Memoria `project_cell_based_execution` documenta la convention de cells.

## Archivos relacionados

- `audit/CELL-DESIGN-2026-04-26.md` — design completo de las 6 cells
- `audit/round2/ARCHITECTURE-RECOMMENDATION.md` — rationale post-pivot
- `audit/round2/ARCHITECTURE-PLUGINS-CONSULTATION.md` — round 1 mesa-15 score 149/165
- `audit/round2/ARCHITECTURE-ROBUSTNESS-MESA.md` — workflow architects review (ADR 004)
- `audit/round2/ARCHITECTURE-ROBUSTNESS-WP-IDIOMS.md` — mesa-22 round 1
- `audit/round2/ARCHITECTURE-ROBUSTNESS-REFACTOR-COST.md` — mesa-02 round 1 cost analysis
- Memoria `project_architecture_core_plus_addons.md`

## Trigger para re-evaluar

- Sprint 5 cierre (Q3-Q4 2026): re-evaluar si la división en 5 addons justifica el costo, vs colapsar a 2-3 si vertical-X no escaló (ej. inventario 0 usuarios).
- Si Akibara contrata 2do/3er dev: re-evaluar si Cell H (Design Ops horizontal) justifica owner dedicado o se folde en core.
- Si WP 7.X cambia el modelo `Requires Plugins`: revisar header compatibility.

## Referencias

- `wp-content/plugins/akibara/akibara.php` (monolítico actual — pre-pivot)
- Memoria `project_architecture_core_plus_addons.md`
- WordPress 6.5 `Requires Plugins` header docs
