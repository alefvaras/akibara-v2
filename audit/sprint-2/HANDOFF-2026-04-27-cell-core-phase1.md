# HANDOFF — Sprint 2 Cell Core Phase 1 (paused mid-deploy)

**Fecha:** 2026-04-27 ~11:40 UTC-4
**Sesión origen:** Sprint 2 Cell Core Phase 1 extraction + 3-mesa adversarial review + 9 fixes + GHA verde + staging deploy ATTEMPT
**Status:** PR #1 ready (8/8 GHA green) · Staging deploy bloqueado por function redeclare conflict · Prod intacto

---

## TL;DR para nueva sesión

1. **Lee este HANDOFF + audit/sprint-2/B-S2-SETUP-01-DONE.md + B-S2-INFRA-01-staging-DONE.md** para context.
2. **Estado deploy actual:** mu-plugin `akibara-00-core-bootstrap.php` está REMOVIDO de staging (en `/tmp/akibara-00-core-bootstrap.php.removed`). Plugin akibara-core en staging está INACTIVE. Staging home HTTP 200 OK.
3. **Issue pendiente:** load order conflict — al activar akibara-core con legacy akibara, fatal `Cannot redeclare akb_sinonimos()` (y antes `akb_editorial_pattern()`).
4. **Próximo paso:** debug exact load sequence + investigar por qué guards no funcionan, o adoptar approach simpler (e.g., desactivar legacy plugin completo cuando akibara-core active).

---

## Trabajo COMPLETADO en esta sesión

### B-S2-SETUP-01 — DONE (ver `audit/sprint-2/B-S2-SETUP-01-DONE.md`)

- `.github/workflows/quality.yml` (8 jobs)
- `bin/grep-{voseo,claims,secrets}.sh` + `bin/setup-pre-commit.sh`
- Playwright config + 6 @critical golden flow tests
- WordPress lint configs

### Cell Core Phase 1 — c0digo COMPLETO + 3-mesa adversarial review APPROVED

- `wp-content/plugins/akibara-core/`: nuevo plugin foundation
  - akibara-core.php main + Bootstrap.php + ServiceLocator.php + ModuleRegistry.php
  - 6 modules relocated (search, category-urls, order, email-safety, customer-edit-address, address-autocomplete)
  - includes/akibara-helpers.php (akb_normalize, akb_extract_info, akb_strip_accents)
  - data/normalize.php + data/sinonimos.php
- `wp-content/mu-plugins/akibara-00-core-bootstrap.php` (mesa-01 OPCIÓN C decision)
- `wp-content/plugins/akibara/akibara.php` modificado para skip Phase 1 modules cuando AKIBARA_CORE_PLUGIN_LOADED

### 9 P0/P1 fixes aplicados via 3-mesa review

| # | Fix | Mesa origen |
|---|---|---|
| 1 | Constant rename AKIBARA_CORE_LOADED → AKIBARA_CORE_PLUGIN_LOADED | mesa-22 P0 |
| 2 | Per-file load guards en 4 modules (AKB_CORE_<NAME>_LOADED) | mesa-15 P0-1 |
| 3 | Copy data/normalize.php + sinonimos.php + helpers a akibara-core/ | mesa-15 P0-3 + mesa-16 F-01/F-02 |
| 4 | AKIBARA_DIR/FILE → AKIBARA_CORE_DIR/FILE en search.php | mesa-15 P0-3 |
| 5 | register_activation_hook + register_deactivation_hook | mesa-15 P0-4 + mesa-22 Issue 7 |
| 6 | Priority swap (declare:4, bootstrap:5) | mesa-15 P1-2 |
| 7 | Mu-plugin loader (mesa-01 OPCIÓN C) | mesa-01 arbitration |
| 8 | akb_core_initialized() helper + Bootstrap::is_initialized() | mesa-22 Issue 4 + mesa-16 F-06 |
| 9 | .gitignore scope fix (data/ → /data/ root only) | (descubierto durante stage) |

### GitHub PR #1 ESTADO

- URL: https://github.com/alefvaras/akibara-v2/pull/1
- 5 commits en feat branch (3a86150 → 5efc127)
- **GHA Run #25003760879: 8/8 jobs PASS** (Content gates, Gitleaks, PHP lint, PHPUnit, plugin-check, JS/CSS lint, Playwright @critical, Trivy)
- SonarCloud check failing (separate integration, not my workflow)

