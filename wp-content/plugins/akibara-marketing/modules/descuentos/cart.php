<?php
/**
 * Akibara Descuentos — Carrito (Cupones Virtuales)
 *
 * Implementa descuentos a nivel de carrito usando el patrón de cupones virtuales
 * (mismo patrón que Flycart, YayPricing, ADP).
 *
 * Los cupones virtuales se fabrican via woocommerce_get_shop_coupon_data
 * sin existir en la base de datos.
 *
 * v11.1: Soporte de tramos por serie — descuento escalonado por cantidad
 * de tomos de la misma serie en el carrito.
 *
 * @package Akibara\Descuentos
 * @version 11.1.0
 */

defined( 'ABSPATH' ) || exit;

class Akibara_Descuento_Cart {

	private $main;
	private static $cart_processing = false;

	const COUPON_PREFIX       = 'akibara_cart_';
	const TRAMO_COUPON_PREFIX = 'akibara_tramo_';

	public function __construct( Akibara_Descuento_Taxonomia $main ) {
		$this->main = $main;
	}

	/**
	 * Registra los hooks de carrito.
	 */
	public function register_hooks(): void {
		// Fabricar cupón virtual cuando WC lo busca
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'fabricar_cupon_virtual' ), 10, 2 );

		// Gestionar auto-apply/remove de cupones
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'gestionar_cupones_carrito' ), 1000 );

		// Personalizar display del cupón
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'label_cupon_virtual' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'ocultar_remove_cupon' ), 10, 3 );

		// Suprimir mensajes de cupón (solo para nuestros cupones)
		add_filter( 'woocommerce_coupon_message', array( $this, 'suprimir_mensaje_cupon' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'suprimir_error_cupon' ), 10, 3 );

		// Cart notices for tramos
		add_action( 'woocommerce_before_cart_table', array( $this, 'mostrar_notices_tramos' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'mostrar_notices_tramos' ) );
	}

	// ══════════════════════════════════════════════════════════════
	// CUPON VIRTUAL
	// ══════════════════════════════════════════════════════════════

	/**
	 * Intercepta la carga de cupón y devuelve datos fabricados.
	 * Soporta cupones de carrito (akibara_cart_) y de tramos (akibara_tramo_).
	 */
	public function fabricar_cupon_virtual( $data, $code ) {
		// Standard cart coupons
		if ( strpos( $code, self::COUPON_PREFIX ) === 0 ) {
			return $this->fabricar_cupon_carrito( $data, $code );
		}

		// Tramo (volume tier) coupons
		if ( strpos( $code, self::TRAMO_COUPON_PREFIX ) === 0 ) {
			return $this->fabricar_cupon_tramo( $data, $code );
		}

		return $data;
	}

	/**
	 * Fabricar cupón de carrito estándar.
	 */
	private function fabricar_cupon_carrito( $data, string $code ) {
		$rule_id = substr( $code, strlen( self::COUPON_PREFIX ) );
		$regla   = $this->buscar_regla_carrito( $rule_id );

		if ( ! $regla ) {
			return $data;
		}

		$tipo_descuento = $regla['tipo_descuento'] ?? 'porcentaje';
		$valor          = (float) ( $regla['valor'] ?? 0 );

		if ( $tipo_descuento === 'porcentaje' ) {
			$discount_type = 'percent';
			$amount        = $valor;
		} else {
			$discount_type = 'fixed_cart';
			$amount        = $valor;
		}

		return $this->coupon_data_array( $amount, $discount_type );
	}

	/**
	 * Fabricar cupón de tramo por serie.
	 * Code format: akibara_tramo_{rule_id}_{serie_hash_md5_32char}
	 *
	 * Parse by fixed hash length (md5 = 32 hex chars) en lugar de explode('_'),
	 * porque rule_id puede contener underscores (ej: rule_vol_serie).
	 */
	private function fabricar_cupon_tramo( $data, string $code ) {
		$suffix = substr( $code, strlen( self::TRAMO_COUPON_PREFIX ) );

		// Hash md5 al final (32 hex) + underscore separador
		if ( strlen( $suffix ) < 34 || $suffix[ strlen( $suffix ) - 33 ] !== '_' ) {
			return $data;
		}

		$serie_hash = substr( $suffix, -32 );
		$rule_id    = substr( $suffix, 0, -33 );

		if ( $rule_id === '' || ! ctype_xdigit( $serie_hash ) ) {
			return $data;
		}

		$regla = $this->buscar_regla_carrito( $rule_id );
		if ( ! $regla || empty( $regla['tramos'] ) ) {
			return $data;
		}

		$amount = $this->calcular_descuento_tramo( $regla, $serie_hash );
		if ( $amount <= 0 ) {
			return $data;
		}

		return $this->coupon_data_array( $amount, 'fixed_cart' );
	}

	/**
	 * Base coupon data array.
	 */
	private function coupon_data_array( float $amount, string $discount_type ): array {
		return array(
			'id'                         => PHP_INT_MAX,
			'amount'                     => $amount,
			'discount_type'              => $discount_type,
			'individual_use'             => false,
			'usage_limit'                => 0,
			'usage_count'                => 0,
			'date_expires'               => null,
			'free_shipping'              => false,
			'product_ids'                => array(),
			'exclude_product_ids'        => array(),
			'exclude_sale_items'         => false,
			'minimum_amount'             => '',
			'maximum_amount'             => '',
			'email_restrictions'         => array(),
			'product_categories'         => array(),
			'exclude_product_categories' => array(),
			'usage_limit_per_user'       => 0,
			'limit_usage_to_x_items'     => null,
			'virtual'                    => true,
		);
	}

	// ══════════════════════════════════════════════════════════════
	// GESTION DE CUPONES
	// ══════════════════════════════════════════════════════════════

	/**
	 * Gestiona auto-apply y auto-remove de cupones de carrito.
	 */
	public function gestionar_cupones_carrito( $cart ) {
		// self::$cart_processing ya protege contra recursión dentro de un mismo calculate_totals.
		// El guard did_action anterior era sobre-restrictivo: add_to_cart incrementa el counter
		// antes de calculate_totals explícito, bloqueando la aplicación de cupones automática.
		if ( self::$cart_processing ) {
			return;
		}

		self::$cart_processing = true;

		try {
			// ▼▼▼ PATCH 01: guard individual_use ▼▼▼
			if ( $this->tiene_cupon_individual_use( $cart ) ) {
				foreach ( $cart->get_applied_coupons() as $code ) {
					if ( str_starts_with( $code, self::COUPON_PREFIX ) ||
						str_starts_with( $code, self::TRAMO_COUPON_PREFIX ) ) {
						$cart->remove_coupon( $code );
					}
				}
				return;
			}
			// ▲▲▲ fin PATCH 01 ▲▲▲

			$reglas = $this->main->get_reglas();

			foreach ( $reglas as $regla ) {
				if ( ( $regla['alcance'] ?? 'producto' ) !== 'carrito' ) {
					continue;
				}
				if ( ! $this->main->engine->regla_esta_activa( $regla ) ) {
					continue;
				}

				// Has tramos? Use per-series volume logic
				if ( ! empty( $regla['tramos'] ) ) {
					$this->gestionar_tramos_serie( $cart, $regla );
					continue;
				}

				// Standard cart coupon
				$coupon_code = self::COUPON_PREFIX . $regla['id'];
				$condiciones = $regla['carrito_condiciones'] ?? array();
				$cumple      = $this->evaluar_condiciones_carrito( $cart, $condiciones );
				$tiene_cupon = $cart->has_discount( $coupon_code );

				if ( $cumple && ! $tiene_cupon ) {
					$cart->apply_coupon( $coupon_code );
				} elseif ( ! $cumple && $tiene_cupon ) {
					$cart->remove_coupon( $coupon_code );
				}
			}
		} finally {
			self::$cart_processing = false;
		}
	}

	/**
	 * True si el carrito tiene algún cupón real con individual_use=true.
	 * En ese caso no se auto-aplican cupones virtuales de akibara.
	 */
	private function tiene_cupon_individual_use( WC_Cart $cart ): bool {
		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( str_starts_with( $code, self::COUPON_PREFIX ) ) {
				continue;
			}
			if ( str_starts_with( $code, self::TRAMO_COUPON_PREFIX ) ) {
				continue;
			}

			$coupon = new WC_Coupon( $code );
			if ( $coupon->get_individual_use() ) {
				return true;
			}
		}
		return false;
	}

	// ══════════════════════════════════════════════════════════════
	// TRAMOS POR SERIE (v11.1)
	// ══════════════════════════════════════════════════════════════

	/**
	 * Group cart items by _akibara_serie_norm.
	 *
	 * @return array [ serie_norm => [ 'count' => int, 'name' => string, 'items' => [...] ] ]
	 */
	private function agrupar_series_carrito( $cart ): array {
		$series = array();

		foreach ( $cart->get_cart() as $cart_key => $item ) {
			$product_id = $item['product_id'];
			$serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );

			if ( empty( $serie_norm ) ) {
				continue;
			}

			if ( ! isset( $series[ $serie_norm ] ) ) {
				$serie_name = get_post_meta( $product_id, '_akibara_serie', true );
				if ( empty( $serie_name ) ) {
					$serie_name = ucwords( str_replace( array( '_', '-' ), ' ', $serie_norm ) );
				}
				$series[ $serie_norm ] = array(
					'count' => 0,
					'name'  => $serie_name,
					'items' => array(),
				);
			}

			// Each distinct product counts as 1 volume
			++$series[ $serie_norm ]['count'];
			$series[ $serie_norm ]['items'][] = array(
				'product_id'    => $product_id,
				'regular'       => (float) $item['data']->get_regular_price( 'edit' ),
				'current_price' => (float) $item['data']->get_price(),
			);
		}

		return $series;
	}

	/**
	 * Find the matching tramo tier for a volume count.
	 *
	 * @param array $tramos [ ['min' => 3, 'valor' => 5], ['min' => 5, 'valor' => 8] ]
	 * @param int   $count  Number of volumes
	 * @return array|null   The matching tramo or null
	 */
	private function encontrar_tramo( array $tramos, int $count ): ?array {
		// Sort descending by min so we match the highest tier first
		usort(
			$tramos,
			function ( $a, $b ) {
				return (int) $b['min'] - (int) $a['min'];
			}
		);

		foreach ( $tramos as $tramo ) {
			if ( $count >= (int) $tramo['min'] ) {
				return $tramo;
			}
		}

		return null;
	}

	/**
	 * Manage per-series tramo coupons.
	 */
	private function gestionar_tramos_serie( $cart, array $regla ): void {
		$series  = $this->agrupar_series_carrito( $cart );
		$rule_id = $regla['id'];

		// Determine which coupons SHOULD exist
		$desired = array();
		foreach ( $series as $norm => $data ) {
			$tramo = $this->encontrar_tramo( $regla['tramos'], $data['count'] );
			if ( $tramo ) {
				$code             = self::TRAMO_COUPON_PREFIX . $rule_id . '_' . md5( $norm );
				$desired[ $code ] = true;
			}
		}

		// Remove stale tramo coupons for this rule
		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( strpos( $code, self::TRAMO_COUPON_PREFIX . $rule_id . '_' ) === 0 && ! isset( $desired[ $code ] ) ) {
				$cart->remove_coupon( $code );
			}
		}

		// Apply missing coupons
		foreach ( $desired as $code => $v ) {
			if ( ! $cart->has_discount( $code ) ) {
				$cart->apply_coupon( $code );
			}
		}
	}

	/**
	 * Calculate the fixed discount amount for a tramo coupon.
	 * Only applies the volume discount delta above any existing product-level discount.
	 */
	private function calcular_descuento_tramo( array $regla, string $serie_hash ): float {
		if ( ! WC()->cart ) {
			return 0;
		}

		$series = $this->agrupar_series_carrito( WC()->cart );

		foreach ( $series as $norm => $data ) {
			if ( md5( $norm ) !== $serie_hash ) {
				continue;
			}

			$tramo = $this->encontrar_tramo( $regla['tramos'], $data['count'] );
			if ( ! $tramo ) {
				return 0;
			}

			$tier_pct       = (float) $tramo['valor'];
			$total_discount = 0;

			foreach ( $data['items'] as $item ) {
				$regular = $item['regular'];
				if ( $regular <= 0 ) {
					continue;
				}

				$current_price = $item['current_price'];
				$existing_pct  = ( $current_price > 0 && $current_price < $regular )
					? round( ( $regular - $current_price ) / $regular * 100, 2 )
					: 0;

				// Only apply the additional delta
				if ( $tier_pct > $existing_pct ) {
					$additional_pct  = $tier_pct - $existing_pct;
					$total_discount += (int) round( $regular * $additional_pct / 100 );
				}
			}

			return (float) $total_discount;
		}

		return 0;
	}

	/**
	 * Show notices in cart/checkout about active and upcoming tramos.
	 *
	 * Regla UX: en checkout solo renderizamos mensajes POSITIVOS (descuento ya
	 * ganado). Los mensajes "Agrega X tomos más para obtener Y%" (hints) se
	 * ocultan porque invitan a volver atrás en el funnel — CRO anti-patrón.
	 * Los hints solo se muestran en el carrito, donde agregar items es el
	 * próximo paso natural.
	 */
	public function mostrar_notices_tramos(): void {
		if ( ! WC()->cart ) {
			return;
		}

		$is_checkout_context = function_exists( 'is_checkout' ) && is_checkout()
			&& ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) );

		$reglas = $this->main->get_reglas();
		$series = $this->agrupar_series_carrito( WC()->cart );

		// Buffer para consolidar chips en checkout (render N≤2 individual, N≥3 summary).
		// Acumulamos aquí y rendereamos al final del loop para calcular total y conteo.
		$checkout_chips_buffer = array();

		foreach ( $reglas as $regla ) {
			if ( ( $regla['alcance'] ?? 'producto' ) !== 'carrito' ) {
				continue;
			}
			if ( ! $this->main->engine->regla_esta_activa( $regla ) ) {
				continue;
			}
			if ( empty( $regla['tramos'] ) ) {
				continue;
			}

			// Sort tramos ascending by min for next-tier hints
			$tramos_asc = $regla['tramos'];
			usort(
				$tramos_asc,
				function ( $a, $b ) {
					return (int) $a['min'] - (int) $b['min'];
				}
			);

			foreach ( $series as $norm => $data ) {
				$current_tramo = $this->encontrar_tramo( $regla['tramos'], $data['count'] );

				// Show active discount
				if ( $current_tramo ) {
					$serie_hash = md5( $norm );
					$amount     = $this->calcular_descuento_tramo( $regla, $serie_hash );
					if ( $amount > 0 ) {
						if ( $is_checkout_context ) {
							// Acumular para render consolidado abajo.
							$checkout_chips_buffer[] = array(
								'name'   => $data['name'],
								'count'  => $data['count'],
								'amount' => $amount,
							);
						} else {
							echo '<div class="akb-vol-notice akb-vol-notice--active">';
							echo '<span>Descuento de serie: Comprando ' . esc_html( $data['count'] ) . ' tomos de <strong>' . esc_html( $data['name'] ) . '</strong> ahorras ' . wc_price( $amount ) . '</span>';
							echo '</div>';
						}
					}
				}

				// Find next tier hint
				$next_tramo = null;
				foreach ( $tramos_asc as $t ) {
					if ( $data['count'] < (int) $t['min'] ) {
						$next_tramo = $t;
						break;
					}
				}

				if ( $next_tramo && ! $is_checkout_context ) {
					$needed = (int) $next_tramo['min'] - $data['count'];
					if ( $needed > 0 && $needed <= 3 ) {
						echo '<div class="akb-vol-notice akb-vol-notice--hint">';
						echo '<span>Agrega ' . esc_html( $needed ) . ' tomo(s) m&aacute;s de <strong>' . esc_html( $data['name'] ) . '</strong> para obtener un <strong>' . esc_html( $next_tramo['valor'] ) . '% de descuento</strong></span>';
						echo '</div>';
					}
				}
			}
		}

		// Render consolidado de chips en checkout (fuera del loop de reglas).
		if ( $is_checkout_context && ! empty( $checkout_chips_buffer ) ) {
			$this->render_chips_descuento_checkout( $checkout_chips_buffer );
		}
	}

	/**
	 * Render de chips de descuentos aplicados en checkout.
	 *
	 * Política (consolidada por mesa de trabajo CRO + UX Writer + Frontend):
	 *  - N ≤ 2: chips individuales con nombre de serie + monto `−$X`.
	 *  - N ≥ 3: un solo chip summary `✓ N series con descuento · −$total`
	 *           con tooltip que lista cada serie + monto (aria-describedby).
	 *
	 * A11y: contenedor `role="status" aria-live="polite"` anuncia cambios al SR.
	 * El chip consolidado es `<button>` focusable; tooltip toggle con click/Escape
	 * (JS en checkout-discount-chips.js; hover también funciona en desktop).
	 */
	private function render_chips_descuento_checkout( array $chips ): void {
		$count = count( $chips );
		$total = 0.0;
		foreach ( $chips as $c ) {
			$total += (float) $c['amount'];
		}

		echo '<div class="akb-discount-chips" role="status" aria-live="polite">';

		if ( $count <= 2 ) {
			foreach ( $chips as $c ) {
				echo '<span class="akb-vol-notice akb-vol-notice--active akb-vol-notice--chip">';
				echo '<span class="akb-chip-check" aria-hidden="true">✓</span> ';
				echo '<span class="akb-chip-name">' . esc_html( $c['name'] ) . '</span> · ';
				echo '<span class="akb-chip-amount">−' . wp_kses_post( wc_price( $c['amount'] ) ) . '</span>';
				echo '</span>';
			}
		} else {
			$tooltip_id = 'akb-disc-tt-' . substr( md5( wp_json_encode( $chips ) ), 0, 8 );

			echo '<button type="button" class="akb-vol-notice akb-vol-notice--active akb-vol-notice--chip akb-vol-notice--summary"';
			echo ' aria-describedby="' . esc_attr( $tooltip_id ) . '" aria-expanded="false">';
			echo '<span class="akb-chip-check" aria-hidden="true">✓</span> ';
			echo esc_html( sprintf( _n( '%d serie con descuento', '%d series con descuento', $count, 'akibara' ), $count ) );
			echo ' · <span class="akb-chip-amount">−' . wp_kses_post( wc_price( $total ) ) . '</span>';
			echo ' <span class="akb-chip-info" aria-hidden="true">ⓘ</span>';
			echo '</button>';

			echo '<div class="akb-discount-tooltip" id="' . esc_attr( $tooltip_id ) . '" role="tooltip">';
			echo '<ul class="akb-discount-tooltip__list">';
			foreach ( $chips as $c ) {
				echo '<li>';
				echo '<span class="akb-discount-tooltip__name">' . esc_html( $c['name'] ) . '</span>';
				echo '<span class="akb-discount-tooltip__amount">−' . wp_kses_post( wc_price( $c['amount'] ) ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
			echo '</div>';
		}

		echo '</div>';
	}

	// ══════════════════════════════════════════════════════════════
	// CONDICIONES ESTANDAR
	// ══════════════════════════════════════════════════════════════

	/**
	 * Evalúa si las condiciones del carrito se cumplen.
	 */
	private function evaluar_condiciones_carrito( $cart, array $condiciones ): bool {
		if ( empty( $condiciones ) ) {
			return true;
		}

		foreach ( $condiciones as $cond ) {
			$tipo  = $cond['tipo'] ?? '';
			$valor = (float) ( $cond['valor'] ?? 0 );

			switch ( $tipo ) {
				case 'monto_min':
					if ( $cart->get_displayed_subtotal() < $valor ) {
						return false;
					}
					break;
				case 'cantidad_min':
					if ( $cart->get_cart_contents_count() < $valor ) {
						return false;
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Busca una regla de carrito por su ID.
	 */
	private function buscar_regla_carrito( string $rule_id ): ?array {
		foreach ( $this->main->get_reglas() as $regla ) {
			if ( ( $regla['alcance'] ?? 'producto' ) !== 'carrito' ) {
				continue;
			}
			if ( ( $regla['id'] ?? '' ) === $rule_id ) {
				return $regla;
			}
		}
		return null;
	}

	// ══════════════════════════════════════════════════════════════
	// DISPLAY PERSONALIZADO
	// ══════════════════════════════════════════════════════════════

	/**
	 * Label custom para cupones Akibara en el carrito.
	 */
	public function label_cupon_virtual( $label, $coupon ) {
		$code = $coupon->get_code();

		// Tramo coupon label
		if ( strpos( $code, self::TRAMO_COUPON_PREFIX ) === 0 ) {
			return $this->label_cupon_tramo( $code );
		}

		if ( strpos( $code, self::COUPON_PREFIX ) !== 0 ) {
			return $label;
		}

		$rule_id = substr( $code, strlen( self::COUPON_PREFIX ) );
		$regla   = $this->buscar_regla_carrito( $rule_id );

		if ( $regla ) {
			return esc_html( $regla['nombre'] ?? 'Descuento Akibara' );
		}

		return 'Descuento Akibara';
	}

	/**
	 * Label for tramo coupons: "Descuento serie: [Name] (-X%)"
	 */
	private function label_cupon_tramo( string $code ): string {
		$suffix = substr( $code, strlen( self::TRAMO_COUPON_PREFIX ) );
		$parts  = explode( '_', $suffix, 2 );
		if ( count( $parts ) < 2 ) {
			return 'Descuento por volumen';
		}

		$rule_id    = $parts[0];
		$serie_hash = $parts[1];

		$regla = $this->buscar_regla_carrito( $rule_id );
		if ( ! $regla || empty( $regla['tramos'] ) ) {
			return 'Descuento por volumen';
		}

		$series = $this->agrupar_series_carrito( WC()->cart );
		foreach ( $series as $norm => $data ) {
			if ( md5( $norm ) === $serie_hash ) {
				$tramo = $this->encontrar_tramo( $regla['tramos'], $data['count'] );
				$pct   = $tramo ? $tramo['valor'] : '?';
				return 'Descuento serie: ' . esc_html( $data['name'] ) . ' (-' . esc_html( $pct ) . '%)';
			}
		}

		return 'Descuento por volumen';
	}

	/**
	 * Oculta el link [Remove] para cupones virtuales Akibara.
	 */
	public function ocultar_remove_cupon( $html, $coupon, $discount_amount_html ) {
		$code = $coupon->get_code();
		if ( strpos( $code, self::COUPON_PREFIX ) === 0 || strpos( $code, self::TRAMO_COUPON_PREFIX ) === 0 ) {
			return $discount_amount_html;
		}
		return $html;
	}

	/**
	 * Suprime mensajes de "Cupón aplicado" para cupones virtuales.
	 */
	public function suprimir_mensaje_cupon( $msg, $msg_code, $coupon ) {
		if ( is_a( $coupon, 'WC_Coupon' ) ) {
			$code = $coupon->get_code();
			if ( strpos( $code, self::COUPON_PREFIX ) === 0 || strpos( $code, self::TRAMO_COUPON_PREFIX ) === 0 ) {
				return '';
			}
		}
		return $msg;
	}

	/**
	 * Suprime errores de cupones virtuales.
	 */
	public function suprimir_error_cupon( $err, $err_code, $coupon ) {
		if ( is_a( $coupon, 'WC_Coupon' ) ) {
			$code = $coupon->get_code();
			if ( strpos( $code, self::COUPON_PREFIX ) === 0 || strpos( $code, self::TRAMO_COUPON_PREFIX ) === 0 ) {
				return '';
			}
		}
		return $err;
	}
}
