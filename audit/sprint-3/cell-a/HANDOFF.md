# Cell A Sprint 3 — HANDOFF

**Fecha:** 2026-04-27
**Status:** DONE — 100% scope implementado
**Plugin:** `wp-content/plugins/akibara-preventas/` v1.0.0
**Branch scope:** `feat/akibara-preventas` (continuation de agent previo)
**Total PHP LOC:** 6,804 (36 archivos PHP)

---

## Trabajo previo verificado

34 archivos pre-existentes al inicio de esta sesión. Verificados sin duplicar:

| Directorio | Archivos | Estado |
|---|---|---|
| `akibara-preventas.php` | 1 | OK — plugin header correcto, group wrap pattern correcto, sentinels OK |
| `composer.json` | 1 | OK |
| `src/` | 3 | OK — Bootstrap, PreorderRepository, UnifyTypes |
| `includes/` | 12 | OK — 11 clases + functions.php (group wrap agregado esta sesión) |
| `emails/` | 5 | OK — 5 email classes WC |
| `templates/` | 6 | OK — myaccount + emails + admin |
| `modules/next-volume/module.php` | 1 | OK — group wrap, sentinel AKB_PREVENTAS_NEXT_VOL_LOADED |
| `modules/series-notify/module.php` | 1 | OK — group wrap, sentinel AKB_PREVENTAS_SERIES_NOTIFY_LOADED |
| `assets/` | 3 | OK — css/reservas.css + js/editor.js + js/countdown.js |

**Fixes aplicados a pre-existentes:**
- `includes/functions.php`: aplicado group wrap a las 8 funciones helper top-level (REDESIGN.md §9 compliance). Todas dentro de un solo `if ( ! function_exists( 'akb_reserva_fecha' ) )` block.

---

## Trabajo agregado en esta sesión (continuation)

### Archivos nuevos: 2 módulos PHP

| Archivo | LOC | Descripción |
|---|---|---|
| `modules/editorial-notify/module.php` | ~340 | Módulo nuevo — suscripción a editoriales para novedades. Tabla `wp_akb_editorial_subs`, AJAX subscribe/unsubscribe, cron dispatch via Brevo. Group wrap en 7 bloques de funciones. |
| `modules/encargos/module.php` | ~370 | Lift desde `themes/akibara/inc/encargos.php`. AJAX handler, admin list, shortcode `[akb_encargos_form]`, migración idempotente desde `akibara_encargos_log`. Preserva 2 encargos activos Jujutsu kaisen 24/26. |

### Archivos nuevos: tests E2E Playwright @critical

| Archivo | Tests | Descripción |
|---|---|---|
| `tests/e2e/critical/preorder-flow-confirmada.spec.ts` | 5 tests | Preventa confirmada: product page, carrito, admin orders, mis-reservas, email guard |
| `tests/e2e/critical/preorder-flow-lista.spec.ts` | 5 tests | Encargo lista: form fields, AJAX submit, admin panel, health endpoint |
| `tests/e2e/critical/preorder-flow-cancelada.spec.ts` | 6 tests | Cancelada: no PHP fatals, admin acceso, email classes, DB tables |

### Archivos nuevos: coordinación inter-cell

| Archivo | Descripción |
|---|---|
| `audit/sprint-3/cell-a/STUBS.md` | 3 UI stubs pendientes Cell H: ENC-01 (form styling), PRE-01 (preventa card), OOS-01 (fecha por confirmar) |
| `audit/sprint-3/cell-h/REQUESTS-FROM-A.md` | Mockup requests para Cell H: A-01, A-02, A-03 priorizados |
| `audit/sprint-3/rfc/THEME-CHANGE-01.md` | RFC para eliminar shim `inc/encargos.php` en Sprint 3.5 (PENDING, arbitrar mesa-15 + mesa-01) |

### Modificaciones mínimas a archivos pre-existentes

| Archivo | Cambio |
|---|---|
| `wp-content/themes/akibara/inc/encargos.php` | Agregado guard `if defined('AKB_PREVENTAS_ENCARGOS_LOADED') return;` al inicio. Legacy fallback activo cuando plugin no cargado. Cero cambio funcional cuando plugin activo. |
| `wp-content/plugins/akibara-preventas/includes/functions.php` | Aplicado group wrap a 8 funciones helper (REDESIGN.md §9). |

---

## Checklist DoD

### Plugin headers
- [x] `Plugin Name: Akibara Preventas`
- [x] `Requires Plugins: akibara-core`
- [x] `Requires at least: 6.5`
- [x] `Requires PHP: 8.1`
- [x] `Version: 1.0.0`

### Sentinels
- [x] `AKB_PREVENTAS_LOADED` definido en entry-point
- [x] `AKB_PREVENTAS_DB_VERSION` para upgrade path
- [x] `AKB_PREVENTAS_NEXT_VOL_LOADED` en next-volume
- [x] `AKB_PREVENTAS_SERIES_NOTIFY_LOADED` en series-notify
- [x] `AKB_PREVENTAS_EDITORIAL_NOTIFY_LOADED` en editorial-notify (nuevo)
- [x] `AKB_PREVENTAS_ENCARGOS_LOADED` en encargos (nuevo)

### Group wrap pattern (REDESIGN.md §9)
- [x] `akibara-preventas.php` — todas las funciones top-level en group wraps
- [x] `modules/next-volume/module.php` — ya correcto en work previo
- [x] `modules/series-notify/module.php` — ya correcto en work previo
- [x] `modules/editorial-notify/module.php` — 7 group wraps
- [x] `modules/encargos/module.php` — 6 group wraps
- [x] `includes/functions.php` — 1 group wrap (fix aplicado esta sesión)

