<?php
/**
 * Plugin Name:       Akibara MercadoLibre
 * Plugin URI:        https://github.com/alefvaras/akibara-v2
 * Description:       Sincronizacion bidireccional WooCommerce MercadoLibre Chile (MLC). OAuth PKCE, webhook handler, sync engine, publisher, pricing con markup, ordenes.
 * Version:           1.0.0
 * Author:            Akibara
 * Text Domain:       akibara-mercadolibre
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  akibara-core
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// ── File-level guard — group wrap pattern (REDESIGN.md §9 / Sprint 2 postmortem) ──
if ( defined( 'AKB_ML_LOADED' ) ) {
	return;
}
define( 'AKB_ML_LOADED', '1.0.0' );

// ── Constants (always defined, idempotent) ────────────────────────────────────
if ( ! defined( 'AKB_ML_VERSION' ) ) {
	define( 'AKB_ML_VERSION', '1.0.0' );
}
if ( ! defined( 'AKB_ML_DIR' ) ) {
	define( 'AKB_ML_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AKB_ML_URL' ) ) {
	define( 'AKB_ML_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'AKB_ML_FILE' ) ) {
	define( 'AKB_ML_FILE', __FILE__ );
}

// API / DB constants — same values as legacy module for backward compat
if ( ! defined( 'AKB_ML_API_URL' ) ) {
	define( 'AKB_ML_API_URL', 'https://api.mercadolibre.com' );
}
if ( ! defined( 'AKB_ML_SITE_ID' ) ) {
	define( 'AKB_ML_SITE_ID', 'MLC' );
}
if ( ! defined( 'AKB_ML_CURRENCY' ) ) {
	define( 'AKB_ML_CURRENCY', 'CLP' );
}
if ( ! defined( 'AKB_ML_DB_VER' ) ) {
	define( 'AKB_ML_DB_VER', '1.3' );
}
// Legacy fallback constant — pricing code references this directly in some paths
if ( ! defined( 'AKB_ML_FREE_SHIPPING_THRESHOLD' ) ) {
	define( 'AKB_ML_FREE_SHIPPING_THRESHOLD', 19990 );
}

// ── PSR-4 autoloader (Akibara\MercadoLibre\* → src/*) ────────────────────────
if ( ! defined( 'AKB_ML_AUTOLOADER_REGISTERED' ) ) {
	define( 'AKB_ML_AUTOLOADER_REGISTERED', true );

	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'Akibara\\MercadoLibre\\';
			if ( strpos( $class, $prefix ) !== 0 ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = AKB_ML_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

// ── WooCommerce HPOS + Checkout Blocks compatibility ──────────────────────────
if ( ! function_exists( 'akb_mercadolibre_declare_wc_compat' ) ) {

	function akb_mercadolibre_declare_wc_compat(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				AKB_ML_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				AKB_ML_FILE,
				true
			);
		}
	}

	add_action( 'before_woocommerce_init', 'akb_mercadolibre_declare_wc_compat' );

} // end group wrap

// ── Procedural includes — loaded early so functions are available for hooks ───
if ( ! function_exists( 'akb_mercadolibre_load_includes' ) ) {

	function akb_mercadolibre_load_includes(): void {
		$dir = AKB_ML_DIR;
		require_once $dir . 'includes/class-ml-api.php';
		require_once $dir . 'includes/class-ml-db.php';
		require_once $dir . 'includes/class-ml-pricing.php';
		require_once $dir . 'includes/class-ml-publisher.php';
		require_once $dir . 'includes/class-ml-orders.php';
		require_once $dir . 'includes/class-ml-webhook.php';
		require_once $dir . 'includes/class-ml-sync.php';
		require_once $dir . 'admin/settings.php';
		require_once $dir . 'admin/products-meta.php';
	}

} // end group wrap

// Load at plugins_loaded priority 9 — after core (priority 5) but before addon init (priority 10)
if ( ! function_exists( 'akb_mercadolibre_early_load' ) ) {

	function akb_mercadolibre_early_load(): void {
		if ( ! class_exists( '\Akibara\Core\Bootstrap' ) ) {
			return;
		}
		akb_mercadolibre_load_includes();
	}

	add_action( 'plugins_loaded', 'akb_mercadolibre_early_load', 9 );

} // end group wrap

// ── Bootstrap via AddonContract (post-INCIDENT-01 — type-safe registration) ──
if ( ! function_exists( 'akb_mercadolibre_register' ) ) {

	function akb_mercadolibre_register(): void {
		if ( ! class_exists( '\Akibara\Core\Bootstrap' ) ) {
			return;
		}
		\Akibara\Core\Bootstrap::instance()->register_addon( new \Akibara\MercadoLibre\Plugin() );
	}

	// Priority 10 = after core's plugins_loaded:5 init and after our early_load at 9
	add_action( 'plugins_loaded', 'akb_mercadolibre_register', 10 );

} // end group wrap

// ── DB install / upgrade on load ──────────────────────────────────────────────
if ( ! function_exists( 'akb_mercadolibre_maybe_upgrade_db' ) ) {

	function akb_mercadolibre_maybe_upgrade_db(): void {
		if ( get_option( 'akb_ml_db_version', '0' ) !== AKB_ML_DB_VER ) {
			if ( function_exists( 'akb_ml_create_table' ) ) {
				akb_ml_create_table();
				akb_ml_migrate_db();
				update_option( 'akb_ml_db_version', AKB_ML_DB_VER );
			}
		}
	}

	add_action( 'plugins_loaded', 'akb_mercadolibre_maybe_upgrade_db', 15 );

} // end group wrap

// ── Activation hook ───────────────────────────────────────────────────────────
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( function_exists( 'akb_ml_create_table' ) ) {
			akb_ml_create_table();
			akb_ml_migrate_db();
			update_option( 'akb_ml_db_version', AKB_ML_DB_VER );
		}
	}
);

// ── Deactivation hook — cleanup Action Scheduler recurring actions ─────────────
register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( 'akb_ml_health_sync', array(), 'akibara-ml' );
		as_unschedule_all_actions( 'akb_ml_stale_sync', array(), 'akibara-ml' );
		as_unschedule_all_actions( 'akb_ml_retry_errors', array(), 'akibara-ml' );
		delete_option( 'akb_ml_as_migration_done' );
	}
);
