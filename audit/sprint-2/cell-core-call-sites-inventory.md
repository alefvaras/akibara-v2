# Cell Core Call-Sites Inventory — Sprint 2 Condition #1

**Fecha:** 2026-04-27
**Origen:** `server-snapshot/public_html/wp-content/` (read-only mirror prod)
**Scope:** 4 zonas externas → 13 módulos foundation a extraer a `akibara-core/`
**Sprint 2 condition:** #1 (de 5) per `audit/sprint-2/ARCHITECTURE-PRE-REVIEW.md`
**Método:** Explore agent (very thorough) + spot-check `grep` validation manual
**Output:** este archivo, listo para guiar Cell Core extraction weeks 2-3

---

## Resumen ejecutivo

- **~200 referencias externas** identificadas hacia los 13 módulos foundation
- **70%** ya tienen `function_exists()` / `class_exists()` defensive guards en consumers
- **30% sin guards** — concentradas en series-autofill (P0) + rut/phone fields (P1) + health-check fallback (P1)
- **Orden recomendado de extracción:** Fase 1 (5 módulos cero-refs) → Fase 2 (3 con guards OK) → Fase 3 (3 medium) → Fase 4 (series-autofill, último)
- **Bloqueadores hard pre-extracción:** ninguno — todos los módulos extraibles con defensive upgrades menores

| Zona | Total refs | Mayor consumidor |
|---|---|---|
| `themes/akibara/` | ~150 | series-autofill (15 files, ~50 refs) |
| `mu-plugins/` | ~5 | health-check (akibara-core-helpers comment) |
| `plugins/akibara-reservas/` | ~10 | email-template (5 templates con guards) |
| `plugins/akibara-whatsapp/` | 0 | n/a |

---

## Inventory por módulo (orden por risk profile)

### 1. search — RISK: P3 (cero refs)

**Refs en theme/:** 0 directo. `themes/akibara/inc/smart-features.php` lee transient `akibara_search_buffer` pero NO llama AJAX action ni función del módulo.

**Refs en akibara-reservas/, akibara-whatsapp/, mu-plugins/:** 0

**Defensive guards:** N/A (sin call-sites)

**Migration concerns:** Safe. AJAX endpoint `wp_ajax_akibara_search` y `wp_ajax_nopriv_akibara_search` son consumidos por JS frontend pero el handler PHP es interno al módulo.

---

### 2. category-urls — RISK: P3 (cero refs)

**Refs externas:** 0 en las 4 zonas

**Defensive guards:** N/A

**Migration concerns:** Safe. Rewrite rules y query vars internos al plugin.

---

### 3. order (catálogo ordering por tomo) — RISK: P3 (cero refs)

**Refs externas:** 0 en las 4 zonas

**⚠️ Nota correctiva:** El Explore agent inicial confundió `akibara_order_is_metro_pickup()` (función del theme en `inc/metro-pickup-notice.php:46`) con el módulo `Akibara_Order` del plugin (ordenamiento catálogo por tomo). Son cosas distintas. Validation `grep -rn 'Akibara_Order\|akibara_order_'` confirma cero refs externas al módulo.

**Defensive guards:** N/A

**Migration concerns:** Safe. Hooks `woocommerce_get_catalog_ordering_args` y `woocommerce_default_catalog_orderby_options` son internos.

---

### 4. email-safety — RISK: P3 (cero refs)

**Refs externas:** 0 en las 4 zonas

**Defensive guards:** N/A

**Migration concerns:** Safe. Module hooks `phpmailer_init` y `wp_mail` filter — todo interno. NOTA: orden de carga vs `mu-plugins/akibara-brevo-smtp.php` matters (mu-plugins cargan ANTES que plugins). Validar prioridades hooks `phpmailer_init`.

---

### 5. customer-edit-address — RISK: P3 (cero refs)

**Refs externas:** 0 en las 4 zonas

**Defensive guards:** N/A

**Migration concerns:** Safe. Hooks `woocommerce_after_edit_account_address_form` internos.

---

### 6. address-autocomplete — RISK: P3 (solo CSS comment)

**Refs en theme/:**
- `themes/akibara/assets/css/checkout.css:88` — comment "lo hace el JS en address-autocomplete-v2 + un listener global aquí abajo" [COMMENT ONLY, sin código]

