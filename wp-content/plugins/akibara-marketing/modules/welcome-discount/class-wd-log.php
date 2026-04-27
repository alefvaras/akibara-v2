<?php
/**
 * Akibara Welcome Discount — Audit Log
 *
 * Append-only. Emails stored as SHA-256 hashes (privacy).
 * IPs stored as SHA-256 hashes (no raw IPs at rest).
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/class-wd-log.php
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

class Akibara_WD_Log {

	const METRICS_CACHE_KEY = 'akb_wd_stats_v1';
	const METRICS_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	const EV_SUBSCRIBED       = 'subscribed';
	const EV_CONFIRMED        = 'confirmed';
	const EV_COUPON_GENERATED = 'coupon_generated';
	const EV_COUPON_REDEEMED  = 'coupon_redeemed';
	const EV_REJECTED_RUT     = 'rejected_rut';
	const EV_REJECTED_EMAIL   = 'rejected_email';
	const EV_SUSPICIOUS_ADDR  = 'suspicious_address';
	const EV_RATELIMIT_HIT    = 'ratelimit_hit';
	const EV_INVALID_DOMAIN   = 'invalid_domain';
	const EV_EXPIRED_TOKEN    = 'expired_token';
	const EV_INVALID_TOKEN    = 'invalid_token';
	const EV_DUPLICATE_EMAIL  = 'duplicate_email';
	const EV_CAPTCHA_FAIL     = 'captcha_fail';
	const EV_EMAIL_SENT       = 'email_sent';
	const EV_INVALID_RUT_FMT  = 'invalid_rut_format';

	public static function write(
		string $event,
		string $email = '',
		string $coupon = '',
		array $details = array(),
		string $ip = ''
	): void {
		global $wpdb;

		$wpdb->insert(
			akb_wd_table_log(),
			array(
				'event'       => $event,
				'email_hash'  => $email ? hash( 'sha256', strtolower( trim( $email ) ) ) : '',
				'ip_hash'     => $ip ? hash( 'sha256', $ip ) : '',
				'coupon_code' => $coupon,
				'details'     => $details ? wp_json_encode( $details ) : null,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		// Invalidate metrics cache: every Log::write() reflects a state change
		// relevant to the admin metrics panel.
		delete_transient( self::METRICS_CACHE_KEY );
	}

	public static function metrics(): array {
		$cached = get_transient( self::METRICS_CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$sub   = akb_wd_table_sub();
		$log   = akb_wd_table_log();
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$week  = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $log = $wpdb->prefix . 'akb_wd_log' (no user input).
		$coupons_issued_7d   = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT email_hash) FROM {$log} WHERE event=%s AND created_at >= %s",
				self::EV_COUPON_GENERATED,
				$week
			)
		);
		$coupons_redeemed_7d = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT email_hash) FROM {$log} WHERE event=%s AND created_at >= %s",
				self::EV_COUPON_REDEEMED,
				$week
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$redemption_rate_7d  = $coupons_issued_7d > 0
			? round( 100.0 * $coupons_redeemed_7d / $coupons_issued_7d, 1 )
			: 0.0;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sub/$log son $wpdb->prefix . tabla custom (sin user input).
		$stats = array(
			'subscriptions_pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sub} WHERE status='pending'" ),
			'subscriptions_confirmed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sub} WHERE status='confirmed'" ),
			'coupons_issued'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sub} WHERE coupon_code != ''" ),
			'coupons_redeemed'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sub} WHERE coupon_redeemed_at IS NOT NULL" ),
			'coupons_issued_7d'       => $coupons_issued_7d,
			'coupons_redeemed_7d'     => $coupons_redeemed_7d,
			'redemption_rate_7d'      => $redemption_rate_7d,
			'abuse_suspects_30d'      => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE event='suspicious_address' AND created_at >= %s", $since )
			),
			'ratelimit_hits_30d'      => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE event='ratelimit_hit' AND created_at >= %s", $since )
			),
			'rejections_rut_30d'      => (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE event='rejected_rut' AND created_at >= %s", $since )
			),
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		set_transient( self::METRICS_CACHE_KEY, $stats, self::METRICS_CACHE_TTL );

		return $stats;
	}
}
