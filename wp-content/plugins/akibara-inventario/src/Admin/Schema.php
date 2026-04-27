<?php
/**
 * Akibara Inventario — Schema installer (idempotent).
 *
 * Owns 3 tables:
 *   - wp_akb_stock_log       (inventory change audit trail — migrated from legacy)
 *   - wp_akb_stock_rules     (NEW: restock rules per product/editorial/series)
 *   - wp_akb_back_in_stock_subs (back-in-stock subscriptions — migrated from wp_akb_bis_subs)
 *
 * Migration strategy:
 *   - wp_akb_stock_log: legacy table already exists in prod (akb_inv_db_version 2.1).
 *     Schema is compatible — no dbDelta changes needed. Update version sentinel only.
 *   - wp_akb_bis_subs: renamed to wp_akb_back_in_stock_subs for namespace clarity.
 *     Migration copies existing rows on first run (idempotent, safe).
 *   - wp_akb_stock_rules: new table, no legacy data.
 *
 * @package Akibara\Inventario\Admin
 * @since   1.0.0
 */

namespace Akibara\Inventario\Admin;

defined( 'ABSPATH' ) || exit;

final class Schema {

	const DB_VERSION = '1.0';
	const OPTION_KEY = 'akb_inventario_db_version';

	/**
	 * Run install if version mismatch. Called from Plugin::init() on every load
	 * but only executes SQL when version is outdated (fast path: 1 get_option).
	 */
	public static function maybe_install(): void {
		if ( get_option( self::OPTION_KEY ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		// ── 1. wp_akb_stock_log (migrated from legacy inventory module) ──────────
		// Table already exists in prod with akb_inv_db_version=2.1.
		// Schema is additive-compatible: same columns + same indexes.
		// dbDelta is idempotent — safe to run on existing tables.
		$stock_log_table = $wpdb->prefix . 'akb_stock_log';
		dbDelta( "CREATE TABLE IF NOT EXISTS {$stock_log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			old_stock INT DEFAULT NULL,
			new_stock INT DEFAULT NULL,
			reason VARCHAR(255) DEFAULT '',
			source VARCHAR(50) DEFAULT 'manual',
			user_id BIGINT UNSIGNED DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY product_id (product_id),
			KEY created_at (created_at)
		) {$charset};" );

		// ── 2. wp_akb_stock_rules (NEW) ──────────────────────────────────────────
		$stock_rules_table = $wpdb->prefix . 'akb_stock_rules';
		dbDelta( "CREATE TABLE IF NOT EXISTS {$stock_rules_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_type ENUM('product','editorial','series','global') NOT NULL DEFAULT 'product',
			target_id BIGINT UNSIGNED DEFAULT NULL,
			target_slug VARCHAR(200) DEFAULT NULL,
			low_stock_threshold INT UNSIGNED NOT NULL DEFAULT 2,
			reorder_qty INT UNSIGNED NOT NULL DEFAULT 0,
			auto_notify TINYINT(1) NOT NULL DEFAULT 1,
			notes TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY rule_type_target (rule_type, target_id),
			KEY target_slug (target_slug(100))
		) {$charset};" );

		// ── 3. wp_akb_back_in_stock_subs (migrated from wp_akb_bis_subs) ────────
		// New canonical name — bis_subs is legacy.
		// On first install: create new table + copy data from legacy (if exists).
		$bis_table        = $wpdb->prefix . 'akb_back_in_stock_subs';
		$bis_legacy_table = $wpdb->prefix . 'akb_bis_subs';
		dbDelta( "CREATE TABLE IF NOT EXISTS {$bis_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(255) NOT NULL,
			token VARCHAR(64) NOT NULL,
			status ENUM('active','notified','unsubscribed') NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			notified_at DATETIME DEFAULT NULL,
			converted_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY email_product (email, product_id),
			KEY product_id (product_id),
			KEY status (status),
			KEY token (token)
		) {$charset};" );

		// Migrate legacy rows from wp_akb_bis_subs to wp_akb_back_in_stock_subs
		// if old table exists and new table is empty.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix . literal strings, no user input.
		$legacy_exists = $wpdb->get_var(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$bis_legacy_table}'"
		);
		if ( $legacy_exists ) {
			$new_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bis_table}" );
			if ( $new_count === 0 ) {
				$wpdb->query(
					"INSERT IGNORE INTO {$bis_table} (id, product_id, email, token, status, created_at, notified_at, converted_at)
					 SELECT id, product_id, email, token, status, created_at, notified_at, converted_at
					 FROM {$bis_legacy_table}"
				);
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		update_option( self::OPTION_KEY, self::DB_VERSION );

		// Remove legacy version sentinels to avoid double-runs from old module code.
		// Legacy: akb_inventory_db_version (inventory module), akb_bis_db_ver (back-in-stock module).
		// We leave legacy option values in place — the legacy modules check them and bail early.
		// Setting them to the max known version prevents re-runs if legacy code is still present.
		if ( get_option( 'akb_inventory_db_version' ) !== '2.1' ) {
			update_option( 'akb_inventory_db_version', '2.1' );
		}
		if ( get_option( 'akb_bis_db_ver' ) !== '1.0' ) {
			update_option( 'akb_bis_db_ver', '1.0' );
		}
	}
}
