<?php
/**
 * Plugin Name:       Akibara Core
 * Plugin URI:        https://github.com/alefvaras/akibara-v2
 * Description:       Foundation core para addons Akibara. ServiceLocator + ModuleRegistry + Lifecycle hooks + 6 módulos foundation Phase 1 (search, category-urls, order, email-safety, customer-edit-address, address-autocomplete).
 * Version:           1.0.0
 * Author:            Akibara
 * Text Domain:       akibara-core
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Sprint 2 B-S2-CELLCORE — Phase 1 atomic deploy.
 * Memoria: project_architecture_core_plus_addons.md
 *
 * Carga order: este plugin DEBE cargar antes que `akibara/akibara.php` legacy.
 * El plugin akibara legacy declarará `Requires Plugins: akibara-core` (WP 6.5+)
 * para garantizar load order via WP nativo.
 *
 * @package Akibara\Core
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ─── Single-load guard ───────────────────────────────────────────────────────
// IMPORTANTE: usamos AKIBARA_CORE_PLUGIN_LOADED (no AKIBARA_CORE_LOADED) porque
// el plugin legacy `akibara/` tiene un internal `includes/akibara-core.php` que
// define `AKIBARA_CORE_LOADED='10.0.0'` con DIFERENTE significado (guard interno
// del legacy helper file, no del plugin akibara-core nuevo). Mesa-22 P0 finding.
if ( defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	return;
}
define( 'AKIBARA_CORE_PLUGIN_LOADED', true );
define( 'AKIBARA_CORE_VERSION', '1.0.0' );
define( 'AKIBARA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKIBARA_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'AKIBARA_CORE_FILE', __FILE__ );

// ─── PSR-4 Autoloader (Akibara\Core\* → src/*) ───────────────────────────────
spl_autoload_register(
	function ( string $class ): void {
		$prefix = 'Akibara\\Core\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = AKIBARA_CORE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// ─── WooCommerce HPOS compatibility ─────────────────────────────────────────
add_action(
	'before_woocommerce_init',
	function (): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
		}
	}
);

// ─── Shared helpers (akb_normalize, akb_extract_info, akb_strip_accents) ─────
// Mesa-15 P0-3 fix: helpers DEBEN cargar antes que search/order modules que los usan.
require_once AKIBARA_CORE_DIR . 'includes/akibara-helpers.php';

// ─── Register Phase 1 modules en ModuleRegistry — priority 4 (mesa-15 P1-2) ──
// PRIORITY 4 (antes que Bootstrap priority 5) para que cuando akibara_core_init
// fire, el registry ya tenga los modules declarados. Addons que hookean
// akibara_core_init pueden consultar $modules->all() y obtener data real.
add_action(
	'plugins_loaded',
	function (): void {
		$registry = \Akibara\Core\Bootstrap::instance()->modules();
		// Phase 1 modules (Sprint 2).
		$registry->declare_module( 'search', '1.0.0', 'core' );
		$registry->declare_module( 'category-urls', '1.0.0', 'core' );
		$registry->declare_module( 'order', '1.0.0', 'core' );
		$registry->declare_module( 'email-safety', '1.0.0', 'core' );
		$registry->declare_module( 'customer-edit-address', '1.0.0', 'core' );
		$registry->declare_module( 'address-autocomplete', '1.0.0', 'core' );
		// Phase 2 modules (Polish #1 2026-04-26) — 9 modules migrated from legacy.
		$registry->declare_module( 'rut', '1.0.0', 'core' );
		$registry->declare_module( 'phone', '2.0.0', 'core' );
		$registry->declare_module( 'installments', '2.0.0', 'core' );
		$registry->declare_module( 'product-badges', '1.1.0', 'core' );
		$registry->declare_module( 'checkout-validation', '1.0.0', 'core' );
		$registry->declare_module( 'health-check', '1.0.0', 'core' );
		$registry->declare_module( 'series-autofill', '1.0.0', 'core' );
		$registry->declare_module( 'email-template', '2.0.0', 'core' );
	},
	4
);

// ─── Bootstrap (init ServiceLocator + ModuleRegistry, fire akibara_core_init) ─
add_action(
	'plugins_loaded',
	function (): void {
		\Akibara\Core\Bootstrap::instance()->init();
	},
	5 // priority 5 — addons can hook akibara_core_init via plugins_loaded:>=10 OR file-include
);

// ─── Helpers compartidos (deben cargar antes de cualquier module) ────────────
// akb_ajax_endpoint() es helper crítico usado por marketing/inventario/mercadolibre/
// preventas/whatsapp. Polish #1 retry 2026-04-27: migrado desde legacy akibara/
// para permitir eliminación del legacy plugin sin romper addons.
require_once AKIBARA_CORE_DIR . 'includes/helpers/ajax.php';

// ─── Phase 1 modules (Sprint 2 atomic deploy) ────────────────────────────────
// Cargan en file-include time (top-level), antes de hooks plugins_loaded.
// Sus add_action/add_filter calls registran hooks aquí mismo.
// Cada module tiene per-file load guard para idempotency.
require_once AKIBARA_CORE_DIR . 'includes/akibara-search.php';
require_once AKIBARA_CORE_DIR . 'includes/akibara-category-urls.php';
require_once AKIBARA_CORE_DIR . 'includes/akibara-order.php';
require_once AKIBARA_CORE_DIR . 'includes/akibara-email-safety.php';
require_once AKIBARA_CORE_DIR . 'modules/customer-edit-address/module.php';
require_once AKIBARA_CORE_DIR . 'modules/address-autocomplete/module.php';

// ─── Phase 2 modules (Polish #1 2026-04-26) — 9 modules + email-template ────
// 9 modules NOT migrated in Sprint 2 are now owned by akibara-core.
// Legacy akibara/ skip guards in akibara.php will detect AKIBARA_CORE_PLUGIN_LOADED
// and skip registering these modules.
require_once AKIBARA_CORE_DIR . 'includes/class-akibara-email-template.php';
require_once AKIBARA_CORE_DIR . 'modules/rut/module.php';
require_once AKIBARA_CORE_DIR . 'modules/phone/module.php';
require_once AKIBARA_CORE_DIR . 'modules/installments/module.php';
require_once AKIBARA_CORE_DIR . 'modules/product-badges/module.php';
require_once AKIBARA_CORE_DIR . 'modules/checkout-validation/module.php';
require_once AKIBARA_CORE_DIR . 'modules/health-check/module.php';
require_once AKIBARA_CORE_DIR . 'modules/series-autofill/module.php';

// ─── Activation / deactivation hooks (mesa-15 P0-4 fix) ──────────────────────
// Cuando admin activa el plugin (o WP cron primer firing), crear tabla index +
// schedule reorder cron. Cuando desactiva, limpia crons. NO drop table en
// uninstall (decisión: data preservation per memoria feedback_minimize_behavior_change).
register_activation_hook(
	__FILE__,
	function (): void {
		// Crear tabla wp_akibara_index si akb_create_index_table() está disponible.
		// La función la define akibara-search.php que ya cargamos arriba.
		if ( function_exists( 'akb_create_index_table' ) ) {
			akb_create_index_table();
		}
		update_option( 'akibara_needs_rebuild', 1, false );
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function (): void {
		// Limpiar SOLO crons que viven en este plugin (no los del legacy).
		wp_clear_scheduled_hook( 'akibara_reorder_cron' );
		flush_rewrite_rules();
	}
);

// ─── Public API helpers (consumed by future addons en Sprint 3+) ─────────────

if ( ! function_exists( 'akb_core_file_loaded' ) ) {
	/**
	 * Check si akibara-core file-include time terminó (constants defined).
	 *
	 * IMPORTANTE: returns true ANTES de Bootstrap::init() corra (plugins_loaded:5).
	 * Si necesitas verificar que ServiceLocator y ModuleRegistry están ready
	 * para usar, usa `akb_core_initialized()` en su lugar.
	 *
	 * @return bool
	 */
	function akb_core_file_loaded(): bool {
		return defined( 'AKIBARA_CORE_VERSION' );
	}
}

