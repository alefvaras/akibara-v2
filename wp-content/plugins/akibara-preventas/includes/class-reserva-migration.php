<?php
/**
 * Migracion de datos desde YITH Pre-Order a Akibara Reservas.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Migration {

	const UNIFY_FLAG = 'akb_reservas_unified_v1';

	/**
	 * Migración idempotente de "pedido_especial" a "preventa".
	 * Se ejecuta una sola vez, marcada por la opción UNIFY_FLAG.
	 * No toca productos que ya sean 'preventa' ni los que no tengan meta.
	 */
	public static function maybe_unify_types(): void {
		if ( get_option( self::UNIFY_FLAG ) ) return;
		if ( ! class_exists( 'WooCommerce' ) ) return;

		global $wpdb;

		// 1. postmeta: _akb_reserva_tipo='pedido_especial' → 'preventa'
		$affected_posts = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = %s AND meta_value = %s",
				'preventa', '_akb_reserva_tipo', 'pedido_especial'
			)
		);

		// 2. order item meta: _akb_item_tipo='pedido_especial' → 'preventa'
		$affected_items = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}woocommerce_order_itemmeta SET meta_value = %s WHERE meta_key = %s AND meta_value = %s",
				'preventa', '_akb_item_tipo', 'pedido_especial'
			)
		);

		// 3. HPOS (si está activo): wc_orders_meta usa la misma estructura
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		$has_hpos   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table;
		$affected_hpos = 0;
		if ( $has_hpos ) {
			$affected_hpos = (int) $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}wc_orders_meta SET meta_value = %s WHERE meta_key = %s AND meta_value = %s",
					'preventa', '_akb_item_tipo', 'pedido_especial'
				)
			);
		}

		// 4. Copiar opción de texto legacy si no existe la nueva
		$legacy_txt = get_option( 'akb_reservas_texto_pedido_especial', '' );
		$new_txt    = get_option( 'akb_reservas_texto_sin_fecha', '' );
		if ( $legacy_txt && ! $new_txt ) {
			update_option( 'akb_reservas_texto_sin_fecha', $legacy_txt );
		}

		// 5. Limpiar transients de precio para que el descuento se recalcule
		wc_delete_product_transients();

		update_option( self::UNIFY_FLAG, [
			'at'              => time(),
			'posts_migrated'  => (int) $affected_posts,
			'items_migrated'  => (int) $affected_items,
			'hpos_migrated'   => $affected_hpos,
		] );
	}

	/**
	 * Ejecutar migracion de productos.
	 * @return array Stats de migracion.
	 */
	public static function run(): array {
		global $wpdb;

		$stats = [ 'products' => 0, 'orders' => 0, 'skipped' => 0 ];

		// ─── Migrar productos ────────────────────────────────────
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_ywpo_preorder', 'yes'
			)
		);

		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				$stats['skipped']++;
				continue;
			}

			// Ya migrado?
			if ( Akibara_Reserva_Product::is_reserva( $product ) ) {
				$stats['skipped']++;
				continue;
			}

			// Leer meta YITH
			$fecha_ts   = (int) get_post_meta( $pid, '_ywpo_for_sale_date', true );
			$fecha_modo = get_post_meta( $pid, '_ywpo_availability_date_mode', true );
			$yith_price = get_post_meta( $pid, '_ywpo_preorder_price', true );

			// Determinar tipo (YITH no distingue, asumimos preventa)
			$tipo = 'preventa';

			// Determinar modo de fecha
			$modo = 'sin_fecha';
			if ( $fecha_ts > 0 ) {
				$modo = ( 'date' === $fecha_modo ) ? 'fija' : 'estimada';
			}

			// Calcular descuento si hay precio especial
			$descuento = 0;
			if ( $yith_price && is_numeric( $yith_price ) ) {
				$regular = (float) $product->get_regular_price( 'edit' );
				if ( $regular > 0 && (float) $yith_price < $regular ) {
					$descuento = (int) round( ( 1 - (float) $yith_price / $regular ) * 100 );
				}
			}

			Akibara_Reserva_Product::set_meta( $product, [
				'enabled'    => 'yes',
				'tipo'       => $tipo,
				'fecha'      => $fecha_ts,
				'fecha_modo' => $modo,
				'descuento'  => $descuento,
				'auto'       => 'no',
			] );

			$stats['products']++;
		}

		// ─── Migrar ordenes ──────────────────────────────────────
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_order_has_preorder', 'yes'
			)
		);

		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order ) continue;

			// Ya migrado?
			if ( 'yes' === $order->get_meta( '_akb_tiene_reserva' ) ) continue;

			$yith_status = $order->get_meta( '_ywpo_status' );
			$estado_map  = [
				'waiting'   => 'esperando',
				'completed' => 'completada',
				'cancelled' => 'cancelada',
			];

			$order->update_meta_data( '_akb_tiene_reserva', 'yes' );
			$order->update_meta_data( '_akb_reserva_estado', $estado_map[ $yith_status ] ?? 'esperando' );

			// Migrar item meta
			foreach ( $order->get_items() as $item ) {
				$item_preorder = $item->get_meta( '_ywpo_item_preorder' );
				if ( 'yes' !== $item_preorder ) continue;

				$item_status = $item->get_meta( '_ywpo_item_status' );
				$item_fecha  = $item->get_meta( '_ywpo_item_for_sale_date' );

				$item->update_meta_data( '_akb_item_reserva', 'yes' );
				$item->update_meta_data( '_akb_item_estado', $estado_map[ $item_status ] ?? 'esperando' );
				$item->update_meta_data( '_akb_item_tipo', 'preventa' );
				if ( $item_fecha ) {
					$item->update_meta_data( '_akb_item_fecha_estimada', (int) $item_fecha );
				}
				$item->save();
			}

			$order->save();
			$stats['orders']++;
		}

		// Marcar como migrado
		update_option( 'akb_reservas_yith_migrated', true );

		return $stats;
	}
}
