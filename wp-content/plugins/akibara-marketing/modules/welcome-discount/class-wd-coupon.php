<?php
/**
 * Akibara Welcome Discount — WC Coupon Generator
 *
 * Creates real WC coupon posts (not virtual).
 * WC natively enforces: usage_limit=1, usage_limit_per_user=1, email_restrictions.
 * Our RUT/email check at checkout is the second layer.
 *
 * Code is deterministic: same email → same code (BIENVENIDA-{8 hex chars}).
 * Idempotent: calling generate() twice for the same email returns the same code.
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/class-wd-coupon.php
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

class Akibara_WD_Coupon {

	const PREFIX = 'BIENVENIDA-';

	/**
	 * Generate (or retrieve existing) WC coupon for the given email.
	 *
	 * @return string Coupon code.
	 */
	public static function generate( string $email ): string {
		$code = self::build_code( $email );

		// Idempotent: if coupon already exists, return it.
		if ( wc_get_coupon_id_by_code( $code ) ) {
			return $code;
		}

		$settings = Akibara_WD_Settings::all();

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $settings['discount_type'] );
		$coupon->set_amount( (string) $settings['amount'] );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_email_restrictions( array( strtolower( trim( $email ) ) ) );
		$coupon->set_date_expires( strtotime( '+' . (int) $settings['validity_days'] . ' days' ) );

		if ( (int) $settings['min_order'] > 0 ) {
			$coupon->set_minimum_amount( (string) $settings['min_order'] );
		}

		$coupon->set_description(
			sprintf(
				'Bienvenida — generado para %s el %s',
				sanitize_email( $email ),
				current_time( 'Y-m-d H:i' )
			)
		);

		$coupon->save();

		return $code;
	}

	/**
	 * Deterministic code from email.
	 * BIENVENIDA-{first 8 hex chars of HMAC-SHA256(email, wp_salt)}.
	 */
	public static function build_code( string $email ): string {
		$hmac = hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
		return self::PREFIX . strtoupper( substr( $hmac, 0, 8 ) );
	}

	public static function is_welcome_coupon( string $code ): bool {
		return str_starts_with( strtoupper( $code ), self::PREFIX );
	}

	/**
	 * Lookup the coupon code stored in our subscriptions table for an email.
	 *
	 * @return string|null
	 */
	public static function get_for_email( string $email ): ?string {
		global $wpdb;
		$code = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT coupon_code FROM %i WHERE email = %s AND coupon_code != '' LIMIT 1",
				akb_wd_table_sub(),
				strtolower( trim( $email ) )
			)
		);
		return $code ?: null;
	}
}
