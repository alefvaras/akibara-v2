<?php
/**
 * Akibara Welcome Discount — Settings
 *
 * Single option key `akibara_wd_settings` (array).
 * Static cache reset via flush() between requests/tests.
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/class-wd-settings.php
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

class Akibara_WD_Settings {

	const OPTION_KEY = 'akibara_wd_settings';

	private static $cache = array();

	public static function get( string $key, $default = null ) {
		if ( empty( self::$cache ) ) {
			self::$cache = (array) get_option( self::OPTION_KEY, array() );
		}
		return self::$cache[ $key ] ?? $default;
	}

	public static function all(): array {
		if ( empty( self::$cache ) ) {
			self::$cache = (array) get_option( self::OPTION_KEY, array() );
		}
		return array_merge( self::defaults(), self::$cache );
	}

	public static function save( array $raw ): void {
		$d = self::defaults();

		$clean = array(
			'discount_type'   => in_array( $raw['discount_type'] ?? '', array( 'percent', 'fixed_cart' ), true )
								? $raw['discount_type'] : $d['discount_type'],
			'amount'          => max( 1, min( 100000, (int) ( $raw['amount'] ?? $d['amount'] ) ) ),
			'min_order'       => max( 0, (int) ( $raw['min_order'] ?? $d['min_order'] ) ),
			'validity_days'   => max( 1, min( 365, (int) ( $raw['validity_days'] ?? $d['validity_days'] ) ) ),
			'double_optin'    => ! empty( $raw['double_optin'] ) ? 1 : 0,
			'from_name'       => sanitize_text_field( $raw['from_name'] ?? $d['from_name'] ),
			'from_email'      => is_email( $raw['from_email'] ?? '' )
								? sanitize_email( $raw['from_email'] ) : $d['from_email'],
			'blacklist_extra' => sanitize_textarea_field( $raw['blacklist_extra'] ?? '' ),
			'rate_limit_day'  => max( 1, min( 20, (int) ( $raw['rate_limit_day'] ?? $d['rate_limit_day'] ) ) ),
		);

		self::$cache = $clean;
		update_option( self::OPTION_KEY, $clean, false );
	}

	public static function defaults(): array {
		return array(
			'discount_type'   => 'percent',
			'amount'          => 10,
			'min_order'       => 15000,
			'validity_days'   => 30,
			'double_optin'    => 1,
			'from_name'       => 'Akibara',
			'from_email'      => 'contacto@akibara.cl',
			'blacklist_extra' => '',
			'rate_limit_day'  => 3,
		);
	}

	public static function flush(): void {
		self::$cache = array();
	}
}
