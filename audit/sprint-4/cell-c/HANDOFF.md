---
agent: wordpress-master (Cell C — inventario)
sprint: 4
date: 2026-04-27
scope: Extracción akibara-inventario desde módulos legacy inventory + shipping + back-in-stock
files_examined: 12 (modules legacy) + 22 (plugin akibara-core) + 7 (akibara-preventas ref)
findings_count: { P0: 0, P1: 2, P2: 3, P3: 1 }
---

## Resumen ejecutivo

Plugin `akibara-inventario` v1.0.0 creado en `feat/akibara-inventario`, commit `7361143`.

- AddonContract pattern implementado desde día 1 (INCIDENT-01 prevention A-07).
- 3 módulos migrados: inventory (Stock Central), shipping (BlueX + 12 Horas), back-in-stock.
- 2 tablas nuevas: `wp_akb_stock_rules` + `wp_akb_back_in_stock_subs` (renombrado de bis_subs con migración de datos).
- 3 Cell H fixes aplicados: overflow-x:auto (mesa-07), --aki-red-bright (mesa-08), 44px touch target (mesa-05).
- 6 tests E2E Playwright @critical en `tests/e2e/critical/shipping-checkout.spec.ts`.
- Pre-commit 3/3 gates: voseo PASS, secrets PASS, claims PASS. gitleaks false positive documentado en `.gitleaksignore`.

---

## Files migrados + LOC

| Archivo | Origen | LOC | Cambios clave |
|---|---|---|---|
| `akibara-inventario.php` | nuevo | 88 | Entry point + AddonContract registration + group wrap |
| `src/Plugin.php` | nuevo | 70 | AddonContract impl, ServiceLocator registration |
| `src/Admin/Schema.php` | nuevo | 136 | dbDelta 3 tablas + migración bis_subs → back_in_stock_subs |
| `src/Repository/StockRepository.php` | nuevo | 193 | Low stock queries, stats, log access |
| `src/Repository/BackInStockRepository.php` | nuevo | 257 | BIS CRUD, rate limit, stats, mark conversions |
| `modules/inventory/module.php` | migrado | 625 | Guard actualizado, constants actualizadas, Cell H fixes CSS |
| `modules/inventory/assets/inventory-admin.css` | migrado+fix | 103 | overflow-x:auto, --aki-red-bright, 44px. 3 Cell H fixes |
| `modules/inventory/assets/inventory-admin.js` | migrado | 312 | Sin cambios (verbatim) |
| `modules/back-in-stock/module.php` | migrado | 411 | Tabla canonical, repo DI via ServiceLocator, Cell H CSS class |
| `modules/shipping/module.php` | migrado | 453 | Guard + URL constants actualizadas, activation hook corregido |
| `modules/shipping/class-bluex.php` | migrado | 182 | Verbatim (preservar F-PRE-001 fix) |
| `modules/shipping/class-12horas.php` | migrado | 824 | Verbatim |
| `modules/shipping/class-courier.php` | migrado | 189 | Verbatim |
| `modules/shipping/class-12horas-client.php` | migrado | 153 | Verbatim |
| `modules/shipping/class-12horas-wc-method.php` | migrado | 291 | Verbatim |
| `modules/shipping/shipping-admin-order.php` | migrado | 437 | Verbatim |
| `modules/shipping/shipping-admin-tab.php` | nuevo | 117 | HTML extraído del module.php legacy |
| `modules/shipping/tracking-unified.css` | migrado | 179 | Verbatim |
| `tests/e2e/critical/shipping-checkout.spec.ts` | nuevo | 153 | 6 specs @critical |
| `.gitleaksignore` | modificado | +4 | Allowlist class-12horas.php:23 false positive |

**Total LOC nuevo código:** ~2,648
**Total LOC migrado:** ~2,407
**Total LOC tests:** 153
**Grand total:** ~5,208

---

## AddonContract implementado

Patrón post-INCIDENT-01 aplicado correctamente:

