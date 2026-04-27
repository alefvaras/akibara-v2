# Cell Core Phase 1 — Redesign Workshop Arbitration

**Fecha:** 2026-04-27
**Árbitro vinculante:** mesa-01 (lead architect)
**Inputs:** mesa-15 voto F · mesa-22 voto F · HANDOFF 2026-04-27 · 5 archivos clave inspeccionados
**Timebox:** 1 hora

---

## 0. CONTEXT

Sprint 2 Cell Core Phase 1 quedó **paused mid-deploy** (2026-04-27 ~11:40 UTC-4) tras un fatal `Cannot redeclare function akb_sinonimos()` al intentar `wp plugin activate akibara-core` en staging. PR #1 (`feat/sprint-2-setup-cell-core-phase1`) tenía 8/8 GHA jobs PASS y 9 P0/P1 fixes aprobados por 3-mesa adversarial review previo.

**Trigger del redesign:** investigación reveló que el mu-plugin loader `akibara-00-core-bootstrap.php` estaba REMOVIDO de staging (movido a `/tmp/`) ANTES de la activación. El usuario eligió Opción 3 (redesign workshop con timebox 1h) sobre Opción 1 (debug + continue) y Opción 2 (defer Cell Core).

**ESTADO REAL DESCUBIERTO 2026-04-27 ~12:30 UTC-4:** PR #1 fue **MERGEADO** a main el 2026-04-27T15:30:51Z (= 11:30 UTC-4) — 10 minutos ANTES de que la sesión previa pausara y escribiera HANDOFF. El HANDOFF dice "PR #1 ready (8/8 GHA green)" pero NO se actualizó para reflejar que el merge ocurrió. Main contiene ahora el architecture C (mu-plugin loader) SIN el F-pivot defensive layer. Si se deploya main hoy, el fatal redeclare se reproduce. **Este F-pivot work va en un PR #2 nuevo.**

**Outcome esperado:** approach final + plan de re-deploy del F-pivot fix vía PR #2 → main → staging → prod.

---

## 1. APPROACHES EVALUATED

| ID | Nombre | Estado pre-workshop | Mesa-15 | Mesa-22 | Mesa-01 (vinculante) |
|---|---|---|---|---|---|
| **A** | Rename constant guard alphabetical | Mesa-22 propuesta original; rejected by Mesa-01 (mañana) | ❌ | ❌ | ❌ |
| **B** | `function_exists()` wrappers en TODAS las declarations | No implementado | ❌ alone | ❌ alone | ❌ alone |
| **C** | Mu-plugin loader (current) | Implementado; "falló" en deploy (en realidad: nunca corrió bajo design conditions porque mu-plugin estaba removido) | ❌ alone | ❌ alone | ❌ alone |
| **D** | akibara-core como WRAPPER de legacy | No considerado seriamente | ❌ | ❌ | ❌ |
| **E** | Desactivar legacy plugin completo | No considerado | ❌ | ❌ | ❌ |
| **F** | **Hibrido: mu-plugin + `function_exists` defensive layer** | No considerado | ✅ | ✅ | ✅ **WINNER** |
| **G** | WP `Requires Plugins` header (WP 6.5+) | Mencionado pero no usado | ❌ alone | ❌ alone | ❌ alone |

**Voto unánime: F (3/3 mesas).**

---

## 2. COMPARISON MATRIX

Pesos del plan workshop (Step 3). Scores: 1 (peor) ↔ 5 (mejor).

