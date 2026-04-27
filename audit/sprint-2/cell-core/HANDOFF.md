# Cell Core Phase 1 — HANDOFF para Sprint 3+

**Sprint 2 cierre:** 2026-04-27 ~13:30 UTC-4
**Status:** ✅ Deployed prod (commits 703faee + 782d80a + ad3c60f en main)
**Plugin:** `wp-content/plugins/akibara-core/` v1.0.0
**Mu-plugin loader:** `wp-content/mu-plugins/akibara-00-core-bootstrap.php`

Este archivo documenta la **API pública de akibara-core** que las cells Sprint 3+ pueden consumir READ-ONLY. Si una cell necesita extender la API, abrir RFC en `audit/sprint-{N}/rfc/CORE-CHANGE-{NN}.md`.

---

## 1. Constants disponibles globalmente

| Constant | Type | Set when | Where |
|---|---|---|---|
| `AKIBARA_CORE_PLUGIN_LOADED` | `true` | mu-plugin fires (very early WP boot) | akibara-core.php:36 |
| `AKIBARA_CORE_VERSION` | string `'1.0.0'` | file-include time | akibara-core.php:37 |
| `AKIBARA_CORE_DIR` | path string | file-include time | akibara-core.php:38 |
| `AKIBARA_CORE_URL` | URL string | file-include time | akibara-core.php:39 |
| `AKIBARA_CORE_FILE` | __FILE__ | file-include time | akibara-core.php:40 |
| `AKB_TABLE` | string `'wp_akibara_index'` | search.php loaded | includes/akibara-search.php |
| `AKB_SEARCH_LOADED` | string `'10.0.0'` | search.php file-level guard | includes/akibara-search.php |
| `AKB_CORE_CATEGORY_URLS_LOADED` | string `'1.0.0'` | category-urls.php loaded | includes/akibara-category-urls.php |
| `AKIBARA_ORDER_LOADED` | string `'10.0.0'` | order.php loaded | includes/akibara-order.php |
| `AKB_CORE_EMAIL_SAFETY_LOADED` | string `'1.0.0'` | email-safety.php loaded | includes/akibara-email-safety.php |
| `AKB_CORE_CEA_LOADED` | string `'1.0.0'` | customer-edit-address loaded | modules/customer-edit-address/module.php |
| `AKB_CORE_PLACES_LOADED` | string `'1.0.0'` | address-autocomplete loaded | modules/address-autocomplete/module.php |

---

## 2. Public functions (top-level, idempotent guards)

### akibara-core.php (main file)

```php
akb_core_file_loaded(): bool
// True cuando akibara-core.php ya parseó (constants defined). Útil para guards en addons.

akb_core_loaded(): bool
// @deprecated alias de akb_core_file_loaded().

akb_core_initialized(): bool
// True cuando Bootstrap::init() corrió (post plugins_loaded:5). ServiceLocator + ModuleRegistry ready.

akb_core_module_loaded(string $module): bool
// True si AKB_CORE_MODULE_<UPPER>_LOADED está defined.
// Ejemplo: akb_core_module_loaded('search') → checks AKB_CORE_MODULE_SEARCH_LOADED
```

### includes/akibara-search.php

```php
akb_sinonimos(): array
// Diccionario sinónimos editorial para búsqueda. Lazy-load desde data/sinonimos.php.

akb_create_index_table(): void
// dbDelta para wp_akibara_index. Idempotent.

akb_migrate_v10(string $table): void
// Schema migration v9→v10 (adds sku_norm, cats_norm, tags_norm, searchable, in_stock, total_sales).

akb_index_product(int $id): void
// Index single product into wp_akibara_index. Hook en save_post_product, woocommerce_update_product.

akb_rebuild_full_index(?callable $cb = null): array
// Rebuild masivo. Returns ['productos' => N, 'tiempo' => seconds].

akb_cache_version(): int
akb_bump_cache_version(): void
// Cache versioning para invalidación coordinada.

akb_search_has_fulltext(): bool
// Check si FULLTEXT index existe (transient cached).

akb_query_index(string $q, string $cat = ''): array
// Motor de búsqueda. Returns rows con title_orig, display_data, post_name.

akb_format_results(array $rows, string $q): array
// Format raw rows para REST/AJAX response.

akb_rest_handler(WP_REST_Request $req): WP_REST_Response
// Endpoint /wp-json/akibara/v1/search?q=...&cat=...

akb_rest_suggest_handler(WP_REST_Request $req): WP_REST_Response
// Endpoint /wp-json/akibara/v1/suggest?q=...

akb_get_suggestions(string $q): array
// Compute series + autores + trending suggestions max 6 items.
```

