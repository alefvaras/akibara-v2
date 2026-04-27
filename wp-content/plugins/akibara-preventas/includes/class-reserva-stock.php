<?php
/**
 * Auto-reserva cuando un producto queda sin stock.
 * Unifica lo que antes era "auto pedido especial" bajo el concepto único de preventa.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Stock {

	public static function init(): void {
		add_action( 'woocommerce_product_set_stock_status', [ __CLASS__, 'on_stock_change' ], 10, 3 );
	}

	/**
	 * Cuando cambia el stock status de un producto.
	 */
	public static function on_stock_change( int $product_id, string $status, $product ): void {
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( $product_id );
		}
		if ( ! $product ) return;

		// Solo productos simples y variaciones
		if ( ! in_array( $product->get_type(), [ 'simple', 'variation' ], true ) ) return;

		if ( 'outofstock' === $status ) {
			self::maybe_enable_auto( $product );
		} elseif ( 'instock' === $status ) {
			self::maybe_disable_auto( $product );
		}
	}

	/**
	 * Habilitar automaticamente como reserva (preventa).
	 */
	private static function maybe_enable_auto( WC_Product $product ): void {
		// Verificar que la feature este activada
		if ( ! get_option( 'akb_reservas_auto_oos_enabled' ) ) return;

		// Verificar que no sea ya una reserva manual
		if ( Akibara_Reserva_Product::is_reserva( $product ) && ! Akibara_Reserva_Product::is_auto( $product ) ) {
			return;
		}

		// Verificar categoria
		if ( ! self::product_in_allowed_categories( $product ) ) return;

		// Activar (descuento 0: que lo resuelva get_descuento() via categoría o default global)
		Akibara_Reserva_Product::set_meta( $product, [
			'enabled'    => 'yes',
			'tipo'       => 'preventa',
			'fecha_modo' => 'sin_fecha',
			'fecha'      => 0,
			'descuento'  => 0,
			'auto'       => 'yes',
		] );

		// Auto-categorizar
		$pid = $product->get_type() === 'variation' ? $product->get_parent_id() : $product->get_id();
		akb_reserva_sync_category( $pid, 'preventa', true );
	}

	/**
	 * Desactivar automaticamente si fue auto-generada.
	 */
	private static function maybe_disable_auto( WC_Product $product ): void {
		if ( ! Akibara_Reserva_Product::is_reserva( $product ) ) return;

		// Solo desactivar si fue auto-generada
		if ( ! Akibara_Reserva_Product::is_auto( $product ) ) return;

		Akibara_Reserva_Product::remove_all_meta( $product );

		// Quitar de categorias de reserva
		$pid = $product->get_type() === 'variation' ? $product->get_parent_id() : $product->get_id();
		akb_reserva_sync_category( $pid, '', false );
	}

	/**
	 * Verificar si el producto pertenece a las categorias permitidas.
	 */
	private static function product_in_allowed_categories( WC_Product $product ): bool {
		$allowed = get_option( 'akb_reservas_auto_oos_categories', [] );

		// Si no hay categorias configuradas, aplica a todas
		if ( empty( $allowed ) || ! is_array( $allowed ) ) return true;

		$product_id = $product->get_type() === 'variation'
			? $product->get_parent_id()
			: $product->get_id();

		$cat_ids = wc_get_product_cat_ids( $product_id );

		return ! empty( array_intersect( $cat_ids, array_map( 'intval', $allowed ) ) );
	}
}
