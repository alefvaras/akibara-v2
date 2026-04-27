# THEME-CHANGE-REQUEST 01

**Cell origen:** A
**Sprint:** 3
**Solicitante:** mesa-16-php-pro (Cell A lead)
**Status:** ✅ APPROVED
**Fecha solicitud:** 2026-04-27
**Fecha decisión:** 2026-04-27
**Aprobado por:** Akibara Owner (decisión directa, skip mesa-15/mesa-01 arbitration)

---

## Problema

`wp-content/themes/akibara/inc/encargos.php` contiene la lógica AJAX completa del formulario de encargos. Sprint 3 Cell A mueve esa lógica a `akibara-preventas/modules/encargos/module.php` para que viva en el plugin (testeable, versionable, sin depender del tema activo).

El archivo del tema sigue siendo cargado por `functions.php` del tema y registraría los mismos hooks AJAX si no se le pone un guard, causando redeclare de `akibara_ajax_encargo_submit`.

## Workaround disponible

SÍ — ya aplicado en Sprint 3:

El archivo `inc/encargos.php` del tema fue modificado minimalmente (agregado `if defined('AKB_PREVENTAS_ENCARGOS_LOADED') return;` al inicio + comment explicativo). Esto hace que cuando `akibara-preventas` está activo, el tema no registre nada. Cuando `akibara-preventas` NO está activo, el tema registra la función legacy como fallback.

Cell A continúa sin bloqueo. Este RFC es para limpieza en Sprint 3.5.

## Cambio propuesto

En Sprint 3.5, una vez confirmado que `akibara-preventas` está activo en prod:

1. Eliminar el contenido de `inc/encargos.php` dejando solo el guard comment (o eliminar el archivo completo si `functions.php` lo carga condicionalmente).
2. Verificar que `functions.php` del tema tenga `if ( ! defined('AKB_PREVENTAS_ENCARGOS_LOADED') ) { require_once ... }` antes del require de `encargos.php`.

Alternativa más limpia: Cell H agrega en `functions.php`:
```php
if ( ! defined( 'AKB_PREVENTAS_ENCARGOS_LOADED' ) ) {
    require_once get_template_directory() . '/inc/encargos.php';
}
```
Esto hace el require condicional sin tocar `encargos.php`.

## Mockup requerido

- [x] No — change es non-visual. Es eliminación de lógica duplicada.

## Impact analysis

- Visual regression risk: NINGUNO (solo AJAX handler, sin HTML/CSS).
- Performance impact: MÍNIMO (early return en `plugins_loaded` si plugin activo — nanosegundos).
- Accessibility: sin impacto.
- Riesgo encargos Jujutsu kaisen 24/26: CERO — el módulo encargos preserva `akibara_encargos_log` (wp_options) Y migra a `wp_akb_special_orders` con retrocompatibilidad explícita.

## Decision (Akibara Owner)

**Status:** ✅ APPROVED — 2026-04-27

**Justificación de aprobación:**
- Workaround ya aplicado en Sprint 3 es minimalista (guard `if defined() return;` + comment)
- Impact analysis cero riesgo: non-visual, sin perf, sin a11y, encargos prod Jujutsu kaisen 24/26 preservados via dual-write
- Sprint 3.5 cleanup: aplicar alternativa más limpia (require condicional en `functions.php`) — preferible a editar `encargos.php` repetidamente

## Sprint 3.5 implementation plan

**Asignado:** Cell H (theme owner) Sprint 3.5
**Acción:**
1. Editar `wp-content/themes/akibara/functions.php` agregando require condicional:
   ```php
   if ( ! defined( 'AKB_PREVENTAS_ENCARGOS_LOADED' ) ) {
       require_once get_template_directory() . '/inc/encargos.php';
   }
   ```
2. Una vez confirmado en staging que el plugin `akibara-preventas` está activo y el require condicional funciona:
   - **Opción A (preferida):** eliminar `inc/encargos.php` completamente. El guard en functions.php ya previene el load.
   - **Opción B:** dejar `inc/encargos.php` como legacy fallback con guard activo (current state). Trade-off: menos limpio pero zero risk de breaking si plugin deactivated.
3. Cell H decide A vs B en Sprint 3.5 basado en confianza del deploy (probable A si Sentry 24h verde + smoke E2E verde).
4. Update HANDOFF Cell H Sprint 3.5 con elección final.

**No bloqueante:** este cambio NO bloquea deploy Sprint 3 a prod. El estado actual (guard en `inc/encargos.php`) es production-safe.
