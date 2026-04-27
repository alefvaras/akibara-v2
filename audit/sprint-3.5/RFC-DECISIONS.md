# RFC Decisions — Sprint 3.5

**Fecha:** 2026-04-27
**Sprint:** 3.5 (Lock Release post Sprint 3 paralelo)
**Arbitrators:** mesa-15-architect-reviewer + mesa-01-lead-arquitecto
**Approved by:** Akibara Owner (delegated direct approval)
**Referencia metodológica:** `audit/CELL-DESIGN-2026-04-26.md:608-659` (Sprint X.5 Lock Release pattern)

---

## Resumen ejecutivo

Sprint 3 generó un único RFC formal: `THEME-CHANGE-01` (encargos guard migration theme→preventas). El RFC fue pre-aprobado por Akibara Owner el 2026-04-27, saltando arbitración mesa-15/mesa-01 por bajo riesgo y workaround ya aplicado. Sprint 3.5 ratifica formalmente la decisión y bloquea la implementación de la "Opción A" propuesta en el RFC original por un hallazgo crítico de arquitectura: el `functions.php` del tema vive completo en `server-snapshot/` (322 LOC), no en `wp-content/themes/akibara/` del repo de trabajo. Avanzar con Opción A sin un sync controlado del tema previo introduciría riesgo de overwrite del theme prod.

Cero RFCs `CORE-CHANGE-*` en Sprint 3 → no se justifica branch `feat/core-s3.5` ni cambios al núcleo `akibara/` plugin.

---

## Decisiones tabuladas

| RFC ID | Título | Status | Decision | Implementation branch | Owner | Verification |
|---|---|---|---|---|---|---|
| THEME-CHANGE-01 | Encargos guard migration theme→preventas | APPROVED | Opción B (mantener guard, zero-risk) | `feat/theme-design-s3` (ya merged) | Cell H | Sentry 24h verde post-deploy + smoke E2E encargos |

---

## Detalle THEME-CHANGE-01

### Contexto

Sprint 3 Cell A migró la lógica AJAX del formulario de encargos desde `wp-content/themes/akibara/inc/encargos.php` (legacy) hacia `wp-content/plugins/akibara-preventas/modules/encargos/module.php` (nuevo addon plugin). La motivación arquitectónica:

- Lógica de negocio fuera del tema (testeable, versionable, no depende del tema activo).
- Alineamiento con la arquitectura objetivo "Core + 5 Addons + Cell H" (ver `MEMORY.md → project_architecture_core_plus_addons.md`).
- Persistencia migrada de `wp_options.akibara_encargos_log` (single-row) a tabla dedicada `wp_akb_special_orders` con dual-write para retrocompatibilidad.

**Problema técnico:** el archivo legacy `inc/encargos.php` sigue siendo cargado incondicionalmente por `functions.php` del tema (línea 134 del archivo en `server-snapshot/`):

```php
require_once AKIBARA_THEME_DIR . '/inc/encargos.php';
```

Sin guard, ambos paths registrarían `akibara_ajax_encargo_submit` y dispararían un fatal error por redeclaración de función.

### Workaround aplicado (Sprint 3, ya en main)

Cell A modificó `inc/encargos.php` agregando un early return condicional al inicio del archivo:

```php
// Delegate to plugin if loaded; do not double-register.
if ( defined( 'AKB_PREVENTAS_ENCARGOS_LOADED' ) ) {
    return;
}
```

Verificado en `wp-content/themes/akibara/inc/encargos.php:20-22`. El plugin `akibara-preventas` define la constante en su bootstrap antes de que `functions.php` del tema haga el `require_once`. Estado actual production-safe.

### Decisión: Opción B (mantener guard)

**Status:** ✅ APPROVED

**Implementación final:** `inc/encargos.php` permanece en el tema con el guard `if defined() return;` activo. NO se elimina el archivo. NO se modifica `functions.php` del tema en Sprint 3.5.

**Racional:**

