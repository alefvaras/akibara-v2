<?php
/**
 * Akibara Inventario — StockRepository.
 *
 * Thin data-access layer for stock log queries.
 * All heavy analytics queries go through here for testability.
 *
 * @package Akibara\Inventario\Repository
 * @since   1.0.0
 */

namespace Akibara\Inventario\Repository;

defined( 'ABSPATH' ) || exit;

class StockRepository {

	/**
	 * Return products with stock at or below threshold.
	 *
	 * @param int $threshold Low stock threshold (default: WC global setting).
	 * @param int $limit Max results.
	 * @return array<int, array{id:int, title:string, stock:int, threshold:int, editorial:string}>
	 */
	public function get_low_stock_products( int $threshold = 0, int $limit = 50 ): array {
		global $wpdb;

		if ( $threshold <= 0 ) {
			$threshold = max( 1, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix . literal strings.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title,
				        CAST(stk.meta_value AS SIGNED) AS stock_qty,
				        COALESCE(NULLIF(CAST(low.meta_value AS SIGNED), 0), %d) AS low_threshold
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} ss  ON ss.post_id  = p.ID AND ss.meta_key  = '_stock_status'
				 JOIN {$wpdb->postmeta} stk ON stk.post_id = p.ID AND stk.meta_key = '_stock'
				 LEFT JOIN {$wpdb->postmeta} low ON low.post_id = p.ID AND low.meta_key = '_low_stock_amount'
				 WHERE p.post_type = 'product'
				   AND p.post_status = 'publish'
				   AND ss.meta_value = 'instock'
				   AND CAST(stk.meta_value AS SIGNED) > 0
				   AND CAST(stk.meta_value AS SIGNED) <= COALESCE(NULLIF(CAST(low.meta_value AS SIGNED), 0), %d)
				 ORDER BY CAST(stk.meta_value AS SIGNED) ASC
				 LIMIT %d",
				$threshold,
				$threshold,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = array(
				'id'        => (int) $row->ID,
				'title'     => $row->post_title,
				'stock'     => (int) $row->stock_qty,
				'threshold' => (int) $row->low_threshold,
				'editorial' => '', // Populated lazily by caller if needed.
			);
		}

		return $results;
	}

	/**
	 * Return stock log entries for a product (most recent first).
	 *
	 * @param int $product_id 0 = all products.
	 * @param int $limit Max rows.
	 * @param int $offset Pagination offset.
	 * @return array<int, object>
	 */
	public function get_log( int $product_id = 0, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'akb_stock_log';

		if ( $product_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.*, p.post_title
					 FROM {$table} l
					 LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
					 WHERE l.product_id = %d
					 ORDER BY l.created_at DESC
					 LIMIT %d OFFSET %d",
					$product_id,
					$limit,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.*, p.post_title
					 FROM {$table} l
					 LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
					 ORDER BY l.created_at DESC
					 LIMIT %d OFFSET %d",
					$limit,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return aggregate stock stats.
	 *
	 * @return array{total:int, instock:int, outofstock:int, onbackorder:int, low:int, no_manage:int, inventory_value:float}
	 */
	public function get_stats(): array {
		global $wpdb;
		$default_low = max( 1, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
		);

		$status_rows = $wpdb->get_results(
			"SELECT pm.meta_value s, COUNT(*) c
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_stock_status'
			   AND p.post_type = 'product'
			   AND p.post_status = 'publish'
			 GROUP BY pm.meta_value"
		);

		$statuses = array( 'instock' => 0, 'outofstock' => 0, 'onbackorder' => 0 );
		foreach ( (array) $status_rows as $r ) {
			$statuses[ $r->s ] = (int) $r->c;
		}

		$low = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->postmeta} stk
				 JOIN {$wpdb->postmeta} ss  ON ss.post_id  = stk.post_id AND ss.meta_key = '_stock_status' AND ss.meta_value = 'instock'
				 LEFT JOIN {$wpdb->postmeta} low ON low.post_id = stk.post_id AND low.meta_key = '_low_stock_amount'
				 JOIN {$wpdb->posts} p ON p.ID = stk.post_id
				 WHERE stk.meta_key = '_stock'
				   AND CAST(stk.meta_value AS SIGNED) > 0
				   AND CAST(stk.meta_value AS SIGNED) <= COALESCE(NULLIF(CAST(low.meta_value AS SIGNED), 0), %d)
				   AND p.post_type = 'product' AND p.post_status = 'publish'",
				$default_low
			)
		);

		$no_manage = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			   AND p.ID NOT IN (
			       SELECT post_id FROM {$wpdb->postmeta}
			       WHERE meta_key = '_manage_stock' AND meta_value = 'yes'
			   )"
		);

		$inventory_value = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(CAST(s.meta_value AS SIGNED) * CAST(pr.meta_value AS DECIMAL(10,2))), 0)
			 FROM {$wpdb->postmeta} s
			 JOIN {$wpdb->postmeta} pr ON pr.post_id = s.post_id AND pr.meta_key = '_price'
			 JOIN {$wpdb->posts} p ON p.ID = s.post_id
			 WHERE s.meta_key = '_stock'
			   AND CAST(s.meta_value AS SIGNED) > 0
			   AND p.post_type = 'product' AND p.post_status = 'publish'"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_merge(
			array(
				'total'           => $total,
				'low'             => $low,
				'no_manage'       => $no_manage,
				'inventory_value' => $inventory_value,
			),
			$statuses
		);
	}
}