---

## Bloqueador actual: function redeclare conflict

### Symptom

Al activar `wp plugin activate akibara-core` en staging, fatal:
```
Cannot redeclare function akb_sinonimos() 
(previously declared in akibara/includes/akibara-search.php:35) 
in akibara-core/includes/akibara-search.php on line 37
```

(Antes era `akb_editorial_pattern()` — fixed con AKIBARA_CORE_LOADED define en helpers.php.)

### Root cause analysis (incompleto)

1. **Sin mu-plugin loader, akibara legacy carga primero** (alphabetical: `akibara` < `akibara-core`).
2. Legacy akibara/akibara.php hardloads `includes/akibara-search.php` → defines `akb_sinonimos` (line 35).
3. Cuando `akibara-core` se activa, su `includes/akibara-search.php` (relocated copy) tries to define `akb_sinonimos` again → fatal.
4. Mi guard `if (defined('AKB_SEARCH_LOADED')) return;` debería skipear, pero parece que NO se está ejecutando.

### Hipótesis a investigar (próxima sesión)

1. **Constant `AKB_SEARCH_LOADED` not defined when expected** — verificar en CLI: `php -r 'define("ABSPATH","/tmp"); define("AKIBARA_V10_LOADED",true); require legacy/akibara-search.php; echo defined("AKB_SEARCH_LOADED")?"YES":"NO";'`
2. **Function declaration BEFORE the load guard check** in akibara-search.php — review file structure
3. **Legacy file en staging tiene contenido distinto** que server-snapshot — `diff` para confirmar
4. **WordPress double-load durante activation** — wp_admin/plugins.php activate path puede causar re-include
5. **Possible que `include` instead of `include_once` somewhere** triggers re-execution

### Approaches alternativos a considerar

A. **Desactivar plugin akibara legacy automáticamente** cuando akibara-core se active (via plugin activation hook):
   - Pro: 100% guaranteed no conflict
   - Con: behavior change major — legacy modules NO migrated to core would also stop working
   - Aplicable después que TODOS los modules estén migrated (Sprint 5)

B. **Wrap todas las function declarations en legacy + core con `function_exists` guards**:
   - Modify legacy includes/akibara-search.php, akibara-order.php, etc en staging
   - Modify mi akibara-core/ copies similar
   - Pro: idempotent, order-independent
   - Con: tedious, modifies many files, drift de prod

C. **Run plugin akibara-core ALONE** (deactivate legacy):
   - Currently 6 modules en core. Legacy tiene 22 modules más (banner, popup, brevo, etc.)
   - Si legacy desactivado, esos 22 dejan de funcionar — major regression
   - Aplicable solo después de Sprint 3-5 (todos migrated)

D. **Approach completamente nuevo** — escribir akibara-core como WRAPPER que wraps legacy plugin's helpers:
   - akibara-core no declares functions itself, just provides API for addons to use
   - Functions live in legacy (until legacy fully extracted)
   - Pro: zero conflict
   - Con: defeats purpose de extraction

---

## Estado filesystem actual

### Local repo (committed)

```
HEAD: 5efc12709cf8a7d44a8988d7d3d2df8c49ae82ea (feat/sprint-2-setup-cell-core-phase1)
PR #1: open
Branch synced with remote ✓
Working tree: 1 modified file (helpers.php with AKIBARA_CORE_LOADED define addition — uncommitted)
```

### Staging server

```
/staging/wp-content/mu-plugins/akibara-00-core-bootstrap.php → REMOVED to /tmp/akibara-00-core-bootstrap.php.removed
/staging/wp-content/plugins/akibara-core/ → exists (deployed via rsync)
/staging/wp-content/plugins/akibara/akibara.php → modified version deployed (with AKIBARA_CORE_PLUGIN_LOADED wraps)
Plugin akibara-core: INACTIVE (couldn't activate due to fatal)
Plugin akibara: ACTIVE (working normally)

Backups disponibles:
  /tmp/akibara.php.bak-20260427-113000 (legacy main file pre-modification)
  /tmp/staging-mu-plugins.bak-20260427-113000.tar.gz (mu-plugins pre-deploy)
```

### Prod server

```
NOT TOUCHED — 100% intact.
akibara.cl serving normally HTTP 200.
```

---

## Decisiones tomadas (no re-debatir)

