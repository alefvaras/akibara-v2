<?php
/**
 * Akibara Inventario — BackInStockRepository.
 *
 * Data-access for wp_akb_back_in_stock_subs table.
 * Canonical table name (migrated from wp_akb_bis_subs).
 *
 * @package Akibara\Inventario\Repository
 * @since   1.0.0
 */

namespace Akibara\Inventario\Repository;

defined( 'ABSPATH' ) || exit;

class BackInStockRepository {

	/** @return string Canonical table name. */
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'akb_back_in_stock_subs';
	}

	/**
	 * Count active subscriptions for a product.
	 */
	public function count_active( int $product_id ): int {
		global $wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND status = 'active'",
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count active subs for an email (rate limit check).
	 */
	public function count_active_by_email( string $email ): int {
		global $wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE email = %s AND status = 'active'",
				$email
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Find existing subscription row.
	 *
	 * @return object|null
	 */
	public function find( string $email, int $product_id ): ?object {
		global $wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$table} WHERE email = %s AND product_id = %d",
				$email,
				$product_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/**
	 * Upsert subscription. Returns 'created'|'reactivated'|'already_active'.
	 */
	public function upsert( string $email, int $product_id, string $token ): string {
		global $wpdb;
		$table    = $this->table();
		$existing = $this->find( $email, $product_id );

		if ( $existing ) {
			if ( $existing->status === 'active' ) {
				return 'already_active';
			}
			$wpdb->update(
				$table,
				array(
					'status'       => 'active',
					'token'        => $token,
					'notified_at'  => null,
					'converted_at' => null,
				),
				array( 'id' => (int) $existing->id )
			);
			return 'reactivated';
		}

		$wpdb->insert(
			$table,
			array(
				'product_id' => $product_id,
				'email'      => $email,
				'token'      => $token,
				'status'     => 'active',
			)
		);
		return 'created';
	}

	/**
	 * Find subscription by token.
	 *
	 * @return object|null {id, product_id}
	 */
	public function find_by_token( string $token ): ?object {
		global $wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, product_id FROM {$table} WHERE token = %s AND status != 'unsubscribed'",
				$token
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/**
	 * Mark as unsubscribed.
	 */
	public function unsubscribe( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array( 'status' => 'unsubscribed' ),
			array( 'id' => $id )
		);
	}

	/**
	 * Get active subscribers for a product (for email dispatch).
	 *
	 * @param int $limit Safety cap to prevent memory spikes.
	 * @return array<int, object>
	 */
	public function get_active_subs( int $product_id, int $limit = 500 ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email, token FROM {$table}
				 WHERE product_id = %d AND status = 'active'
				 ORDER BY created_at ASC
				 LIMIT %d",
				$product_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark rows as notified (batch).
	 */
	public function mark_notified( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'status'      => 'notified',
				'notified_at' => current_time( 'mysql', 1 ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Mark conversion for email+product_ids combination.
	 *
	 * @param string $email
	 * @param int[]  $product_ids
	 */
	public function mark_converted( string $email, array $product_ids ): void {
		if ( empty( $product_ids ) ) {
			return;
		}
		global $wpdb;
		$table        = $this->table();
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$params       = array_merge( array( $email ), $product_ids );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'notified', converted_at = NOW()
				 WHERE email = %s
				   AND product_id IN ({$placeholders})
				   AND status IN ('notified','active')
				   AND converted_at IS NULL",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Aggregate stats for admin panel.
	 *
	 * @return object{active:int, notified:int, unsub:int, converted:int, total:int}
	 */
	public function get_stats(): object {
		global $wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stats = $wpdb->get_row(
			"SELECT
			    SUM(status='active')           AS active,
			    SUM(status='notified')          AS notified,
			    SUM(status='unsubscribed')      AS unsub,
			    SUM(converted_at IS NOT NULL)   AS converted,
			    COUNT(*)                        AS total
			 FROM {$table}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $stats ?? (object) array( 'active' => 0, 'notified' => 0, 'unsub' => 0, 'converted' => 0, 'total' => 0 );
	}

	/**
	 * Get recent active rows for admin display.
	 *
	 * @return array<int, object>
	 */
	public function get_active_rows( int $limit = 100 ): array {
		global $wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.email, s.product_id, s.created_at, p.post_title
				 FROM {$table} s
				 LEFT JOIN {$wpdb->posts} p ON s.product_id = p.ID
				 WHERE s.status = 'active'
				 ORDER BY s.created_at DESC
				 LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}
}