1. **Zero-risk para prod.** El estado actual ya está deployado y verificado. Sprint 3.5 es Lock Release, no es ventana para cambios opcionales.
2. **Alineamiento con `feedback_minimize_behavior_change`** (MEMORY.md): "Default a opción que NO cambia comportamiento existente. Excepción: P0 security/bug/compliance. Tiebreaker entre opciones razonables." Opción B no cambia el comportamiento; Opción A sí.
3. **Fallback útil si plugin se desactiva.** Si por error operacional `akibara-preventas` queda desactivado (ej. troubleshooting de prod), el legacy fallback en el tema mantiene encargos funcionando hasta que se reactive el plugin. Defense-in-depth.
4. **El RFC original (línea 74-75) ya contemplaba Opción B explícitamente** como aceptable: "Opción B: dejar `inc/encargos.php` como legacy fallback con guard activo (current state). Trade-off: menos limpio pero zero risk de breaking si plugin deactivated."

### Por qué NO Opción A (eliminar `inc/encargos.php`)

Opción A propone eliminar el archivo `inc/encargos.php` del tema y agregar un require condicional en `functions.php`:

```php
if ( ! defined( 'AKB_PREVENTAS_ENCARGOS_LOADED' ) ) {
    require_once get_template_directory() . '/inc/encargos.php';
}
```

**Bloqueador arquitectónico crítico identificado en Sprint 3.5:**

El árbol del tema en `wp-content/themes/akibara/` del repo akibara-v2 está **incompleto**. Solo contiene:

```
wp-content/themes/akibara/
├── assets/
└── inc/
```

El theme COMPLETO vive en `server-snapshot/public_html/wp-content/themes/akibara/` con:

- `functions.php` (322 LOC, mtime 2026-04-26)
- 50+ archivos `inc/*.php`
- Templates root (`404.php`, `front-page.php`, `header.php`, `footer.php`, `home.php`, `index.php`, `page-*.php`, `single.php`, `setup.php`, `template-serie-hub.php`)
- `style.css`, `hero.php`, `header-checkout.php`, `footer-checkout.php`

El `functions.php` real (server-snapshot línea 134) ya tiene:

```php
require_once AKIBARA_THEME_DIR . '/inc/encargos.php';
```

**Riesgo de avanzar con Opción A en Sprint 3.5:**

- Si Cell H elimina `inc/encargos.php` y al mismo tiempo crea/edita un `functions.php` en `wp-content/themes/akibara/` del repo akibara-v2 (que es el que se deploya), ese `functions.php` NO contendría las 322 LOC reales. El deploy sobreescribiría el `functions.php` prod completo, rompiendo:
  - WooCommerce integration (líneas 42-49)
  - Microsoft Clarity, hero/blog preload (líneas 52-58)
  - Wishlist AJAX endpoints (líneas 63-128)
  - Tracking, smart-features, recommendations, serie-landing, cart-enhancements, checkout-accordion, checkout-pudo, metro-pickup, cloudflare-purge, google-business-schema, filters-enhanced, seo, blog, blog-webp, BACS, newsletter, shortcode-editoriales, pack-serie, blog-cta-product, legacy-redirects, blog-product-cta, product-schema, sitemap-indexing, health, bluex-webhook, rest-cart, admin (líneas 131-286)
  - SVG upload support, Twitter card, manifest, Google verification, hreflang (líneas 289-322)

- Si Cell H elimina solo `inc/encargos.php` SIN tocar `functions.php`, el `require_once AKIBARA_THEME_DIR . '/inc/encargos.php'` en línea 134 del `functions.php` prod quedaría apuntando a un archivo inexistente → fatal error en cada page load (`require_once` es bloqueante, no es `include_once`). Tienda completa caída.

**Conclusión:** Opción A no es ejecutable de forma segura en Sprint 3.5 sin un sync controlado previo del `functions.php` real desde `server-snapshot/` al repo akibara-v2.

### Por qué NO crear `functions.php` en akibara-v2 ahora

Crear un nuevo `wp-content/themes/akibara/functions.php` en el repo akibara-v2 (aunque sea para "agregar el require condicional" como propone el RFC línea 64-71) es la operación más peligrosa posible en este momento:

1. El deploy actualmente sincroniza `wp-content/themes/akibara/` del repo al servidor (asumiendo `bin/deploy.sh` o equivalente, per `project_deploy_workflow_docker_first.md`).
2. Un `functions.php` recién creado en akibara-v2 contendría únicamente el snippet del RFC (~6 LOC), no las 322 LOC del archivo prod.
3. Deploy → overwrite del archivo real → tienda en estado degradado o caída total.

Esta clase de error es exactamente el patrón que el guard del Sprint 3 (`AKB_PREVENTAS_ENCARGOS_LOADED return;`) busca evitar: cambios al tema sin entender que el árbol del repo y el árbol prod están desincronizados.

