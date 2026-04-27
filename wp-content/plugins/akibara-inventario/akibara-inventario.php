<?php
/**
 * Plugin Name:       Akibara Inventario
 * Plugin URI:        https://github.com/alefvaras/akibara-v2
 * Description:       Gestión de stock, envíos (BlueX + 12 Horas) y avisos back-in-stock para Akibara
 * Version:           1.0.0
 * Author:            Akibara
 * Text Domain:       akibara-inventario
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  akibara-core
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// File-level guard — group wrap pattern (Sprint 2 postmortem, REDESIGN.md §9).
if ( defined( 'AKB_INV_ADDON_LOADED' ) ) {
	return;
}
define( 'AKB_INV_ADDON_LOADED', '1.0.0' );

// ─── Constants (always defined, idempotent) ──────────────────────────────────
if ( ! defined( 'AKB_INVENTARIO_VERSION' ) ) {
	define( 'AKB_INVENTARIO_VERSION', '1.0.0' );
}
if ( ! defined( 'AKB_INVENTARIO_DIR' ) ) {
	define( 'AKB_INVENTARIO_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AKB_INVENTARIO_URL' ) ) {
	define( 'AKB_INVENTARIO_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'AKB_INVENTARIO_FILE' ) ) {
	define( 'AKB_INVENTARIO_FILE', __FILE__ );
}

// DB table name constants — use $GLOBALS['wpdb'] to avoid depends on global $wpdb at parse time.
if ( ! defined( 'AKB_INV_TABLE_STOCK_RULES' ) ) {
	define( 'AKB_INV_TABLE_STOCK_RULES', $GLOBALS['wpdb']->prefix . 'akb_stock_rules' );
}
if ( ! defined( 'AKB_INV_TABLE_STOCK_LOG' ) ) {
	define( 'AKB_INV_TABLE_STOCK_LOG', $GLOBALS['wpdb']->prefix . 'akb_stock_log' );
}
if ( ! defined( 'AKB_INV_TABLE_BIS_SUBS' ) ) {
	define( 'AKB_INV_TABLE_BIS_SUBS', $GLOBALS['wpdb']->prefix . 'akb_back_in_stock_subs' );
}

// DB version sentinels.
if ( ! defined( 'AKB_INVENTARIO_DB_VERSION' ) ) {
	define( 'AKB_INVENTARIO_DB_VERSION', '1.0' );
}

// ─── PSR-4 autoloader (Akibara\Inventario\* → src/*) ────────────────────────
if ( ! defined( 'AKB_INVENTARIO_AUTOLOADER_REGISTERED' ) ) {
	define( 'AKB_INVENTARIO_AUTOLOADER_REGISTERED', true );

	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'Akibara\\Inventario\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = AKB_INVENTARIO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

// ─── AddonContract registration via Bootstrap::register_addon() ─────────────
// Preferred pattern (post-INCIDENT-01): type-safe, per-addon isolation.
// Guard ensures akibara-core is loaded before registration attempt.
if ( ! function_exists( 'akb_inventario_register' ) ) {

	function akb_inventario_register(): void {
		if ( ! class_exists( '\Akibara\Core\Bootstrap' ) ) {
			return;
		}
		\Akibara\Core\Bootstrap::instance()->register_addon(
			new \Akibara\Inventario\Plugin()
		);
	}

	add_action( 'plugins_loaded', 'akb_inventario_register', 10 );

} // end group wrap
