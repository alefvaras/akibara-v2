<?php
/**
 * Akibara Descuentos — Banner de Campaña
 *
 * Helper publico que devuelve el HTML del banner de la campaña activa
 * con mayor descuento (si hay alguna regla con `banner_text` definido).
 *
 * Lifted from server-snapshot/.../modules/descuentos/banner.php v11.1.0
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 * Logic unchanged.
 *
 * @package Akibara\Marketing\Descuentos
 * @since   11.1.0
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKIBARA_DESCUENTOS_BANNER_LOADED' ) ) {
	return;
}
define( 'AKIBARA_DESCUENTOS_BANNER_LOADED', '11.1.0' );

/**
 * Lee las reglas persistidas soportando el wrapper v11 (`_v` + `_rules`)
 * y el formato plano v10.
 *
 * @return array<int, array<string, mixed>>
 */
function akibara_descuento_banner_get_reglas(): array {
	$raw = get_option( 'akibara_descuento_reglas', array() );
	if ( is_array( $raw ) && isset( $raw['_v'] ) ) {
		return is_array( $raw['_rules'] ?? null ) ? $raw['_rules'] : array();
	}
	return is_array( $raw ) ? $raw : array();
}

/**
 * Retorna el texto del banner si hay regla activa con banner_text definido.
 * Reemplaza {COUNTDOWN} con data-attribute para JS countdown.
 * Priority: la regla activa con mayor valor (o la primera que encuentra).
 *
 * @return string HTML del banner, o string vacio si no hay campaña activa.
 */
function akibara_descuento_banner_html(): string {
	$reglas = akibara_descuento_banner_get_reglas();
	if ( empty( $reglas ) ) {
		return '';
	}

	$now     = time();
	$activas = array();
	foreach ( $reglas as $r ) {
		if ( empty( $r['activo'] ) ) {
			continue;
		}
		$ini = ! empty( $r['fecha_inicio'] ) ? strtotime( $r['fecha_inicio'] ) : null;
		$fin = ! empty( $r['fecha_fin'] ) ? strtotime( $r['fecha_fin'] . ' 23:59:59' ) : null;
		if ( $ini && $now < $ini ) {
			continue;
		}
		if ( $fin && $now > $fin ) {
			continue;
		}
		// Solo si tiene banner_text configurado.
		if ( empty( $r['banner_text'] ) ) {
			continue;
		}
		$activas[] = $r;
	}
	if ( empty( $activas ) ) {
		return '';
	}

	// Ordenar por valor descendente (la promo mas agresiva gana).
	usort( $activas, static fn( $a, $b ) => (int) ( $b['valor'] ?? 0 ) - (int) ( $a['valor'] ?? 0 ) );
	$r = $activas[0];

	$raw_text = (string) $r['banner_text'];
	$fin_ts   = ! empty( $r['fecha_fin'] ) ? strtotime( $r['fecha_fin'] . ' 23:59:59' ) : 0;

	// Escapar primero, luego inyectar el <span> del countdown (que es HTML confiable).
	$text = esc_html( $raw_text );
	$text = str_replace(
		'{COUNTDOWN}',
		'<span class="akb-camp-countdown" data-akb-camp-end="' . esc_attr( (string) $fin_ts ) . '">...</span>',
		$text
	);

	// Reemplazar {EDITORIAL} si aplica (scope product_brand).
	if ( strpos( $text, '{EDITORIAL}' ) !== false ) {
		$term_name = '';
		$taxes     = is_array( $r['taxonomias'] ?? null ) ? $r['taxonomias'] : array();
		foreach ( $taxes as $t ) {
			if ( ( $t['taxonomy'] ?? '' ) === 'product_brand' && ! empty( $t['term_id'] ) ) {
				$term = get_term( (int) $t['term_id'], 'product_brand' );
				if ( $term && ! is_wp_error( $term ) ) {
					$term_name = $term->name;
					break;
				}
			}
		}
		// Fallback legacy al shape filtros['product_brand'].
		if ( $term_name === '' && ! empty( $r['filtros']['product_brand'] ) ) {
			$term_id = (int) ( is_array( $r['filtros']['product_brand'] ) ? $r['filtros']['product_brand'][0] : $r['filtros']['product_brand'] );
			$term    = get_term( $term_id, 'product_brand' );
			if ( $term && ! is_wp_error( $term ) ) {
				$term_name = $term->name;
			}
		}
		$text = str_replace( '{EDITORIAL}', $term_name !== '' ? esc_html( $term_name ) : 'editorial', $text );
	}

	return sprintf(
		'<div class="akb-campaign-banner" data-akb-camp-id="%s" data-akb-camp-end="%d">%s</div>',
		esc_attr( (string) ( $r['id'] ?? '' ) ),
		(int) $fin_ts,
		$text
	);
}

/**
 * ¿Hay alguna campaña con banner activa ahora mismo? Util para decidir enqueue
 * condicional del JS sin tener que renderizar el HTML completo.
 */
function akibara_descuento_banner_is_active(): bool {
	$reglas = akibara_descuento_banner_get_reglas();
	if ( empty( $reglas ) ) {
		return false;
	}
	$now = time();
	foreach ( $reglas as $r ) {
		if ( empty( $r['activo'] ) ) {
			continue;
		}
		if ( empty( $r['banner_text'] ) ) {
			continue;
		}
		$ini = ! empty( $r['fecha_inicio'] ) ? strtotime( $r['fecha_inicio'] ) : null;
		$fin = ! empty( $r['fecha_fin'] ) ? strtotime( $r['fecha_fin'] . ' 23:59:59' ) : null;
		if ( $ini && $now < $ini ) {
			continue;
		}
		if ( $fin && $now > $fin ) {
			continue;
		}
		return true;
	}
	return false;
}
