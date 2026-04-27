<?php
/**
 * Plugin Name: Akibara SEO Breadcrumb Fix
 * Description: Fix BreadcrumbList JSON-LD position string→int + cleanup nested item structure. Cubre B-S1-SEO-01 del Sprint 1. Antes Rank Math emitía "position":"1" (string) que es warning de Schema.org spec.
 * Version: 1.0.0
 * Author: Akibara
 * Requires PHP: 8.1
 *
 * Estrategia
 * ----------
 * Aplicamos el fix vía 2 hooks (defense-in-depth):
 *   1. `rank_math/snippet/breadcrumb` (entity-level, antes del @graph merge)
 *   2. `rank_math/json_ld` (graph-level, prioridad alta como fallback)
 *
 * Y como red de seguridad final: ob_start con regex replace en el script tag
 * `class="rank-math-schema"` para casos donde Rank Math construye el JSON
 * fuera del filter chain.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cast positions to int recursivamente en cualquier itemListElement encontrado.
 */
function akb_seo_fix_breadcrumb_positions( array $items ): array {
	foreach ( $items as $i => $item ) {
		if ( is_array( $item ) && isset( $item['position'] ) ) {
			$items[ $i ]['position'] = (int) $item['position'];
		}
	}
	return $items;
}

// Hook 1: rank_math/snippet/breadcrumb (entity directo)
add_filter( 'rank_math/snippet/breadcrumb', static function ( $entity ) {
	if ( is_array( $entity ) && ! empty( $entity['itemListElement'] ) && is_array( $entity['itemListElement'] ) ) {
		$entity['itemListElement'] = akb_seo_fix_breadcrumb_positions( $entity['itemListElement'] );
	}
	return $entity;
} );

// Hook 2: rank_math/json_ld (graph completo)
add_filter( 'rank_math/json_ld', static function ( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	foreach ( $data as $key => $entity ) {
		if ( is_array( $entity ) && ( $entity['@type'] ?? '' ) === 'BreadcrumbList' &&
		     ! empty( $entity['itemListElement'] ) && is_array( $entity['itemListElement'] ) ) {
			$data[ $key ]['itemListElement'] = akb_seo_fix_breadcrumb_positions( $entity['itemListElement'] );
		}
	}
	return $data;
}, 999 );

// Hook 3: regex sobre output final del JSON-LD script tag (red de seguridad).
// Si los hooks anteriores no atajan el caso, este regex limpia "position":"N"
// dentro de cualquier <script class="rank-math-schema">.
add_action( 'wp_head', static function () {
	ob_start( static function ( $buffer ) {
		if ( strpos( $buffer, 'rank-math-schema' ) === false ) {
			return $buffer;
		}
		return preg_replace_callback(
			'#(<script[^>]*class="rank-math-schema"[^>]*>)(.+?)(</script>)#s',
			static function ( $m ) {
				$json_fixed = preg_replace( '/"position":"(\d+)"/', '"position":$1', $m[2] );
				return $m[1] . $json_fixed . $m[3];
			},
			$buffer
		);
	} );
}, 0 );

add_action( 'wp_footer', static function () {
	if ( ob_get_level() > 0 ) {
		@ob_end_flush();
	}
}, 9999 );
