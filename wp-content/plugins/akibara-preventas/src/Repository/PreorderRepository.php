<?php

declare(strict_types=1);

namespace Akibara\Preventas\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for wp_akb_preorders table.
 *
 * All queries use $wpdb->prepare() for SQL injection prevention.
 * HPOS-compatible: reads from wp_akb_preorders (our table), not WC order tables directly.
 */
final class PreorderRepository {

    private \wpdb $db;

    private string $table_preorders;
    private string $table_batches;
    private string $table_special;

    public function __construct() {
        global $wpdb;
        $this->db              = $wpdb;
        $this->table_preorders = AKB_PREVENTAS_TABLE_PREORDERS;
        $this->table_batches   = AKB_PREVENTAS_TABLE_BATCHES;
        $this->table_special   = AKB_PREVENTAS_TABLE_SPECIAL_ORDERS;
    }

    // ─── Preorders ───────────────────────────────────────────────────────────

    /**
     * Find a preorder record by its WC order ID + order item ID combination.
     *
     * @param int $order_id      WC order ID.
     * @param int $order_item_id WC order item ID.
     * @return object|null       Row object or null.
     */
    public function find_by_order_item( int $order_id, int $order_item_id ): ?object {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from constant (no user input).
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->table_preorders} WHERE order_id = %d AND order_item_id = %d LIMIT 1",
                $order_id,
                $order_item_id
            )
        );

        return $row ?: null;
    }

    /**
     * Find all preorders for a given product ID.
     *
     * @param int    $product_id WC product ID.
     * @param string $status     Filter by status or '' for all.
     * @return object[]
     */
    public function find_by_product( int $product_id, string $status = '' ): array {
        if ( '' !== $status ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->table_preorders} WHERE product_id = %d AND status = %s ORDER BY created_at ASC",
                    $product_id,
                    $status
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->table_preorders} WHERE product_id = %d ORDER BY created_at ASC",
                    $product_id
                )
            );
        }

        return $rows ?: [];
    }

    /**
     * Create a new preorder record.
     *
     * @param array $data Associative array with required fields.
     * @return int|false  Inserted ID or false on failure.
     */
    public function create( array $data ): int|false {
        $defaults = [
            'status'       => 'pending',
            'variation_id' => 0,
            'customer_id'  => 0,
            'qty'          => 1,
        ];

        $data = array_merge( $defaults, $data );

        $inserted = $this->db->insert(
            $this->table_preorders,
            [
                'order_id'       => (int) $data['order_id'],
                'order_item_id'  => (int) $data['order_item_id'],
                'product_id'     => (int) $data['product_id'],
                'variation_id'   => (int) $data['variation_id'],
                'customer_id'    => (int) $data['customer_id'],
                'customer_email' => sanitize_email( $data['customer_email'] ?? '' ),
                'qty'            => (int) $data['qty'],
                'status'         => sanitize_key( $data['status'] ),
                'batch_id'       => isset( $data['batch_id'] ) ? (int) $data['batch_id'] : null,
                'expected_date'  => $data['expected_date'] ?? null,
            ],
            [ '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s' ]
        );

        return false !== $inserted ? (int) $this->db->insert_id : false;
    }

    /**
     * Update the status of a preorder record.
     *
     * @param int    $id     Preorder record ID.
     * @param string $status New status value.
     * @return bool
     */
    public function update_status( int $id, string $status ): bool {
        $extra = [];
        if ( 'delivered' === $status ) {
            $extra['fulfilled_at'] = current_time( 'mysql' );
        } elseif ( 'cancelled' === $status ) {
            $extra['cancelled_at'] = current_time( 'mysql' );
        }

        $result = $this->db->update(
            $this->table_preorders,
            array_merge( [ 'status' => sanitize_key( $status ) ], $extra ),
            [ 'id' => $id ],
            [ '%s' ] + array_fill( 0, count( $extra ), '%s' ),
            [ '%d' ]
        );

        return false !== $result;
    }

    // ─── Special Orders (Encargos) ────────────────────────────────────────────

    /**
     * Create a new special order (encargo) record.
     *
     * @param array $data Form data already sanitized by the caller.
     * @return int|false  Inserted ID or false.
     */
    public function create_special_order( array $data ): int|false {
        $inserted = $this->db->insert(
            $this->table_special,
            [
                'nombre'    => sanitize_text_field( $data['nombre'] ?? '' ),
                'email'     => sanitize_email( $data['email'] ?? '' ),
                'titulo'    => sanitize_text_field( $data['titulo'] ?? '' ),
                'editorial' => sanitize_text_field( $data['editorial'] ?? '' ),
                'volumenes' => sanitize_text_field( $data['volumenes'] ?? '' ),
                'notas'     => sanitize_textarea_field( $data['notas'] ?? '' ),
                'status'    => 'pendiente',
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return false !== $inserted ? (int) $this->db->insert_id : false;
    }

    /**
     * Get all pending special orders.
     *
     * @return object[]
     */
    public function get_pending_special_orders(): array {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $this->db->get_results(
            "SELECT * FROM {$this->table_special} WHERE status = 'pendiente' ORDER BY fecha ASC"
        );

        return $rows ?: [];
    }

    /**
     * Count pending special orders.
     *
     * @return int
     */
    public function count_pending_special_orders(): int {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->table_special} WHERE status = 'pendiente'"
        );
    }

    /**
     * Update special order status.
     *
     * @param int    $id     Special order ID.
     * @param string $status New status.
     * @return bool
     */
    public function update_special_order_status( int $id, string $status ): bool {
        $result = $this->db->update(
            $this->table_special,
            [ 'status' => sanitize_key( $status ) ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );

        return false !== $result;
    }
}