| Criterio | Peso | A | B | C | D | E | F | G |
|---|---|---|---|---|---|---|---|---|
| Robustness vs failure modes | 30% | 1 | 3 | 3 | 5 | 5 | **5** | 3 |
| Behavior change minimization | 20% | 4 | 4 | 4 | 1 | 1 | **5** | 4 |
| Maintenance burden Sprint 3-5 | 20% | 3 | 2 | 4 | 1 | 2 | **4** | 3 |
| Sunk cost preservation (PR #1) | 15% | 2 | 4 | 5 | 1 | 1 | **5** | 4 |
| Clarity for solo dev | 15% | 3 | 4 | 4 | 2 | 3 | **4** | 3 |
| **Weighted total** | **100%** | 2.50 | 3.30 | 3.85 | 2.40 | 2.65 | **4.65** | 3.30 |

**Razón scores F:**
- Robustness 5/5: belt-and-suspenders cubre mu-plugin removal + function_exists race + constant redeclare en un solo design.
- Behavior change 5/5: cero impacto cuando todo va bien (idempotent guards = no-op microsegundos).
- Maintenance 4/5: pattern idiomático WP, replicable para Phase 2-4 modules. Único costo: ~10 LOC/file.
- Sunk cost 5/5: PR #1 sigue vivo; agregamos commits incrementales sobre los 9 fixes existentes.
- Clarity 4/5: 1 punto descontado por la duplicación de defense layers (mu-plugin + wrappers) que un dev nuevo tarda en entender. Compensado por comments inline + REDESIGN.md como documentation source.

---

## 3. DECISIÓN VINCULANTE

**OUTCOME = F-pivot** (Hybrid: mu-plugin loader + `function_exists()` wrappers + `if (!defined())` constant guards)

PR #1 sigue vivo. Se le suman commits incrementales sobre los 9 fixes ya aprobados.

---

## 4. RAZÓN

**Confirmé mecánicamente la falla diagnosticada por mesa-15 y mesa-22.** En `akibara-core/includes/akibara-search.php` el guard `AKB_SEARCH_LOADED` existe (línea 19), pero **las constantes `AKB_MIN_CHARS`, `AKB_LIMIT`, `AKB_CACHE_TTL`, `AKB_CDN_TTL`, `AKB_CACHE_GROUP` (líneas 27-31) NO tienen `if (!defined())`** y `akb_sinonimos()` (línea 37) está bare sin `function_exists()`. Si por cualquier razón el guard se evalúa a `false` (file cargado vía path distinto, doble include de WP activation, o module file invocado directamente), las constantes y funciones intentan re-declararse → mismo fatal class. C SOLO no es suficiente.

**F vence a las otras 3 opciones porque:**

- **vs C-mantener:** mesa-22 detectó que la deduce alphabetical es **al revés** (`-` < `/`, `akibara-core` carga ANTES que `akibara`), y mesa-15 demostró que el deploy nunca testeó C bajo sus condiciones de diseño. C es necesario pero NO suficiente. F lo conserva como mecanismo primario y agrega red de seguridad en los 6 símbolos que chocan.
- **vs D-redesign (wrapper):** anula el propósito de extraction. Sprint 3-5 quedaría bloqueado porque addons no podrían consumir API estable de core. Cost prohibitivo (~5 días rework, +0 valor entregado).
- **vs No-consensus (defer):** tira a la basura 9 fixes ya aprobados + 8/8 GHA verde + 3 mesas que ya validaron arquitectura. Desperdicia el work invertido por una falla de 1 día de deploy.

**Memorias activas validan F:**
- `feedback_robust_default`: F es belt-and-suspenders, opción más robusta de las 4. C-mantener apuesta a un single point of failure.
- `feedback_minimize_behavior_change`: `function_exists()` y `if (!defined())` son **cero cambio funcional** cuando todo va bien — solo actúan si el sistema falla. Idéntico al patrón ya probado en `helpers.php` (line 24, 29-32, 42-44).
- `feedback_no_over_engineering`: NO es over-engineering. Es el patrón idiomático de WP core (`includes/functions.php` lo usa en cada declaración). Mesa-22 lo confirmó.
- `project_audit_right_sizing`: 3 clientes activos. NO podemos costear un deploy fatal en prod. Defensa-en-profundidad justifica las 2 horas.

---

## 5. CHALLENGE TO PRIOR ARBITRATION

**Confirmo mi prior arbitration (C sobre A) sigue vigente.** Mesa-15 tiene razón: el HANDOFF línea 128 dice explícito que el mu-plugin fue movido a `/tmp/` ANTES de la activación fallida. **C nunca corrió bajo sus condiciones de diseño** — no es evidencia de que C falle, es evidencia de un defecto de procedimiento de deploy.

**F NO es "pivot away from C". F es "C + insurance contra mis propios defectos".** El mecanismo determinístico de carga sigue siendo mu-plugin (única forma deterministic en WP), pero ahora si alguien (yo, futuro dev, deploy script) elimina o reordena el mu-plugin, el sistema NO entra en fatal — degrada con `error_log` warning visible. Mesa-22 lo dijo bien: "Belt-and-suspenders no cambia comportamiento existente. Cuando C funciona, el check es no-op microsegundos."

A-rename constants seguía siendo la opción frágil; lo sigue siendo. F es la evolución natural de C, no su reemplazo.

---

## 6. IMPLEMENTATION PLAN (decision = F)

**Archivos a modificar (paths absolutos):**

```
/Users/alefvaras/Documents/akibara-v2/wp-content/plugins/akibara-core/includes/akibara-search.php
/Users/alefvaras/Documents/akibara-v2/wp-content/plugins/akibara-core/includes/akibara-category-urls.php
/Users/alefvaras/Documents/akibara-v2/wp-content/plugins/akibara-core/includes/akibara-order.php
/Users/alefvaras/Documents/akibara-v2/wp-content/plugins/akibara-core/includes/akibara-email-safety.php
/Users/alefvaras/Documents/akibara-v2/wp-content/plugins/akibara-core/modules/customer-edit-address/module.php
/Users/alefvaras/Documents/akibara-v2/wp-content/plugins/akibara-core/modules/address-autocomplete/module.php
```

**Cambios específicos por file:**

1. **Cada `define('AKB_XXX', ...)` top-level → `if (!defined('AKB_XXX')) { define('AKB_XXX', ...); }`** (search.php líneas 27-31 prioritario; resto on-as-needed).
2. **Cada `function akb_xxx(...) { ... }` top-level → wrap completo en `if (!function_exists('akb_xxx')) { ... }`** (search.php `akb_sinonimos`, `akb_create_index_table`, etc. + equivalentes en los otros 5 modules).
3. **Antes del wrap, agregar warning visible** (mesa-22 sugerencia): `if (function_exists('akb_xxx')) { error_log('[akibara-core] akb_xxx ya declarada — possible load order issue'); }`. Solo en funciones, no en constantes (evitar log spam).

**Smoke tests (post-deploy staging):**

```bash
# 1. Verify mu-plugin presente
ls -la staging/wp-content/mu-plugins/akibara-00-core-bootstrap.php

# 2. Activate akibara-core
bin/wp-ssh --staging plugin activate akibara-core

# 3. Verify both plugins activos sin fatal
bin/wp-ssh --staging plugin list --status=active --format=csv | grep -E '^(akibara|akibara-core),'

# 4. Verify constants/functions sin redeclare en error_log
bin/wp-ssh --staging eval 'echo defined("AKIBARA_CORE_PLUGIN_LOADED") ? "OK" : "FAIL";'

# 5. Stress test: re-include manual via wp eval
bin/wp-ssh --staging eval 'require WP_PLUGIN_DIR . "/akibara-core/includes/akibara-search.php"; echo "no-fatal";'
```

**Commits adicionales en PR #1:** 3 commits granulares.
- `cell-core: function_exists guards en 6 module files (mesa-15+22 vote F)`
- `cell-core: if-not-defined guards en 5 constants search.php` (P0)
- `cell-core: smoke verification gate en deploy script staging`

**Tiempo estimado total:** 2h código + 1h staging deploy + smoke + 1h LambdaTest visual = **4h end-to-end, +1 día delay sobre Sprint 2 timeline original**.

---

## 7. SPRINT 2/3 IMPLICATION (go/no-go)

**Decisión: GO con +1 día de delay.**

| Item | Estado tras F-pivot |
|---|---|
| **PR #1 status** | ✅ MERGED 2026-04-27T15:30:51Z (descubrimiento post-redesign workshop). Main contiene arquitectura C sin F-pivot. |
| **PR #2 status** | 🟡 NEW PR para F-pivot fix (3 commits: REDESIGN.md + 6 PHP files + smoke gate). |
| **Sprint 2 cierre** | Sprint 2 cierra cuando PR #2 merged + smoke staging green + smoke prod green + 24h Sentry watch. |
| **B-S2-INFRA-01** | ✅ DONE (staging.akibara.cl operational) |
| **B-S2-SETUP-01** | ✅ DONE (GHA 8/8 verde + Playwright @critical + pre-commit hooks) |
| **B-S2-CELLCORE-01** Phase 1 | 🟡 IN PROGRESS — F implementación + smoke staging + 24h Sentry watch + deploy prod + smoke prod 20/20 + 24h Sentry watch + merge PR #1 |
| **Sprint 3 dependencies** | Cell Core foundation estable es prerequisito para 5 addons (preventas/marketing/inventario/whatsapp/mercadolibre). F desbloquea Sprint 3 una vez deployed verde. |
| **Phase 2-4 modules pending** | Patrón F replicable: aplicar `function_exists` + `if (!defined())` a email-template (Phase 2), product-badges (Phase 2), checkout-validation (Phase 2), health-check (Phase 3), rut (Phase 3), phone (Phase 3), series-autofill (Phase 4 — CRITICAL). |
| **Risk acumulado** | Bajo. F es defensa-en-profundidad sobre arquitectura ya validada por 3 mesas. |

**Trigger de fall-back a Opción 2 (defer Cell Core):** SOLO si el F implementation + smoke staging muestra fatal nuevo no-relacionado al redeclare original. En ese caso, escalar a mesa-01 sesión adicional + considerar D-redesign formal.

---

## 8. DISAGREEMENT CON MESAS

**Discrepo levemente con mesa-22 en un punto:** la sugerencia de `error_log` PRE-wrap (`if (function_exists(...)) { error_log(...) }`) en cada función es útil pero puede generar **log noise excesivo** en boot normal si por alguna razón helpers.php carga 2 veces (legacy path + core path) — escenario que mesa-15 marcó como riesgo concreto.

**Mitigación:** Aplicar log warning SOLO en las 6 funciones públicas top-level (`akb_sinonimos`, `akb_create_index_table`, `akb_editorial_pattern`, `akb_edition_pattern`, `akb_extract_info`, `akb_normalize`). NO en helpers internos ni constants. Esto preserva el insight de mesa-22 (bug visible sin ser fatal) sin spam.

Mesa-15 y mesa-22 alineadas en F. Sin otras discrepancias.

---

**Estado final:** Outcome F vinculante. PR #1 progresa. Sprint 2 cierra +1 día tarde. Sprint 3 unblocked tras deploy verde + 24h Sentry watch.

---

## 9. POSTMORTEM 2026-04-27 ~12:30 UTC-4 — F-pivot sentinel BROKEN, group wrap fix

**Bug encontrado durante deploy staging:** la implementación inicial F-pivot sentinel (`if (function_exists('akb_sinonimos')) return;` antes de los constants) NO funciona porque **PHP hoists top-level function declarations a parse time**.

**Test reproducible (docker php:8.1-cli):**

```php
<?php
echo "function_exists foo: " . (function_exists('foo') ? 'YES' : 'NO') . "\n";
return;
function foo() {}
// → "function_exists foo: YES" (hoisted antes del return)
```

**Consecuencia en staging:** `function_exists('akb_sinonimos')` devolvía `TRUE` en CADA load de `akibara-core/includes/akibara-search.php` (porque PHP las hoistea), el sentinel siempre firing, los `define()` siguientes (AKB_TABLE etc.) NUNCA corrían → fatal "Undefined AKB_TABLE" cuando activation hook llamaba `akb_create_index_table()`.

**Fix robusto aplicado (group wrap):**

```php
// constants defined first
if (! defined('AKB_TABLE')) { define('AKB_TABLE', $wpdb->prefix . 'akibara_index'); }
// ... other constants ...

// Group wrap — PHP NO hoistea functions DENTRO de un if block
if ( ! function_exists( 'akb_sinonimos' ) ) {

    function akb_sinonimos() {...}
    function akb_create_index_table() {...}
    // ... 17 more functions + 11 hooks ...

} // end group wrap
```

**Verificación robusta:**

```php
// Test docker php:8.1-cli: include twice, second time skips, no fatal
include "search-like.php"; // declares foo
include "search-like.php"; // foo already exists, skipped, no redeclare
```

**Aplicado a 3 archivos:**

| File | Functions wrapped | Hooks wrapped |
|---|---|---|
| `akibara-core/includes/akibara-search.php` | 19 | 11 |
| `akibara-core/modules/customer-edit-address/module.php` | 9 | 2 |
| `akibara-core/modules/address-autocomplete/module.php` | 4 | 2 |

**Defensa-en-profundidad final (4 capas):**

1. AKB_*_LOADED check (file-level dedup vía `require_once` realpath)
2. mu-plugin akibara-00-core-bootstrap.php define AKIBARA_CORE_PLUGIN_LOADED early
3. legacy akibara/akibara.php skipea includes cuando AKIBARA_CORE_PLUGIN_LOADED set
4. **group wrap** dentro de `if ( ! function_exists() )` → symbol-level dedup conditional declarations

**Adicional fix descubrió otro bug:** el legacy `akibara/akibara.php` línea 53 cargaba `includes/akibara-core.php` (declara `akb_editorial_pattern` etc.) UNCONDITIONALLY. Wrapped también en el check `! defined('AKIBARA_CORE_PLUGIN_LOADED')` (commit en mismo PR).

**Resultado staging deploy:**

```
Plugin 'akibara-core' activated.
Success: Activated 1 of 1 plugins.

AKIBARA_CORE_PLUGIN_LOADED: YES
AKB_TABLE: wpstg_akibara_index
AKB_SEARCH_LOADED: 10.0.0
akb_sinonimos: YES, akb_create_index_table: YES
akb_cea_can_edit: YES, akb_places_is_enabled: YES
wpstg_akibara_index: 1371 records
HTTP 200 | size 210KB
```

**Lessons:**

- PHP hoisting es trampa silenciosa: 3 mesas + adversarial review NO la detectaron porque no leyeron el código con ojo de "qué pasa con un return entre constants y function declarations en PHP 8".
- Tests local con docker php:8.1-cli debería ser parte del Definition-of-Done para cada cambio que toque load order o function declarations.
- Group wrap pattern es el patrón idiomático WP correcto para shareable plugin code (helpers.php ya lo usaba).
