<?php
/**
 * Abstraccion de meta de producto para reservas.
 * Usa HPOS-compatible $product->get_meta() / update_meta_data().
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Product {

	// ─── Meta keys ───────────────────────────────────────────────
	// Desde 2026-04 existe un único tipo de reserva: PREVENTA (unifica el antiguo "pedido_especial").
	// Los meta se conservan con nombres históricos para no romper datos migrados.
	const META_ENABLED    = '_akb_reserva';           // 'yes'/'no'
	const META_TIPO       = '_akb_reserva_tipo';       // 'preventa' (legacy: 'pedido_especial' se migra a 'preventa')
	const META_FECHA      = '_akb_reserva_fecha';      // Unix timestamp
	const META_FECHA_MODO = '_akb_reserva_fecha_modo'; // 'fija'/'estimada'/'sin_fecha'
	const META_DESCUENTO  = '_akb_reserva_descuento';  // int 0-99 (-1 = usar fallback categoría/global)
	const META_EDITORIAL  = '_akb_reserva_editorial';  // string
	const META_MAX_QTY    = '_akb_reserva_max_qty';    // int, 0 = sin limite
	const META_AUTO       = '_akb_reserva_auto';       // 'yes'/'no' (auto-generada por stock)
	const META_ESTADO_PROVEEDOR = '_akb_reserva_estado_proveedor'; // 'sin_pedir'/'pedido'/'en_transito'/'recibido'
	const META_FECHA_PEDIDO     = '_akb_reserva_fecha_pedido';     // Unix timestamp

	// ─── Estados proveedor válidos ───────────────────────────────
	const ESTADOS_PROVEEDOR = [ 'sin_pedir', 'pedido', 'en_transito', 'recibido' ];

	// ─── Getters ─────────────────────────────────────────────────

	public static function is_reserva( WC_Product $product ): bool {
		if ( ! in_array( $product->get_type(), [ 'simple', 'variation' ], true ) ) {
			return false;
		}
		return 'yes' === $product->get_meta( self::META_ENABLED, true );
	}

	public static function get_fecha( WC_Product $product ): int {
		return (int) $product->get_meta( self::META_FECHA, true );
	}

	public static function get_fecha_modo( WC_Product $product ): string {
		$modo = $product->get_meta( self::META_FECHA_MODO, true );
		return in_array( $modo, [ 'fija', 'estimada', 'sin_fecha' ], true ) ? $modo : 'sin_fecha';
	}

	/**
	 * Descuento aplicable al producto en preventa.
	 * Orden de resolución:
	 *   1. Meta per-producto > 0 (override explícito).
	 *   2. Descuento configurado para alguna de sus categorías.
	 *   3. Default global (akb_reservas_descuento_preventa, por defecto 5%).
	 *
	 * Nota: descuento=0 NO se trata como "override sin descuento" — se usa fallback.
	 * Para un producto hot sin descuento, dejar la reserva desactivada o setear el global a 0.
	 */
	public static function get_descuento( WC_Product $product ): int {
		if ( ! self::is_reserva( $product ) ) return 0;

		$raw = (int) $product->get_meta( self::META_DESCUENTO, true );

		// Override per-producto: cualquier valor > 0 gana.
		if ( $raw > 0 ) {
			return max( 0, min( 99, $raw ) );
		}

		// Fallback 1: descuento por categoría
		$cat_descuento = self::get_descuento_por_categoria( $product );
		if ( $cat_descuento > 0 ) {
			return max( 0, min( 99, $cat_descuento ) );
		}

		// Fallback 2: default global
		$global = (int) get_option( 'akb_reservas_descuento_preventa', 5 );
		return max( 0, min( 99, $global ) );
	}

	/**
	 * Obtiene el mejor (mayor) descuento configurado para las categorías del producto.
	 * Usa la opción 'akb_reservas_descuento_categorias' como mapa term_id => int%.
	 */
	public static function get_descuento_por_categoria( WC_Product $product ): int {
		$map = get_option( 'akb_reservas_descuento_categorias', [] );
		if ( ! is_array( $map ) || empty( $map ) ) return 0;

		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$cat_ids    = wc_get_product_cat_ids( $product_id );
		if ( empty( $cat_ids ) ) return 0;

		$best = 0;
		foreach ( $cat_ids as $cid ) {
			if ( isset( $map[ $cid ] ) && (int) $map[ $cid ] > $best ) {
				$best = (int) $map[ $cid ];
			}
		}
		return max( 0, min( 99, $best ) );
	}

	public static function get_editorial( WC_Product $product ): string {
		return (string) $product->get_meta( self::META_EDITORIAL, true );
	}

	public static function get_max_qty( WC_Product $product ): int {
		return max( 0, (int) $product->get_meta( self::META_MAX_QTY, true ) );
	}

	public static function is_auto( WC_Product $product ): bool {
		return 'yes' === $product->get_meta( self::META_AUTO, true );
	}

	public static function get_estado_proveedor( WC_Product $product ): string {
		$estado = $product->get_meta( self::META_ESTADO_PROVEEDOR, true );
		return in_array( $estado, self::ESTADOS_PROVEEDOR, true ) ? $estado : 'sin_pedir';
	}

	public static function get_fecha_pedido( WC_Product $product ): int {
		return (int) $product->get_meta( self::META_FECHA_PEDIDO, true );
	}

	// ─── Shortcuts ───────────────────────────────────────────────

	public static function is_preventa( WC_Product $product ): bool {
		return self::is_reserva( $product );
	}

	/**
	 * Verifica si la fecha de disponibilidad ya paso.
	 */
	public static function is_fecha_pasada( WC_Product $product ): bool {
		$modo  = self::get_fecha_modo( $product );
		$fecha = self::get_fecha( $product );
		if ( 'sin_fecha' === $modo || $fecha <= 0 ) return false;
		return time() > $fecha;
	}

	/**
	 * Obtiene el brand slug del producto via taxonomía product_brand.
	 */
	public static function get_brand_slug( WC_Product $product ): string {
		$product_id = $product->get_id();
		if ( $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}
		$brands = get_the_terms( $product_id, 'product_brand' );
		if ( ! $brands || is_wp_error( $brands ) ) {
			return '';
		}
		return $brands[0]->slug;
	}

	/**
	 * Obtiene los días de envío configurados para el brand del producto.
	 */
	public static function get_brand_shipping_days( WC_Product $product ): int {
		$brand_slug = self::get_brand_slug( $product );
		if ( empty( $brand_slug ) ) return 0;

		$shipping_times = get_option( 'akb_reservas_brand_shipping_times', [] );
		if ( ! is_array( $shipping_times ) || ! isset( $shipping_times[ $brand_slug ] ) ) {
			return 0;
		}
		return max( 0, (int) $shipping_times[ $brand_slug ] );
	}

	/**
	 * Calcula la fecha estimada de llegada basada en estado proveedor + brand days.
	 */
	public static function get_fecha_estimada_llegada( WC_Product $product ): int {
		$estado = self::get_estado_proveedor( $product );
		if ( 'pedido' !== $estado ) return 0;

		$fecha_pedido = self::get_fecha_pedido( $product );
		if ( $fecha_pedido <= 0 ) return 0;

		$brand_days = self::get_brand_shipping_days( $product );
		if ( $brand_days <= 0 ) return 0;

		return $fecha_pedido + ( $brand_days * DAY_IN_SECONDS );
	}

	/**
	 * Texto de disponibilidad para el frontend.
	 * Usa lógica dinámica basada en estado proveedor + brand shipping times.
	 */
	public static function get_disponibilidad_text( WC_Product $product ): string {
		if ( ! self::is_reserva( $product ) ) return '';

		$modo  = self::get_fecha_modo( $product );
		$fecha = self::get_fecha( $product );

		// Preventa — lógica dinámica por estado proveedor
		$estado      = self::get_estado_proveedor( $product );
		$brand_days  = self::get_brand_shipping_days( $product );

		if ( 'recibido' === $estado ) {
			return 'Producto recibido - Listo para enviar';
		}

		if ( 'en_transito' === $estado ) {
			return 'En camino desde la editorial';
		}

		if ( 'pedido' === $estado ) {
			$fecha_pedido = self::get_fecha_pedido( $product );
			if ( $fecha_pedido > 0 && $brand_days > 0 ) {
				$arrival   = $fecha_pedido + ( $brand_days * DAY_IN_SECONDS );
				$remaining = (int) ceil( ( $arrival - time() ) / DAY_IN_SECONDS );
				if ( $remaining > 0 ) {
					return 'Estimado: llega en ~' . $remaining . ' dias';
				}
				return 'Llegada inminente';
			}
			// Tiene estado pedido pero sin brand_days configurados: fallback a fecha manual
		}

		if ( 'sin_pedir' === $estado && $brand_days > 0 ) {
			return 'Estimado: ~' . $brand_days . ' dias desde que se pida';
		}

		// Fallback: lógica original con fecha manual
		if ( 'fija' === $modo && $fecha > 0 ) {
			return 'Disponible desde: ' . akb_reserva_fecha( $fecha );
		}
		if ( 'estimada' === $modo && $fecha > 0 ) {
			return 'Fecha estimada: ' . akb_reserva_fecha( $fecha );
		}

		// Sin información específica: texto genérico configurable
		return get_option( 'akb_reservas_texto_sin_fecha', 'Estimado: 2-4 semanas' );
	}

	// ─── Setters ─────────────────────────────────────────────────

	/**
	 * Establece el estado de proveedor. Auto-set fecha_pedido cuando cambia a 'pedido'.
	 */
	public static function set_estado_proveedor( WC_Product $product, string $estado ): void {
		if ( ! in_array( $estado, self::ESTADOS_PROVEEDOR, true ) ) return;

		$old_estado = self::get_estado_proveedor( $product );
		$product->update_meta_data( self::META_ESTADO_PROVEEDOR, $estado );

		// Auto-set fecha_pedido cuando cambia a 'pedido' y no tenia fecha previa
		if ( 'pedido' === $estado && 'pedido' !== $old_estado ) {
			$product->update_meta_data( self::META_FECHA_PEDIDO, time() );
		}

		$product->save_meta_data();
	}

	/**
	 * Guarda multiples meta de reserva en el producto.
	 */
	public static function set_meta( WC_Product $product, array $data ): void {
		$map = [
			'enabled'          => self::META_ENABLED,
			'tipo'             => self::META_TIPO,
			'fecha'            => self::META_FECHA,
			'fecha_modo'       => self::META_FECHA_MODO,
			'descuento'        => self::META_DESCUENTO,
			'editorial'        => self::META_EDITORIAL,
			'max_qty'          => self::META_MAX_QTY,
			'auto'             => self::META_AUTO,
			'estado_proveedor' => self::META_ESTADO_PROVEEDOR,
			'fecha_pedido'     => self::META_FECHA_PEDIDO,
		];

		foreach ( $map as $key => $meta_key ) {
			if ( ! array_key_exists( $key, $data ) ) continue;
			// Normalizar tipo: solo 'preventa' es válido desde la unificación.
			$value = ( 'tipo' === $key ) ? 'preventa' : $data[ $key ];
			$product->update_meta_data( $meta_key, $value );
		}
		$product->save_meta_data();
	}

	/**
	 * Elimina toda la meta de reserva del producto.
	 */
	public static function remove_all_meta( WC_Product $product ): void {
		$keys = [
			self::META_ENABLED,
			self::META_TIPO,
			self::META_FECHA,
			self::META_FECHA_MODO,
			self::META_DESCUENTO,
			self::META_EDITORIAL,
			self::META_MAX_QTY,
			self::META_AUTO,
			self::META_ESTADO_PROVEEDOR,
			self::META_FECHA_PEDIDO,
		];
		foreach ( $keys as $key ) {
			$product->delete_meta_data( $key );
		}
		$product->save_meta_data();
	}

	/**
	 * Resetea la reserva cuando la fecha ya paso (auto-desactivar).
	 */
	public static function maybe_expire( WC_Product $product ): bool {
		if ( ! self::is_reserva( $product ) ) return false;
		if ( ! self::is_fecha_pasada( $product ) ) return false;

		// Solo auto-expirar reservas con fecha fija
		if ( 'fija' === self::get_fecha_modo( $product ) ) {
			self::remove_all_meta( $product );
			return true;
		}
		return false;
	}
}
