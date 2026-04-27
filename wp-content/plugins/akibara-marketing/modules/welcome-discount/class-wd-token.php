<?php
/**
 * Akibara Welcome Discount — Token
 *
 * Tokens are 64-char random hex strings (32 bytes of entropy).
 * Only the SHA-256 hash is stored in DB — the raw token exists
 * only in memory at generation time and is delivered via email.
 *
 * Stateless verification: hash(raw) → DB lookup → expiry check.
 * One active token per email at a time (regeneration replaces previous).
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/class-wd-token.php
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

class Akibara_WD_Token {

	const TTL_SECONDS = 48 * HOUR_IN_SECONDS;

	/**
	 * Generate a token for an email, persist the hash in the subscriptions row.
	 * Caller must ensure the row already exists.
	 *
	 * @return string Raw 64-char hex token (only returned once).
	 */
	public static function generate( string $email ): string {
		global $wpdb;

		$raw     = bin2hex( random_bytes( 32 ) );
		$hash    = hash( 'sha256', $raw );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS );

		$wpdb->update(
			akb_wd_table_sub(),
			array(
				'token_hash'       => $hash,
				'token_expires_at' => $expires,
				'status'           => 'pending',
			),
			array( 'email' => strtolower( trim( $email ) ) ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		return $raw;
	}

	/**
	 * Verify a raw token. Returns the associated email on success, null on any failure.
	 *
	 * Fails: token length != 64, not found in DB, already confirmed, expired.
	 *
	 * @return string|null Email on success.
	 */
	public static function verify( string $raw ): ?string {
		global $wpdb;

		if ( strlen( $raw ) !== 64 || ! ctype_xdigit( $raw ) ) {
			return null;
		}

		$hash = hash( 'sha256', $raw );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT email, token_expires_at, status FROM %i WHERE token_hash = %s LIMIT 1',
				akb_wd_table_sub(),
				$hash
			)
		);

		if ( ! $row ) {
			return null;
		}
		if ( $row->status === 'confirmed' ) {
			return null;
		}
		if ( strtotime( $row->token_expires_at ) < time() ) {
			return null;
		}

		return $row->email;
	}

	/**
	 * Check if a raw token exists but is expired (for better UX error messages).
	 */
	public static function is_expired( string $raw ): bool {
		global $wpdb;

		if ( strlen( $raw ) !== 64 ) {
			return false;
		}

		$hash = hash( 'sha256', $raw );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT token_expires_at, status FROM %i WHERE token_hash = %s LIMIT 1',
				akb_wd_table_sub(),
				$hash
			)
		);

		if ( ! $row ) {
			return false;
		}
		if ( $row->status === 'confirmed' ) {
			return false;
		}

		return strtotime( $row->token_expires_at ) < time();
	}
}