### includes/akibara-helpers.php

```php
akb_normalize(string $title, bool $compact = false): string
// Normaliza título: lowercase, sin tildes, sin puntuación. compact=true elimina espacios.

akb_strip_accents(string $text): string
// Solo strip accents (preserve case + spaces).

akb_editorial_pattern(): string
// Regex patterns editoriales (Ivrea, Panini, Planeta, Milky Way, etc.).

akb_edition_pattern(): string
// Regex patterns ediciones (Argentina, Spain, Special, Box Set, etc.).

akb_extract_info(string $titulo): array
// Parse título → ['serie', 'serie_norm', 'numero', 'tipo', 'prioridad'].
// Tipo: 'estandar' | 'compilacion' | 'formato_especial' | 'sin_numero'
```

### includes/akibara-order.php

```php
class Akibara_Order {
    const META_SERIE   = '_akibara_serie_norm';
    const META_NUMERO  = '_akibara_numero';
    const META_TIPO    = '_akibara_tipo';
    const CRON_HOOK    = 'akibara_reorder_cron';

    public static function init(): void;
    public static function extract_info(string $titulo): array;
    public static function write_product_meta(int $post_id): void;
    public static function run_reorder(bool $rebuild_meta = false): array;
    public static function on_product_save(int $post_id): void;
    public static function cron_reorder(): void;
    public static function default_orderby(): string;
    public static function catalog_args(array $args): array;
}
```

### modules/customer-edit-address/module.php

```php
akb_cea_editable_statuses(): array
// Default: ['pending', 'processing', 'on-hold']. Filterable via akb_cea_editable_statuses.

akb_cea_max_edits(): int
// Default: 3. Filterable via akb_cea_max_edits.

akb_cea_non_address_methods(): array
// ['local_pickup', 'akibara_metro', 'metro_san_miguel', 'pudoShipping']

akb_cea_can_edit(WC_Order $order): bool
akb_cea_user_can_access(WC_Order $order): bool
akb_cea_base_url(WC_Order $order): string
akb_cea_form_url(WC_Order $order): string
akb_cea_saved_url(WC_Order $order): string
akb_cea_render_form(WC_Order $order): void
```

### modules/address-autocomplete/module.php

```php
akb_places_is_enabled(): bool
// True si AKB_GOOGLE_MAPS_API_KEY defined + non-empty.

akb_places_get_key(): string
akb_places_region_map(): array
// Map admin_area_level_1 (Google) → WC ISO codes (CL-RM, CL-AP, etc.)

akb_places_should_enqueue(): bool
// True en checkout, order-received, view-order, edit-address endpoints.
```

---

## 3. PSR-4 namespace `Akibara\Core\*`

```php
\Akibara\Core\Bootstrap            // src/Bootstrap.php — singleton, init() idempotent
\Akibara\Core\ServiceLocator       // src/Container/ServiceLocator.php — register/get services
\Akibara\Core\ModuleRegistry       // src/Registry/ModuleRegistry.php — declare/all modules
```

**Acceso desde addons:**

```php
$bootstrap = \Akibara\Core\Bootstrap::instance();
$bootstrap->modules()->declare_module('mi-addon', '1.0.0', 'addon');
$bootstrap->services()->register('preorder.repository', new MyRepo());
```

**Hooks:**

```php
do_action('akibara_core_init', $bootstrap);  // priority 5 plugins_loaded
// Addons hook here to register services + modules
```

---

## 4. Database tables creadas/owned

| Table | Owner | Schema version | Records (prod) |
|---|---|---|---|
| `wp_akibara_index` | core/search | v10 | 1371 |

**Migration:** `akb_create_index_table()` es idempotent (dbDelta + migrate v9→v10 path).

---

## 5. REST endpoints exposed

| Endpoint | Method | Returns |
|---|---|---|
| `/wp-json/akibara/v1/search?q=&cat=` | GET | Array productos JSON |
| `/wp-json/akibara/v1/suggest?q=` | GET | Array sugerencias (series/autores/trending) max 6 |
| `/wp-json/akibara/v1/health` | GET | `{"status":"ok","timestamp":"..."}` |

