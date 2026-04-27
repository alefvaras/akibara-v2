<?php
/**
 * Akibara Welcome Discount — DB Tables
 *
 * wp_akb_wd_subscriptions  — one row per email address
 * wp_akb_wd_log            — append-only audit trail
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/class-wd-db.php v1.0.1
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

// Schema version. Bump when CREATE TABLE statements change so dbDelta re-applies.
// 1.0.1 — Sprint 9 (P-17): added KEY delivery_fp on wp_akb_wd_subscriptions for fingerprint_is_suspicious() WHERE clause.
if ( ! defined( 'AKB_WD_DB_VERSION' ) ) {
	define( 'AKB_WD_DB_VERSION', '1.0.1' );
}

function akb_wd_table_sub(): string {
	global $wpdb;
	return $wpdb->prefix . 'akb_wd_subscriptions';
}

function akb_wd_table_log(): string {
	global $wpdb;
	return $wpdb->prefix . 'akb_wd_log';
}

function akb_wd_create_tables(): void {
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		'CREATE TABLE IF NOT EXISTS ' . akb_wd_table_sub() . " (
		id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		email               VARCHAR(200)    NOT NULL DEFAULT '',
		ip_hash             VARCHAR(64)     NOT NULL DEFAULT '',
		token_hash          VARCHAR(64)     NOT NULL DEFAULT '',
		token_expires_at    DATETIME        NOT NULL DEFAULT '2000-01-01 00:00:00',
		confirmed_at        DATETIME        DEFAULT NULL,
		coupon_code         VARCHAR(100)    NOT NULL DEFAULT '',
		coupon_redeemed_at  DATETIME        DEFAULT NULL,
		rut_hash            VARCHAR(64)     NOT NULL DEFAULT '',
		delivery_fp         VARCHAR(64)     NOT NULL DEFAULT '',
		status              VARCHAR(20)     NOT NULL DEFAULT 'pending',
		created_at          DATETIME        NOT NULL DEFAULT '2000-01-01 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY   email (email(191)),
		KEY          token_hash (token_hash),
		KEY          status (status),
		KEY          coupon_code (coupon_code(50)),
		KEY          delivery_fp (delivery_fp)
	) {$charset};"
	);

	dbDelta(
		'CREATE TABLE IF NOT EXISTS ' . akb_wd_table_log() . " (
		id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event       VARCHAR(50)   NOT NULL DEFAULT '',
		email_hash  VARCHAR(64)   NOT NULL DEFAULT '',
		ip_hash     VARCHAR(64)   NOT NULL DEFAULT '',
		coupon_code VARCHAR(100)  NOT NULL DEFAULT '',
		details     TEXT          DEFAULT NULL,
		created_at  DATETIME      NOT NULL DEFAULT '2000-01-01 00:00:00',
		PRIMARY KEY (id),
		KEY         event (event),
		KEY         email_hash (email_hash),
		KEY         created_at (created_at)
	) {$charset};"
	);
}
