<?php
/**
 * TopSeriesByVolume widget — series ordenadas por unidades vendidas.
 *
 * Consumes Akibara_Order::META_SERIE from akibara-core (read-only).
 * Uses HPOS-aware queries (wc_orders + wc_order_items + wc_orders_meta).
 *
 * @package Akibara\Marketing\Finance\Widgets
 */

declare(strict_types=1);

namespace Akibara\Marketing\Finance\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Returns top N manga series by total units sold, ordered desc.
 *
 * Data source: wc_orders + wc_order_items + wc_orders_meta (HPOS).
 * Meta key: Akibara_Order::META_SERIE = '_akibara_serie_norm'
 * Cache: 30-minute transient, keyed by limit.
 */
final class TopSeriesByVolume {

	private const CACHE_TTL  = 30 * MINUTE_IN_SECONDS;
	private const CACHE_BASE = 'akb_fin_top_series_';

	/**
	 * Fetch top series.
	 *
	 * @param int $limit  Max series to return (default 10).
	 * @return array<int,array{serie:string,units:int}>  Empty array on error.
	 */
	public function fetch( int $limit = 10 ): array {
		$cache_key = self::CACHE_BASE . $limit;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $this->query( $limit );
		set_transient( $cache_key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Invalidate cached data (call on order status change).
	 */
	public function invalidate(): void {
		foreach ( array( 5, 10, 20 ) as $limit ) {
			delete_transient( self::CACHE_BASE . $limit );
		}
	}

	/**
	 * @return array<int,array{serie:string,units:int}>
	 */
	private function query( int $limit ): array {
		global $wpdb;

		// Akibara_Order::META_SERIE = '_akibara_serie_norm'
		// Stored on the product post (write_product_meta), NOT the order line item.
		// We join via _product_id on the order item meta.
		// HPOS: wc_orders is the canonical orders table.

		$meta_key = class_exists( 'Akibara_Order' )
			? \Akibara_Order::META_SERIE
			: '_akibara_serie_norm';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					pm.meta_value          AS serie,
					SUM(oi_qty.meta_value) AS units
				FROM {$wpdb->prefix}woocommerce_order_items  oi
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
					ON oim_pid.order_item_id = oi.order_item_id
					AND oim_pid.meta_key = '_product_id'
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta oi_qty
					ON oi_qty.order_item_id = oi.order_item_id
					AND oi_qty.meta_key = '_qty'
				JOIN {$wpdb->postmeta} pm
					ON pm.post_id = CAST(oim_pid.meta_value AS UNSIGNED)
					AND pm.meta_key = %s
				JOIN {$wpdb->prefix}wc_orders o
					ON o.id = oi.order_id
					AND o.type = 'shop_order'
					AND o.status IN ('wc-completed','wc-processing')
				WHERE pm.meta_value != ''
				GROUP BY pm.meta_value
				ORDER BY units DESC
				LIMIT %d",
				$meta_key,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ) => array(
				'serie' => (string) $row['serie'],
				'units' => (int) $row['units'],
			),
			$rows
		);
	}
}