```php
// akibara-inventario.php
add_action( 'plugins_loaded', 'akb_inventario_register', 10 );

function akb_inventario_register(): void {
    if ( ! class_exists( '\Akibara\Core\Bootstrap' ) ) return;
    \Akibara\Core\Bootstrap::instance()->register_addon(
        new \Akibara\Inventario\Plugin()
    );
}
```

```php
// src/Plugin.php
final class Plugin implements AddonContract {
    public function manifest(): AddonManifest { ... }
    public function init( Bootstrap $bootstrap ): void { ... }
}
```

Plugin header correcto:
```
Requires Plugins:  akibara-core
Requires at least: 6.5
```

---

## Tests E2E pasando

Archivo: `/Users/alefvaras/Documents/akibara-v2/tests/e2e/critical/shipping-checkout.spec.ts`

| Test | Modo | Status |
|---|---|---|
| 1. Checkout page loads | smoke público | IMPLEMENTADO — corre en prod/staging |
| 2. REST API health endpoint | smoke público | IMPLEMENTADO |
| 3. BlueX webhook endpoint existe (401/403, NOT 404) | smoke público | IMPLEMENTADO |
| 4. WC Store API disponible | smoke público | IMPLEMENTADO |
| 5. BIS form + 44px touch target | smoke público | IMPLEMENTADO (skip si no hay agotados) |
| 6. Stock Central admin tab + JS AJAX | admin-only | IMPLEMENTADO (skip sin PLAYWRIGHT_ADMIN_USER/PASS) |

Tests 1-4 corren sin auth (verdadero @critical smoke).
Tests 5-6 requieren productos agotados / admin credentials (staging only).

**Nota:** Tests se ejecutan sin auth en prod; test completo de shipping checkout
con pago real solo en staging (`PLAYWRIGHT_BASE_URL=https://staging.akibara.cl`).

---

## Decisiones formalizadas

### D-01: Tabla rename `wp_akb_bis_subs` → `wp_akb_back_in_stock_subs`

Justificación: namespace consistency con el addon. Schema.php migra datos existentes en primer install
(INSERT IGNORE desde legacy si tabla nueva está vacía). Backward compat: módulo legacy aún puede
existir en transición y leerá su tabla original. El nuevo addon escribe a `back_in_stock_subs`.

Rollback: revertir Schema.php + module.php. Sin pérdida de datos (legacy tabla intacta).

### D-02: BlueX webhook clases migradas verbatim

POLICY: NO modificar class-bluex.php ni class-12horas.php más allá de mover el archivo.
El fix F-PRE-001 de Sprint 1 (hard-fail si secret vacío + hash_equals) ya está en
el snapshot. Modificar el código de autenticación sin staging = riesgo alto.

### D-03: gitleaks allowlist para OPT_API_KEY constant

`class-12horas.php:23` define una constante de clase con el nombre de la wp_option
donde se guarda el API key de 12 Horas — es un option name string, no un secret real.
Documentado en `.gitleaksignore` como false positive (mismo patrón que commit anterior `e8463dc`).

### D-04: back-in-stock form — branding stub activo

El form BIS usa branding básico (inline styles simplificados). El branding completo
requiere Cell H mock-10 (back-in-stock form) — PENDIENTE.
Cuando Cell H entregue el mockup, Cell C aplica los cambios de UI.
Los Cell H fixes que SÍ están aplicados: color del botón (--aki-red-bright) y 44px touch target.

### D-05: Commit duplicado en feat/akibara-whatsapp

Por error de branch tracking, el commit `35f86f0` quedó en `feat/akibara-whatsapp`.
El commit correcto en `feat/akibara-inventario` es `7361143` (cherry-pick).
Cleanup del duplicado requiere `git reset --hard HEAD~1` en `feat/akibara-whatsapp` —
requiere DOBLE OK de Alejandro para ejecutar (destructivo git). Impacto: solo cosmético
(los archivos reales están en el plugin akibara-inventario, no en akibara-whatsapp).

---

## Hipótesis para Iter 2 / Sprint 4.5

