# Cell B — Sprint 3 HANDOFF

**Fecha:** 2026-04-27
**Responsable:** Cell B (akibara-marketing plugin)
**Estado:** COMPLETO — todos los modulos liftados o scaffoldeados

---

## Resumen de trabajo completado

### Plugin: akibara-marketing

**Total LOC (sin vendor/coverage):** ~12,400 lineas PHP

#### Modulos liftados desde server-snapshot

| Modulo | Origen | Adaptaciones clave |
|--------|--------|--------------------|
| `brevo/module.php` | snapshot v1.1.0 | `AKIBARA_V10_LOADED → AKB_MARKETING_LOADED`; delegacion a `EditorialLists` class |
| `banner/module.php` | snapshot v2.0.0 | Load guard; URL constants |
| `popup/module.php` + `coupon-antiabuse.php` + `popup.css` | snapshot v3.1.0 | Load guard; URL constants |
| `cart-abandoned/module.php` | DEPRECATION STUB | Brevo upstream activo — NO migrado (ver decision abajo) |
| `review-request/module.php` | snapshot v1.0.0 | Load guard; `AkibaraEmailTemplate` guard |
| `review-incentive/module.php` | snapshot v1.0.0 | Load guard |
| `referrals/module.php` | snapshot v1.1.0 | DB management movido a central dbDelta |
| `marketing-campaigns/module.php` + `tracking.php` + `welcome-series.php` | snapshot v10.x | Load guard; `const → define()` guards; `AKIBARA_URL/VERSION → AKB_MARKETING_*` |
| `customer-milestones/module.php` | SCAFFOLD (sin fuente legacy) | Birthday + anniversary via Brevo templateId; daily cron |
| `welcome-discount/module.php` + 8 class files + admin.php + popup.css | snapshot v1.1.0 | Load guard; `Akibara\Infra\Brevo → AkibaraBrevo`; `Akibara\Infra\EmailTemplate → AkibaraEmailTemplate` |
| `descuentos/module.php` + engine.php + cart.php + banner.php + presets.php + migration.php + tramos-setup.php + admin.php | snapshot v11.0.0 | Load guard; `AKIBARA_FILE → AKB_MARKETING_FILE`; group wrap |
| `descuentos-tramos/module.php` | SCAFFOLD (sin fuente legacy) | Pass-through — tramos logic ya esta en descuentos/cart.php v11.1 |

#### Archivos de soporte existentes al inicio del sprint

- `src/Brevo/EditorialLists.php` — IDs canonicos 24–31 LOCKED
- `src/Brevo/SegmentationService.php`
- `src/Finance/DashboardController.php` + 5 widgets
- `akibara-marketing.php` — entry point con dbDelta (3 tablas) y module loader

---

## Decision: cart-abandoned

**Fuente:** `project_brevo_upstream_capabilities.md` (memoria del proyecto, confirmado 2026-04-26)

Brevo Abandoned Cart upstream esta **activo** en la cuenta Akibara. El modulo legacy (`akibara/modules/cart-abandoned/`) tiene ~539 LOC de logica custom de carrito abandonado que **duplicaria** lo que Brevo hace nativo.

**Accion tomada:** `modules/cart-abandoned/module.php` creado como DEPRECATION STUB (no ejecuta logica).

**Deuda pendiente:** Auditoria de envios duplicados en Sprint 3.5/4. El modulo legacy sigue en produccion en server-snapshot hasta que se confirme que Brevo ya cubre 100% del flujo.

---

## Tablas DB propias de este plugin

| Tabla | Propietario | Version |
|-------|------------|---------|
| `wp_akb_campaigns` | `akibara-marketing.php` central dbDelta | v1.0 |
| `wp_akb_email_log` | `akibara-marketing.php` central dbDelta | v1.0 |
| `wp_akb_referrals` | `akibara-marketing.php` central dbDelta | v1.0 |
| `wp_akb_wd_subscriptions` | `welcome-discount/module.php` (WD-only) | v1.0.1 |
| `wp_akb_wd_log` | `welcome-discount/module.php` (WD-only) | v1.0.1 |

