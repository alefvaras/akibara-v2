<?php
/**
 * Akibara — BlueX Incoming Webhook
 * Receives tracking status updates from Blue Express
 * and auto-completes WooCommerce orders when delivered.
 *
 * Endpoint: POST /wp-json/akibara/v1/bluex-webhook
 * Auth: X-BlueX-Secret header
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// Secret key for webhook auth (set in wp-config.php or options)
if ( ! defined( 'AKB_BLUEX_WEBHOOK_SECRET' ) ) {
    define( 'AKB_BLUEX_WEBHOOK_SECRET', get_option( 'akb_bluex_webhook_secret', '' ) );
}

add_action( 'rest_api_init', function (): void {
    register_rest_route( 'akibara/v1', '/bluex-webhook', [
        'methods'             => 'POST',
        'callback'            => 'akb_bluex_webhook_handler',
        'permission_callback' => 'akb_bluex_webhook_auth',
    ] );
} );

/**
 * Authenticate webhook request via shared secret.
 *
 * B-S1-SEC-06 (2026-04-27): hard-fail cuando secret vacío (antes `return true` —
 * bypass abierto). El secret se auto-genera vía `admin_init` más abajo en este
 * archivo. Si llega vacío a este auth = misconfig severo → preferimos rechazar
 * legítimos a aceptar todo.
 */
function akb_bluex_webhook_auth( WP_REST_Request $request ): bool {
    $secret = AKB_BLUEX_WEBHOOK_SECRET;
    if ( empty( $secret ) ) {
        error_log( '[Akibara BlueX Webhook] HARD-FAIL: No secret configurado. Visita wp-admin para auto-generar.' );
        return false;
    }

    $header = $request->get_header( 'X-BlueX-Secret' ) ?? $request->get_header( 'x-bluex-secret' ) ?? '';
    if ( empty( $header ) ) {
        return false;
    }
    return hash_equals( $secret, $header );
}

/**
 * Handle incoming tracking update from Blue Express.
 *
 * Expected payload (flexible, handles multiple formats):
 * {
 *   "os": "123456789",
 *   "status": "DELIVERED" | "IN_TRANSIT" | "OUT_FOR_DELIVERY" | "RETURNED" | ...,
 *   "order_id": 12345,  (optional, WC order ID)
 *   "tracking_number": "123456789", (alternative to "os")
 *   "timestamp": "2026-04-12T16:00:00Z"
 * }
 */
function akb_bluex_webhook_handler( WP_REST_Request $request ): WP_REST_Response {
    $body = $request->get_json_params();

    if ( empty( $body ) ) {
        return new WP_REST_Response( [ 'error' => 'Empty payload' ], 400 );
    }

    // Extract tracking number (OS)
    $os = $body['os'] ?? $body['tracking_number'] ?? $body['OS'] ?? '';
    $status = strtoupper( $body['status'] ?? $body['estado'] ?? '' );
    $wc_order_id = absint( $body['order_id'] ?? $body['orderId'] ?? 0 );

    error_log( "[Akibara BlueX Webhook] Received: OS={$os} Status={$status} OrderID={$wc_order_id}" );

    if ( empty( $status ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing status field' ], 400 );
    }

    // Find WC order by order ID or by tracking number in meta
    $order = null;

    if ( $wc_order_id ) {
        $order = wc_get_order( $wc_order_id );
    }

    if ( ! $order && ! empty( $os ) ) {
        // Search by tracking number in order meta
        $orders = wc_get_orders( [
            'limit'      => 1,
            'meta_key'   => '_bluex_tracking_number',
            'meta_value' => $os,
        ] );
        if ( ! empty( $orders ) ) {
            $order = $orders[0];
        }

        // Also try alternative meta keys
        if ( ! $order ) {
            $orders = wc_get_orders( [
                'limit'      => 1,
                'meta_key'   => '_tracking_number',
                'meta_value' => $os,
            ] );
            if ( ! empty( $orders ) ) {
                $order = $orders[0];
            }
        }
    }

    if ( ! $order ) {
        error_log( "[Akibara BlueX Webhook] Order not found for OS={$os} OrderID={$wc_order_id}" );
        return new WP_REST_Response( [ 'status' => 'ok', 'action' => 'order_not_found' ], 200 );
    }

    $order_id = $order->get_id();

    // Map BlueX status to WC actions
    $delivered_statuses = [ 'DELIVERED', 'ENTREGADO', 'ENT', 'ENTREGA_OK', 'ENTREGADA' ];
    $returned_statuses  = [ 'RETURNED', 'DEVUELTO', 'DEV', 'DEVOLUCION' ];

    // Store latest tracking status
    $order->update_meta_data( '_bluex_last_status', $status );
    $order->update_meta_data( '_bluex_last_update', current_time( 'mysql' ) );

    if ( ! empty( $os ) ) {
        $order->update_meta_data( '_bluex_tracking_number', $os );
    }

    if ( in_array( $status, $delivered_statuses, true ) ) {
        // Auto-complete order
        if ( $order->get_status() !== 'completed' ) {
            $order->update_status( 'completed', 'Auto-completado por webhook Blue Express (OS: ' . $os . ')' );
            error_log( "[Akibara BlueX Webhook] Order #{$order_id} auto-completed (OS: {$os})" );
            $order->save();
            return new WP_REST_Response( [ 'status' => 'ok', 'action' => 'completed', 'order_id' => $order_id ], 200 );
        }
    } elseif ( in_array( $status, $returned_statuses, true ) ) {
        // Mark as failed/on-hold for review
        if ( ! in_array( $order->get_status(), [ 'completed', 'cancelled', 'refunded' ], true ) ) {
            $order->add_order_note( 'Blue Express: Envío devuelto (OS: ' . $os . '). Requiere revisión manual.' );
            $order->update_status( 'on-hold', 'Envío devuelto por Blue Express (OS: ' . $os . ')' );
            error_log( "[Akibara BlueX Webhook] Order #{$order_id} set to on-hold (returned, OS: {$os})" );
        }
    } else {
        // Just add a note with the status update
        $order->add_order_note( 'Blue Express tracking: ' . $status . ' (OS: ' . $os . ')' );
    }

    $order->save();

    return new WP_REST_Response( [
        'status'   => 'ok',
        'action'   => 'status_updated',
        'order_id' => $order_id,
        'tracking' => $status,
    ], 200 );
}

// ─── Admin: Generate webhook secret if none exists ───────────────
add_action( 'admin_init', function (): void {
    if ( empty( get_option( 'akb_bluex_webhook_secret' ) ) ) {
        update_option( 'akb_bluex_webhook_secret', wp_generate_password( 32, false ) );
    }
} );
