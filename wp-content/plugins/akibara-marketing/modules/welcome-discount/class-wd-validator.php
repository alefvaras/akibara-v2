<?php
/**
 * Akibara Welcome Discount — Checkout Validator
 *
 * Three anti-abuse checks at woocommerce_checkout_process (form submission):
 *
 *   1. RUT format (modulo 11 checksum) — hard block on invalid RUT
 *   2. RUT uniqueness — hard block if RUT already has completed/processing orders
 *   3. Email uniqueness — hard block if email already redeemed a welcome coupon
 *   4. Delivery fingerprint — SOFT check: log + notify admin, don't block
 *      (avoids false positives on shared addresses, student residences, etc.)
 *
 * Post-order hooks store the RUT hash and fingerprint for future audits.
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/class-wd-validator.php
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

class Akibara_WD_Validator {

	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_at_checkout' ) );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'save_redemption_data' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'mark_coupon_redeemed' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'mark_coupon_redeemed' ), 10, 1 );
	}

	// ─── Main checkout validation ────────────────────────────────────

	public function validate_at_checkout(): void {
		$welcome = $this->get_welcome_coupon_in_cart();
		if ( ! $welcome ) {
			return;
		}

		// Nonce 'woocommerce-process_checkout' validado por WC_Checkout::process_checkout() antes de disparar este hook.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$billing_email = strtolower( sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) ) );
		$billing_rut   = sanitize_text_field( wp_unslash( $_POST['billing_rut'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$ip            = $this->get_client_ip();

		// 1. RUT format validation
		$clean_rut = $this->validate_rut_format( $billing_rut );
		if ( is_wp_error( $clean_rut ) ) {
			wc_add_notice( 'RUT invalido. Verifica el numero ingresado antes de continuar.', 'error' );
			Akibara_WD_Log::write( Akibara_WD_Log::EV_INVALID_RUT_FMT, $billing_email, $welcome, array( 'raw' => substr( $billing_rut, 0, 20 ) ), $ip );
			return;
		}

		// 2. RUT uniqueness — new customers only
		if ( $this->rut_has_previous_orders( $clean_rut ) ) {
			wc_add_notice(
				'Este cupon es exclusivo para clientes nuevos. Tu RUT ya tiene pedidos anteriores en Akibara.',
				'error'
			);
			Akibara_WD_Log::write(
				Akibara_WD_Log::EV_REJECTED_RUT,
				$billing_email,
				$welcome,
				array(
					'reason'   => 'existing_customer',
					'rut_hash' => hash( 'sha256', $clean_rut ),
				),
				$ip
			);
			return;
		}

		// 3. Email uniqueness — one coupon per email ever
		if ( $this->email_already_redeemed( $billing_email ) ) {
			wc_add_notice(
				'Este email ya canjeo un cupon de bienvenida anteriormente.',
				'error'
			);
			Akibara_WD_Log::write( Akibara_WD_Log::EV_REJECTED_EMAIL, $billing_email, $welcome, array(), $ip );
			return;
		}

		// 4. Delivery fingerprint — soft check (log + notify, allow order)
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce woocommerce-process_checkout validado por WC core.
		$fp = $this->compute_delivery_fingerprint( wp_unslash( $_POST ) );
		if ( $this->fingerprint_is_suspicious( $fp, $billing_email ) ) {
			Akibara_WD_Log::write( Akibara_WD_Log::EV_SUSPICIOUS_ADDR, $billing_email, $welcome, array( 'fp' => $fp ), $ip );
			$this->notify_admin_suspicious( $billing_email, $welcome, $fp );
		}

		// Save session data for post-order hooks
		if ( WC()->session ) {
			WC()->session->set( 'akb_wd_rut_hash', hash( 'sha256', $clean_rut ) );
			WC()->session->set( 'akb_wd_fingerprint', $fp );
			WC()->session->set( 'akb_wd_welcome_coupon', $welcome );
		}
	}

	// ─── Post-order hooks ────────────────────────────────────────────

	public function save_redemption_data( $order ): void {
		if ( ! WC()->session ) {
			return;
		}

		$coupon = WC()->session->get( 'akb_wd_welcome_coupon', '' );
		if ( ! $coupon ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			akb_wd_table_sub(),
			array(
				'rut_hash'    => WC()->session->get( 'akb_wd_rut_hash', '' ),
				'delivery_fp' => WC()->session->get( 'akb_wd_fingerprint', '' ),
			),
			array(
				'email'       => strtolower( $order->get_billing_email() ),
				'coupon_code' => $coupon,
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		WC()->session->__unset( 'akb_wd_rut_hash' );
		WC()->session->__unset( 'akb_wd_fingerprint' );
		WC()->session->__unset( 'akb_wd_welcome_coupon' );
	}

	public function mark_coupon_redeemed( int $order_id ): void {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_coupon_codes() as $code ) {
			if ( ! Akibara_WD_Coupon::is_welcome_coupon( $code ) ) {
				continue;
			}

			$wpdb->update(
				akb_wd_table_sub(),
				array( 'coupon_redeemed_at' => current_time( 'mysql' ) ),
				array( 'coupon_code' => $code ),
				array( '%s' ),
				array( '%s' )
			);

			Akibara_WD_Log::write(
				Akibara_WD_Log::EV_COUPON_REDEEMED,
				$order->get_billing_email(),
				$code,
				array( 'order_id' => $order_id )
			);
		}
	}

	// ─── RUT helpers ────────────────────────────────────────────────

	/**
	 * Validate and clean a Chilean RUT.
	 *
	 * Accepts: "12.345.678-9", "12345678-K", "12345678K", "123456789"
	 *
	 * @param string $rut Raw input from user.
	 * @return string|WP_Error Clean RUT (digits + DV, no dots/hyphen) on success.
	 */
	public function validate_rut_format( string $rut ) {
		$clean = strtoupper( preg_replace( '/[^0-9kK]/', '', $rut ) );

		if ( strlen( $clean ) < 2 ) {
			return new WP_Error( 'invalid_rut', 'RUT demasiado corto.' );
		}

		$dv  = substr( $clean, -1 );
		$num = substr( $clean, 0, -1 );

		if ( ! ctype_digit( $num ) || (int) $num < 100000 ) {
			return new WP_Error( 'invalid_rut', 'RUT invalido.' );
		}

		// Modulo 11 checksum
		$sum  = 0;
		$mult = 2;
		for ( $i = strlen( $num ) - 1; $i >= 0; $i-- ) {
			$sum += (int) $num[ $i ] * $mult;
			$mult = ( $mult === 7 ) ? 2 : $mult + 1;
		}
		$rem = 11 - ( $sum % 11 );

		if ( $rem === 11 ) {
			$expected = '0';
		} elseif ( $rem === 10 ) {
			$expected = 'K';
		} else {
			$expected = (string) $rem;
		}

		if ( $dv !== $expected ) {
			return new WP_Error( 'invalid_rut', 'RUT invalido (digito verificador incorrecto).' );
		}

		return $num . $dv;
	}

	public function rut_has_previous_orders( string $clean_rut ): bool {
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_billing_rut',
				'meta_value' => $clean_rut,
				'status'     => array( 'wc-completed', 'wc-processing' ),
				'limit'      => 1,
				'return'     => 'ids',
			)
		);
		return ! empty( $orders );
	}

	public function email_already_redeemed( string $email ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- akb_wd_table_sub() devuelve $wpdb->prefix . tabla custom (sin user input).
		$row = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . akb_wd_table_sub() . ' WHERE email = %s AND coupon_redeemed_at IS NOT NULL LIMIT 1',
				strtolower( $email )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $row;
	}

	// ─── Delivery fingerprint ────────────────────────────────────────

	public function compute_delivery_fingerprint( array $post ): string {
		$name = strtolower(
			sanitize_text_field(
				( $post['ship_to_different_address'] ?? 0 )
				? ( $post['shipping_first_name'] ?? '' ) . ' ' . ( $post['shipping_last_name'] ?? '' )
				: ( $post['billing_first_name'] ?? '' ) . ' ' . ( $post['billing_last_name'] ?? '' )
			)
		);
		$addr = strtolower(
			sanitize_text_field(
				( $post['ship_to_different_address'] ?? 0 )
				? ( $post['shipping_address_1'] ?? '' )
				: ( $post['billing_address_1'] ?? '' )
			)
		);
		$city = strtolower(
			sanitize_text_field(
				( $post['ship_to_different_address'] ?? 0 )
				? ( $post['shipping_city'] ?? '' )
				: ( $post['billing_city'] ?? '' )
			)
		);

		return hash( 'sha256', $name . '|' . $addr . '|' . $city );
	}

	public function fingerprint_is_suspicious( string $fp, string $email ): bool {
		global $wpdb;
		if ( empty( $fp ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- akb_wd_table_sub() devuelve $wpdb->prefix . tabla custom (sin user input).
		$row = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . akb_wd_table_sub() . '
			 WHERE delivery_fp = %s
			   AND email != %s
			   AND coupon_redeemed_at IS NOT NULL
			 LIMIT 1',
				$fp,
				strtolower( $email )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return (bool) $row;
	}

	// ─── Helpers ────────────────────────────────────────────────────

	private function get_welcome_coupon_in_cart(): ?string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			if ( Akibara_WD_Coupon::is_welcome_coupon( $code ) ) {
				return $code;
			}
		}
		return null;
	}

	private function get_client_ip(): string {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	private function notify_admin_suspicious( string $email, string $coupon, string $fp ): void {
		wp_mail(
			(string) get_option( 'admin_email' ),
			'[Akibara] Posible abuso — cupon de bienvenida',
			sprintf(
				"Direccion de despacho ya usada en cupon de bienvenida anterior.\n\n" .
				"Email:       %s\n" .
				"Cupon:       %s\n" .
				"Fingerprint: %s\n\n" .
				'El pedido fue PERMITIDO. Revision manual en WC → Bienvenida.',
				$email,
				$coupon,
				$fp
			)
		);
	}
}
