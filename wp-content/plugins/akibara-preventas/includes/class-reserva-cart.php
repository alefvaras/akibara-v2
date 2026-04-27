<?php
/**
 * Manejo de carrito mixto: reservas + productos disponibles.
 *
 * Modelo final: Aviso claro + 1 sola orden siempre.
 * No se hacen splits de ordenes (problemas con gateways, doble cobro, confusión).
 * Si el cliente quiere recibir lo disponible antes, puede hacer 2 pedidos separados.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Cart {

    /**
     * Atomic stock check with transient lock to prevent race conditions.
     * Uses a short-lived transient as a mutex.
     */
    public static function atomic_stock_check( int $product_id, int $qty = 1 ): bool {
        $lock_key = 'akb_stock_lock_' . $product_id;
        $max_wait = 3; // seconds
        // B-S1-BACK-02 (2026-04-27): float explícito (antes int 0 — confiando en coerción).
        $waited   = 0.0;

        // Wait for lock to be released
        while ( get_transient( $lock_key ) && $waited < $max_wait ) {
            usleep( 100000 ); // 100ms
            $waited += 0.1;
        }

        // Acquire lock (5 second TTL as safety)
        set_transient( $lock_key, time(), 5 );

        // Check stock
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            delete_transient( $lock_key );
            return false;
        }

        $in_stock = $product->is_in_stock();
        if ( $product->managing_stock() ) {
            $stock_qty = $product->get_stock_quantity();
            $in_stock  = $stock_qty >= $qty;
        }

        // Release lock
        delete_transient( $lock_key );

        return $in_stock;
    }


	public static function init(): void {
		// Aviso en carrito cuando hay mezcla
		add_action( 'woocommerce_before_cart_table', [ __CLASS__, 'cart_mixed_notice' ] );
		add_action( 'woocommerce_before_checkout_form', [ __CLASS__, 'checkout_mixed_notice' ], 5 );

		// Marcar items de reserva en carrito con info visible
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'cart_item_data' ], 10, 2 );

		// Resumen en checkout de lo que es reserva
		add_action( 'woocommerce_review_order_after_cart_contents', [ __CLASS__, 'checkout_reserva_summary' ] );
	}

	// ─── Detectar carrito mixto ──────────────────────────────────

	public static function analyze_cart(): array {
		$result = [
			'has_reserva'     => false,
			'has_available'   => false,
			'is_mixed'        => false,
			'reserva_items'   => 0,
			'available_items' => 0,
			'earliest_date'   => 0,
			'latest_date'     => 0,
			'reserva_names'   => [],
			'available_names' => [],
		];

		if ( ! WC()->cart ) return $result;

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
			if ( ! $product ) continue;

			if ( Akibara_Reserva_Product::is_reserva( $product ) ) {
				$result['has_reserva'] = true;
				$result['reserva_items']++;
				$result['reserva_names'][] = $product->get_name();

				$fecha = Akibara_Reserva_Product::get_fecha( $product );
				if ( $fecha > 0 ) {
					if ( $result['earliest_date'] === 0 || $fecha < $result['earliest_date'] ) {
						$result['earliest_date'] = $fecha;
					}
					if ( $fecha > $result['latest_date'] ) {
						$result['latest_date'] = $fecha;
					}
				}
			} else {
				$result['has_available'] = true;
				$result['available_items']++;
				$result['available_names'][] = $product->get_name();
			}
		}

		$result['is_mixed'] = $result['has_reserva'] && $result['has_available'];

		return $result;
	}

	// ─── Aviso en carrito ────────────────────────────────────────

	public static function cart_mixed_notice(): void {
		$cart = self::analyze_cart();

		// Caso 1: Solo reservas — aviso informativo
		if ( $cart['has_reserva'] && ! $cart['has_available'] ) {
			$fecha_text = self::format_fecha_range( $cart );
			echo '<div class="akb-cart-notice akb-cart-notice--reserva">';
			echo '<strong>Tu carrito contiene productos en reserva</strong><br>';
			echo 'Tu pedido se despachara cuando todos los productos esten disponibles';
			if ( $fecha_text ) echo ' (' . esc_html( $fecha_text ) . ')';
			echo '.<br>Te avisaremos por email cuando tu pedido este listo.';
			echo '</div>';
			return;
		}

		// Caso 2: Mezcla — aviso prominente con sugerencia
		if ( ! $cart['is_mixed'] ) return;

		$fecha_text = self::format_fecha_range( $cart );
		$available_text = self::format_names( $cart['available_names'], 3 );
		$reserva_text   = self::format_names( $cart['reserva_names'], 3 );

		echo '<div class="akb-cart-notice akb-cart-notice--mixed">';
		echo '<div class="akb-cart-notice__header">';
		echo '<strong>Tu carrito mezcla productos disponibles y en reserva</strong>';
		echo '</div>';
		echo '<div class="akb-cart-notice__body">';
		echo '<div class="akb-cart-notice__col">';
		echo '<span class="akb-cart-notice__label akb-cart-notice__label--green">Disponible ahora</span>';
		echo '<span class="akb-cart-notice__items">' . esc_html( $available_text ) . '</span>';
		echo '</div>';
		echo '<div class="akb-cart-notice__col">';
		echo '<span class="akb-cart-notice__label akb-cart-notice__label--orange">En reserva</span>';
		echo '<span class="akb-cart-notice__items">' . esc_html( $reserva_text );
		if ( $fecha_text ) echo ' — ' . esc_html( $fecha_text );
		echo '</span>';
		echo '</div>';
		echo '</div>';
		echo '<div class="akb-cart-notice__footer">';
		echo '<strong>Todo se despacha junto</strong> cuando la reserva este disponible.<br>';
		echo '<small>Si prefieres recibir lo disponible antes, te sugerimos hacer 2 pedidos separados.</small>';
		echo '</div>';
		echo '</div>';
	}

	// ─── Aviso en checkout ───────────────────────────────────────

	public static function checkout_mixed_notice(): void {
		$cart = self::analyze_cart();
		if ( ! $cart['has_reserva'] ) return;

		$fecha_text = self::format_fecha_range( $cart );

		if ( $cart['is_mixed'] ) {
			wc_print_notice(
				'<strong>Pedido mixto:</strong> tu orden incluye productos en reserva. Todo se despachara junto cuando la reserva llegue'
				. ( $fecha_text ? ' (' . esc_html( $fecha_text ) . ')' : '' ) . '.',
				'notice'
			);
		} else {
			wc_print_notice(
				'<strong>Pedido en reserva:</strong> tu orden se despachara cuando los productos esten disponibles'
				. ( $fecha_text ? ' (' . esc_html( $fecha_text ) . ')' : '' ) . '.',
				'notice'
			);
		}
	}

	// ─── Resumen de reservas en checkout ──────────────────────────

	public static function checkout_reserva_summary(): void {
		$cart = self::analyze_cart();
		if ( ! $cart['has_reserva'] ) return;

		$fecha_text = self::format_fecha_range( $cart );
		?>
		<tr class="akb-checkout-reserva-row">
			<td colspan="2" style="padding:8px 12px;background:rgba(255,107,53,0.1);border-radius:4px;font-size:13px;">
				<?php if ( $cart['is_mixed'] ) : ?>
					<strong><?php echo esc_html( $cart['reserva_items'] ); ?> producto(s) en reserva</strong> — se despacha todo junto cuando el último producto esté disponible. Si necesitas los productos en stock antes, realiza dos pedidos separados
					<?php if ( $fecha_text ) : ?>
						(<?php echo esc_html( $fecha_text ); ?>)
					<?php endif; ?>
				<?php else : ?>
					<strong>Pedido en reserva</strong>
					<?php if ( $fecha_text ) : ?>
						— disponible <?php echo esc_html( $fecha_text ); ?>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	// ─── Marcar items en carrito ──────────────────────────────────

	public static function cart_item_data( array $data, array $cart_item ): array {
		$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;
		if ( ! $product || ! Akibara_Reserva_Product::is_reserva( $product ) ) return $data;

		$data[] = [
			'key'   => 'PREVENTA',
			'value' => Akibara_Reserva_Product::get_disponibilidad_text( $product ),
		];

		return $data;
	}

	// ─── Helpers ─────────────────────────────────────────────────

	private static function format_fecha_range( array $cart ): string {
		if ( $cart['earliest_date'] <= 0 ) return 'fecha por confirmar';

		if ( $cart['earliest_date'] === $cart['latest_date'] || $cart['latest_date'] <= 0 ) {
			return 'aprox. ' . akb_reserva_fecha( $cart['earliest_date'] );
		}

		return 'entre ' . akb_reserva_fecha( $cart['earliest_date'] ) . ' y ' . akb_reserva_fecha( $cart['latest_date'] );
	}

	private static function format_names( array $names, int $max ): string {
		if ( count( $names ) <= $max ) {
			return implode( ', ', $names );
		}
		$shown = array_slice( $names, 0, $max );
		$rest  = count( $names ) - $max;
		return implode( ', ', $shown ) . ' y ' . $rest . ' mas';
	}
}