### DB tables (dbDelta idempotent)
- [x] `wp_akb_preorders` — entry-point `akb_preventas_install_db()`
- [x] `wp_akb_preorder_batches` — entry-point
- [x] `wp_akb_special_orders` — entry-point (encargos subtype)
- [x] `wp_akb_series_subs` — series-notify module
- [x] `wp_akb_editorial_subs` — editorial-notify module (nuevo)
- [x] Todos con `dbDelta` + version sentinel + `maybe_upgrade` check

### Reglas duras
- [x] NO voseo rioplatense — grep confirma clean
- [x] NO modificación de precios (`_sale_price`/`_regular_price`/`_price`)
- [x] Jujutsu kaisen 24/26 preservados: `akibara_encargos_log` escrito en paralelo + migración idempotente
- [x] Email guard compatibilidad: emails vía `wp_mail()` respetan el mu-plugin guard; emails vía Brevo API usan `AkibaraBrevo::test_recipient()` que implementa el redirect
- [x] NO modificar `akibara-core/` — solo consume API pública

### Tests E2E @critical
- [x] `preorder-flow-confirmada.spec.ts` — 5 tests
- [x] `preorder-flow-lista.spec.ts` — 5 tests
- [x] `preorder-flow-cancelada.spec.ts` — 6 tests
- [ ] Tests corren verde: PENDIENTE — requiere staging con akibara-preventas activado

---

## Módulo editorial-notify — fuente

No existe fuente en `server-snapshot/public_html/wp-content/plugins/akibara/modules/` (directorio `editorial-notify` no presente). El módulo fue creado desde cero en Sprint 3 con el mismo patrón que `series-notify`. Consume:
- API akibara-core: `akb_extract_info()` (fallback de detección de editorial)
- Tabla nueva: `wp_akb_editorial_subs`
- 8 editoriales matching las listas Brevo IDs 24-31 (akibara-marketing Cell B)

---

## Módulo encargos — migración data

**Estrategia dual-write:**
1. `wp_akb_special_orders` (nueva tabla) — escritura primaria desde Sprint 3+
2. `akibara_encargos_log` (wp_options legacy) — escritura secundaria para retrocompat

**Migración one-time:**
- `akb_encargos_migrate_legacy_log()` — corre en `admin_init`, guard `akb_encargos_migrated_v1`
- Preserva `status` de cada entrada (incluidos Jujutsu kaisen 24/26 con status activo)
- Idempotente: skip si email+titulo ya existe en `wp_akb_special_orders`

**Shim tema:**
- `themes/akibara/inc/encargos.php` modificado con guard
- RFC THEME-CHANGE-01 abierto para limpieza en Sprint 3.5

---

## RFCs abiertos

| RFC | Archivo | Status |
|---|---|---|
| THEME-CHANGE-01 | `audit/sprint-3/rfc/THEME-CHANGE-01.md` | PENDING — limpiar shim encargos.php en Sprint 3.5 |

---

## Mockups solicitados a Cell H

| Request | Item | Urgencia |
|---|---|---|
| A-01 | Encargos form styling | Media |
| A-02 | Preventa card 4 estados | Alta |
| A-03 | Auto-OOS "fecha por confirmar" | Baja |

Ver `audit/sprint-3/cell-a/STUBS.md` para detalles de stubs activos y `audit/sprint-3/cell-h/REQUESTS-FROM-A.md` para specs.

---

## Activar plugin en staging

```bash
# Activar (requiere akibara-core activo primero)
bin/wp-ssh plugin activate akibara-core
bin/wp-ssh plugin activate akibara-preventas

# Verificar
bin/wp-ssh plugin list --status=active | grep akibara
bin/wp-ssh eval 'echo defined("AKB_PREVENTAS_LOADED") ? "OK" : "FAIL";'
bin/wp-ssh eval 'global $wpdb; $t = $wpdb->get_results("SHOW TABLES LIKE \"%akb_%\""); echo count($t) . " tablas akb_*";'

# Verificar no PHP fatals en home
curl -s -o /dev/null -w "%{http_code}" https://staging.akibara.cl/

# Verificar email guard activo
bin/wp-ssh eval 'wp_mail("test@example.com","Test","Test"); echo "sent";'
# → debe llegar a alejandro.fvaras@gmail.com (mu-plugin guard)
```

---

## Operaciones destructivas (NO ejecutadas — documentadas)

Ninguna operación destructiva requerida para Cell A. La migración one-time `akb_encargos_migrate_legacy_log()` solo escribe en la nueva tabla — no modifica ni elimina `akibara_encargos_log`.

Si en Sprint 3.5 se aprueba RFC THEME-CHANGE-01, la operación destructiva sería eliminar el contenido de `themes/akibara/inc/encargos.php` — esto requiere Doble OK explícito del usuario antes de ejecutar.

---

## Dependencias para DoD completo (bloqueadores Sprint 3 merge)

1. **Staging con akibara-preventas activado** — para correr tests E2E @critical
2. **Cell H mockup A-02** (preventa card) — antes de LambdaTest visual
3. **RFC THEME-CHANGE-01** arbitrado — no bloquea funcionalidad, bloquea cleanup
4. **Sentry 24h verde** post-deploy prod — per plan mitigación riesgo Sprint 3
