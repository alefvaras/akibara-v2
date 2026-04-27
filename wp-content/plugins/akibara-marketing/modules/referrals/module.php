<?php
/**
 * Akibara Marketing — Referral Program
 *
 * Lifted from server-snapshot plugins/akibara/modules/referrals/module.php (v1.1.0).
 * Adapted: load guard changed from AKIBARA_V10_LOADED → AKB_MARKETING_LOADED.
 * DB table wp_akb_referrals is now managed by the marketing plugin's central dbDelta
 * in akibara-marketing.php (AKB_MARKETING_DB_VERSION sentinel). This module skips
 * its own install call to avoid double-managing the same table.
 * Group wrap pattern applied (Sprint 2 REDESIGN.md §9).
 *
 * @package    Akibara\Marketing
 * @subpackage Referrals
 * @version    1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_MARKETING_REFERRALS_LOADED' ) ) {
	return;
}
define( 'AKB_MARKETING_REFERRALS_LOADED', '1.1.0' );

// ── Constants (always defined) ──────────────────────────────────────────────
if ( ! defined( 'AKB_REF_AMOUNT' ) )       { define( 'AKB_REF_AMOUNT', 3000 ); }     // $3,000 CLP referee discount
if ( ! defined( 'AKB_REF_MIN_ORDER' ) )    { define( 'AKB_REF_MIN_ORDER', 25000 ); } // Min order for referral coupon
if ( ! defined( 'AKB_REF_COOKIE_DAYS' ) )  { define( 'AKB_REF_COOKIE_DAYS', 30 ); }
if ( ! defined( 'AKB_REF_MAX_PER_MONTH' ) ) { define( 'AKB_REF_MAX_PER_MONTH', 5 ); }

// ── Group wrap ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_marketing_referrals_sentinel' ) ) {

	function akb_marketing_referrals_sentinel(): bool {
		return defined( 'AKB_MARKETING_REFERRALS_LOADED' );
	}

	// ── REFERRAL CODE GENERATION ──────────────────────────────────────────────

	function akb_get_user_referral_code( int $user_id = 0 ): string {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return '';
		}
		$code = get_user_meta( $user_id, '_akb_referral_code', true );
		if ( ! empty( $code ) ) {
			return (string) $code;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}
		$name = strtoupper( substr( preg_replace( '/[^a-z]/', '', strtolower( $user->first_name ?: $user->display_name ) ), 0, 8 ) );
		if ( empty( $name ) ) {
			$name = 'AKI';
		}
		global $wpdb;
		$table    = $wpdb->prefix . 'akb_referrals';
		$inserted = false;
		$code     = '';
		for ( $attempts = 0; $attempts < 5; $attempts++ ) {
			$code   = 'REF-' . $name . '-' . strtoupper( wp_generate_password( 4, false, false ) );
			$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (referrer_email, referrer_code, status) VALUES (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user->user_email,
					$code,
					'pending'
				)
			);
			if ( $result ) {
				$inserted = true;
				break;
			}
		}
		if ( ! $inserted ) {
			return '';
		}
		update_user_meta( $user_id, '_akb_referral_code', $code );
		return $code;
	}

	// ── SET REF COOKIE FROM ?ref=CODE ─────────────────────────────────────────

	add_action( 'init', function (): void {
		if ( isset( $_GET['ref'] ) && ! is_admin() ) {
			$ref = sanitize_text_field( wp_unslash( $_GET['ref'] ) );
			if ( preg_match( '/^REF-[A-Z]{1,8}-[A-Z0-9]{4}$/', $ref ) ) {
				setcookie( 'akb_ref', $ref, time() + ( AKB_REF_COOKIE_DAYS * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
				$_COOKIE['akb_ref'] = $ref;
			}
		}
	} );

	// ── APPLY REFEREE COUPON AT CHECKOUT (cart discount) ─────────────────────

	add_action( 'woocommerce_before_calculate_totals', 'akb_referral_apply_discount', 10, 1 );

	function akb_referral_apply_discount( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( empty( $_COOKIE['akb_ref'] ) ) {
			return;
		}
		// Only for non-logged-in or logged-in users who haven't purchased before
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			if ( wc_get_customer_order_count( $user_id ) > 0 ) {
				return;
			}
		}
		// Check cart meets minimum
		if ( $cart->get_subtotal() < AKB_REF_MIN_ORDER ) {
			return;
		}
		// Check if referral coupon already applied
		$applied = $cart->get_applied_coupons();
		foreach ( $applied as $c ) {
			if ( strpos( $c, 'refreferido-' ) === 0 ) {
				return;
			}
		}
		$ref = sanitize_text_field( wp_unslash( $_COOKIE['akb_ref'] ) );
		// Validate the ref code exists in DB
		global $wpdb;
		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}akb_referrals WHERE referrer_code = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ref
			)
		);
		if ( ! $exists ) {
			return;
		}
		// Create or get referee coupon code
		$coupon_code = strtolower( 'refreferido-' . $ref );
		if ( ! $cart->has_discount( $coupon_code ) ) {
			$cart->add_discount( $coupon_code );
		}
	}

	// Create referee discount coupon on-the-fly if it doesn't exist
	add_filter( 'woocommerce_get_shop_coupon_data', 'akb_referral_virtual_coupon', 10, 2 );

	function akb_referral_virtual_coupon( $data, string $code ): mixed {
		if ( strpos( $code, 'refreferido-' ) !== 0 ) {
			return $data;
		}
		return array(
			'discount_type'   => 'fixed_cart',
			'coupon_amount'   => AKB_REF_AMOUNT,
			'minimum_amount'  => (string) AKB_REF_MIN_ORDER,
			'usage_limit'     => 1,
			'individual_use'  => true,
			'free_shipping'   => false,
		);
	}

	// ── ON FIRST COMPLETED ORDER — reward referrer ────────────────────────────

	add_action( 'woocommerce_order_status_completed', 'akb_referral_process_reward', 20 );

	function akb_referral_process_reward( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Only first completed order from customer
		$customer_id = $order->get_customer_id();
		if ( $customer_id && wc_get_customer_order_count( $customer_id ) > 1 ) {
			return;
		}
		// Check for ref cookie in order meta
		$ref_code = $order->get_meta( '_akb_ref_code' );
		if ( empty( $ref_code ) ) {
			return;
		}
		// Already processed
		if ( $order->get_meta( '_akb_ref_rewarded' ) ) {
			return;
		}
		global $wpdb;
		$referral = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}akb_referrals WHERE referrer_code = %s AND status = 'pending' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ref_code
			)
		);
		if ( ! $referral ) {
			return;
		}
		// Check monthly cap
		$month_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}akb_referrals WHERE referrer_email = %s AND status = 'completed' AND YEAR(completed_at) = YEAR(NOW()) AND MONTH(completed_at) = MONTH(NOW())", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$referral->referrer_email
			)
		);
		if ( $month_count >= AKB_REF_MAX_PER_MONTH ) {
			return;
		}
		// Issue referrer reward coupon ($3,000 CLP)
		$coupon_code = 'REFREFERIDO-' . strtoupper( wp_generate_password( 6, false, false ) );
		$coupon      = new WC_Coupon();
		$coupon->set_code( $coupon_code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( (string) AKB_REF_AMOUNT );
		$coupon->set_individual_use( false );
		$coupon->set_usage_limit( 1 );
		$coupon->set_minimum_amount( (string) AKB_REF_MIN_ORDER );
		$coupon->add_meta_data( '_akb_referral_reward', '1', true );
		$coupon->save();
		// Update referral record
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'akb_referrals',
			array(
				'referee_email'       => $order->get_billing_email(),
				'referee_order_id'    => $order_id,
				'referrer_coupon_code' => $coupon_code,
				'status'              => 'completed',
				'completed_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $referral->id )
		);
		$order->update_meta_data( '_akb_ref_rewarded', '1' );
		$order->save();
		// Notify referrer by email
		if ( class_exists( 'AkibaraBrevo' ) ) {
			$api_key = \AkibaraBrevo::get_api_key();
			if ( ! empty( $api_key ) ) {
				akb_referral_send_reward_email( $referral->referrer_email, $coupon_code, $api_key );
			}
		}
	}

	// Save ref cookie to order meta on checkout
	add_action( 'woocommerce_checkout_update_order_meta', function ( int $order_id ): void {
		if ( ! empty( $_COOKIE['akb_ref'] ) ) {
			update_post_meta( $order_id, '_akb_ref_code', sanitize_text_field( wp_unslash( $_COOKIE['akb_ref'] ) ) );
		}
	} );

	function akb_referral_send_reward_email( string $email, string $coupon_code, string $api_key ): bool {
		$subject  = '¡Te ganaste un descuento por referir un amigo!';
		$body_html = sprintf(
			'<p>Uno de tus referidos acaba de hacer su primera compra. ¡Gracias por difundir Akibara!<br><br>Tu cupón de recompensa: <strong style="font-size:20px;color:#D90010">%s</strong><br><br>$%s CLP de descuento en tu próxima compra sobre $%s CLP.</p>',
			esc_html( $coupon_code ),
			number_format( AKB_REF_AMOUNT, 0, ',', '.' ),
			number_format( AKB_REF_MIN_ORDER, 0, ',', '.' )
		);
		$response = wp_remote_post(
			'https://api.brevo.com/v3/smtp/email',
			array(
				'headers' => array( 'api-key' => $api_key, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'sender'      => array( 'name' => 'Akibara', 'email' => 'contacto@akibara.cl' ),
					'to'          => array( array( 'email' => \AkibaraBrevo::test_recipient( $email ) ) ),
					'subject'     => $subject,
					'htmlContent' => $body_html,
				) ),
				'timeout' => 10,
			)
		);
		return ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 300;
	}

	// ── ADMIN tab ─────────────────────────────────────────────────────────────

	add_filter( 'akibara_admin_tabs', function ( array $tabs ): array {
		$tabs['referrals'] = array(
			'label'       => 'Referidos',
			'short_label' => 'Referidos',
			'icon'        => 'dashicons-groups',
			'group'       => 'marketing',
			'callback'    => 'akb_referral_render_admin',
		);
		return $tabs;
	} );

	function akb_referral_render_admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}
		global $wpdb;
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_referrals" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_referrals WHERE status='completed'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		?>
		<div class="akb-page-header">
			<h2 class="akb-page-header__title">Programa de Referidos</h2>
			<p class="akb-page-header__desc">Códigos REF-NOMBRE-XXXX. Referee obtiene $<?php echo number_format( AKB_REF_AMOUNT, 0, ',', '.' ); ?> CLP. v<?php echo esc_html( AKB_MARKETING_REFERRALS_LOADED ); ?></p>
		</div>
		<div class="akb-stats">
			<div class="akb-stat"><div class="akb-stat__value"><?php echo esc_html( (string) $total ); ?></div><div class="akb-stat__label">Códigos generados</div></div>
			<div class="akb-stat"><div class="akb-stat__value akb-stat__value--success"><?php echo esc_html( (string) $completed ); ?></div><div class="akb-stat__label">Referidos completados</div></div>
		</div>
		<div class="akb-notice akb-notice--info">
			<strong>Cómo funciona:</strong> Cada cliente registrado puede compartir su link ?ref=REF-NAME-XXXX.
			El nuevo cliente recibe $<?php echo number_format( AKB_REF_AMOUNT, 0, ',', '.' ); ?> CLP de descuento.
			Al completar su primera compra, el referidor recibe un cupón de recompensa. Máximo <?php echo esc_html( (string) AKB_REF_MAX_PER_MONTH ); ?> referidos por mes.
		</div>
		<?php
	}

} // end group wrap