**Refs en akibara-reservas/, akibara-whatsapp/, mu-plugins/:** 0

**Defensive guards:** N/A (sin hard calls)

**Migration concerns:** Safe. JS enqueue interno al módulo; theme solo coordina via CSS.

---

### 7. email-template — RISK: P2 (refs guarded OK)

**Refs en theme/:** 0

**Refs en akibara-reservas/:** 5 templates con `class_exists('AkibaraEmailTemplate')` guard:
- `templates/emails/reserva-lista.php:17`
- `templates/emails/reserva-cancelada.php:17`
- `templates/emails/reserva-confirmada.php:17`
- `templates/emails/nueva-reserva.php:19`
- `templates/emails/reserva-fecha-cambiada.php:19`

Todos siguen patrón: `$has_template = class_exists('AkibaraEmailTemplate'); if ( $has_template ) { echo AkibaraEmailTemplate::headline(...); ... }` (graceful degradation).

**Refs en akibara-whatsapp/, mu-plugins/:** 0

**Defensive guards:** ✅ SÍ — patrón excelente ya implementado en TODOS los call-sites

**Migration concerns:**
- Class name `AkibaraEmailTemplate` MUST exportarse a `akibara-core` con MISMO nombre (bridge alias OK) o actualizar los 5 templates
- Recomendación: keep `AkibaraEmailTemplate` como alias bridge a `Akibara\Core\Infra\EmailTemplate`

---

### 8. product-badges — RISK: P2 (refs guarded OK)

**Refs en theme/:**
- `themes/akibara/setup.php:161-162` — `if ( function_exists( 'akb_plugin_get_discount_pct' ) ) { return akb_plugin_get_discount_pct( $product ); }` [GUARDED ✅]
- `themes/akibara/setup.php:175` (similar pattern para `akb_plugin_render_badges`) [GUARDED ✅]
- `themes/akibara/inc/setup.php:161,175` — duplicado del mismo pattern (duplicate file inc/ vs root)

Comment en theme: *"Wrapper defensivo — la implementación vive en el plugin Akibara (modules/product-badges/module.php)"*

**Refs en akibara-reservas/, akibara-whatsapp/, mu-plugins/:** 0

**Defensive guards:** ✅ SÍ

**Migration concerns:**
- Function names `akb_plugin_get_discount_pct` y `akb_plugin_render_badges` deben exportarse con mismos nombres
- Atención: hay duplicate `setup.php` en theme root y `inc/setup.php` (B-S1-CLEAN-03 lo eliminó pero verificar con `git status`)

---

### 9. checkout-validation — RISK: P2 (asset enqueue)

**Refs en theme/:**
- `themes/akibara/inc/woocommerce.php:1050-1051` — `wp_enqueue_script('akb-checkout-validation', AKIBARA_THEME_URI . '/assets/js/checkout-validation.js', ...)` [theme-local asset, NO PHP function call]
- `themes/akibara/inc/enqueue.php` — comment referencing checkout-validation.js
- `themes/akibara/inc/checkout-pudo.php:94` — comment "checkout-validation/module.php + checkout-validation.js"
- `themes/akibara/assets/css/checkout.css` — comments

**Refs en akibara-reservas/, akibara-whatsapp/, mu-plugins/:** 0

**Defensive guards:** N/A — solo asset enqueue, no PHP coupling

**Migration concerns:** Safe. JS asset es theme-local. PHP validation hooks `woocommerce_checkout_process` quedan en akibara-core.

---

### 10. health-check — RISK: P1 (theme fallback hardcodes plugin slug)

**Refs en theme/:**
- `themes/akibara/inc/health.php:15` — `defined('AKIBARA_HEALTH_CHECK_LOADED')` [GUARD ✅]
- `themes/akibara/inc/health.php:15,20` — `function_exists('akibara_health_check')` [GUARD ✅]
- `themes/akibara/inc/health.php:85` — array de critical_plugins incluye literal `'akibara/akibara.php'` [HARDCODED slug]

**Refs en mu-plugins/:**
- `akibara-core-helpers.php` — comment refiere a `akb_log_last_critical` option ("para que health-check lo detecte")

**Refs en akibara-reservas/, akibara-whatsapp/:** 0

**Defensive guards:** ✅ Function existence guard OK; ❌ Plugin slug hardcoded