| Tema | Decisión | Razón |
|---|---|---|
| Code review approach | 3 mesas adversarial paralelas (mesa-15 + mesa-22 + mesa-16) | Multiple perspectives, identified 4 P0 issues that solo dev review missed |
| Load order fix | Mesa-01 vinculante: OPCIÓN C mu-plugin loader | Robustness > suerte alphabetical (mesa-22 OPCIÓN A rejected) |
| Constant naming | AKIBARA_CORE_PLUGIN_LOADED (boolean) for plugin guard | Avoid collision con legacy AKIBARA_CORE_LOADED (string '10.0.0') |
| Per-file guards convention | AKB_CORE_<MODULE_NAME>_LOADED | Consistent prefix, addons documented in HANDOFF |
| Helpers location | akibara-core/includes/akibara-helpers.php | Idempotent con function_exists, falsa positives evitados |
| Mu-plugin name | akibara-00-core-bootstrap.php | -00- forces alphabetical priority entre mu-plugins |
| Memoria | feedback_minimize_behavior_change applied: legacy plugin sigue active in parallel | Atomic deploy = relocate, not removal |

---

## Próximos pasos sugeridos para nueva sesión

### Opción 1 — Continuar Cell Core Phase 1 deploy

1. Restaurar mu-plugin: `mv /tmp/akibara-00-core-bootstrap.php.removed staging/wp-content/mu-plugins/akibara-00-core-bootstrap.php`
2. Debug fatal con CLI tracing (php -r exact sequence)
3. Probar Approach B (function_exists guards en legacy + mi modules)
4. Smoke staging completo
5. 24h Sentry watch staging
6. Deploy prod con DOBLE OK
7. 24h Sentry watch prod
8. Mergear PR #1

### Opción 2 — Pausar Cell Core, mover a Phase 2 o otros items

1. Dejar PR #1 abierto (NO mergear hasta Phase 1 deployed verde)
2. Cerrar Sprint 2 con only B-S2-INFRA-01 + B-S2-SETUP-01 done (Cell Core deferred)
3. Sprint 3 puede arrancar sin akibara-core extracted (addons consumirán helpers via legacy plugin akibara hasta Sprint 4-5)

### Opción 3 — Re-design Cell Core extraction

1. Mesa-15 + mesa-01 architectural redesign con learnings de this session
2. Considerar Approach D (akibara-core como wrapper, no extract)
3. Document trade-offs en audit/sprint-2/cell-core/REDESIGN.md

---

## Memorias activas relevantes

- `project_architecture_core_plus_addons.md` — target Core + 5 Addons + Cell H
- `feedback_robust_default.md` — default a opción más robusta
- `feedback_no_over_engineering.md` — YAGNI universal
- `feedback_minimize_behavior_change.md` — preserve behavior 100%
- `project_no_key_rotation_policy.md` — repo private, leaks contained
- `project_deploy_workflow_docker_first.md` — Docker → linting → tests → smoke → deploy → monitoring

---

## Files key (paths absolutos)

### Para LEER (autoritativo)

```
/Users/alefvaras/Documents/akibara-v2/audit/sprint-2/HANDOFF-2026-04-27-cell-core-phase1.md  # este file
/Users/alefvaras/Documents/akibara-v2/audit/sprint-2/B-S2-INFRA-01-staging-DONE.md
/Users/alefvaras/Documents/akibara-v2/audit/sprint-2/B-S2-SETUP-01-DONE.md
/Users/alefvaras/Documents/akibara-v2/audit/sprint-2/cell-core-call-sites-inventory.md
```

### Modificados en esta sesión

```
.github/workflows/quality.yml
.eslintrc.json + .stylelintrc.json + .prettierrc.json + .prettierignore
.gitignore (scope data/ → /data/)
.gitleaksignore
audit/sprint-2/{conditions, B-S2-*, this HANDOFF}.md
bin/grep-{voseo,claims,secrets}.sh + setup-pre-commit.sh
package.json + playwright.config.ts
scripts/sync-staging.sh
tests/e2e/critical/golden-flow.spec.ts
wp-content/plugins/akibara-core/ (full new)
wp-content/plugins/akibara/akibara.php (modified)
wp-content/mu-plugins/akibara-00-core-bootstrap.php (new)
```

---

**FIN HANDOFF. Sesión paused 2026-04-27 ~11:40 UTC-4.**
