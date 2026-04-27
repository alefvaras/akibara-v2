<?php
/**
 * Auto-purge Cloudflare when LiteSpeed purges cache.
 *
 * @package Akibara
 * @since   2.1.0
 */
defined( 'ABSPATH' ) || exit;

add_action( 'litespeed_purged_all', 'akb_cf_purge_all' );
add_action( 'litespeed_api_purge_all', 'akb_cf_purge_all' );

function akb_cf_purge_all(): void {
    $token = get_option( 'akb_cf_api_token', '' );
    $zone  = get_option( 'akb_cf_zone_id', '' );

    if ( empty( $token ) || empty( $zone ) ) {
        return;
    }

    wp_remote_request(
        "https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache",
        [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'purge_everything' => true ] ),
            'timeout' => 10,
        ]
    );
}