---

## 6. WP Hooks emitted

| Hook | Type | Fires when |
|---|---|---|
| `akibara_core_init` | action | plugins_loaded:5 — Bootstrap initialized |
| `akb_core_module_<name>_loaded` | constant signal | module file-include done |

**Hooks consumed por core (NO emitir desde addons sin RFC):**

- `save_post_product`, `woocommerce_update_product` (search index)
- `before_delete_post`, `updated_post_meta` (search index invalidation)
- `init` priority 15 (category-urls register rewrite rules)
- `template_redirect` (404 cache control + customer-edit-address handler)
- `posts_search`, `posts_orderby` priority 150 (search injection)
- `woocommerce_default_catalog_orderby`, `woocommerce_get_catalog_ordering_args` (order)
- `phpmailer_init`, `wp_mail` (email-safety conditional)
- `wp_enqueue_scripts` (places autocomplete)
- `before_woocommerce_init` (HPOS compat declare)
- `plugins_loaded:4` (modules registry declarations)
- `plugins_loaded:5` (Bootstrap::init)

---

## 7. Plugin headers para addons (Sprint 3+)

**OBLIGATORIO** declarar dependency:

```php
/**
 * Plugin Name:       Akibara <Addon Name>
 * Plugin URI:        https://github.com/alefvaras/akibara-v2
 * Description:       <description>
 * Version:           1.0.0
 * Author:            Akibara
 * Text Domain:       akibara-<addon>
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  akibara-core
 * License:           GPL-2.0-or-later
 */
```

WP 6.5+ `Requires Plugins` header **garantiza load order** entre regular plugins (akibara-core carga ANTES que addons).

---

## 8. Defensa-en-profundidad load patterns (lessons learned Sprint 2)

Sprint 2 descubrió que **PHP hoists top-level function declarations a parse time**. Sentinel guards `if (function_exists()) return;` NO previenen redeclare cross-file.

**Patrón obligatorio para addons:** group wrap todas las top-level function declarations + hook registrations dentro de un `if ( ! function_exists( 'first_function' ) )` block.

```php
defined('ABSPATH') || exit;

// File-level guard
if (defined('AKB_MIADDON_LOADED')) {
    return;
}
define('AKB_MIADDON_LOADED', '1.0.0');

// Constants (always defined regardless)
if (!defined('AKB_MIADDON_TABLE')) {
    define('AKB_MIADDON_TABLE', $GLOBALS['wpdb']->prefix . 'mi_addon');
}

// Group wrap (functions inside NO se hoistean)
if ( ! function_exists( 'akb_miaddon_<sentinel>' ) ) {

    function akb_miaddon_<sentinel>() { ... }
    function akb_miaddon_other() { ... }

    add_action( 'init', 'akb_miaddon_<sentinel>' );

} // end group wrap
```

Ver postmortem completo en `audit/sprint-2/cell-core/REDESIGN.md` sección 9.

---

## 9. Lock policy Sprint 3+

`plugins/akibara-core/` es **READ-ONLY** desde cells verticales A/B/C/D.

Si una cell necesita cambio en Core:

1. Abrir RFC `audit/sprint-{N}/rfc/CORE-CHANGE-{NN}.md` (template en `audit/CELL-DESIGN-2026-04-26.md`)
2. mesa-15 + mesa-01 arbitran en Sprint X.5
3. Aprobado → cambio aplicado en X.5 + cells re-sync main
4. Rechazado → cell hace workaround

---

## 10. Ejemplos de consumo desde addons

### Suscribirse a `akibara_core_init`

```php
add_action('akibara_core_init', function($bootstrap) {
    $bootstrap->services()->register('preventas.repo', new \Akibara\Preventas\Repository());
}, 10);
```

### Verificar core ready antes de usar API

```php
add_action('plugins_loaded', function() {
    if (!akb_core_initialized()) {
        return; // Core no ready, skip
    }
    // Safe to use Akibara\Core\* classes + akb_* helpers
}, 10);
```

### Defensive guard en theme/addon que llama función pública

```php
if (function_exists('akb_extract_info')) {
    $info = akb_extract_info($product_title);
    // use $info
} else {
    // graceful degradation
}
```

---

**FIN HANDOFF Cell Core Phase 1.** Sprint 3+ cells consumen este API. Cualquier ambigüedad: leer código en `wp-content/plugins/akibara-core/` o abrir RFC.
