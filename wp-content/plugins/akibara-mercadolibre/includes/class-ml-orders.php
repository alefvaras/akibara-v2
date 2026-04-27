<?php
defined( 'ABSPATH' ) || exit;

// ── Dead-letter queue: tras 3 fallos consecutivos parseando una orden ML,
// la registramos en una opción persistente para revisión manual y dejamos
// de reintentar. Evita loops infinitos con órdenes corruptas o productos
// que fueron eliminados del catálogo WC.
function akb_ml_order_dead_letter_add( string $resource, string $reason ): void {
	$dl = get_option( 'akb_ml_order_dead_letter', array() );
	if ( ! is_array( $dl ) ) {
		$dl = array();
	}
	$dl[ $resource ] = array(
		'reason'    => $reason,
		'failed_at' => current_time( 'mysql' ),
	);
	// Evitar crecimiento sin fin — max 200 entries, FIFO
	if ( count( $dl ) > 200 ) {
		$dl = array_slice( $dl, -200, null, true );
	}
	update_option( 'akb_ml_order_dead_letter', $dl, false );
	akb_ml_log( 'order', "Orden ML {$resource} movida a dead-letter: {$reason}", 'error' );
}

function akb_ml_order_attempt_count( string $resource, int $increment = 0 ): int {
	$counts = get_option( 'akb_ml_order_attempts', array() );
	if ( ! is_array( $counts ) ) {
		$counts = array();
	}
	$n = (int) ( $counts[ $resource ] ?? 0 );
	if ( $increment !== 0 ) {
		$counts[ $resource ] = $n + $increment;
		// Cleanup: mantener solo los últimos 500 resources activos
		if ( count( $counts ) > 500 ) {
			$counts = array_slice( $counts, -500, null, true );
		}
		update_option( 'akb_ml_order_attempts', $counts, false );
		return $counts[ $resource ];
	}
	return $n;
}

function akb_ml_order_attempt_clear( string $resource ): void {
	$counts = get_option( 'akb_ml_order_attempts', array() );
	if ( is_array( $counts ) && isset( $counts[ $resource ] ) ) {
		unset( $counts[ $resource ] );
		update_option( 'akb_ml_order_attempts', $counts, false );
	}
}

