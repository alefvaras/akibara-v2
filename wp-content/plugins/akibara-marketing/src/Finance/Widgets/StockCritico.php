<?php
/**
 * StockCritico widget — productos con stock bajo umbral critico.
 *
 * Data source: wp_postmeta (_stock) JOIN wc_orders (for recent sales velocity).
 * Threshold: stock < 3 (default, filterable).
 *
 * @package Akibara\Marketing\Finance\Widgets
 */

declare(strict_types=1);

namespace Akibara\Marketing\Finance\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Returns products with stock below critical threshold (default: < 3 units).
 *
 * Queries wp_postmeta for _stock values. HPOS-aware for sold-last-30d count.
 * Cache: 15-minute transient.
 */
final class StockCritico {

	private const CACHE_TTL       = 15 * MINUTE_IN_SECONDS;
	private const CACHE_KEY       = 'akb_fin_stock_critico';
	private const DEFAULT_THRESH  = 3;

	/**
	 * Fetch products with critical stock.
	 *
	 * @param int $threshold  Include products with stock strictly below this value.
	 * @param int $limit      Max products to return.
	 * @return array<int,array{product_id:int,title:string,stock:int,sold_30d:int,sku:string}>
	 */
	public function fetch( int $threshold = self::DEFAULT_THRESH, int $limit = 20 ): array {
		$cache_key = self::CACHE_KEY . '_' . $threshold . '_' . $limit;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $this->query( $threshold, $limit );
		set_transient( $cache_key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Invalidate cache.
	 */
	public function invalidate(): void {
		// Remove common threshold+limit combinations.
		foreach ( array( 3, 5 ) as $t ) {
			foreach ( array( 10, 20, 50 ) as $l ) {
				delete_transient( self::CACHE_KEY . '_' . $t . '_' . $l );
			}
		}
	}

	/**
	 * @return array<int,array{product_id:int,title:string,stock:int,sold_30d:int,sku:string}>
	 */
	private function query( int $threshold, int $limit ): array {
		global $wpdb;

		// Step 1: fetch products with stock < threshold (published + simple/variation).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stock_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID as product_id, p.post_title as title,
						CAST(pm_stock.meta_value AS SIGNED) AS stock,
						COALESCE(pm_sku.meta_value, '') AS sku
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm_stock
					ON pm_stock.post_id = p.ID
					AND pm_stock.meta_key = '_stock'
				LEFT JOIN {$wpdb->postmeta} pm_sku
					ON pm_sku.post_id = p.ID
					AND pm_sku.meta_key = '_sku'
				WHERE p.post_status = 'publish'
				AND p.post_type IN ('product', 'product_variation')
				AND CAST(pm_stock.meta_value AS SIGNED) >= 0
				AND CAST(pm_stock.meta_value AS SIGNED) < %d
				ORDER BY stock ASC
				LIMIT %d",
				$threshold,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $stock_rows ) || empty( $stock_rows ) ) {
			return array();
		}

		$product_ids = array_map( static fn( array $r ) => (int) $r['product_id'], $stock_rows );

		// Step 2: sold in last 30 days per product (HPOS).
		$sold_map = $this->sold_last_30d( $product_ids );

		return array_map(
			static fn( array $row ) => array(
				'product_id' => (int) $row['product_id'],
				'title'      => (string) $row['title'],
				'stock'      => (int) $row['stock'],
				'sold_30d'   => (int) ( $sold_map[ (int) $row['product_id'] ] ?? 0 ),
				'sku'        => (string) $row['sku'],
			),
			$stock_rows
		);
	}

	/**
	 * Returns sold quantity per product in last 30 days.
	 *
	 * @param int[] $product_ids
	 * @return array<int,int>  product_id => total_qty
	 */
	private function sold_last_30d( array $product_ids ): array {
		if ( empty( $product_ids ) ) {
			return array();
		}

		global $wpdb;
		$cutoff      = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CAST(oim_pid.meta_value AS UNSIGNED) AS product_id,
					SUM(CAST(oim_qty.meta_value AS UNSIGNED)) AS total_qty
				FROM {$wpdb->prefix}woocommerce_order_items oi
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
					ON oim_pid.order_item_id = oi.order_item_id
					AND oim_pid.meta_key = '_product_id'
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_qty
					ON oim_qty.order_item_id = oi.order_item_id
					AND oim_qty.meta_key = '_qty'
				JOIN {$wpdb->prefix}wc_orders o
					ON o.id = oi.order_id
					AND o.type = 'shop_order'
					AND o.status IN ('wc-completed','wc-processing')
					AND o.date_created_gmt >= %s
				WHERE CAST(oim_pid.meta_value AS UNSIGNED) IN ($placeholders)
				GROUP BY product_id",
				array_merge( array( $cutoff ), $product_ids )
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row['product_id'] ] = (int) $row['total_qty'];
			}
		}
		return $map;
	}
}
