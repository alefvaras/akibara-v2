<?php
/**
 * Gallery cleanup — auto-remove orphan attachment IDs from `_product_image_gallery`
 * when the referenced attachment is deleted.
 *
 * Why: WP core auto-clears `_thumbnail_id` on attachment delete, but does NOT
 * touch `_product_image_gallery` (a WC custom field). Orphan IDs left in the
 * meta cause the single-product gallery template to render empty thumbnail
 * buttons (visible as black boxes — see findings.md P0-01).
 *
 * This hook fires on `delete_attachment` and removes the deleted ID from any
 * product whose gallery meta references it.
 *
 * Companion fixes:
 *  - A: render-time guard in `template-parts/single-product/gallery.php`
 *  - C: one-shot cleanup script `bin/cleanup-orphan-gallery-ids.php`
 *
 * @package Akibara
 * @since   1.0.0 (audit 2026-04-27 P0-01)
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'akibara_remove_orphan_gallery_id' ) ) {
    /**
     * Remove a single attachment ID from any product's `_product_image_gallery`.
     *
     * Uses esc_like patterns to find products whose meta_value contains the ID
     * (anywhere in the comma-separated list). Updates each affected row.
     *
     * @param int $attachment_id Attachment post ID being deleted.
     * @return int Number of products updated.
     */
    function akibara_remove_orphan_gallery_id( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        if ( ! $attachment_id ) {
            return 0;
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND ( meta_value = %s
                   OR meta_value LIKE %s
                   OR meta_value LIKE %s
                   OR meta_value LIKE %s )",
            (string) $attachment_id,
            $wpdb->esc_like( $attachment_id . ',' ) . '%',
            '%,' . $wpdb->esc_like( ',' . $attachment_id . ',' ) . '%',
            '%,' . $wpdb->esc_like( $attachment_id )
        ) );

        if ( empty( $rows ) ) {
            return 0;
        }

        $updated = 0;
        foreach ( $rows as $row ) {
            $ids       = array_filter( array_map( 'intval', explode( ',', (string) $row->meta_value ) ) );
            $remaining = array_filter( $ids, function ( $id ) use ( $attachment_id ) {
                return (int) $id !== (int) $attachment_id;
            } );
            $new_value = implode( ',', $remaining );
            if ( $new_value !== (string) $row->meta_value ) {
                update_post_meta( (int) $row->post_id, '_product_image_gallery', $new_value );
                $updated++;
            }
        }

        return $updated;
    }
}

add_action( 'delete_attachment', 'akibara_remove_orphan_gallery_id', 10, 1 );
