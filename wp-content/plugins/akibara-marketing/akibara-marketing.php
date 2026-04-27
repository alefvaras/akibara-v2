<?php
/**
 * Plugin Name: Akibara Marketing
 * Plugin URI: https://github.com/alefvaras/akibara-v2
 * Description: Marketing automation + Brevo + finance dashboard manga-specific
 * Version: 1.0.0
 * Author: Akibara
 * Text Domain: akibara-marketing
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: akibara-core
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// ── File-level sentinel (PHP hoisting lesson — Sprint 2 REDESIGN.md §9) ──
if ( defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
define( 'AKB_MARKETING_LOADED', '1.0.0' );

// ── Constants (always defined) ──────────────────────────────────────────────
if ( ! defined( 'AKB_MARKETING_DIR' ) ) {
	define( 'AKB_MARKETING_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AKB_MARKETING_URL' ) ) {
	define( 'AKB_MARKETING_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'AKB_MARKETING_FILE' ) ) {
	define( 'AKB_MARKETING_FILE', __FILE__ );
}
if ( ! defined( 'AKB_MARKETING_VERSION' ) ) {
	define( 'AKB_MARKETING_VERSION', '1.0.0' );
}
if ( ! defined( 'AKB_MARKETING_DB_VERSION' ) ) {
	define( 'AKB_MARKETING_DB_VERSION', '1.0.0' );
}

// ── PSR-4 autoloader ────────────────────────────────────────────────────────
if ( ! defined( 'AKB_MARKETING_AUTOLOADER_REGISTERED' ) ) {
	define( 'AKB_MARKETING_AUTOLOADER_REGISTERED', true );

	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'Akibara\\Marketing\\';
			$len    = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) !== 0 ) {
				return;
			}
			$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, $len ) );
			$file     = AKB_MARKETING_DIR . 'src/' . $relative . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

// ── Group wrap — all top-level function + hook declarations ─────────────────
// Prevents PHP hoisting redeclare bug (see Sprint 2 postmortem REDESIGN.md §9).
if ( ! function_exists( 'akb_marketing_sentinel' ) ) {

	/**
	 * Sentinel function — never called directly.
	 * Presence signals this group wrap already executed.
	 */
	function akb_marketing_sentinel(): bool {
		return defined( 'AKB_MARKETING_LOADED' );
	}

	// ── Bootstrap: hook into akibara_core_init ───────────────────────────────
	add_action(
		'akibara_core_init',
		static function ( $bootstrap ): void {
			// Register plugin in the core module registry.
			$bootstrap->modules()->declare_module( 'akibara-marketing', '1.0.0', 'addon' );

			// Run DB migrations idempotently.
			akb_marketing_maybe_run_migrations();

			// Load modules.
			akb_marketing_load_modules();
		},
		20
	);

	// ── DB migration runner ──────────────────────────────────────────────────
	function akb_marketing_maybe_run_migrations(): void {
		if ( get_option( 'akb_marketing_db_version', '0' ) === AKB_MARKETING_DB_VERSION ) {
			return;
		}
		akb_marketing_run_dbdelta();
		update_option( 'akb_marketing_db_version', AKB_MARKETING_DB_VERSION );
	}

	function akb_marketing_run_dbdelta(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		// ── wp_akb_campaigns ─────────────────────────────────────────────────
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}akb_campaigns (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name        VARCHAR(200)        NOT NULL DEFAULT '',
			type        VARCHAR(50)         NOT NULL DEFAULT '',
			status      VARCHAR(20)         NOT NULL DEFAULT 'draft',
			brevo_id    VARCHAR(100)                 DEFAULT NULL,
			config      LONGTEXT                     DEFAULT NULL,
			created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_status  (status),
			KEY idx_type    (type)
		) $charset_collate;";

		// ── wp_akb_email_log ─────────────────────────────────────────────────
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}akb_email_log (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			email       VARCHAR(200)        NOT NULL DEFAULT '',
			type        VARCHAR(100)        NOT NULL DEFAULT '',
			subject     VARCHAR(500)                 DEFAULT NULL,
			status      VARCHAR(20)         NOT NULL DEFAULT 'sent',
			brevo_msg   VARCHAR(100)                 DEFAULT NULL,
			order_id    BIGINT(20) UNSIGNED           DEFAULT NULL,
			sent_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_email   (email(100)),
			KEY idx_type    (type),
			KEY idx_sent_at (sent_at)
		) $charset_collate;";

		// ── wp_akb_referrals ─────────────────────────────────────────────────
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}akb_referrals (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			referrer_id     BIGINT(20) UNSIGNED           DEFAULT NULL,
			referrer_email  VARCHAR(200)        NOT NULL DEFAULT '',
			referred_email  VARCHAR(200)                 DEFAULT NULL,
			code            VARCHAR(50)         NOT NULL DEFAULT '',
			status          VARCHAR(20)         NOT NULL DEFAULT 'pending',
			reward_coupon   VARCHAR(100)                 DEFAULT NULL,
			order_id        BIGINT(20) UNSIGNED           DEFAULT NULL,
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			converted_at    DATETIME                     DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_code       (code),
			KEY idx_referrer (referrer_id),
			KEY idx_status   (status)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	// ── Module loader ────────────────────────────────────────────────────────
	function akb_marketing_load_modules(): void {
		$module_dir = AKB_MARKETING_DIR . 'modules/';

		// Load order matters: brevo first (shared helpers), then other modules.
		$modules = array(
			'brevo/module.php',
			'banner/module.php',
			'popup/module.php',
			'descuentos/module.php',
			'descuentos-tramos/module.php',
			'welcome-discount/module.php',
			'marketing-campaigns/module.php',
			'review-request/module.php',
			'review-incentive/module.php',
			'referrals/module.php',
			// 'customer-milestones/module.php', // DEFERRED Sprint 5+ (audit/sprint-3/cell-b/DECISION-CUSTOMER-MILESTONES.md)
			'finance-dashboard/module.php',
		);

		// cart-abandoned: DEPRECATED — Brevo upstream covers this natively.
		// Module file exists at modules/cart-abandoned/module.php (preserved for audit trail).
		// See HANDOFF.md §Decisión cart-abandoned for full rationale.

		// customer-milestones: DEFERRED Sprint 5+ — scaffold only, sin Brevo automations creadas.
		// Pre-condiciones activación: customer base ≥50/mes + _billing_birthday capturado + 3 templates Brevo.
		// See DECISION-CUSTOMER-MILESTONES.md for full rationale.

		foreach ( $modules as $module ) {
			$path = $module_dir . $module;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

} // end group wrap
