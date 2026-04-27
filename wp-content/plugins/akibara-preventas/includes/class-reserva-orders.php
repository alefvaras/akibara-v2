<?php
/**
 * Manejo de ordenes: status custom, meta de orden/item, completar/cancelar.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Orders {

	const ORDER_HAS      = '_akb_tiene_reserva';
	const ORDER_ESTADO   = '_akb_reserva_estado';
	const ITEM_RESERVA   = '_akb_item_reserva';
	const ITEM_ESTADO    = '_akb_item_estado';
	const ITEM_FECHA     = '_akb_item_fecha_estimada';
	const ITEM_TIPO      = '_akb_item_tipo';

	public static function init(): void {

		// Meta en checkout
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'add_item_meta' ], 10, 4 );
		add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'on_order_created' ] );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_payment_complete' ] );

		// Display meta en admin
		add_filter( 'woocommerce_order_item_display_meta_key', [ __CLASS__, 'display_meta_key' ], 10, 2 );
		add_filter( 'woocommerce_order_item_display_meta_value', [ __CLASS__, 'display_meta_value' ], 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ __CLASS__, 'hidden_meta' ] );

		// Prevenir cancelacion automatica de ordenes pendientes con reserva
		add_filter( 'woocommerce_cancel_unpaid_order', [ __CLASS__, 'prevent_auto_cancel' ], 10, 2 );

		// AJAX para completar/cancelar desde admin — migrados a helper akb_ajax_endpoint()
		if ( function_exists( 'akb_ajax_endpoint' ) ) {
			akb_ajax_endpoint( 'akb_reserva_completar', [
				'nonce'      => 'akb_reserva_action',
				'capability' => 'manage_woocommerce',
				'handler'    => [ __CLASS__, 'handle_completar' ],
			] );
			akb_ajax_endpoint( 'akb_reserva_cancelar', [
				'nonce'      => 'akb_reserva_action',
				'capability' => 'manage_woocommerce',
				'handler'    => [ __CLASS__, 'handle_cancelar' ],
			] );
		} else {
			add_action( 'wp_ajax_akb_reserva_completar', [ __CLASS__, 'ajax_completar' ] );
			add_action( 'wp_ajax_akb_reserva_cancelar', [ __CLASS__, 'ajax_cancelar' ] );
		}

		// Notificacion de cambio de fecha
		add_action( 'akb_reserva_fecha_cambiada', [ __CLASS__, 'on_fecha_cambiada' ], 10, 3 );
	}


	// ─── Checkout: agregar meta a items ──────────────────────────

	public static function add_item_meta( $item, $cart_item_key, $values, $order ): void {
		if ( ! $item instanceof WC_Order_Item_Product ) return;

		$product = $item->get_product();
		if ( ! $product || ! Akibara_Reserva_Product::is_reserva( $product ) ) return;

		$fecha = Akibara_Reserva_Product::get_fecha( $product );

		$item->update_meta_data( self::ITEM_RESERVA, 'yes' );
		$item->update_meta_data( self::ITEM_ESTADO, 'esperando' );
		$item->update_meta_data( self::ITEM_TIPO, 'preventa' );

		if ( $fecha > 0 ) {
			$item->update_meta_data( self::ITEM_FECHA, $fecha );
		}
	}

	// ─── Checkout: meta de orden + status + emails ───────────────

	public static function on_order_created( $order ): void {
		if ( ! $order instanceof WC_Order ) return;

		$has_reserva = false;
		foreach ( $order->get_items() as $item ) {
			if ( 'yes' === $item->get_meta( self::ITEM_RESERVA ) ) {
				$has_reserva = true;
				break;
			}
		}

		if ( ! $has_reserva ) return;

		// Only send emails if order is paid (not pending payment)
		$paid_statuses = [ 'processing', 'completed', 'on-hold' ];
		$send_emails = in_array( $order->get_status(), $paid_statuses, true );
		$order->update_meta_data( self::ORDER_HAS, 'yes' );
		$order->update_meta_data( self::ORDER_ESTADO, 'esperando' );
		if ( $send_emails ) {
			// Marcar ANTES de encolar: evita que on_payment_complete duplique el envío.
			$order->update_meta_data( '_akb_reserva_emails_sent', 'yes' );
		}
		$order->save();

		if ( $send_emails ) {
			self::dispatch_email( 'akb_reserva_confirmada_email', $order->get_id(), $order );
			self::dispatch_email( 'akb_nueva_reserva_email', $order->get_id(), $order );
		}
	}

	/**
	 * Send reservation emails when payment completes (for async payment methods).
	 */
	public static function on_payment_complete( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;
		if ( 'yes' !== $order->get_meta( self::ORDER_HAS ) ) return;
		// Only fire if emails were not already sent at creation time
		if ( $order->get_meta( '_akb_reserva_emails_sent' ) ) return;
		$order->update_meta_data( '_akb_reserva_emails_sent', 'yes' );
		$order->save();
		self::dispatch_email( 'akb_reserva_confirmada_email', $order_id, $order );
		self::dispatch_email( 'akb_nueva_reserva_email', $order_id, $order );
	}

	// ─── Completar reserva ───────────────────────────────────────

	/**
	 * Completa todos los items de reserva en una orden.
	 */
	public static function complete_order( WC_Order $order ): void {
		$changed = false;

		foreach ( $order->get_items() as $item ) {
			if ( 'yes' !== $item->get_meta( self::ITEM_RESERVA ) ) continue;
			if ( 'esperando' !== $item->get_meta( self::ITEM_ESTADO ) ) continue;

			$item->update_meta_data( self::ITEM_ESTADO, 'completada' );
			$item->save();
			$changed = true;
		}

		if ( $changed ) {
			$order->update_meta_data( self::ORDER_ESTADO, 'completada' );
			$order->save();

			// Email al cliente
			self::dispatch_email( 'akb_reserva_lista_email', $order->get_id(), $order );

			$order->add_order_note( 'Reserva completada - productos disponibles.' );
		}
	}

	/**
	 * Completa un item especifico.
	 */
	public static function complete_item( WC_Order $order, int $item_id ): void {
		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product ) return;
		if ( 'esperando' !== $item->get_meta( self::ITEM_ESTADO ) ) return;

		$item->update_meta_data( self::ITEM_ESTADO, 'completada' );
		$item->save();

		// Verificar si todos los items estan completados
		$all_done = true;
		foreach ( $order->get_items() as $check_item ) {
			if ( 'yes' === $check_item->get_meta( self::ITEM_RESERVA ) && 'esperando' === $check_item->get_meta( self::ITEM_ESTADO ) ) {
				$all_done = false;
				break;
			}
		}

		if ( $all_done ) {
			$order->update_meta_data( self::ORDER_ESTADO, 'completada' );
			$order->save();
		}

		self::dispatch_email( 'akb_reserva_lista_email', $order->get_id(), $order );
	}

	// ─── Cancelar reserva ────────────────────────────────────────

	public static function cancel_order( WC_Order $order ): void {
		foreach ( $order->get_items() as $item ) {
			if ( 'yes' !== $item->get_meta( self::ITEM_RESERVA ) ) continue;
			if ( 'esperando' !== $item->get_meta( self::ITEM_ESTADO ) ) continue;

			$item->update_meta_data( self::ITEM_ESTADO, 'cancelada' );
			$item->save();
		}

		$order->update_meta_data( self::ORDER_ESTADO, 'cancelada' );
		$order->save();

		self::dispatch_email( 'akb_reserva_cancelada_email', $order->get_id(), $order );

		$order->add_order_note( 'Reserva cancelada.' );
	}

	// ─── Ordenes pendientes ──────────────────────────────────────

	/**
	 * Obtiene ordenes con reservas en estado 'esperando'.
	 */
	public static function get_pending_orders( int $limit = -1 ): array {
		return wc_get_orders( [
			'limit'      => $limit,
			'meta_query' => [
				'relation' => 'AND',
				[ 'key' => self::ORDER_HAS,    'value' => 'yes' ],
				[ 'key' => self::ORDER_ESTADO,  'value' => 'esperando' ],
			],
			'return' => 'ids',
		] );
	}

	// ─── Display meta ────────────────────────────────────────────

	public static function display_meta_key( $key, $meta ): string {
		$map = [
			self::ITEM_RESERVA => 'Reserva',
			self::ITEM_ESTADO  => 'Estado reserva',
			self::ITEM_FECHA   => 'Fecha estimada',
			self::ITEM_TIPO    => 'Tipo',
		];
		return $map[ $key ] ?? $key;
	}

	public static function display_meta_value( $value, $meta, $item ) {
		if ( self::ITEM_FECHA === $meta->key && is_numeric( $value ) ) {
			return akb_reserva_fecha( (int) $value );
		}
		if ( self::ITEM_ESTADO === $meta->key ) {
			return akb_reserva_estado_label( $value );
		}
		if ( self::ITEM_TIPO === $meta->key ) {
			return 'PREVENTA';
		}
		if ( self::ITEM_RESERVA === $meta->key && 'yes' === $value ) {
			return 'Si';
		}
		return $value;
	}

	public static function hidden_meta( array $metas ): array {
		// No ocultar nada, todos los meta son utiles para el admin
		return $metas;
	}

	// ─── Prevenir cancelacion automatica ─────────────────────────

	public static function prevent_auto_cancel( $cancel, $order ): bool {
		if ( $order instanceof WC_Order && akb_reserva_order_tiene_reserva( $order ) ) {
			return false;
		}
		return $cancel;
	}

	// ─── Cambio de fecha ─────────────────────────────────────────

	public static function on_fecha_cambiada( int $product_id, int $old_fecha, int $new_fecha ): void {
		// Límite de 500 para evitar agotamiento de memoria si hay muchas reservas pendientes.
		$pending = self::get_pending_orders( 500 );
		if ( empty( $pending ) ) return;

		WC()->mailer();

		// Clave de idempotencia por orden+producto: evita emails duplicados si el hook
		// se dispara múltiples veces (ej: bulk update de fecha en el admin).
		$sent_meta_key = '_akb_fecha_email_sent_' . $product_id;

		foreach ( $pending as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) continue;

			// Saltar si ya notificamos este producto en esta orden.
			if ( $order->get_meta( $sent_meta_key ) ) continue;

			$email_dispatched = false;
			foreach ( $order->get_items() as $item ) {
				if ( 'yes' !== $item->get_meta( self::ITEM_RESERVA ) ) continue;
				if ( 'esperando' !== $item->get_meta( self::ITEM_ESTADO ) ) continue;

				$item_product_id = $item->get_variation_id() ?: $item->get_product_id();
				if ( (int) $item_product_id !== $product_id ) continue;

				// Actualizar fecha del item
				$item->update_meta_data( self::ITEM_FECHA, $new_fecha );
				$item->save();

				// Enviar email de cambio de fecha (máximo uno por orden, aunque haya N items del mismo producto)
				if ( ! $email_dispatched ) {
					self::dispatch_email( 'akb_reserva_fecha_cambiada_email', $order->get_id(), $order, [ $product_id, $old_fecha, $new_fecha ] );
					$email_dispatched = true;
				}
			}

			if ( $email_dispatched ) {
				$order->update_meta_data( $sent_meta_key, time() );
				$order->save();
			}
		}
	}

	// ─── AJAX ────────────────────────────────────────────────────

	public static function handle_completar( array $post ): array {
		$order_id = absint( $post['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) return [ 'error' => 'not_found', 'message' => 'Orden no encontrada' ];

		self::complete_order( $order );
		self::send_whatsapp_ready_notification( $order );
		return [ 'message' => 'Reserva completada' ];
	}

	public static function handle_cancelar( array $post ): array {
		$order_id = absint( $post['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) return [ 'error' => 'not_found', 'message' => 'Orden no encontrada' ];

		self::cancel_order( $order );
		return [ 'message' => 'Reserva cancelada' ];
	}

	// Fallback legacy handlers (solo se registran si el helper no está disponible).
	public static function ajax_completar(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Sin permisos', 403 ); return; }
		check_ajax_referer( 'akb_reserva_action', 'nonce' );
		$result = self::handle_completar( $_POST );
		if ( isset( $result['error'] ) ) wp_send_json_error( $result ); else wp_send_json_success( $result );
	}

	public static function ajax_cancelar(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Sin permisos', 403 ); return; }
		check_ajax_referer( 'akb_reserva_action', 'nonce' );
		$result = self::handle_cancelar( $_POST );
		if ( isset( $result['error'] ) ) wp_send_json_error( $result ); else wp_send_json_success( $result );
	}


	// ─── Dispatch helper ────────────────────────────────────────

	/**
	 * Despacha un email de reserva: async vía AS si está habilitado, síncrono como fallback.
	 *
	 * @param string    $email_action Hook WC del email.
	 * @param int       $order_id     ID de la orden.
	 * @param WC_Order  $order        Objeto orden (solo usado en fallback síncrono).
	 * @param array     $extra_args   Args adicionales para el do_action (ej. fechas).
	 */
	private static function dispatch_email( string $email_action, int $order_id, WC_Order $order, array $extra_args = [] ): void {
		if ( Akibara_Reserva_Email_Queue::is_async_enabled() ) {
			Akibara_Reserva_Email_Queue::enqueue( $email_action, $order_id, $extra_args );
			return;
		}
		// Fallback síncrono (flag off o AS no disponible).
		WC()->mailer();
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		do_action( $email_action, $order_id, $order, ...$extra_args );
	}


    /**
     * Envía notificación WhatsApp al cliente cuando la reserva está lista.
     * Usa la API de WhatsApp Business o link directo como fallback.
     */
    public static function send_whatsapp_ready_notification( WC_Order $order ): void {
        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) return;

        // Normalizar teléfono chileno
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        if ( strlen( $phone ) === 9 && $phone[0] === '9' ) {
            $phone = '56' . $phone;
        } elseif ( strlen( $phone ) === 8 ) {
            $phone = '569' . $phone;
        }

        $nombre   = $order->get_billing_first_name();
        $order_no = $order->get_order_number();
        $items    = [];
        foreach ( $order->get_items() as $item ) {
            if ( 'yes' === $item->get_meta( self::ITEM_RESERVA ) ) {
                $items[] = $item->get_name();
            }
        }
        $productos = implode( ', ', array_slice( $items, 0, 3 ) );
        if ( count( $items ) > 3 ) $productos .= ' y ' . ( count( $items ) - 3 ) . ' más';

        $mensaje = sprintf(
            '¡Hola %s! 🎉 Tu reserva #%s ya está lista. Productos: %s. ' .
            'Procederemos al despacho pronto. Cualquier consulta, responde este mensaje.',
            $nombre,
            $order_no,
            $productos
        );

        // Log para admin
        $order->add_order_note( '📱 Notificación WhatsApp enviada al cliente: ' . $phone );

        // Guardar meta para no enviar doble
        $order->update_meta_data( '_akb_wa_notified', time() );
        $order->save();

        // Intentar enviar vía API si existe, sino log el mensaje
        if ( function_exists( 'akb_whatsapp_send_message' ) ) {
            akb_whatsapp_send_message( $phone, $mensaje );
        } else {
            // Guardar URL para envío manual desde admin
            $wa_url = 'https://wa.me/' . $phone . '?text=' . rawurlencode( $mensaje );
            $order->add_order_note( '📱 Enviar WhatsApp manual: ' . $wa_url );
            $order->save();
        }
    }

}