**Migration concerns:**
- Theme implementa endpoint fallback `/wp-json/akibara/v1/health` cuando módulo no carga
- **Si extraemos a akibara-core:** REST endpoint debe responder al mismo path (`/akibara/v1/health`) por backward compat — NO renombrar a `/akibara-core/v1/health`
- **Hardcoded `'akibara/akibara.php'` slug check** en theme: si plugin akibara/ legacy se desactiva tras migración, health.php reportará "akibara plugin missing". Cambiar a `'akibara-core/akibara-core.php'` post-migración OR check ambos
- `akb_log_last_critical` option debe seguir accesible desde mu-plugin

---

### 11. rut — RISK: P1 (field key hardcoded sin isset guard)

**Refs en theme/:**
- `themes/akibara/woocommerce/checkout/form-checkout.php:170` — `get_user_meta($uid, 'billing_rut', true)` [meta key direct]
- `themes/akibara/inc/woocommerce.php:733-735` — field config para `billing_rut` (priority/placeholder) [HARDCODED]
- `themes/akibara/inc/clarity.php:65` — array PII fields incluye `'billing_rut'`
- `themes/akibara/assets/js/checkout-validation.js` — `if (id === 'billing_rut')` [JS field detection]
- `themes/akibara/assets/js/checkout-steps.js` — 3 ocurrencias `attr("id") === "billing_rut"`

**Refs en akibara-reservas/, akibara-whatsapp/, mu-plugins/:** 0

**Defensive guards:** ❌ NO — field key hardcoded sin `isset()` check

**Migration concerns:**
- RUT field es REQUIRED en checkout Chile
- Theme asume `$fields['billing']['billing_rut']` existe sin `isset()` validation
- Si rut module no carga, theme intenta config-ar field inexistente → PHP notices
- **Fix pre-migration:** wrap field config en `if ( isset( $fields['billing']['billing_rut'] ) )` en `inc/woocommerce.php:733-735`
- **OR** asegurar akibara-core registra field en `woocommerce_checkout_fields` priority **menor** que el priority del theme (theme usa default 10, registrar field en priority 5)
- JS validation está OK — no requiere cambios

---

### 12. phone — RISK: P1 (similar a rut)

**Refs en theme/:**
- `themes/akibara/inc/woocommerce.php:733-735` — field config `billing_phone` [HARDCODED]
- `themes/akibara/inc/clarity.php:65` — array incluye `'billing_phone'`
- `themes/akibara/assets/css/checkout.css:322,342` — selectors `#billing_phone_field`

**Refs en akibara-reservas/:**
- `plugins/akibara-reservas/includes/class-reserva-orders.php:364` — `$order->get_billing_phone()` [WC native method, NO module-specific]

**Refs en akibara-whatsapp/, mu-plugins/:** 0

**Defensive guards:** ❌ NO

**Migration concerns:** Mismo patrón que rut. Field key hardcoded; CSS selectors OK; aplicar mismo fix `isset()` o priority 5.

---

### 13. series-autofill — RISK: P0 (CRITICAL — 50+ refs sin guards consistentes)

**Heaviest consumer.** 15 archivos del theme dependen de `_akibara_serie` y/o `_akibara_serie_norm` post_meta + funciones `akibara_series_*`.

**Refs en theme/ (categorizadas):**

**A. Direct `get_post_meta()` con key `_akibara_serie[_norm]` — sin guards (P0):**
- `inc/blog-cta-product.php:124`
- `inc/blog-product-cta.php:3 refs`
- `inc/cart-enhancements.php:47-49` (2 refs)
- `inc/pack-serie.php` (3 refs)
- `inc/serie-landing.php` (9 refs — meta queries directas)
- `inc/recommendations.php` (7 refs)
- `inc/series-hub.php` (2 refs)
- `inc/smart-features.php` (6 refs)
- `inc/seo/schema-collection.php:52-53` (2 refs)
- `inc/seo/schema-faq.php:45,51` (3 refs)
- `inc/seo/schema-product.php:423-425` (3 refs)
- `template-parts/content/mini-cart.php` (1 ref)
- `inc/woocommerce.php` (2 refs)

