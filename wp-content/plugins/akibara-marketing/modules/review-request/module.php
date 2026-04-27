<?php
/**
 * Akibara Marketing — Solicitud de Reseñas Post-Compra
 *
 * Lifted from server-snapshot plugins/akibara/modules/review-request/module.php (v1.0.0).
 * Adapted: load guard changed from AKIBARA_V10_LOADED → AKB_MARKETING_LOADED.
 * Group wrap pattern applied (Sprint 2 REDESIGN.md §9).
 *
 * @package    Akibara\Marketing
 * @subpackage ReviewRequest
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_MARKETING_REVIEW_REQ_LOADED' ) ) {
	return;
}

// Feature flag
if ( function_exists( 'akb_is_module_enabled' ) && ! akb_is_module_enabled( 'review-request' ) ) {
	return;
}

define( 'AKB_MARKETING_REVIEW_REQ_LOADED', '1.0.0' );

// ── Group wrap ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_marketing_review_req_sentinel' ) ) {

	function akb_marketing_review_req_sentinel(): bool {
		return defined( 'AKB_MARKETING_REVIEW_REQ_LOADED' );
	}

	// ── DEFAULTS ──────────────────────────────────────────────────────────────

	function akb_review_defaults(): array {
		return array(
			'akibara_review_req_enabled'       => true,
			'akibara_review_req_days'          => 10,
			'akibara_review_req_max_products'  => 3,
			'akibara_google_review_enabled'    => true,
			'akibara_google_review_days_after' => 7,
			'akibara_google_review_url'        => '',
			'akibara_review_req_min_spacing'   => 3,
			'akibara_review_req_reask_days'    => 30,
		);
	}

	function akb_review_opt( string $key ): mixed {
		$defaults = akb_review_defaults();
		return get_option( $key, $defaults[ $key ] ?? '' );
	}

	// ── ACTION SCHEDULER — Per-order scheduling ───────────────────────────────

	add_action( 'woocommerce_order_status_processing', 'akb_review_schedule_on_complete', 20 );
	add_action( 'woocommerce_order_status_shipping-progress', 'akb_review_schedule_on_complete', 20 );
	add_action( 'woocommerce_order_status_completed', 'akb_review_schedule_on_complete', 20 );

	function akb_review_schedule_on_complete( int $order_id ): void {
		if ( ! akb_review_opt( 'akibara_review_req_enabled' ) ) {
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Excluir órdenes de MercadoLibre
		if ( $order->get_meta( '_akb_ml_order_id' ) ) {
			return;
		}
		if ( $order->get_meta( '_akb_review_req_scheduled' ) || $order->get_meta( '_akb_review_req_sent' ) ) {
			return;
		}
		$days = (int) akb_review_opt( 'akibara_review_req_days' );
		as_schedule_single_action(
			time() + ( $days * DAY_IN_SECONDS ),
			'akb_review_send_scheduled',
			array( 'order_id' => $order_id ),
			'akibara-review'
		);
		$order->update_meta_data( '_akb_review_req_scheduled', gmdate( 'Y-m-d H:i:s' ) );
		$order->save();
	}

	add_action( 'woocommerce_order_status_refunded', 'akb_review_cancel_on_refund' );
	add_action( 'woocommerce_order_status_cancelled', 'akb_review_cancel_on_refund' );
	add_action( 'woocommerce_order_status_failed', 'akb_review_cancel_on_refund' );

	function akb_review_cancel_on_refund( int $order_id ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( 'akb_review_send_scheduled', array( 'order_id' => $order_id ), 'akibara-review' );
		as_unschedule_all_actions( 'akb_review_send_google_scheduled', array( 'order_id' => $order_id ), 'akibara-review' );
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( '_akb_review_req_sent', 'cancelled' );
			$order->save();
		}
	}

	add_action( 'akb_review_send_scheduled', 'akb_review_execute_product_email' );

	function akb_review_execute_product_email( array $args ): void {
		$order_id = $args['order_id'] ?? 0;
		if ( ! $order_id || ! akb_review_opt( 'akibara_review_req_enabled' ) ) {
			return;
		}
		if ( ! class_exists( 'AkibaraBrevo' ) ) {
			return;
		}
		$api_key = \AkibaraBrevo::get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( in_array( $order->get_meta( '_akb_review_req_sent' ), array( 'yes', 'skip', 'cancelled' ), true ) ) {
			return;
		}
		if ( ! in_array( $order->get_status(), array( 'processing', 'shipping-progress', 'completed' ), true ) ) {
			$order->update_meta_data( '_akb_review_req_sent', 'skip' );
			$order->save();
			return;
		}
		$email      = $order->get_billing_email();
		$first_name = $order->get_billing_first_name();
		if ( empty( $email ) ) {
			return;
		}
		if ( ! $order->get_customer_id() && get_option( 'woocommerce_review_rating_verification_required' ) === 'yes' ) {
			$order->update_meta_data( '_akb_review_req_sent', 'skip' );
			$order->save();
			return;
		}
		if ( akb_review_should_suppress( $order, $email ) ) {
			$order->update_meta_data( '_akb_review_req_sent', 'skip' );
			$order->save();
			return;
		}
		$products = akb_review_get_products( $order, $email );
		if ( empty( $products ) ) {
			$order->update_meta_data( '_akb_review_req_sent', 'skip' );
			$order->save();
			return;
		}
		$sent = akb_review_send_product_email( $email, $first_name, $products, $api_key );
		$order->update_meta_data( '_akb_review_req_sent', $sent ? 'yes' : 'failed' );
		$order->update_meta_data( '_akb_review_req_date', gmdate( 'Y-m-d' ) );
		$order->save();
		if ( $sent && akb_review_opt( 'akibara_google_review_enabled' ) ) {
			$google_days = (int) akb_review_opt( 'akibara_google_review_days_after' );
			as_schedule_single_action(
				time() + ( $google_days * DAY_IN_SECONDS ),
				'akb_review_send_google_scheduled',
				array( 'order_id' => $order_id ),
				'akibara-review'
			);
		}
	}

	add_action( 'akb_review_send_google_scheduled', 'akb_review_execute_google_email' );

	function akb_review_execute_google_email( array $args ): void {
		$order_id = $args['order_id'] ?? 0;
		if ( ! $order_id || ! class_exists( 'AkibaraBrevo' ) ) {
			return;
		}
		$api_key = \AkibaraBrevo::get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_akb_google_review_sent' ) ) {
			return;
		}
		$email      = $order->get_billing_email();
		$first_name = $order->get_billing_first_name();
		if ( akb_review_is_opted_out( $email ) ) {
			$order->update_meta_data( '_akb_google_review_sent', 'skip' );
			$order->save();
			return;
		}
		$rating = akb_review_get_customer_rating( $order, $email );
		if ( $rating >= 4 ) {
			$google_url = akb_review_opt( 'akibara_google_review_url' );
			if ( empty( $google_url ) ) {
				$order->update_meta_data( '_akb_google_review_sent', 'skip' );
				$order->save();
				return;
			}
			$sent = akb_review_send_google_email( $email, $first_name, $google_url, $api_key );
			$order->update_meta_data( '_akb_google_review_sent', $sent ? 'yes' : 'failed' );
		} elseif ( $rating >= 1 && $rating <= 3 ) {
			$sent = akb_review_send_support_email( $email, $first_name, $api_key );
			$order->update_meta_data( '_akb_google_review_sent', $sent ? 'support_sent' : 'support_failed' );
		} else {
			$order->update_meta_data( '_akb_google_review_sent', 'no_review' );
		}
		$order->save();
	}

	// Legacy cron cleanup
	add_action( 'init', function (): void {
		$timestamp = wp_next_scheduled( 'akibara_review_request_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'akibara_review_request_check' );
		}
	} );

	// ── SUPPRESSION ───────────────────────────────────────────────────────────

	function akb_review_should_suppress( $order, string $email ): bool {
		if ( akb_review_is_opted_out( $email ) ) {
			return true;
		}
		if ( $order->get_total_refunded() >= ( (float) $order->get_total() * 0.5 ) ) {
			return true;
		}
		$min_spacing   = (int) akb_review_opt( 'akibara_review_req_min_spacing' );
		$recent_orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => array( 'processing', 'shipping-progress', 'completed' ),
				'limit'         => 10,
				'orderby'       => 'date',
				'order'         => 'DESC',
			)
		);
		$now        = time();
		$reask_days = max( 14, (int) akb_review_opt( 'akibara_review_req_reask_days' ) );
		foreach ( $recent_orders as $recent ) {
			if ( $recent->get_id() === $order->get_id() ) {
				continue;
			}
			if ( $recent->get_meta( '_akb_next_vol_sent' ) === 'yes' ) {
				$vol_sent_date = $recent->get_meta( '_akb_next_vol_sent_date' );
				$vol_ts        = $vol_sent_date ? strtotime( $vol_sent_date ) : null;
				if ( ! $vol_ts ) {
					$order_date = $recent->get_date_completed();
					if ( $order_date ) {
						$vol_ts = strtotime( '+7 days', $order_date->getTimestamp() );
					}
				}
				if ( $vol_ts && ( $now - $vol_ts ) >= 0 && ( $now - $vol_ts ) <= ( $min_spacing * DAY_IN_SECONDS ) ) {
					return true;
				}
			}
			$req_date = $recent->get_meta( '_akb_review_req_date' );
			if ( $req_date && ( $now - strtotime( $req_date ) ) < ( $reask_days * DAY_IN_SECONDS ) ) {
				return true;
			}
		}
		return false;
	}

	function akb_review_is_opted_out( string $email ): bool {
		static $optouts   = null;
		static $loaded_at = 0;
		if ( $optouts === null || ( time() - $loaded_at ) > 60 ) {
			$optouts   = get_option( 'akibara_review_optout_emails', array() );
			$loaded_at = time();
		}
		return in_array( $email, (array) $optouts, true );
	}

	// ── GET REVIEWABLE PRODUCTS ───────────────────────────────────────────────

	function akb_review_get_products( $order, string $email ): array {
		$max         = (int) akb_review_opt( 'akibara_review_req_max_products' );
		$products    = array();
		$seen_series = array();
		$items       = $order->get_items();
		$sorted      = array();
		$all_ids     = array();
		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$all_ids[] = $product->get_id();
			$sorted[]  = array( 'product' => $product, 'price' => (float) $product->get_price() );
		}
		usort( $sorted, fn( array $a, array $b ) => $b['price'] <=> $a['price'] );
		$reviewed_ids = akb_review_get_reviewed_ids( $all_ids, $email );
		foreach ( $sorted as $s ) {
			if ( count( $products ) >= $max ) {
				break;
			}
			$product    = $s['product'];
			$product_id = $product->get_id();
			if ( in_array( $product_id, $reviewed_ids, true ) ) {
				continue;
			}
			$serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
			if ( ! empty( $serie_norm ) && isset( $seen_series[ $serie_norm ] ) ) {
				continue;
			}
			if ( ! empty( $serie_norm ) ) {
				$seen_series[ $serie_norm ] = true;
			}
			$img_id     = $product->get_image_id();
			$products[] = array(
				'id'    => $product_id,
				'name'  => $product->get_name(),
				'url'   => get_permalink( $product_id ),
				'image' => $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '',
			);
		}
		return $products;
	}

	function akb_review_get_reviewed_ids( array $product_ids, string $email ): array {
		if ( empty( $product_ids ) ) {
			return array();
		}
		$comments = get_comments( array( 'post__in' => $product_ids, 'author_email' => $email, 'type' => 'review' ) );
		return empty( $comments ) ? array() : array_unique( array_map( fn( $c ) => (int) $c->comment_post_ID, $comments ) );
	}

	function akb_review_get_customer_rating( $order, string $email ): int {
		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$pid = $item->get_product_id();
			if ( $pid ) {
				$product_ids[] = $pid;
			}
		}
		if ( empty( $product_ids ) ) {
			return 0;
		}
		$reviews    = get_comments( array( 'post__in' => $product_ids, 'author_email' => $email, 'type' => 'review' ) );
		$max_rating = 0;
		foreach ( $reviews as $review ) {
			$rating = (int) get_comment_meta( $review->comment_ID, 'rating', true );
			if ( $rating > $max_rating ) {
				$max_rating = $rating;
			}
		}
		return $max_rating;
	}

	// ── EMAIL SENDERS ─────────────────────────────────────────────────────────

	function akb_review_send_product_email( string $email, string $name, array $products, string $api_key ): bool {
		if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
			return akb_review_send_brevo( $email, $name, '¿Qué te pareció tu compra?', '<p>Déjanos tu reseña</p>', $api_key );
		}
		$T             = 'AkibaraEmailTemplate';
		$first_name    = $name ?: 'Lector';
		$product_names = array_map( fn( array $p ) => $p['name'], array_slice( $products, 0, 2 ) );
		$subject       = $first_name . ', ¿qué te pareció ' . implode( ' y ', $product_names ) . '?';
		$preheader     = 'Tu opinión ayuda a otros lectores a elegir bien. Déjanos una reseña rápida.';
		$html          = $T::build(
			$preheader,
			function () use ( $T, $first_name, $products ) {
				$body  = '';
				$body .= $T::headline( '¿Qué te pareció tu compra?' );
				$body .= $T::intro( esc_html( $first_name ) . ', tu opinión ayuda a otros lectores a elegir bien.' );
				foreach ( $products as $product ) {
					$body .= $T::product_card( $product, 'review' );
				}
				$body .= $T::incentive_box( 'Bonus por tu reseña', 'Recibe un 5% de descuento en tu próxima compra' );
				$body .= $T::paragraph( '<small style="color:#666">Te enviaremos un cupón exclusivo cuando publiquemos tu reseña. Válido por 30 días, mínimo $10.000 CLP.</small>', 'center' );
				$body .= $T::divider();
				$body .= $T::paragraph( '<a href="mailto:contacto@akibara.cl" style="color:#666;font-size:12px;text-decoration:underline">¿Tuviste algún problema con tu pedido? Escríbenos</a>', 'center' );
				$body .= $T::signature();
				return $body;
			},
			$email,
			'akb_review_unsub'
		);
		return akb_review_send_brevo( $email, $name, $subject, $html, $api_key );
	}

	function akb_review_send_google_email( string $email, string $name, string $google_url, string $api_key ): bool {
		if ( ! class_exists( 'AkibaraEmailTemplate' ) || empty( $google_url ) ) {
			return akb_review_send_brevo( $email, $name, 'Una última cosa', '<p>Déjanos una reseña en Google</p>', $api_key );
		}
		$T          = 'AkibaraEmailTemplate';
		$first_name = $name ?: 'Lector';
		$html       = $T::build(
			'Tu reseña en Google nos ayuda a que más lectores nos encuentren.',
			function () use ( $T, $first_name, $google_url ) {
				$body  = $T::headline( 'Tu opinión nos ayuda mucho' );
				$body .= $T::intro(
					'Gracias por tu reseña en nuestra tienda — nos alegra saber que tuviste una buena experiencia.<br><br>'
					. 'Akibara es una tienda chica, llevada con harto cariño. Cada reseña en Google nos ayuda a que más lectores de manga nos puedan encontrar.'
				);
				$body .= $T::cta( 'Dejar reseña en Google', $google_url, 'review-google' );
				$body .= $T::paragraph( 'No es obligación — solo si te nace.', 'center' );
				$body .= $T::signature();
				return $body;
			},
			$email,
			'akb_review_unsub'
		);
		return akb_review_send_brevo( $email, $name, $first_name . ', una última cosa', $html, $api_key );
	}

	function akb_review_send_support_email( string $email, string $name, string $api_key ): bool {
		if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
			return akb_review_send_brevo( $email, $name, 'Queremos arreglar esto', '<p>Lamentamos que tu experiencia no haya sido buena</p>', $api_key, 'contacto@akibara.cl' );
		}
		$T          = 'AkibaraEmailTemplate';
		$first_name = $name ?: 'Lector';
		$html       = $T::build(
			'Tu experiencia nos importa. Queremos saber qué pasó.',
			function () use ( $T, $first_name ) {
				$body  = $T::paragraph(
					'Hola ' . esc_html( $first_name ) . ',<br><br>'
					. 'Vimos tu reseña y lamentamos que tu experiencia no haya sido la mejor. Nos la tomamos en serio.<br><br>'
					. '¿Puedes contarnos qué pasó? Queremos revisarlo y ver cómo solucionarlo cuanto antes.<br><br>'
					. 'Responde este mismo correo y lo atendemos directo.'
				);
				$body .= $T::signature();
				return $body;
			},
			$email,
			'akb_review_unsub'
		);
		return akb_review_send_brevo( $email, $name, $first_name . ', queremos arreglar esto', $html, $api_key, 'contacto@akibara.cl' );
	}

	function akb_review_send_brevo( string $to_email, string $to_name, string $subject, string $html, string $api_key, string $reply_to = '' ): bool {
		$body = array(
			'sender'      => array( 'name' => 'Akibara', 'email' => 'contacto@akibara.cl' ),
			'to'          => array( array( 'email' => \AkibaraBrevo::test_recipient( $to_email ), 'name' => $to_name ) ),
			'subject'     => $subject,
			'htmlContent' => $html,
		);
		if ( $reply_to ) {
			$body['replyTo'] = array( 'email' => $reply_to );
		}
		$unsub_url       = add_query_arg( array( 'akb_unsub' => '1', 'email' => rawurlencode( $to_email ), 'token' => wp_hash( $to_email . 'akb_review_unsub' ) ), home_url( '/' ) );
		$body['headers'] = array( 'List-Unsubscribe' => '<' . $unsub_url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click' );
		$response        = wp_remote_post(
			'https://api.brevo.com/v3/smtp/email',
			array( 'headers' => array( 'api-key' => $api_key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ), 'timeout' => 10 )
		);
		if ( is_wp_error( $response ) ) {
			akb_brevo_log_transactional_error( 'review', 'WP_Error: ' . $response->get_error_message(), $to_email, $subject );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		akb_brevo_log_transactional_error( 'review', "HTTP {$code}: " . substr( (string) wp_remote_retrieve_body( $response ), 0, 400 ), $to_email, $subject );
		return false;
	}

	if ( ! function_exists( 'akb_brevo_log_transactional_error' ) ) {
		function akb_brevo_log_transactional_error( string $ctx, string $reason, string $to, string $subject ): void {
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'brevo-tx', 'error', $reason, array( 'ctx' => $ctx, 'to' => $to, 'subject' => $subject ) );
			} else {
				error_log( sprintf( '[Akibara:brevo-tx:error] %s · {"ctx":"%s","to":"%s","subject":"%s"}', $reason, $ctx, $to, $subject ) );
			}
			update_option( 'akibara_brevo_tx_last_error', array( 'time' => current_time( 'mysql' ), 'ctx' => $ctx, 'reason' => $reason, 'to' => $to, 'subject' => $subject ), false );
		}
	}

	// ── FRONTEND — Pre-fill star rating from email click ─────────────────────

	add_action( 'wp_footer', function (): void {
		if ( ! is_product() ) {
			return;
		}
		?>
		<script>
		window.addEventListener("load",function(){
			var m=window.location.search.match(/akb_rating=(\d)/);
			var hashReview=window.location.hash==="#reviews"||window.location.hash==="#tab-reviews";
			if(!m&&!hashReview)return;
			var tabBtn=document.querySelector(".product-tabs__tab[data-tab='reviews']");if(tabBtn)tabBtn.click();
			setTimeout(function(){var rev=document.getElementById("reviews");if(rev)rev.scrollIntoView({behavior:"smooth"});},200);
			if(m){var r=parseInt(m[1]);if(r>=1&&r<=5){setTimeout(function(){var stars=document.querySelectorAll("#commentform .stars a");if(stars.length>=r)stars[r-1].click();},500);}}
		});
		</script>
		<?php
	} );

	// ── UNSUBSCRIBE HANDLER ───────────────────────────────────────────────────

	add_action( 'template_redirect', function (): void {
		if ( empty( $_GET['akb_unsub'] ) || empty( $_GET['email'] ) || empty( $_GET['token'] ) ) {
			return;
		}
		$email = sanitize_email( wp_unslash( $_GET['email'] ) );
		$token = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		if ( ! $email || ! is_email( $email ) ) {
			wp_die( 'Email inválido.', 'Error', array( 'response' => 400 ) );
		}
		if ( ! hash_equals( wp_hash( $email . 'akb_review_unsub' ), $token ) ) {
			wp_die( 'Link inválido.', 'Error', array( 'response' => 403 ) );
		}
		$optouts = get_option( 'akibara_review_optout_emails', array() );
		if ( ! in_array( $email, $optouts, true ) ) {
			$optouts[] = $email;
			update_option( 'akibara_review_optout_emails', $optouts, false );
		}
		wp_die(
			'<div style="text-align:center;padding:60px 20px;font-family:Inter,Arial,sans-serif"><h2>Listo, no recibirás más emails de reseñas</h2><p style="color:#666">Si cambias de opinión, puedes escribirnos a contacto@akibara.cl</p><a href="' . esc_url( home_url() ) . '" style="color:#D90010">Volver a Akibara</a></div>',
			'Desuscrito — Akibara',
			array( 'response' => 200 )
		);
	} );

} // end group wrap
