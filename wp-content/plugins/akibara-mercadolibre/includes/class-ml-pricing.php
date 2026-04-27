<?php
defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// CÁLCULO DE PRECIO
// ══════════════════════════════════════════════════════════════════

/**
 * Aplica redondeo psicológico al precio final (.990 o .900).
 *
 * Marketplaces LATAM muestran ~5-8% más conversión con precios terminados
 * en .990/.900 vs números "fríos" como .881 o .905.
 *
 * Opción `price_rounding`:
 *   'none'  → sin cambio (ej: $11.905)
 *   '990'   → redondear hacia arriba al próximo .990 (ej: $11.905 → $11.990)
 *   '900'   → redondear hacia arriba al próximo .900 (ej: $11.905 → $11.900, $12.050 → $12.900)
 *
 * Nota: preserva el piso de $1.100 (mínimo ML Chile).
 */
function akb_ml_apply_psychological_rounding( int $price ): int {
	$mode = akb_ml_opt( 'price_rounding', 'none' );
	if ( $mode === 'none' || $price < 1100 ) {
		return $price;
	}

	if ( $mode === '990' ) {
		$thousands = intdiv( $price, 1000 );
		$remainder = $price % 1000;
		// Si ya termina exactamente en 990, no tocar
		if ( $remainder === 990 ) {
			return $price;
		}
		// Si está sobre 990 (ej: 12.050), subir al siguiente miler + 990
		if ( $remainder > 990 ) {
			return ( $thousands + 1 ) * 1000 + 990;
		}
		return $thousands * 1000 + 990;
	}

	if ( $mode === '900' ) {
		$thousands = intdiv( $price, 1000 );
		$remainder = $price % 1000;
		if ( $remainder === 900 ) {
			return $price;
		}
		if ( $remainder > 900 ) {
			return ( $thousands + 1 ) * 1000 + 900;
		}
		return $thousands * 1000 + 900;
	}

	return $price;
}

/**
 * Umbral de precio ML a partir del cual el envío gratis es obligatorio (regla MLC).
 * Si el precio final supera este valor, ML descuenta el costo de envío del payout.
 *
 * Editable desde /wp-admin/admin.php?page=akibara&tab=shipping.
 * Si `akibara_ml_free_shipping_threshold` es 0 → hereda de `akibara_free_shipping_threshold` (WC).
 */
function akb_ml_get_free_shipping_threshold(): int {
	$ml = (int) get_option( 'akibara_ml_free_shipping_threshold', 0 );
	if ( $ml > 0 ) {
		return $ml;
	}
	$wc = (int) get_option( 'akibara_free_shipping_threshold', 19990 );
	return $wc > 0 ? $wc : 19990;
}

if ( ! defined( 'AKB_ML_FREE_SHIPPING_THRESHOLD' ) ) {
	define( 'AKB_ML_FREE_SHIPPING_THRESHOLD', 19990 ); // Fallback legacy — código usa la función ahora.
}

/**
 * Calcula el precio ML a partir del precio WC aplicando markup de comisión.
 *
 * Regla clave: el envío gratis es obligatorio solo para productos ≥ $19.990 CLP.
 * Por eso el costo de envío se absorbe SOLO si el precio final cruza el umbral.
 * Productos más baratos no pagan envío (lo paga el comprador) → no inflar precio.
 *
 * Fórmula: precio_ml = ⌈ (precio_wc [+ envío si cruza umbral]) ÷ (1 − total_pct) ⌉
 *
 * Ejemplos (total=16%, envío estimado=$3.100):
 *   WC=$10.000 → ML sin envío=$11.905 (NO cruza $19.990) → final=$11.905
 *   WC=$20.000 → ML sin envío=$23.810 (cruza $19.990) → final=(20000+3100)/0.84=$27.500
 */
function akb_ml_calculate_price( float $wc_price, int $override = 0 ): int {
	// Si hay override manual, usarlo directamente (sin redondeo)
	if ( $override > 0 ) {
		return $override;
	}

	$commission = (float) akb_ml_opt( 'commission_pct', 13.0 );
	$extra      = (float) akb_ml_opt( 'extra_margin_pct', 3.0 );
	$shipping   = (float) akb_ml_opt( 'shipping_cost_estimate', 0 );
	$total      = ( $commission + $extra ) / 100.0;
	$total      = max( 0.01, min( 0.70, $total ) );

	// Primer cálculo: sin absorber envío (caso comprador paga envío)
	$price_no_ship = (int) ceil( $wc_price / ( 1.0 - $total ) );

	// ¿Cruza el umbral de envío gratis obligatorio? → absorber el costo de envío
	if ( $price_no_ship >= akb_ml_get_free_shipping_threshold() && $shipping > 0 ) {
		$price = (int) ceil( ( $wc_price + $shipping ) / ( 1.0 - $total ) );
	} else {
		$price = $price_no_ship;
	}

	$raw = max( 1100, $price );
	return akb_ml_apply_psychological_rounding( $raw );
}

/**
 * Obtiene el precio override manual de un producto, 0 = sin override.
 */
function akb_ml_db_get_override( int $product_id ): int {
	global $wpdb;
	$val = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ml_price_override FROM {$wpdb->prefix}akb_ml_items WHERE product_id = %d",
			$product_id
		)
	);
	return (int) ( $val ?: 0 );
}