**B. Función calls a `akibara_series_*` — guards parciales:**
- `woocommerce/single-product.php:48` — `function_exists('akibara_render_series_hub')` [GUARDED ✅]
- `template-parts/single-product/related.php:24,55` — `function_exists('akibara_get_smart_recommendations')`, `function_exists('akibara_get_primary_genre_name')`, `function_exists('akibara_get_genre_popular')` [GUARDED ✅]
- `inc/series-hub.php` — `akibara_series_hub_cache_version()`, `akibara_series_hub_get_source()`, `akibara_series_hub_get_data()`, `akibara_render_series_hub()`, `akibara_ajax_load_series_hub()` [partial guards en hook declarations, NO en call-sites internos]

**Refs en akibara-reservas/, akibara-whatsapp/, mu-plugins/:** 0

**Defensive guards:** ⚠️ PARCIAL — guards existen para function calls a `akibara_render_series_hub` y similares, PERO los `get_post_meta()` directos asumen meta existe sin validación

**Migration concerns (CRITICAL):**
- Si module no carga, `update_post_meta(_akibara_serie_norm)` NO se ejecuta → theme queries retornan vacío → layouts degradan silently
- **Functions a exportar a akibara-core con mismos nombres:**
  - `akibara_series_hub_cache_version()`
  - `akibara_series_hub_get_source()`
  - `akibara_series_hub_get_data()`
  - `akibara_render_series_hub()`
  - `akibara_ajax_load_series_hub()` (AJAX action)
  - `akibara_get_smart_recommendations()`
  - `akibara_get_primary_genre_name()`
  - `akibara_get_genre_popular()`
- **Meta keys a preservar:**
  - `_akibara_serie`
  - `_akibara_serie_norm`
- **Options/transients a preservar:**
  - `akb_series_hub_cache_version`
  - `akb_sh_*` (caches)
  - `akibara_series_*` (transients)

**Recomendación EXTRAcción:**
- **EXTRACTAR ÚLTIMO** — después que infraestructura core estable
- Implementar theme helper PRE-extraction:
  ```php
  if ( ! function_exists( 'akibara_has_series_data' ) ) {
      function akibara_has_series_data( int $product_id ): bool {
          $serie = get_post_meta( $product_id, '_akibara_serie_norm', true );
          return ! empty( $serie );
      }
  }
  ```
- **NO** extraer hasta que tests verifiquen post_meta `_akibara_serie_norm` se sigue popula correctamente (smoke con productos serie reales: One Piece, Jujutsu Kaisen, Berserk)
- **Migration class** (`Akibara_Series_Migration` mencionado en CLEAN-017) → mover a `legacy/migration-cli.php` como WP-CLI command (NO auto-run)

---

## Defensive guards inventario

### Guards implementados (OK):

| Pattern | Location | Module guarded |
|---|---|---|
| `function_exists('akb_plugin_get_discount_pct')` | `themes/akibara/setup.php:161` | product-badges |
| `function_exists('akb_plugin_render_badges')` | `themes/akibara/setup.php:175` | product-badges |
| `class_exists('AkibaraEmailTemplate')` | `plugins/akibara-reservas/templates/emails/*.php` (5 files) | email-template |
| `function_exists('akibara_render_series_hub')` | `themes/akibara/woocommerce/single-product.php:48` | series-autofill (partial) |
| `function_exists('akibara_get_smart_recommendations')` | `template-parts/single-product/related.php:24` | series-autofill (partial) |
| `function_exists('akibara_get_primary_genre_name')` | `template-parts/single-product/related.php:55` | series-autofill (partial) |
| `function_exists('akibara_get_genre_popular')` | `template-parts/single-product/related.php:55` | series-autofill (partial) |
| `defined('AKIBARA_HEALTH_CHECK_LOADED')` | `themes/akibara/inc/health.php:15` | health-check (detection) |
| `function_exists('akibara_health_check')` | `themes/akibara/inc/health.php:20` | health-check (detection) |
| `class_exists('Akibara_Reserva_*')` | `themes/akibara/woocommerce/myaccount/dashboard.php` | akibara-reservas (no scope core) |

### Guards FALTANTES (FIX antes/durante extracción):

| Module | Location | Issue | Fix |
|---|---|---|---|
| rut | `themes/akibara/inc/woocommerce.php:733-735` | Hardcoded `billing_rut` field key | Wrap en `isset($fields['billing']['billing_rut'])` o registrar field en priority 5 |
| phone | `themes/akibara/inc/woocommerce.php:733-735` | Hardcoded `billing_phone` | Mismo patrón rut |
| series-autofill | 13 archivos theme | `get_post_meta('_akibara_serie_norm')` directo sin validation | Add `akibara_has_series_data()` helper + guard antes de queries |
| health-check | `themes/akibara/inc/health.php:85` | Hardcoded slug `'akibara/akibara.php'` | Check ambos slugs post-migración |

