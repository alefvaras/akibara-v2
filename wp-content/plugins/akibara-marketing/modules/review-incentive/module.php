<?php
/**
 * Akibara Marketing — Incentivo Reseñas
 *
 * Lifted from server-snapshot plugins/akibara/modules/review-incentive/module.php (v1.0.0).
 * Adapted: load guard changed from AKIBARA_V10_LOADED → AKB_MARKETING_LOADED.
 * Group wrap pattern applied (Sprint 2 REDESIGN.md §9).
 *
 * When a verified customer leaves a product review, they automatically
 * receive a unique 5% discount coupon via email (Brevo).
 *
 * @package    Akibara\Marketing
 * @subpackage ReviewIncentive
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_MARKETING_REVIEW_INCENTIVE_LOADED' ) ) {
	return;
}
define( 'AKB_MARKETING_REVIEW_INCENTIVE_LOADED', '1.0.0' );

// ── Group wrap ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_marketing_review_incentive_sentinel' ) ) {

	function akb_marketing_review_incentive_sentinel(): bool {
		return defined( 'AKB_MARKETING_REVIEW_INCENTIVE_LOADED' );
	}

	/**
	 * Config editable desde admin UI. Bounds razonables para prevenir typos.
	 *
	 * @return array{pct:int,min:int,days:int}
	 */
	function akb_review_incentive_config(): array {
		$pct  = (int) get_option( 'akb_review_incentive_pct', 5 );
		$min  = (int) get_option( 'akb_review_incentive_min', 20000 );
		$days = (int) get_option( 'akb_review_incentive_days', 30 );
		return array(
			'pct'  => max( 1, min( 50, $pct ) ),
			'min'  => max( 0, min( 500000, $min ) ),
			'days' => max( 1, min( 365, $days ) ),
		);
	}

	// When comment is posted (auto-approved reviews)
	add_action( 'comment_post', 'akb_review_incentive_on_comment', 10, 3 );
	add_action( 'wp_set_comment_status', 'akb_review_incentive_on_approve', 10, 2 );

	function akb_review_incentive_on_comment( int $comment_id, $approved, array $commentdata ): void {
		if ( $approved !== 1 ) {
			return;
		}
		akb_review_incentive_process( $comment_id );
	}

	function akb_review_incentive_on_approve( string $comment_id, string $status ): void {
		if ( $status !== 'approve' ) {
			return;
		}
		akb_review_incentive_process( (int) $comment_id );
	}

	/**
	 * Check if a coupon can be issued for this email + product combination.
	 * Lock persisted as wp_option with autoload=no (avoids custom table for low-volume).
	 *
	 * @return array{can:bool,reason:string}
	 */
	function akb_review_incentive_can_issue( string $email, int $product_id ): array {
		if ( ! wc_customer_bought_product( $email, 0, $product_id ) ) {
			return array( 'can' => false, 'reason' => 'not_verified_buyer' );
		}
		$lock_key = 'akb_ri_issued_' . md5( $email . '_' . $product_id );
		if ( false !== get_option( $lock_key, false ) ) {
			return array( 'can' => false, 'reason' => 'duplicate_email_product' );
		}
		return array( 'can' => true, 'reason' => '' );
	}

	/**
	 * Main processor: validate review, create coupon, send email.
	 */
	function akb_review_incentive_process( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || $post->post_type !== 'product' ) {
			return;
		}
		$rating = (int) get_comment_meta( $comment_id, 'rating', true );
		if ( $rating < 1 ) {
			return;
		}
		$email = $comment->comment_author_email;
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}
		// Already rewarded for this specific comment
		if ( ! empty( get_comment_meta( $comment_id, '_akb_review_coupon', true ) ) ) {
			return;
		}
		$check = akb_review_incentive_can_issue( $email, (int) $comment->comment_post_ID );
		if ( ! $check['can'] ) {
			return;
		}

		$cfg    = akb_review_incentive_config();
		$code   = 'RESENA-' . strtoupper( wp_generate_password( 5, false, false ) );
		$expiry = gmdate( 'Y-m-d', strtotime( '+' . $cfg['days'] . ' days' ) );

		// Create WC coupon
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( $cfg['pct'] );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_minimum_amount( (string) $cfg['min'] );
		$coupon->set_date_expires( $expiry );
		$coupon->add_meta_data( '_akb_review_incentive', '1', true );
		$coupon->add_meta_data( '_akb_review_comment_id', $comment_id, true );
		$coupon->add_meta_data( '_akb_review_email', $email, true );
		$coupon->save();

		// Lock: prevent duplicate issuance
		$lock_key = 'akb_ri_issued_' . md5( $email . '_' . $comment->comment_post_ID );
		add_option( $lock_key, array( 'code' => $code, 'issued_at' => gmdate( 'Y-m-d H:i:s' ) ), '', 'no' );
		update_comment_meta( $comment_id, '_akb_review_coupon', $code );

		// Send email via Brevo
		if ( class_exists( 'AkibaraBrevo' ) ) {
			$api_key = \AkibaraBrevo::get_api_key();
			if ( ! empty( $api_key ) ) {
				akb_review_incentive_send_email( $email, $comment->comment_author, $code, $cfg['pct'], $expiry, $api_key );
			}
		}
	}

	function akb_review_incentive_send_email( string $email, string $name, string $code, int $pct, string $expiry, string $api_key ): bool {
		$first_name = $name ?: 'Lector';
		$subject    = $first_name . ', gracias por tu reseña — aquí está tu descuento';
		$body_html  = sprintf(
			'<p>Hola <strong>%s</strong>,<br><br>Gracias por dejar tu reseña. Como agradecimiento, aquí está tu cupón de descuento exclusivo:<br><br><strong style="font-size:24px;color:#D90010">%s</strong><br><br>%d%% de descuento. Válido hasta el %s. Un solo uso.<br><br>¡Úsalo en tu próxima compra!</p>',
			esc_html( $first_name ),
			esc_html( $code ),
			$pct,
			esc_html( $expiry )
		);
		$response = wp_remote_post(
			'https://api.brevo.com/v3/smtp/email',
			array(
				'headers' => array( 'api-key' => $api_key, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'sender'      => array( 'name' => 'Akibara', 'email' => 'contacto@akibara.cl' ),
					'to'          => array( array( 'email' => \AkibaraBrevo::test_recipient( $email ), 'name' => $first_name ) ),
					'subject'     => $subject,
					'htmlContent' => $body_html,
				) ),
				'timeout' => 10,
			)
		);
		if ( is_wp_error( $response ) ) {
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'review-incentive', 'error', 'Email send failed', array( 'err' => $response->get_error_message() ) );
			}
			return false;
		}
		return (int) wp_remote_retrieve_response_code( $response ) < 300;
	}

	// ── ADMIN tab via akibara_admin_tabs filter ───────────────────────────────

	add_filter( 'akibara_admin_tabs', function ( array $tabs ): array {
		$tabs['review_incentive'] = array(
			'label'       => 'Incentivo Reseñas',
			'short_label' => 'Reseñas',
			'icon'        => 'dashicons-star-filled',
			'group'       => 'marketing',
			'callback'    => 'akb_review_incentive_render_admin',
		);
		return $tabs;
	} );

	function akb_review_incentive_render_admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}
		if ( isset( $_POST['akb_ri_save'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'akb_ri_save' ) ) {
			update_option( 'akb_review_incentive_pct', max( 1, min( 50, (int) ( $_POST['pct'] ?? 5 ) ) ) );
			update_option( 'akb_review_incentive_min', max( 0, min( 500000, (int) ( $_POST['min'] ?? 20000 ) ) ) );
			update_option( 'akb_review_incentive_days', max( 1, min( 365, (int) ( $_POST['days'] ?? 30 ) ) ) );
			echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
		}
		$cfg = akb_review_incentive_config();
		?>
		<div class="akb-page-header">
			<h2 class="akb-page-header__title">Incentivo de Reseñas</h2>
			<p class="akb-page-header__desc">Cupón automático cuando un comprador verificado deja una reseña. v<?php echo esc_html( AKB_MARKETING_REVIEW_INCENTIVE_LOADED ); ?></p>
		</div>
		<div class="akb-card akb-card--section">
			<h3 class="akb-section-title">Configuración</h3>
			<form method="post">
				<?php wp_nonce_field( 'akb_ri_save' ); ?>
				<div class="akb-field">
					<label class="akb-field__label">Descuento (%)</label>
					<input type="number" name="pct" value="<?php echo esc_attr( (string) $cfg['pct'] ); ?>" min="1" max="50" class="akb-field__input" style="max-width:100px">
				</div>
				<div class="akb-field">
					<label class="akb-field__label">Compra mínima (CLP)</label>
					<input type="number" name="min" value="<?php echo esc_attr( (string) $cfg['min'] ); ?>" min="0" step="1000" class="akb-field__input" style="max-width:150px">
				</div>
				<div class="akb-field">
					<label class="akb-field__label">Validez (días)</label>
					<input type="number" name="days" value="<?php echo esc_attr( (string) $cfg['days'] ); ?>" min="1" max="365" class="akb-field__input" style="max-width:100px">
				</div>
				<button type="submit" name="akb_ri_save" value="1" class="akb-btn akb-btn--primary">Guardar</button>
			</form>
		</div>
		<?php
	}

} // end group wrap
