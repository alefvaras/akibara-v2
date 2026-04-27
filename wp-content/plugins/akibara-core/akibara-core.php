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
if ( defined( 'AKIBARA_CORE_LOADED' ) ) {
	return;
}
define( 'AKIBARA_CORE_LOADED', true );
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

// ─── Bootstrap (instantiate ServiceLocator + ModuleRegistry, init modules) ──
add_action(
	'plugins_loaded',
	function (): void {
		\Akibara\Core\Bootstrap::instance()->init();
	},
	5 // priority 5 — antes de plugins regular (default 10), addons can hook desde plugins_loaded:10
);

// ─── 6 Foundation modules (Phase 1 atomic deploy) ────────────────────────────
// Estos modules se relocated desde plugins/akibara/ legacy.
// El plugin akibara legacy detecta AKIBARA_CORE_LOADED y skip duplicate load.
require_once AKIBARA_CORE_DIR . 'includes/akibara-search.php';
require_once AKIBARA_CORE_DIR . 'includes/akibara-category-urls.php';
require_once AKIBARA_CORE_DIR . 'includes/akibara-order.php';
require_once AKIBARA_CORE_DIR . 'includes/akibara-email-safety.php';
require_once AKIBARA_CORE_DIR . 'modules/customer-edit-address/module.php';
require_once AKIBARA_CORE_DIR . 'modules/address-autocomplete/module.php';

// ─── Register Phase 1 modules en ModuleRegistry ──────────────────────────────
// Late hook (priority 6) para que Bootstrap (priority 5) ya haya inicializado registry.
add_action(
	'plugins_loaded',
	function (): void {
		$registry = \Akibara\Core\Bootstrap::instance()->modules();
		$registry->declare_module( 'search', '1.0.0', 'core' );
		$registry->declare_module( 'category-urls', '1.0.0', 'core' );
		$registry->declare_module( 'order', '1.0.0', 'core' );
		$registry->declare_module( 'email-safety', '1.0.0', 'core' );
		$registry->declare_module( 'customer-edit-address', '1.0.0', 'core' );
		$registry->declare_module( 'address-autocomplete', '1.0.0', 'core' );
	},
	6
);

// ─── Public API helpers (consumed by future addons in Sprint 3+) ─────────────
if ( ! function_exists( 'akb_core_loaded' ) ) {
	/**
	 * Check si akibara-core está activo y bootstrapped.
	 *
	 * @return bool
	 */
	function akb_core_loaded(): bool {
		return defined( 'AKIBARA_CORE_VERSION' );
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
		if ( ! akb_core_loaded() ) {
			return false;
		}
		$constant = 'AKB_CORE_MODULE_' . strtoupper( str_replace( '-', '_', $module ) ) . '_LOADED';
		return defined( $constant );
	}
}