---

## Constantes AKB_* y AKIBARA_*

| Constante | Definida en | Consumida en | Scope |
|---|---|---|---|
| `AKIBARA_THEME_VERSION` | `themes/akibara/functions.php:11` | 5+ theme files | theme version |
| `AKIBARA_ASSET_VER` | `themes/akibara/functions.php:20` | `functions.php:26` | asset cache version |
| `AKIBARA_THEME_DIR` / `AKIBARA_THEME_URI` | WP constants | 30+ theme files | theme paths |
| `AKIBARA_HEALTH_CHECK_LOADED` | (definido por módulo health-check al cargar) | `themes/akibara/inc/health.php:15` | health detection signal — **MUST preservar en akibara-core** |
| `AKIBARA_RESERVAS_LOADED` | `plugins/akibara-reservas/akibara-reservas.php:16` | self-guard | (no scope core) |
| `AKB_BREVO_API_KEY` / `AKIBARA_BREVO_API_KEY` | `wp-config-private.php` (Sprint 1 EMAIL-03) | `mu-plugins/akibara-brevo-smtp.php:44,47` | (no scope core) |
| `AKB_BLUEX_WEBHOOK_SECRET` | `wp-config-private.php` | `themes/akibara/inc/bluex-webhook.php:16` | (no scope core, theme-owned) |
| `AKIBARA_METRO_PICKUP_WA` | `themes/akibara/inc/metro-pickup-notice.php` | self + `inc/woocommerce.php:1163` | (no scope core, theme) |
| `AKIBARA_WA_PHONE_DEFAULT` | `plugins/akibara-whatsapp/akibara-whatsapp.php:14` | self | (no scope core) |

**Conclusión constantes:** Solo `AKIBARA_HEALTH_CHECK_LOADED` requiere preservación durante migración a akibara-core.

---

## Hooks/filtros custom emitidos

Audit de `do_action('akibara_*'|'akb_*')` y `apply_filters('akibara_*'|'akb_*')` emitidos por theme + plugins. **CERO listeners externos detectados** — todos los hooks custom son potencial extension points pero actualmente sin consumers cross-plugin.

| Hook | Tipo | Emitido por | Listeners |
|---|---|---|---|
| `akibara_hero_upload_url_subdir` | filter | `themes/akibara/hero.php:15`, `inc/hero-preload.php:24` | none |
| `akibara_hero_files` | filter | `themes/akibara/hero.php:22` | none |
| `akibara_contact_email` | filter | `themes/akibara/page-contacto.php:65` | none |
| `akibara_before_footer` | action | `themes/akibara/footer.php:1` | none |
| `akibara_footer_brand_after` | action | `themes/akibara/footer.php:86` | none |
| `akibara_homepage_after_latest` | action | `front-page.php:75` | none |
| `akibara_homepage_after_bestsellers` | action | `front-page.php:142` | none |
| `akibara_email_preheader` | filter | `woocommerce/emails/email-header.php:75` | none |
| `akibara_checkout_pudo_selector` | action | `woocommerce/checkout/form-checkout.php:211` | none |
| `akibara_ship_unified_grid` | filter | `inc/checkout-accordion.php:632` | none |
| `akb_auto_trim_should_apply` | filter | `inc/image-auto-trim.php:49` | none |
| `akibara_pack_discount_pct` | filter | `inc/pack-serie.php:95` | none |
| `akibara_product_trust_signals` | filter | `inc/woocommerce.php:1301` | none |
| `akibara_notify_sheet_queued` | action | `template-parts/content/product-card.php:205` | none |
| `akb_reserva_fecha_cambiada` | action | `plugins/akibara-reservas/includes/class-reserva-admin.php:687` | (internal) |
| `akb_reserva_editoriales` | filter | `plugins/akibara-reservas/includes/functions.php:60` | (internal) |
| `akb_circuit_ttl` | filter | `mu-plugins/akibara-core-helpers.php:108` | (internal) |
| `akibara_indexnow_types` | filter | `mu-plugins/akibara-indexnow.php:46` | none |