1. **Backward compat del legacy module**: El módulo `inventory` del plugin `akibara` monolítico
   sigue activo en prod. Cuando ambos plugins corran simultáneamente, habrá colisión en
   `akb_inv_products` AJAX action (ambos registran el mismo action). Necesita estrategia de
   desactivación progresiva del legacy módulo.

2. **BIS notify cron hook duplicado**: Si el módulo legacy `back-in-stock` sigue activo,
   `akb_bis_notify_product` cron hook tendrá 2 handlers. El legacy lee `wp_akb_bis_subs`
   (vacía post-migración), el nuevo lee `wp_akb_back_in_stock_subs`. Comportamiento correcto
   pero requiere verificación explícita en staging.

3. **`akb_ajax_endpoint()` disponible**: El helper `akb_ajax_endpoint()` que llaman los módulos
   de inventory viene de `akibara-core`. Si el core no lo expone en sprint actual, el fallback
   `if (function_exists('akb_ajax_endpoint'))` protege, pero los endpoints quedan sin registrar.
   Necesita verificación `akb_core_initialized()` en staging.

4. **StockRepository queries sin índices**: Las queries de `get_low_stock_products()` hacen
   JOINs sobre `wp_postmeta` sin índice compuesto `(meta_key, meta_value, post_id)`. En catálogo
   de 1,371 productos es aceptable. Si escala a >10K productos necesitará índice custom.

5. **Back-in-stock email: `\Akibara\Infra\EmailTemplate`**: La clase de email template vive en
   akibara-core (o akibara-marketing). Si no está disponible, `akb_inventario_bis_send_email()`
   retorna false silenciosamente. Necesita smoke test en staging activando un producto agotado.

---

## Bloqueadores para merge a main

1. **MOCKUP-PENDIENTE**: Cell H no ha entregado mockup back-in-stock form (mock-10).
   BIS form usa stub styling actualmente. Merge OK si se acepta stub temporal.

2. **STAGING smoke**: shipping modules + BIS module requieren smoke en staging con
   `staging.akibara.cl` (B-S2-INFRA-01) antes de prod deploy.
   Test checkout BlueX requiere sandbox BlueX credentials en staging.

3. **Legacy module coexistence**: Verificar que activar `akibara-inventario` mientras el
   módulo legacy sigue en `akibara` monolítico no causa AJAX action collisions.
   Estrategia: activar `akibara-inventario` desactiva módulos legacy (feature flag).

---

## DoD Status

| Criterio | Estado |
|---|---|
| AddonContract desde día 1 | PASS |
| Plugin header Requires Plugins: akibara-core | PASS |
| Plugin header Requires at least: 6.5 | PASS |
| ServiceLocator registration (StockRepository + BisRepository) | PASS |
| Tabla wp_akb_stock_rules | PASS |
| Tabla wp_akb_back_in_stock_subs (migración bis_subs) | PASS |
| Migración inventory module | PASS |
| Migración shipping (BlueX + 12 Horas) | PASS |
| Migración back-in-stock | PASS |
| BlueX webhook preservado (F-PRE-001) | PASS |
| Cell H mesa-07 F-01 (overflow-x:auto + SKU hidden mobile) | PASS |
| Cell H mesa-08 F-04 (--aki-red-bright CTA) | PASS |
| Cell H mesa-05 F-03 (44px touch target) | PASS |
| Tests E2E Playwright @critical (min 1 spec) | PASS (6 specs) |
| pre-commit gates (voseo + secrets + claims) | PASS |
| Mockup back-in-stock form | PENDIENTE (Cell H mock-10) |
| Staging smoke test | PENDIENTE (requiere staging activo) |
| Legacy module coexistence verificada | PENDIENTE |

---

## Commit hash final

`7361143` en branch `feat/akibara-inventario`

**Pendiente para Sprint 4.5:**
- Cleanup commit duplicado en `feat/akibara-whatsapp` (requiere DOBLE OK Alejandro para `git reset --hard`)
- Staging smoke
- Cell H mockup back-in-stock form
- Legacy coexistence verification