**Razon por la que WD tiene sus propias tablas:** Las tablas `wd_subscriptions` y `wd_log` son internas al modulo welcome-discount y no tienen dependencias cross-modulo. Separarlas del central dbDelta evita que un bug en welcome-discount afecte las tablas core.

---

## IDs de listas Brevo — LOCKED

Canonical source: `src/Brevo/EditorialLists.php`

| Lista | ID |
|-------|----|
| Ivrea AR | 24 |
| Panini AR | 25 |
| Planeta ES | 26 |
| Milky Way | 27 |
| Ovni Press | 28 |
| Ivrea ES | 29 |
| Panini ES | 30 |
| Arechi | 31 |

**NO modificar estos IDs** — son IDs de listas reales en la cuenta Brevo de produccion.

---

## Verificacion de calidad

### PHP syntax (18 archivos nuevos de esta sesion)
```
PASS: 18  FAIL: 0
```
Verificado via `docker compose run --rm composer php -l` en PHP 8.3.

### Voseo grep
```
grep -rn "confirmá|hacé|tenés|podés|\bvos\b|sos..." modules/
→ 0 hits
```

### Constants auditadas
- Todos los `AKIBARA_V10_LOADED` → `AKB_MARKETING_LOADED`
- `const AKB_MKT_OPTION` → `if (!defined()) define()`
- `const AKB_MKT_TRACKING_OPTION` → `if (!defined()) define()`
- `AKIBARA_URL` → `AKB_MARKETING_URL`
- `AKIBARA_VERSION` → `AKB_MARKETING_VERSION`
- `AKIBARA_FILE` → `AKB_MARKETING_FILE`
- `Akibara\Infra\EmailTemplate` → `AkibaraEmailTemplate` (con fallback wp_mail)
- `Akibara\Infra\Brevo` → `AkibaraBrevo`

---

## Pendientes para Sprint 4 / Siguiente iteracion

### Tests (directorio `tests/` existe pero esta vacio)

Los siguientes tests son deuda tecnica activa:

1. **Smoke Brevo wiring** — subscribe order → list per editorial (IDs 24–31)
2. **Welcome-series E1** — primer email en t=0 post-subscripcion popup
3. **Finance widgets** — 5 widgets retornan data != null con seed
4. **Voseo-grep zero** — ya pasa manualmente; agregar al CI
5. **WD subscribe flow** — email blacklist, rate limit, captcha, duplicate check
6. **Descuentos engine** — regla_aplica_a_producto con taxonomia jerarquica

### UI stubs pendientes de mockup Cell H

Ver `STUBS.md` en este mismo directorio.

### Auditoria cart-abandoned duplicados

Antes de desactivar el modulo legacy en produccion, confirmar:
- Brevo Abandoned Cart cubre 100% de los triggers (add-to-cart, tiempo de abandono)
- No hay emails duplicados en el periodo de transicion
- Trigger: Sprint 3.5 o Sprint 4 segun prioridad del backlog

---

## Modulo loader (akibara-marketing.php) — estado final

```php
$modules = array(
    'brevo/module.php',           // cargado primero (shared helpers)
    'banner/module.php',
    'popup/module.php',
    'descuentos/module.php',
    'descuentos-tramos/module.php',
    'welcome-discount/module.php',
    'marketing-campaigns/module.php',
    'review-request/module.php',
    'review-incentive/module.php',
    'referrals/module.php',
    'customer-milestones/module.php',
    'finance-dashboard/module.php',
);
// cart-abandoned excluido (DEPRECATED — Brevo upstream)
```

---

## Solicitudes a Cell H (Design Ops)

Ver `REQUESTS-FROM-B.md` en `audit/sprint-3/cell-h/`.

Resumen de los 2 items que bloquean activation de modulos:
1. **customer-milestones** admin UI — STUB activo, necesita mockup para templates Brevo
2. **finance-dashboard** render — DashboardController::render() es STUB, necesita layout de widgets

---

*Handoff generado por Cell B, Sprint 3 Paralelo, 2026-04-27*