**Implicación migration:** ningún hook emitido por akibara/ se consume cross-plugin externamente. Safe to refactor sin coordination con consumers.

---

## Asset paths cross-plugin

Audit `plugins_url()` y `WP_PLUGIN_URL` apuntando a paths de plugin akibara/.

**Resultado:** ✅ **CERO asset paths cross-plugin detectados.**

| Asset | Definido en | Path Type |
|---|---|---|
| `akb-checkout-validation` JS | `themes/akibara/inc/woocommerce.php:1050-1051` | theme-local (`AKIBARA_THEME_URI`) |
| `series-hub-v2.js` | `themes/akibara/inc/series-hub.php:42` (LSCWP excl) | theme-local |

**Conclusión:** assets son theme-local o internos al plugin. Safe migration sin URL rewrites.

---

## Cron events cross-plugin

Verificar via `bin/wp-ssh cron event list` post-extracción que ningún cron registrado contra `akibara/akibara.php` plugin path queda huérfano. Crons identificados que pueden tocar módulos foundation:

| Cron hook | Owner module | Migration risk |
|---|---|---|
| `akb_bluex_logs_purge` | mu-plugin (Sprint 1) | none — no toca core |
| `akibara_series_hub_recompute` (probable) | series-autofill | MUST verificar al migrar |
| `akb_health_log_critical` (probable) | health-check | MUST verificar al migrar |

**Acción:** Cell Core extraction — durante migration módulo a módulo, ejecutar `bin/wp-ssh cron event list | grep akibara` ANTES y DESPUÉS de cada migración. Si callback class movió de namespace, `wp_unschedule_event` + `wp_schedule_event` re-bind.

---

## Riesgos identificados (top 5)

| # | Risk | Severidad | Probabilidad | Mitigación |
|---|---|---|---|---|
| 1 | series-autofill metadata `_akibara_serie_norm` no popula post-migración → theme breaks 15 archivos silenciosamente | P0 | Media-Alta | Theme helper `akibara_has_series_data()` + extracting LAST + smoke con producto serie |
| 2 | RUT/Phone fields no registran si akibara-core load priority > theme priority → checkout sin validación CL | P1 | Media | Registrar fields en `woocommerce_checkout_fields` priority 5 (default theme = 10) |
| 3 | Health-check endpoint `/akibara/v1/health` no responde post-migración por slug change → monitoring breaks | P1 | Media | Preservar `register_rest_route('akibara/v1', 'health', ...)` exact path; update theme health.php hardcoded slug check |
| 4 | Cron events orphaned tras class namespace change | P1 | Baja-Media | `wp_unschedule_event` + re-schedule en `register_activation_hook` de akibara-core |
| 5 | `class_exists('AkibaraEmailTemplate')` falla porque clase movió a `Akibara\Core\Infra\EmailTemplate` namespace → emails reservas degradan a fallback | P2 | Baja con bridge | Bridge alias: `class_alias('Akibara\Core\Infra\EmailTemplate', 'AkibaraEmailTemplate')` durante transición |

---

## Recomendaciones para Cell Core extraction

### Orden de extracción por fase (risk-stratified)

**Fase 1 — Cero refs externas (extraer primero, batch único, low risk):**
1. `search`
2. `category-urls`
3. `order` (catálogo ordering)
4. `email-safety`
5. `customer-edit-address`
6. `address-autocomplete`

**Fase 2 — Refs externas con guards OK (low risk, batch):**
7. `email-template` (5 templates con `class_exists` guards) → exportar + `class_alias` bridge
8. `product-badges` (2 functions con `function_exists` guards) → exportar mismo nombre
9. `checkout-validation` (asset-only coupling)

**Fase 3 — Defensive upgrades requeridos (medium risk, atomic per módulo):**
10. `health-check` — preservar REST path `/akibara/v1/health` + update theme slug check post-migración
11. `rut` — registrar field en priority 5 (antes que theme priority 10) + opcional `isset()` guard en theme
12. `phone` — mismo patrón rut

**Fase 4 — Critical, last (alto riesgo, dedicated PR):**
13. `series-autofill` — implementar theme helper `akibara_has_series_data()` PRE-extracción → exportar functions con mismos nombres → extraer → smoke exhaustivo con productos serie reales (One Piece, Jujutsu Kaisen, Berserk) → validar `_akibara_serie_norm` se popula → migration class CLEAN-017 a `legacy/migration-cli.php`