add_action(
	'akb_ml_process_order_async',
	static function ( string $resource ): void {
		// Si ya está en dead-letter, descartar silenciosamente
		$dl = get_option( 'akb_ml_order_dead_letter', array() );
		if ( is_array( $dl ) && isset( $dl[ $resource ] ) ) {
			return;
		}

		$attempt      = akb_ml_order_attempt_count( $resource, 1 );
		$max_attempts = (int) apply_filters( 'akb_ml_order_max_attempts', 3 );

		$resp = akb_ml_request( 'GET', $resource );
		if ( isset( $resp['error'] ) || empty( $resp['order_items'] ) ) {
			if ( $attempt >= $max_attempts ) {
				akb_ml_order_dead_letter_add( $resource, $resp['error'] ?? 'Respuesta sin order_items' );
				akb_ml_order_attempt_clear( $resource );
			} else {
				// Re-encolar con delay exponencial: 60s, 300s, 900s
				if ( function_exists( 'as_schedule_single_action' ) ) {
					$delay = array( 60, 300, 900 )[ $attempt - 1 ] ?? 900;
					as_schedule_single_action( time() + $delay, 'akb_ml_process_order_async', array( 'resource' => $resource ), 'akibara-ml' );
				}
			}
			return;
		}

		$ml_order_id = (string) ( $resp['id'] ?? '' );
		if ( ! $ml_order_id ) {
			return;
		}

		// ── Deduplicar: no crear si ya existe una orden WC para esta orden ML ──
		$existing = wc_get_orders(
			array(
				'meta_key'   => '_akb_ml_order_id',
				'meta_value' => $ml_order_id,
				'limit'      => 1,
				'return'     => 'ids',
			)
		);
		if ( ! empty( $existing ) ) {
			// Orden ya existe → actualizar estado si cambió
			$wc_order = wc_get_order( $existing[0] );
			if ( ! $wc_order ) {
				return;
			}
			$ml_status  = $resp['status'] ?? '';
			$payments   = $resp['payments'] ?? array();
			$pay_status = ! empty( $payments ) ? ( $payments[0]['status'] ?? '' ) : '';

			if ( $ml_status === 'cancelled' && ! in_array( $wc_order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
				$wc_order->update_status( 'cancelled', 'Orden cancelada en MercadoLibre.' );
			} elseif ( in_array( $ml_status, array( 'paid', 'confirmed' ), true ) && $pay_status === 'approved' && $wc_order->get_status() === 'pending' ) {
				// Marcar product_ids de esta orden ML para bloquear el loop de stock sync per-product.
				foreach ( $wc_order->get_items() as $oi ) {
					$p = $oi->get_product();
					if ( $p ) {
						akb_ml_order_scope_add( $p->get_id() );
					}
				}
				$wc_order->update_status( 'processing', 'Pago aprobado en MercadoLibre.' );
				// WC reduce stock automáticamente al transitar a processing
			}
			return;
		}

		// ── Mapear estado de pago ML → WC ──────────────────────────────────────
		$ml_status  = $resp['status'] ?? '';
		$payments   = $resp['payments'] ?? array();
		$pay_status = ! empty( $payments ) ? ( $payments[0]['status'] ?? '' ) : '';

		if ( in_array( $ml_status, array( 'paid', 'confirmed' ), true ) && $pay_status === 'approved' ) {
			$wc_status = 'processing';
		} elseif ( $ml_status === 'cancelled' ) {
			$wc_status = 'cancelled';
		} else {
			$wc_status = 'pending';
		}

		// ── Datos del comprador ────────────────────────────────────────────────
		$buyer      = $resp['buyer'] ?? array();
		$first_name = sanitize_text_field( $buyer['first_name'] ?? $buyer['nickname'] ?? 'Comprador' );
		$last_name  = sanitize_text_field( $buyer['last_name'] ?? 'ML' );
		$email      = sanitize_email( $buyer['email'] ?? '' );
		$phone      = sanitize_text_field( $buyer['phone']['number'] ?? '' );

		// ML a veces devuelve email protegido tipo xxx@compradores.mercadolibre.cl — lo usamos igual
		if ( empty( $email ) ) {
			$email = 'ml-' . $ml_order_id . '@akibara.cl'; // fallback interno
		}

		// ── Dirección de envío ML ──────────────────────────────────────────────
		$shipping_addr = $resp['shipping']['receiver_address'] ?? array();
		$street        = sanitize_text_field( ( $shipping_addr['street_name'] ?? '' ) . ' ' . ( $shipping_addr['street_number'] ?? '' ) );
		$city          = sanitize_text_field( $shipping_addr['city']['name'] ?? '' );
		$state         = sanitize_text_field( $shipping_addr['state']['name'] ?? '' );
		$zip           = sanitize_text_field( $shipping_addr['zip_code'] ?? '' );
		$country       = 'CL';

		// ── Crear orden WC ─────────────────────────────────────────────────────
		$order = wc_create_order(
			array(
				'status'      => 'pending',
				'customer_id' => 0,
			)
		);
		if ( is_wp_error( $order ) ) {
			akb_ml_log( 'order', 'Error creando orden WC para ML #' . $ml_order_id . ': ' . $order->get_error_message() );
			return;
		}

		// Billing
		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( $last_name );
		$order->set_billing_email( $email );
		if ( $phone ) {
			$order->set_billing_phone( $phone );
		}
		if ( $street ) {
			$order->set_billing_address_1( trim( $street ) );
		}
		if ( $city ) {
			$order->set_billing_city( $city );
		}
		if ( $state ) {
			$order->set_billing_state( $state );
		}
		if ( $zip ) {
			$order->set_billing_postcode( $zip );
		}
		$order->set_billing_country( $country );

		// Shipping (mismo que billing si no hay dirección específica)
		$order->set_shipping_first_name( $first_name );
		$order->set_shipping_last_name( $last_name );
		$order->set_shipping_address_1( trim( $street ) );
		$order->set_shipping_city( $city );
		$order->set_shipping_state( $state );
		$order->set_shipping_postcode( $zip );
		$order->set_shipping_country( $country );

		// Método de pago
		$order->set_payment_method( 'mercadolibre' );
		$order->set_payment_method_title( 'MercadoLibre' );

		// ── Agregar productos ──────────────────────────────────────────────────
		global $wpdb;
		$items_added = 0;

		foreach ( (array) $resp['order_items'] as $ml_item ) {
			$ml_item_id = $ml_item['item']['id'] ?? '';
			$qty        = max( 1, (int) ( $ml_item['quantity'] ?? 1 ) );
			$unit_price = (float) ( $ml_item['unit_price'] ?? 0 );
			if ( empty( $ml_item_id ) ) {
				continue;
			}

			// Buscar producto WC por ml_item_id en nuestra tabla
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT product_id FROM {$wpdb->prefix}akb_ml_items WHERE ml_item_id = %s",
					$ml_item_id
				),
				ARRAY_A
			);

			if ( ! $row ) {
				// Fallback: seller_custom_field contiene el product_id de WC
				$custom_field = sanitize_text_field( $ml_item['item']['seller_custom_field'] ?? '' );
				if ( $custom_field && is_numeric( $custom_field ) ) {
					$fallback_product = wc_get_product( (int) $custom_field );
					if ( $fallback_product ) {
						$row = array( 'product_id' => $fallback_product->get_id() );
					}
				}
			}

			if ( ! $row ) {
				continue;
			}

			$product = wc_get_product( (int) $row['product_id'] );
			if ( ! $product ) {
				continue;
			}

			// Usar precio ML (lo que pagó el comprador) como precio de línea
			$line_total = $unit_price * $qty;
			$order->add_product(
				$product,
				$qty,
				array(
					'subtotal' => $line_total,
					'total'    => $line_total,
				)
			);
			$items_added++;
		}

		if ( $items_added === 0 ) {
			// No se pudo mapear ningún producto — abortar y limpiar
			$order->delete( true );
			akb_ml_log( 'order', 'Orden ML #' . $ml_order_id . ' descartada: no se encontraron productos WC.' );
			// Éste es un fallo "definitivo": productos fueron eliminados de WC o nunca existieron.
			// Marcar dead-letter directo para no reintentar.
			akb_ml_order_dead_letter_add( $resource, 'Ningún producto WC mapeable desde order_items' );
			akb_ml_order_attempt_clear( $resource );
			return;
		}

		// ── Totales y meta ─────────────────────────────────────────────────────
		$order->calculate_totals();
		$order->update_meta_data( '_akb_ml_order_id', $ml_order_id );
		$order->update_meta_data( '_akb_ml_buyer_nickname', $buyer['nickname'] ?? '' );
		$order->add_order_note(
			sprintf(
				'Orden MercadoLibre #%s | Comprador: %s | Estado ML: %s | Pago: %s',
				$ml_order_id,
				esc_html( $buyer['nickname'] ?? $first_name ),
				$ml_status,
				$pay_status ?: 'N/A'
			)
		);

		// ── Guard anti-loop per-product: marcar ANTES de set_status para que el hook
		// de stock sync (akb_ml_order_scope_has) excluya sólo los productos de esta orden ML.
		// WC reduce stock automáticamente al transitar a 'processing' (wc_maybe_reduce_stock_levels).
		if ( $wc_status === 'processing' ) {
			foreach ( $order->get_items() as $oi ) {
				$p = $oi->get_product();
				if ( $p ) {
					akb_ml_order_scope_add( $p->get_id() );
				}
			}
		}

		// ── Transitar al estado correcto ───────────────────────────────────────
		$order->set_status( $wc_status );
		$order->save();

		akb_ml_log(
			'order',
			sprintf(
				'Orden WC #%d creada para ML #%s | Estado: %s | Items: %d',
				$order->get_id(),
				$ml_order_id,
				$wc_status,
				$items_added
			)
		);

		// Éxito → limpiar contador de reintentos
		akb_ml_order_attempt_clear( $resource );
	}
);
