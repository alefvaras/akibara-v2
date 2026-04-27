<?php
/**
 * Frontend: badges, botones, precios, disponibilidad.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Frontend {

	/** Anti-recursion flag para filtros de precio */
	private static bool $filtering_price = false;

	public static function init(): void {
		// Texto del boton
		add_filter( 'woocommerce_product_single_add_to_cart_text', [ __CLASS__, 'button_text' ], 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', [ __CLASS__, 'button_text' ], 20, 2 );

		// Nota: badges y clases en loop/single los renderiza el theme (product-card.php + info.php)
		// vía akb_plugin_render_badges(). No enganchamos hooks aquí para evitar duplicación.

		// Countdown + Social proof en single (despues del precio, antes del boton)
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'single_countdown' ], 25 );
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'single_social_proof' ], 26 );

		// Texto de disponibilidad reemplaza stock HTML
		add_filter( 'woocommerce_get_stock_html', [ __CLASS__, 'stock_html' ], 99, 2 );

		// Precio con descuento preventa
		add_filter( 'woocommerce_product_get_price', [ __CLASS__, 'filter_price' ], 998, 2 );
		add_filter( 'woocommerce_product_variation_get_price', [ __CLASS__, 'filter_price' ], 998, 2 );
		add_filter( 'woocommerce_product_get_sale_price', [ __CLASS__, 'filter_sale_price' ], 998, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', [ __CLASS__, 'filter_sale_price' ], 998, 2 );
		add_filter( 'woocommerce_product_is_on_sale', [ __CLASS__, 'filter_on_sale' ], 998, 2 );
		add_filter( 'woocommerce_variation_prices_price', [ __CLASS__, 'filter_variation_range_price' ], 998, 2 );

		// Permitir compra en productos sin stock cuando están marcados como reserva.
		// is_in_stock: controla catálogo/render. is_purchasable: controla carrito
		// (clásico + blocks). backorders_allowed: permite decrementar stock < 0 en fulfillment.
		// Las tres capas deben alinearse para que una preventa sea comprable end-to-end.
		add_filter( 'woocommerce_product_is_in_stock', [ __CLASS__, 'filter_in_stock' ], 10, 2 );
		add_filter( 'woocommerce_is_purchasable', [ __CLASS__, 'filter_purchasable' ], 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', [ __CLASS__, 'filter_purchasable' ], 10, 2 );
		add_filter( 'woocommerce_product_backorders_allowed', [ __CLASS__, 'filter_backorders' ], 10, 3 );

		// Cantidad maxima
		add_filter( 'woocommerce_quantity_input_args', [ __CLASS__, 'max_qty_args' ], 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', [ __CLASS__, 'validate_max_qty' ], 10, 4 );

		// Estilos + JS
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_styles' ] );

		// Shortcode
		add_shortcode( 'akb_preventas', [ __CLASS__, 'shortcode_preventas' ] );
	}

	// ─── Texto del boton ─────────────────────────────────────────

	public static function button_text( string $text, $product ): string {
		if ( ! $product instanceof WC_Product ) return $text;
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return $text;
		return 'Reservar ahora';
	}

	// ─── Stock HTML → Disponibilidad ─────────────────────────────

	public static function stock_html( string $html, $product ): string {
		if ( ! $product instanceof WC_Product ) return $html;
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return $html;

		$text = Akibara_Reserva_Product::get_disponibilidad_text( $product );
		if ( empty( $text ) ) return $html;

		// Determinar clase CSS segun estado proveedor
		$estado = Akibara_Reserva_Product::get_estado_proveedor( $product );
		$css_class = 'akb-availability';
		if ( 'en_transito' === $estado ) {
			$css_class .= ' akb-availability--transit';
		} elseif ( 'recibido' === $estado ) {
			$css_class .= ' akb-availability--ready';
		} elseif ( 'pedido' === $estado ) {
			$css_class .= ' akb-availability--ordered';
		}

		return '<p class="' . esc_attr( $css_class ) . '">' . esc_html( $text ) . '</p>';
	}

	// ─── Precio con descuento ────────────────────────────────────

	public static function filter_price( $price, $product ) {
		if ( self::$filtering_price ) return $price;
		if ( ! $product instanceof WC_Product ) return $price;
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return $price;

		$descuento = Akibara_Reserva_Product::get_descuento( $product );
		if ( $descuento <= 0 ) return $price;

		try {
			self::$filtering_price = true;
			$regular = (float) $product->get_regular_price( 'edit' );
		} finally {
			self::$filtering_price = false;
		}

		if ( $regular <= 0 ) return $price;

		$preventa_price = round( $regular * ( 1 - $descuento / 100 ), wc_get_price_decimals() );

		// Si ya viene un precio menor (ej: descuento taxonomia), dar el mejor al cliente
		$incoming = (float) $price;
		if ( $incoming > 0 && $incoming < $preventa_price ) {
			return $price;
		}

		return (string) $preventa_price;
	}

	public static function filter_sale_price( $price, $product ) {
		if ( self::$filtering_price ) return $price;
		if ( ! $product instanceof WC_Product ) return $price;
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return $price;

		$descuento = Akibara_Reserva_Product::get_descuento( $product );
		if ( $descuento <= 0 ) return $price;

		try {
			self::$filtering_price = true;
			$regular = (float) $product->get_regular_price( 'edit' );
		} finally {
			self::$filtering_price = false;
		}

		if ( $regular <= 0 ) return $price;

		$preventa_price = round( $regular * ( 1 - $descuento / 100 ), wc_get_price_decimals() );

		// Si ya viene un precio menor (ej: descuento taxonomia), dar el mejor al cliente
		$incoming = (float) $price;
		if ( $incoming > 0 && $incoming < $preventa_price ) {
			return $price;
		}

		return (string) $preventa_price;
	}
	public static function filter_on_sale( $on_sale, $product ) {
		if ( ! $product instanceof WC_Product ) return $on_sale;
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return $on_sale;
		return Akibara_Reserva_Product::get_descuento( $product ) > 0;
	}

	public static function filter_variation_range_price( $price, $variation ) {
		return self::filter_price( $price, $variation );
	}

	// ─── Stock: permitir compra en OOS ───────────────────────────

	public static function filter_in_stock( bool $in_stock, $product ): bool {
		// wp_doing_ajax() distingue admin-ajax desde frontend (donde is_admin() es
		// true por diseño de WP) del admin real. Saltar solo el admin real evita
		// que el filtro se desactive en paginación AJAX, bulk-add y REST calls.
		if ( is_admin() && ! wp_doing_ajax() ) return $in_stock;
		if ( ! $product instanceof WC_Product ) return $in_stock;
		if ( Akibara_Reserva_Product::is_reserva( $product ) ) return true;
		return $in_stock;
	}

	public static function filter_purchasable( $purchasable, $product ) {
		if ( is_admin() && ! wp_doing_ajax() ) return $purchasable;
		if ( ! $product instanceof WC_Product ) return $purchasable;
		if ( Akibara_Reserva_Product::is_reserva( $product ) ) return true;
		return $purchasable;
	}

	public static function filter_backorders( $allowed, $product_id, $product ) {
		if ( $product instanceof WC_Product && Akibara_Reserva_Product::is_reserva( $product ) ) {
			return true;
		}
		return $allowed;
	}

	// ─── Cantidad maxima ─────────────────────────────────────────

	public static function max_qty_args( array $args, $product ): array {
		if ( ! $product instanceof WC_Product ) return $args;
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return $args;

		$max = Akibara_Reserva_Product::get_max_qty( $product );
		if ( $max > 0 ) {
			$args['max_value'] = $max;
		}
		return $args;
	}

	public static function validate_max_qty( bool $passed, int $product_id, int $quantity, int $variation_id = 0 ): bool {
		$check_id = $variation_id > 0 ? $variation_id : $product_id;
		$product = wc_get_product( $check_id );
		if ( ! $product || ! Akibara_Reserva_Product::is_reserva( $product ) ) return $passed;

                // ── Atomic stock check (mutex via transient, previene race conditions) ──
                if ( ! Akibara_Reserva_Cart::atomic_stock_check( $check_id, $quantity ) ) {
                    wc_add_notice( 'Stock insuficiente para esta reserva. Otro cliente puede haber reservado el último ejemplar.', 'error' );
                    return false;
                }

		$max = Akibara_Reserva_Product::get_max_qty( $product );
		if ( $max <= 0 ) return $passed;

		// Sumar cantidad ya en carrito
		$cart_qty = 0;
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( (int) $item['product_id'] === $product_id || (int) ( $item['variation_id'] ?? 0 ) === $check_id ) {
					$cart_qty += $item['quantity'];
				}
			}
		}

		if ( ( $cart_qty + $quantity ) > $max ) {
			wc_add_notice(
				sprintf( 'Solo puedes reservar un maximo de %d unidades de este producto.', $max ),
				'error'
			);
			return false;
		}
		return $passed;
	}

	// ─── Countdown Timer (solo single, preventas con fecha fija) ─

	public static function single_countdown(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) return;
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return;

		$estado = Akibara_Reserva_Product::get_estado_proveedor( $product );

		// Countdown basado en estado proveedor + brand days
		if ( 'pedido' === $estado ) {
			$llegada = Akibara_Reserva_Product::get_fecha_estimada_llegada( $product );
			if ( $llegada > 0 && $llegada > time() ) {
				self::render_countdown( $llegada, 'Llega en:' );
				return;
			}
		}

		// Fallback: countdown basado en fecha manual (fija)
		$modo  = Akibara_Reserva_Product::get_fecha_modo( $product );
		$fecha = Akibara_Reserva_Product::get_fecha( $product );
		if ( 'fija' !== $modo || $fecha <= 0 || $fecha <= time() ) return;

		// Calcular dias restantes
		$diff = $fecha - time();
		$days = (int) floor( $diff / DAY_IN_SECONDS );

		if ( $days > 90 ) return; // No mostrar countdown si faltan mas de 3 meses

		self::render_countdown( $fecha, 'Disponible en:' );
	}

	private static function render_countdown( int $target_timestamp, string $label ): void {
		$diff    = $target_timestamp - time();
		$days    = (int) floor( $diff / DAY_IN_SECONDS );
		$hours   = (int) floor( ( $diff % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		$minutes = (int) floor( ( $diff % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

		echo '<div class="akb-countdown" data-timestamp="' . esc_attr( $target_timestamp ) . '">';
		echo '<div class="akb-countdown-label">' . esc_html( $label ) . '</div>';
		echo '<div class="akb-countdown-timer">';
		echo '<div class="akb-cd-unit"><span class="akb-cd-num" data-unit="days">' . esc_html( $days ) . '</span><span class="akb-cd-txt">dias</span></div>';
		echo '<div class="akb-cd-sep">:</div>';
		echo '<div class="akb-cd-unit"><span class="akb-cd-num" data-unit="hours">' . esc_html( str_pad( (string) $hours, 2, '0', STR_PAD_LEFT ) ) . '</span><span class="akb-cd-txt">hrs</span></div>';
		echo '<div class="akb-cd-sep">:</div>';
		echo '<div class="akb-cd-unit"><span class="akb-cd-num" data-unit="minutes">' . esc_html( str_pad( (string) $minutes, 2, '0', STR_PAD_LEFT ) ) . '</span><span class="akb-cd-txt">min</span></div>';
		echo '</div></div>';
	}

	// ─── Social Proof (cuantas personas reservaron) ──────────────

	public static function single_social_proof(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) return;
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return;

		$total_sales = (int) $product->get_total_sales();
		if ( $total_sales <= 0 ) return;

		echo '<div class="akb-social-proof">';
		echo '<span class="akb-sp-icon">&#128293;</span> ';
		echo '<span class="akb-sp-text">' . esc_html( $total_sales ) . ' personas ya lo reservaron</span>';
		echo '</div>';
	}

	// ─── Shortcodes ──────────────────────────────────────────────

	/**
	 * [akb_preventas limit="12" editorial="Ivrea" columns="4" orderby="date"]
	 */
	public static function shortcode_preventas( $atts ): string {
		$atts = shortcode_atts( [
			'limit'     => 12,
			'editorial' => '',
			'columns'   => 4,
			'orderby'   => 'date',
		], $atts );

		$meta_query = [
			'relation' => 'AND',
			[ 'key' => '_akb_reserva', 'value' => 'yes' ],
		];

		if ( ! empty( $atts['editorial'] ) ) {
			$meta_query[] = [ 'key' => '_akb_reserva_editorial', 'value' => sanitize_text_field( $atts['editorial'] ) ];
		}

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['limit'],
			'orderby'        => $atts['orderby'],
			'order'          => 'DESC',
			'meta_query'     => $meta_query,
		];

		return self::render_product_grid( $args, (int) $atts['columns'], 'Preventas' );
	}

	private static function render_product_grid( array $args, int $columns, string $title ): string {
		$products = new WP_Query( $args );

		if ( ! $products->have_posts() ) {
			return '<p>No hay ' . esc_html( strtolower( $title ) ) . ' disponibles en este momento.</p>';
		}

		ob_start();
		echo '<div class="akb-reservas-grid">';

		woocommerce_product_loop_start();
		while ( $products->have_posts() ) {
			$products->the_post();
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();

		echo '</div>';
		wp_reset_postdata();

		return ob_get_clean() ?: '';
	}

	// ─── Estilos + JS ────────────────────────────────────────────

	public static function enqueue_styles(): void {
		// Cargar siempre en paginas WC + single product (el tema usa template custom)
		if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_account_page() && ! is_singular( 'product' ) ) return;

		wp_enqueue_style(
			'akb-reservas',
			AKIBARA_RESERVAS_URL . 'assets/css/reservas.css',
			[],
			AKIBARA_RESERVAS_VERSION
		);

		// Countdown JS en single product
		if ( is_product() || is_singular( 'product' ) ) {
			wp_enqueue_script(
				'akb-reservas-countdown',
				AKIBARA_RESERVAS_URL . 'assets/js/countdown.js',
				[],
				AKIBARA_RESERVAS_VERSION,
				true
			);
		}
	}
}