if ( ! function_exists( 'akb_core_loaded' ) ) {
	/**
	 * @deprecated Use akb_core_file_loaded() o akb_core_initialized().
	 * Backward compat alias — returns true cuando file-include time done.
	 *
	 * @return bool
	 */
	function akb_core_loaded(): bool {
		return akb_core_file_loaded();
	}
}

if ( ! function_exists( 'akb_core_initialized' ) ) {
	/**
	 * Check si Bootstrap::init() corrió y services están ready (post plugins_loaded:5).
	 *
	 * @return bool
	 */
	function akb_core_initialized(): bool {
		if ( ! akb_core_file_loaded() ) {
			return false;
		}
		// Bootstrap::init() es idempotent — call seguro. Pero si todavía no plugins_loaded:5,
		// entonces $instance->initialized = false. Check con flag interno.
		if ( ! class_exists( '\Akibara\Core\Bootstrap', false ) ) {
			return false;
		}
		return \Akibara\Core\Bootstrap::instance()->is_initialized();
	}
}

if ( ! function_exists( 'akb_core_module_loaded' ) ) {
	/**
	 * Check si un módulo específico está cargado en el core.
	 *
	 * @param string $module Module name (slug, e.g. 'search', 'category-urls').
	 * @return bool
	 */
	function akb_core_module_loaded( string $module ): bool {
		if ( ! akb_core_file_loaded() ) {
			return false;
		}
		$constant = 'AKB_CORE_MODULE_' . strtoupper( str_replace( '-', '_', $module ) ) . '_LOADED';
		return defined( $constant );
	}
}
