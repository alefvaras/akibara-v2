<?php
/**
 * Akibara Welcome Discount — Subscription Handler + Anti-Abuse
 *
 * Anti-abuse layers at subscription time:
 *   1. Email format validation
 *   2. Domain blacklist (~40 known temp-mail providers + custom)
 *   3. IP rate limit (3 attempts/day default, configurable)
 *   4. Math captcha (server-side challenge/response)
 *   5. Duplicate email check (pending/confirmed)
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/class-wd-subscribe.php
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

class Akibara_WD_Subscribe {

	// ─── Domain blacklist ────────────────────────────────────────────

	public static function get_blacklisted_domains(): array {
		$defaults = array(
			'mailinator.com',
			'guerrillamail.com',
			'guerrillamail.net',
			'guerrillamail.org',
			'guerrillamail.biz',
			'guerrillamail.de',
			'guerrillamail.info',
			'tempmail.com',
			'tempmail.net',
			'temp-mail.org',
			'temp-mail.io',
			'10minutemail.com',
			'10minutemail.net',
			'10minutemail.org',
			'yopmail.com',
			'yopmail.fr',
			'yopmail.net',
			'maildrop.cc',
			'sharklasers.com',
			'guerrillamailblock.com',
			'grr.la',
			'spam4.me',
			'trashmail.at',
			'trashmail.io',
			'trashmail.me',
			'trashmail.net',
			'trashmail.org',
			'dispostable.com',
			'fakeinbox.com',
			'throwam.com',
			'spamgourmet.com',
			'spamgourmet.net',
			'spamgourmet.org',
			'mailnull.com',
			'cmail.org',
			'spamspot.com',
			'nwldx.com',
			'eelmail.com',
			'spamdrop.net',
			'throwaway.email',
			'spamherelots.com',
			'getairmail.com',
			'filzmail.com',
			'trashmail.com',
			'mailexpire.com',
			'hmamail.com',
			'crapmail.org',
			'mt2015.com',
		);

		$extra_raw = Akibara_WD_Settings::get( 'blacklist_extra', '' );
		$extra     = array_filter( array_map( 'trim', explode( "\n", (string) $extra_raw ) ) );

		return (array) apply_filters( 'akibara_welcome_email_blacklist', array_merge( $defaults, $extra ) );
	}

	public static function is_domain_blacklisted( string $email ): bool {
		$parts  = explode( '@', strtolower( $email ) );
		$domain = end( $parts );
		return in_array( $domain, self::get_blacklisted_domains(), true );
	}

	// ─── Rate limiting ───────────────────────────────────────────────

	private static function rl_key( string $ip ): string {
		return 'akb_wd_rl_' . md5( $ip );
	}

	public static function is_rate_limited( string $ip ): bool {
		$max = (int) Akibara_WD_Settings::get( 'rate_limit_day', 3 );
		$cnt = (int) get_transient( self::rl_key( $ip ) );
		return $cnt >= $max;
	}

	public static function increment_rate_limit( string $ip ): void {
		$key = self::rl_key( $ip );
		$cnt = (int) get_transient( $key );
		set_transient( $key, $cnt + 1, DAY_IN_SECONDS );
	}

	// ─── Captcha (server-side math challenge) ────────────────────────

	public static function generate_captcha(): array {
		$a  = random_int( 2, 15 );
		$b  = random_int( 2, 15 );
		$id = wp_generate_password( 24, false );
		set_transient( 'akb_wd_cap_' . $id, $a + $b, 5 * MINUTE_IN_SECONDS );
		return array(
			'id'       => $id,
			'question' => "¿Cuanto es {$a} + {$b}?",
		);
	}

	public static function verify_captcha( string $id, string $answer ): bool {
		if ( ! preg_match( '/^[a-zA-Z0-9]{16,30}$/', $id ) ) {
			return false;
		}
		$expected = get_transient( 'akb_wd_cap_' . $id );
		if ( $expected === false ) {
			return false;
		}
		delete_transient( 'akb_wd_cap_' . $id );
		return (int) $answer === (int) $expected;
	}

	// ─── Email validation ────────────────────────────────────────────

	/**
	 * @return true|WP_Error
	 */
	public static function validate_email( string $email ) {
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'Email invalido.' );
		}
		if ( self::is_domain_blacklisted( $email ) ) {
			return new WP_Error( 'blacklisted_domain', 'Este tipo de email no esta permitido. Usa tu email personal o de trabajo.' );
		}
		return true;
	}

	// ─── Subscribe flow ──────────────────────────────────────────────

	/**
	 * Register a new subscription.
	 *
	 * @param string $email
	 * @param string $ip
	 * @param string $captcha_id
	 * @param string $captcha_answer
	 * @return true|WP_Error
	 */
	public static function subscribe( string $email, string $ip, string $captcha_id, string $captcha_answer ) {
		global $wpdb;

		$email = strtolower( sanitize_email( $email ) );

		// 1. Email format + domain blacklist
		$valid = self::validate_email( $email );
		if ( is_wp_error( $valid ) ) {
			Akibara_WD_Log::write( Akibara_WD_Log::EV_INVALID_DOMAIN, $email, '', array(), $ip );
			return $valid;
		}

		// 2. IP rate limit
		if ( self::is_rate_limited( $ip ) ) {
			Akibara_WD_Log::write( Akibara_WD_Log::EV_RATELIMIT_HIT, $email, '', array(), $ip );
			return new WP_Error( 'rate_limited', 'Demasiadas solicitudes. Intenta mas tarde.' );
		}

		// 3. Captcha
		if ( ! self::verify_captcha( $captcha_id, $captcha_answer ) ) {
			Akibara_WD_Log::write( Akibara_WD_Log::EV_CAPTCHA_FAIL, $email, '', array(), $ip );
			return new WP_Error( 'captcha_fail', 'Respuesta incorrecta. Intenta de nuevo.' );
		}

		// 4. Duplicate check
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, status, coupon_code FROM %i WHERE email = %s LIMIT 1',
				akb_wd_table_sub(),
				$email
			)
		);

		if ( $existing ) {
			if ( $existing->status === 'confirmed' && ! empty( $existing->coupon_code ) ) {
				Akibara_WD_Log::write( Akibara_WD_Log::EV_DUPLICATE_EMAIL, $email, $existing->coupon_code, array(), $ip );
				return new WP_Error( 'already_subscribed', 'Ya tienes un cupon de bienvenida. Revisa tu bandeja de entrada.' );
			}
			// Pending or expired: regenerate token, don't create new row.
		} else {
			$wpdb->insert(
				akb_wd_table_sub(),
				array(
					'email'            => $email,
					'ip_hash'          => hash( 'sha256', $ip ),
					'token_hash'       => '',
					'token_expires_at' => current_time( 'mysql' ),
					'status'           => 'pending',
					'created_at'       => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		// 5. Generate token
		$token    = Akibara_WD_Token::generate( $email );
		$settings = Akibara_WD_Settings::all();

		if ( (int) $settings['double_optin'] ) {
			// Double opt-in: send confirmation email, wait for click
			$sent = Akibara_WD_Email::send_confirmation( $email, $token );
			Akibara_WD_Log::write(
				Akibara_WD_Log::EV_EMAIL_SENT,
				$email,
				'',
				array(
					'type' => 'confirmation',
					'sent' => $sent,
				),
				$ip
			);
		} else {
			// Single opt-in: confirm immediately (no email click required)
			return self::confirm( $token );
		}

		// 6. Increment rate limit counter
		self::increment_rate_limit( $ip );
		Akibara_WD_Log::write( Akibara_WD_Log::EV_SUBSCRIBED, $email, '', array(), $ip );

		return true;
	}

	// ─── Confirm flow ─────────────────────────────────────────────────

	/**
	 * Confirm a subscription from the email link token.
	 *
	 * @param string $raw_token
	 * @return true|WP_Error
	 */
	public static function confirm( string $raw_token ) {
		global $wpdb;

		$email = Akibara_WD_Token::verify( $raw_token );

		if ( ! $email ) {
			$event = Akibara_WD_Token::is_expired( $raw_token )
				? Akibara_WD_Log::EV_EXPIRED_TOKEN
				: Akibara_WD_Log::EV_INVALID_TOKEN;
			Akibara_WD_Log::write( $event );

			$code = $event === Akibara_WD_Log::EV_EXPIRED_TOKEN ? 'expired_token' : 'invalid_token';
			$msg  = $event === Akibara_WD_Log::EV_EXPIRED_TOKEN
				? 'El enlace expiro (valido 48 h). Suscribete de nuevo para obtener un cupon nuevo.'
				: 'El enlace no es valido.';
			return new WP_Error( $code, $msg );
		}

		// Mark confirmed and invalidate token
		$wpdb->update(
			akb_wd_table_sub(),
			array(
				'status'       => 'confirmed',
				'confirmed_at' => current_time( 'mysql' ),
				'token_hash'   => '',
			),
			array( 'email' => $email ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		Akibara_WD_Log::write( Akibara_WD_Log::EV_CONFIRMED, $email );

		// Generate WC coupon and save code
		$coupon_code = Akibara_WD_Coupon::generate( $email );

		$wpdb->update(
			akb_wd_table_sub(),
			array( 'coupon_code' => $coupon_code ),
			array( 'email' => $email ),
			array( '%s' ),
			array( '%s' )
		);

		Akibara_WD_Log::write( Akibara_WD_Log::EV_COUPON_GENERATED, $email, $coupon_code );

		// Send coupon email
		$settings = Akibara_WD_Settings::all();
		$sent     = Akibara_WD_Email::send_coupon( $email, $coupon_code, $settings );
		Akibara_WD_Log::write(
			Akibara_WD_Log::EV_EMAIL_SENT,
			$email,
			$coupon_code,
			array(
				'type' => 'coupon',
				'sent' => $sent,
			)
		);

		return true;
	}
}