### Defensive guard helper — `AKB_REQUIRE_CORE()` pattern

Crear en akibara-core:

```php
// plugins/akibara-core/includes/api-public.php

if ( ! function_exists( 'akb_core_loaded' ) ) {
    function akb_core_loaded(): bool {
        return defined( 'AKIBARA_CORE_VERSION' );
    }
}

if ( ! function_exists( 'akb_core_module_loaded' ) ) {
    function akb_core_module_loaded( string $module ): bool {
        return akb_core_loaded() && defined( 'AKB_CORE_MODULE_' . strtoupper( $module ) . '_LOADED' );
    }
}
```

**Uso en theme post-migración:**

```php
if ( ! akb_core_module_loaded( 'series_autofill' ) ) {
    return; // graceful degradation
}
// proceed with series-dependent rendering
```

### Atomic migration per módulo (critical pattern)

Cada migración módulo-a-módulo debe ser **atomic deploy** (no parcial):

1. **PRE:** smoke prod 20/20 GREEN
2. **Module en akibara-core:** clase + namespace + tests
3. **Plugin akibara/ legacy:** module DEACTIVATED en mismo deploy (no doble registro hooks)
4. **Theme guards:** `function_exists()` actualizado si nombres cambian
5. **Smoke prod 20/20 + 24h Sentry GREEN** antes de empezar siguiente módulo

### Post-extraction validation checklist

Antes de cerrar cada Phase:

- [ ] `bin/wp-ssh plugin list | grep -E 'akibara|akibara-core'` → ambos active
- [ ] `bin/wp-ssh eval 'echo class_exists("Akibara\\\\Core\\\\ServiceLocator") ? "OK":"FAIL";'` → OK
- [ ] `curl https://akibara.cl/wp-json/akibara/v1/health` → JSON 200 (Phase 3 onwards)
- [ ] `bin/wp-ssh cron event list | grep akibara` → todos resuelven a callbacks vivos
- [ ] Smoke prod 20/20 PASS
- [ ] Sentry 24h GREEN (`mcp__sentry__search_issues organizationSlug=akibara`)
- [ ] HANDOFF.md actualizado con módulo completed + RFCs pendientes

### Mu-plugins load-bearing — orden de carga

mu-plugins cargan **antes** que plugins. Verificar que ningún módulo extraído a akibara-core depende de algo que mu-plugin emite **después** del init:

| mu-plugin | Hook timing | Conflict risk con akibara-core |
|---|---|---|
| `akibara-brevo-smtp.php` | `phpmailer_init` (priority 100) | email-safety hook order — verificar |
| `akibara-sentry-customizations.php` | `init` early | none |
| `akibara-email-testing-guard.php` | `wp_mail` filter | email-safety overlap — verificar prioridades |
| `akibara-core-helpers.php` | `plugins_loaded` | helpers básicos, low risk |

**Acción:** Cell Core PR #1 (Fase 1) debe incluir smoke email test:
```bash
bin/wp-ssh eval 'wp_mail("alejandro.fvaras@gmail.com","S2 Cell Core smoke","ok");'
# Verificar: (a) llega vía Brevo SMTP, (b) destinatario es alejandro.fvaras@gmail.com, (c) Sentry breadcrumbs preservados
```

---

## Conclusiones finales

**Surface analysis:** ~200 referencias identificadas, 70% ya defensively guarded. Migration es viable con defensive upgrades menores en 4 áreas (rut/phone fields, health-check slug, series-autofill helper).

**Critical path:** series-autofill es módulo de máxima complejidad externa. Theme helper + extracción LAST + smoke exhaustivo mitigan risk P0.

**Next conditions Sprint 2 (post #1):**
- #2 Plan serialización 4 CLEAN destructivos (1 per week + 24h Sentry gap)
- #4 `bin/sync-staging.sh` versionado + PII anonymization assertion
- #5 Verify `wc hpos status` antes HPOSFacade

**Sin bloqueadores hard.** Cell Core extraction puede arrancar weeks 2-3 una vez completados conditions #2/#4/#5 + B-S2-INFRA-01 staging.

---

**FIN INVENTORY. Próximo paso: condition #5 (verify HPOS status, 5 min) — pequeño y rápido.**
