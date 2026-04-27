<?php
/**
 * Funciones helper para Akibara Reservas.
 *
 * Todas las funciones top-level usan group wrap (REDESIGN.md §9 — Sprint 2 lesson learned).
 */

defined( 'ABSPATH' ) || exit;

// ─── Helper functions (group wrap per REDESIGN.md §9) ────────────────────────

if ( ! function_exists( 'akb_reserva_fecha' ) ) {

/**
 * Formatea un timestamp como dd/mm/yyyy en zona horaria de Chile.
 */
function akb_reserva_fecha( int $timestamp ): string {
	if ( $timestamp <= 0 ) return 'Sin fecha';
	try {
		$dt = new DateTimeImmutable( '@' . $timestamp );
		$dt = $dt->setTimezone( new DateTimeZone( 'America/Santiago' ) );
		return $dt->format( 'd/m/Y' );
	} catch ( Exception $e ) {
		return 'Sin fecha';
	}
}

/**
 * Convierte una fecha Y-m-d (desde input HTML) a timestamp Unix en zona Chile.
 */
function akb_reserva_fecha_to_timestamp( string $date_str ): int {
	if ( empty( $date_str ) ) return 0;
	try {
		$dt = new DateTimeImmutable( $date_str, new DateTimeZone( 'America/Santiago' ) );
		return $dt->getTimestamp();
	} catch ( Exception $e ) {
		return 0;
	}
}

/**
 * Verifica si un producto tiene preventa activa.
 */
function akb_reserva_esta_activa( $product ): bool {
	$product = wc_get_product( $product );
	if ( ! $product instanceof WC_Product ) return false;
	return Akibara_Reserva_Product::is_reserva( $product );
}

/**
 * Retorna la etiqueta del estado de reserva.
 */
function akb_reserva_estado_label( string $estado ): string {
	$labels = [
		'esperando'  => 'En espera',
		'completada' => 'Completada',
		'cancelada'  => 'Cancelada',
	];
	return $labels[ $estado ] ?? $estado;
}

/**
 * Lista de editoriales disponibles.
 */
function akb_reserva_editoriales(): array {
	return apply_filters( 'akb_reserva_editoriales', [
		'Ivrea',
		'Panini',
		'Distrito Manga',
		'ECC',
		'Ovni Press',
		'Planeta Comic',
		'Norma Editorial',
		'Milky Way',
		'Arechi Manga',
		'Moztros',
	] );
}

/**
 * Genera URL de WhatsApp con mensaje pre-armado.
 */
function akb_reserva_whatsapp_url( string $mensaje ): string {
	$numero = get_option( 'akb_reservas_whatsapp_numero', '' );
	if ( empty( $numero ) ) return '';
	$numero = preg_replace( '/[^0-9]/', '', $numero );
	return 'https://wa.me/' . $numero . '?text=' . rawurlencode( $mensaje );
}

/**
 * Verifica si la orden tiene items de reserva.
 */
function akb_reserva_order_tiene_reserva( $order ): bool {
	$order = wc_get_order( $order );
	if ( ! $order instanceof WC_Order ) return false;
	return 'yes' === $order->get_meta( '_akb_tiene_reserva' );
}

/**
 * Sincroniza la categoria de un producto segun su estado de reserva.
 * Agrega a "Preventas", o la quita si ya no es reserva.
 * También limpia la antigua "Pedidos Especiales" si existe (legacy).
 */
function akb_reserva_sync_category( int $product_id, string $tipo = '', bool $is_reserva = false ): void {
	$cat_preventa       = get_term_by( 'slug', 'preventas', 'product_cat' );
	$cat_pedido_legacy  = get_term_by( 'slug', 'pedidos-especiales', 'product_cat' );

	if ( ! $cat_preventa ) return;

	$current_cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
	if ( is_wp_error( $current_cats ) ) return;

	// Quitar ambas (la legacy siempre se limpia, exista o no)
	$to_remove = [ $cat_preventa->term_id ];
	if ( $cat_pedido_legacy ) $to_remove[] = $cat_pedido_legacy->term_id;
	$current_cats = array_diff( $current_cats, $to_remove );

	if ( $is_reserva ) {
		$current_cats[] = $cat_preventa->term_id;
	}

	wp_set_post_terms( $product_id, array_unique( array_map( 'intval', $current_cats ) ), 'product_cat' );
}

} // end group wrap
