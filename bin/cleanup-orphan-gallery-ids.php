<?php
/**
 * One-shot cleanup of orphan attachment IDs in `_product_image_gallery`.
 *
 * Audit 2026-04-27 P0-01: 29 products in ID range 22000-22999 (The Climber 1-17,
 * Smiley 1-11, Atelier of Witch Hat 14) have gallery meta pointing to attachment
 * IDs that don't exist in Media Library. This causes the single-product gallery
 * template to render empty thumbnail buttons (visible as black boxes).
 *
 * USAGE:
 *   wp eval-file bin/cleanup-orphan-gallery-ids.php          # dry-run (default)
 *   wp eval-file bin/cleanup-orphan-gallery-ids.php execute  # destructive
 *
 * Run dry-run FIRST. Verify counts match expected (~29 products, ~46 orphan IDs).
 * Then run with `execute` to commit changes.
 *
 * Companion fixes (already deployed):
 *  - A: render-time guard in `template-parts/single-product/gallery.php`
 *  - D: `delete_attachment` hook in `inc/gallery-cleanup.php`
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "ERROR: this script must be run via `wp eval-file`.\n" );
    exit( 1 );
}

$execute = false;
foreach ( (array) ( $args ?? [] ) as $arg ) {
    if ( $arg === 'execute' ) {
        $execute = true;
    }
}

$mode = $execute ? 'EXECUTE' : 'DRY-RUN';
WP_CLI::line( "=== Cleanup orphan gallery IDs — mode: {$mode} ===" );

global $wpdb;

$rows = $wpdb->get_results(
    "SELECT post_id, meta_value
     FROM {$wpdb->postmeta}
     WHERE meta_key = '_product_image_gallery'
       AND meta_value <> ''"
);

WP_CLI::line( sprintf( 'Inspecting %d products with non-empty _product_image_gallery…', count( $rows ) ) );

$products_affected = 0;
$orphans_total     = 0;
$report            = [];

foreach ( $rows as $row ) {
    $post_id  = (int) $row->post_id;
    $ids      = array_filter( array_map( 'intval', explode( ',', (string) $row->meta_value ) ) );
    $valid    = [];
    $orphans  = [];

    foreach ( $ids as $aid ) {
        $post_obj = get_post( $aid );
        if ( $post_obj && $post_obj->post_type === 'attachment' ) {
            $valid[] = $aid;
        } else {
            $orphans[] = $aid;
        }
    }

    if ( empty( $orphans ) ) {
        continue;
    }

    $products_affected++;
    $orphans_total += count( $orphans );

    $product   = get_post( $post_id );
    $title     = $product ? $product->post_title : '(unknown)';
    $new_value = implode( ',', $valid );

    $report[] = [
        'product_id' => $post_id,
        'title'      => $title,
        'orphans'    => $orphans,
        'before'     => (string) $row->meta_value,
        'after'      => $new_value,
    ];

    if ( $execute ) {
        update_post_meta( $post_id, '_product_image_gallery', $new_value );
    }
}

WP_CLI::line( '' );
WP_CLI::line( '=== Affected products ===' );
foreach ( $report as $r ) {
    WP_CLI::line( sprintf(
        '  #%d  "%s"  orphans=[%s]  before="%s"  after="%s"',
        $r['product_id'],
        $r['title'],
        implode( ',', $r['orphans'] ),
        $r['before'],
        $r['after']
    ) );
}

WP_CLI::line( '' );
WP_CLI::line( '=== Summary ===' );
WP_CLI::line( sprintf( '  Products affected:   %d', $products_affected ) );
WP_CLI::line( sprintf( '  Orphan IDs removed:  %d', $orphans_total ) );
WP_CLI::line( sprintf( '  Mode:                %s', $mode ) );

if ( ! $execute ) {
    WP_CLI::line( '' );
    WP_CLI::warning( 'DRY-RUN — no changes committed. Re-run with `execute` to apply.' );
} else {
    WP_CLI::success( sprintf( 'Cleanup complete. %d products updated.', $products_affected ) );
}