### Tasks Sprint 4 follow-up

- **TASK-S4-THEME-01** (P1, owner Cell H): Sync controlado del `functions.php` y archivos faltantes desde `server-snapshot/public_html/wp-content/themes/akibara/` al repo `wp-content/themes/akibara/`. Plan obligatorio:
  - Diff explícito archivo por archivo antes de copiar.
  - Validar mtime y contenido contra prod live (no asumir que `server-snapshot/` está al día — usar `bin/wp-ssh ls -la wp-content/themes/akibara/` o checksums).
  - Cherry-pick del cambio del RFC: agregar el require condicional `if ( ! defined( 'AKB_PREVENTAS_ENCARGOS_LOADED' ) ) { require_once ... }` SOLO en línea 134 del `functions.php` sync'd.
  - Test en staging (`staging.akibara.cl`, ver `project_staging_subdomain.md`) antes de prod.
  - PR review obligatorio + smoke E2E.

- **TASK-S4-THEME-02** (P3, owner Cell H, gated por TASK-S4-THEME-01 + 7 días Sentry verde): Una vez `functions.php` está sincronizado en akibara-v2 y el require condicional verificado en staging, evaluar Opción A (eliminar `inc/encargos.php` físicamente). Trade-off: pierde el fallback legacy si plugin desactivado. Decisión condicionada a:
  - Confianza alta en uptime de `akibara-preventas` (sin reactivaciones manuales en últimos 30 días).
  - Cobertura E2E completa de flow encargos via plugin (`tests/e2e/critical/preorder-flow-*.spec.ts` ya scaffolded).
  - Sentry 7 días verde post-sync sin errors relacionados a encargos.

- **TASK-S4-THEME-03** (P2, owner Cell H, dependencia de TASK-S4-THEME-01): Documentar en `wp-content/themes/akibara/README.md` (a crear) la política de sync repo↔prod del tema. Este RFC es el primer caso donde la desincronización causó un bloqueador real; debe quedar capturado para futuros sprints.

---

## CORE-CHANGE RFCs

**Cero RFCs `CORE-CHANGE-*` en Sprint 3.** No se modificó el plugin núcleo `wp-content/plugins/akibara/`. No se justifica abrir branch `feat/core-s3.5` ni convocar arbitración de Lock Release sobre el core.

El template `audit/sprint-3/rfc/_TEMPLATE-CORE-CHANGE.md` permanece como referencia para Sprint 4+.

---

## Riesgos residuales declarados

1. **Desincronización repo↔prod del tema (alto).** El estado de `wp-content/themes/akibara/` en akibara-v2 es subset estricto de prod. Cualquier sprint que toque el tema sin un sync previo arriesga overwrite de prod. Mitigación: TASK-S4-THEME-01 + TASK-S4-THEME-03.
2. **Guard de defensa en profundidad reduce, no elimina, el riesgo de redeclare (bajo).** Si en algún momento el plugin `akibara-preventas` cambia el nombre de la constante `AKB_PREVENTAS_ENCARGOS_LOADED`, el guard del tema dejaría de funcionar y volvería el fatal error. Mitigación: añadir test unit/integration que verifique la presencia de la constante post-bootstrap del plugin (Sprint 4 Cell A).
3. **Dual-write encargos (medio).** El módulo del plugin escribe simultáneamente en `wp_options.akibara_encargos_log` (legacy) y `wp_akb_special_orders` (nuevo). Sin gate temporal, esto se mantendrá indefinidamente. Sprint 4+ debe definir cuándo deprecar la escritura legacy. Out-of-scope para Sprint 3.5.

---

## Sign-off

- **mesa-15-architect-reviewer:** APPROVED — 2026-04-27. Decisión Opción B confirmada por análisis arquitectónico (sync repo↔prod incompleto bloquea Opción A en esta ventana). Tasks Sprint 4 documentadas para resolver la causa raíz.
- **mesa-01-lead-arquitecto:** APPROVED — 2026-04-27. Co-firma del análisis. Sprint 3.5 cierra Lock Release sin cambios al tema; cualquier limpieza queda diferida a Sprint 4 con plan controlado.
- **Akibara Owner:** APPROVED — 2026-04-27 (delegated direct approval registrada en RFC original `audit/sprint-3/rfc/THEME-CHANGE-01.md:7-9, 53-60`).
