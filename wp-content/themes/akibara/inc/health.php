<?php
/**
 * Akibara — Health Check Endpoint
 * GET /wp-json/akibara/v1/health
 *
 * Returns 200 if WordPress, WooCommerce, and DB are functional.
 * Returns 503 if any critical service is down.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// Skip entirely if plugin health-check module already loaded (it has proper auth)
if ( defined( 'AKIBARA_HEALTH_CHECK_LOADED' ) || function_exists( 'akibara_health_check' ) ) {
    return;
}

add_action( 'rest_api_init', function (): void {
    if ( function_exists( 'akibara_health_check' ) ) return;
    register_rest_route( 'akibara/v1', '/health', [
        'methods'             => 'GET',
        'callback'            => 'akibara_theme_health_check',
        'permission_callback' => '__return_true',
    ] );
} );

function akibara_theme_health_check( WP_REST_Request $request ): WP_REST_Response {
    // Modo protegido: si el endpoint recibe ?verbose=1 exigimos un token en
    // wp_options `akibara_health_token` para exponer integraciones externas.
    $verbose = '1' === (string) $request->get_param( 'verbose' );
    if ( $verbose ) {
        $token_header = $request->get_header( 'x-akb-health-token' );
        $token_query  = (string) $request->get_param( 'token' );
        $expected     = get_option( 'akibara_health_token', '' );
        if ( ! $expected || ! hash_equals( (string) $expected, (string) ( $token_header ?: $token_query ) ) ) {
            $verbose = false;
        }
    }

    $checks  = [];
    $healthy = true;

    // WordPress
    $checks['wordpress'] = [
        'status'  => 'ok',
        'version' => get_bloginfo( 'version' ),
    ];

    // Database
    global $wpdb;
    try {
        $db_ok = (bool) $wpdb->get_var( 'SELECT 1' );
        $checks['database'] = [ 'status' => $db_ok ? 'ok' : 'error' ];
        if ( ! $db_ok ) $healthy = false;
    } catch ( \Exception $e ) {
        $checks['database'] = [ 'status' => 'error', 'message' => 'Connection failed' ];
        $healthy = false;
    }

    // WooCommerce
    if ( class_exists( 'WooCommerce' ) ) {
        $checks['woocommerce'] = [ 'status' => 'ok', 'version' => WC_VERSION ];
    } else {
        $checks['woocommerce'] = [ 'status' => 'error' ];
        $healthy = false;
    }

    // Object Cache
    $checks['object_cache'] = [
        'status' => wp_using_ext_object_cache() ? 'external' : 'internal',
    ];

    // Disk space
    $free = disk_free_space( ABSPATH );
    $checks['disk'] = [
        'status'  => $free > 100 * 1024 * 1024 ? 'ok' : 'warning',
        'free_mb' => round( $free / 1024 / 1024 ),
    ];

    // Plugins críticos
    $critical_plugins = [
        'woocommerce'              => 'woocommerce/woocommerce.php',
        'bluex-for-woocommerce'    => 'bluex-for-woocommerce/woocommerce-bluex.php',
        'akibara'                  => 'akibara/akibara.php',
    ];
    $missing = [];
    $active  = (array) get_option( 'active_plugins', [] );
    foreach ( $critical_plugins as $name => $path ) {
        if ( ! in_array( $path, $active, true ) ) {
            $missing[] = $name;
        }
    }
    $checks['critical_plugins'] = [
        'status'  => empty( $missing ) ? 'ok' : 'warning',
        'missing' => $missing,
    ];
    if ( ! empty( $missing ) ) $healthy = false;

    // Dev cache mode (alerta si sigue activo en prod).
    $dev_cache = (int) get_option( 'akb_dev_nocache_global', 0 );
    $checks['dev_cache_global'] = [
        'status'  => $dev_cache ? 'warning' : 'ok',
        'enabled' => (bool) $dev_cache,
        'note'    => $dev_cache ? 'LiteSpeed OFF globalmente — deshabilitar en producción.' : '',
    ];

    // Verbose: probe Brevo (sin exponer API key).
    if ( $verbose ) {
        $brevo_key = function_exists( 'akb_brevo_get_api_key' )
            ? akb_brevo_get_api_key()
            : (string) get_option( 'akibara_brevo_api_key', '' );
        if ( $brevo_key ) {
            $resp = wp_remote_get( 'https://api.brevo.com/v3/account', [
                'timeout' => 3,
                'headers' => [ 'api-key' => $brevo_key, 'accept' => 'application/json' ],
            ] );
            $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
            $checks['brevo'] = [
                'status' => 200 === $code ? 'ok' : 'error',
                'http'   => $code,
            ];
        } else {
            $checks['brevo'] = [ 'status' => 'skipped', 'reason' => 'no API key configured' ];
        }

        // PHP info
        $checks['php'] = [
            'version'      => PHP_VERSION,
            'memory_limit' => ini_get( 'memory_limit' ),
        ];

        // Plugins activos (count)
        $checks['plugins'] = [ 'active' => count( $active ) ];
    }

    $status_code = $healthy ? 200 : 503;

    return new WP_REST_Response( [
        'status'    => $healthy ? 'healthy' : 'unhealthy',
        'timestamp' => gmdate( 'c' ),
        'verbose'   => $verbose,
        'checks'    => $checks,
    ], $status_code );
}
