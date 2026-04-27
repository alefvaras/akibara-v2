<?php
/**
 * Akibara — REST API Cart Endpoints
 *
 * Replaces admin-ajax.php cart handlers to bypass CDN POST restrictions.
 * All endpoints under /wp-json/akibara/v1/cart/
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function (): void {

    // POST /cart/add
    register_rest_route( 'akibara/v1', '/cart/add', [
        'methods'             => 'POST',
        'callback'            => 'akibara_rest_cart_add',
        'permission_callback' => '__return_true',
    ] );

    // POST /cart/remove
    register_rest_route( 'akibara/v1', '/cart/remove', [
        'methods'             => 'POST',
        'callback'            => 'akibara_rest_cart_remove',
        'permission_callback' => '__return_true',
    ] );

    // POST /cart/update-qty
    register_rest_route( 'akibara/v1', '/cart/update-qty', [
        'methods'             => 'POST',
        'callback'            => 'akibara_rest_cart_update_qty',
        'permission_callback' => '__return_true',
    ] );

    // GET /cart
    register_rest_route( 'akibara/v1', '/cart', [
        'methods'             => 'GET',
        'callback'            => 'akibara_rest_cart_get',
        'permission_callback' => '__return_true',
    ] );

    // GET /cart/get — backward-compatible alias (JS uses this URL).
    register_rest_route( 'akibara/v1', '/cart/get', [
        'methods'             => 'GET',
        'callback'            => 'akibara_rest_cart_get',
        'permission_callback' => '__return_true',
    ] );

    // POST /session/keep-alive — silent heartbeat to prevent WC session/nonce
    // expiry during user activity on the checkout page.
    register_rest_route( 'akibara/v1', '/session/keep-alive', [
        'methods'             => 'POST',
        'callback'            => 'akibara_rest_session_keep_alive',
        'permission_callback' => '__return_true',
    ] );
} );

/**
 * Verify nonce from header or body.
 */
function akibara_rest_verify_cart_nonce( WP_REST_Request $request ): bool {
    $nonce = $request->get_header( 'X-WP-Nonce' )
          ?? $request->get_param( 'nonce' )
          ?? '';
    return wp_verify_nonce( $nonce, 'akibara-cart-nonce' ) ||
           wp_verify_nonce( $nonce, 'wp_rest' );
}

/**
 * POST /cart/add — Add product to cart
 */
function akibara_rest_cart_add( WP_REST_Request $request ): WP_REST_Response {
    // Note: WooCommerce intentionally does not require nonce for 'add to cart'
    // to support full page caching where nonces might expire.
    // See WC_AJAX::add_to_cart().

    // B-S1-SEC-07 (2026-04-27): rate limit 10/min/IP en /cart/add (anti-abuse).
    if ( function_exists( 'akb_rate_limit' ) ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        if ( ! akb_rate_limit( 'cart_add:' . md5( $ip ), 10, MINUTE_IN_SECONDS ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => 'Demasiadas solicitudes. Intenta en un minuto.' ],
                429
            );
        }
    }

    // Ensure WC session/cart is loaded
    if ( ! WC()->cart ) {
        wc_load_cart();
    }

    $product_id = absint( $request->get_param( 'product_id' ) );
    $quantity   = absint( $request->get_param( 'quantity' ) ?: 1 );

    if ( ! $product_id ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'ID de producto inválido' ], 400 );
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Producto no encontrado' ], 404 );
    }

    if ( ! $product->is_in_stock() ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Producto sin stock' ], 400 );
    }

    if ( ! $product->is_purchasable() ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Producto no disponible para compra' ], 400 );
    }

    // Detect if already in cart
    $cart_item_key = WC()->cart->generate_cart_id( $product_id );
    $existing_key  = WC()->cart->find_product_in_cart( $cart_item_key );

    // Amazon/Shopify pattern: if product is already at max stock, don't error — confirm it's in cart
    if ( $existing_key ) {
        $existing_item   = WC()->cart->get_cart_item( $existing_key );
        $current_qty     = $existing_item ? $existing_item['quantity'] : 0;
        $manages_stock   = $product->managing_stock();
        $stock_qty       = $product->get_stock_quantity();
        $sold_individual = $product->is_sold_individually();

        $at_max = $sold_individual
            || ( $manages_stock && $stock_qty !== null && $current_qty >= $stock_qty );

        if ( $at_max ) {
            $available_stock = $manages_stock && $stock_qty !== null
                ? (int) $stock_qty
                : max( 1, (int) $current_qty );
            wc_clear_notices();
            return new WP_REST_Response( [
                'success' => true,
                'data'    => [
                    'already_in_cart' => true,
                    'message'         => 'Ya tienes ' . $product->get_name() . ' en tu carrito. Solo quedan ' . number_format_i18n( $available_stock ) . ' unidades disponibles.',
                    'count'           => WC()->cart->get_cart_contents_count(),
                    'total'           => WC()->cart->get_cart_total(),
                    'key'             => $existing_key,
                ],
            ], 200 );
        }
    }

    $added = WC()->cart->add_to_cart( $product_id, $quantity );

    if ( $added ) {
        $new_qty = 0;
        $cart_item = WC()->cart->get_cart_item( $added );
        if ( $cart_item ) {
            $new_qty = $cart_item['quantity'];
        }

        $message = $existing_key
            ? $product->get_name() . ' — cantidad actualizada (' . $new_qty . ')'
            : $product->get_name() . ' agregado al carrito';

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'message' => $message,
                'count'   => WC()->cart->get_cart_contents_count(),
                'total'   => WC()->cart->get_cart_total(),
                'key'     => $added,
            ],
        ], 200 );
    }

    // Collect WC notices for a meaningful error message
    $notices = wc_get_notices( 'error' );
    wc_clear_notices();
    $error_msg = ! empty( $notices )
        ? html_entity_decode( wp_strip_all_tags( $notices[0]['notice'] ?? $notices[0] ), ENT_QUOTES, 'UTF-8' )
        : 'No se pudo agregar al carrito';

    return new WP_REST_Response( [ 'success' => false, 'message' => $error_msg ], 400 );
}

/**
 * POST /cart/remove — Remove item from cart
 */
function akibara_rest_cart_remove( WP_REST_Request $request ): WP_REST_Response {
    if ( ! akibara_rest_verify_cart_nonce( $request ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Nonce inválido' ], 403 );
    }

    if ( ! WC()->cart ) {
        wc_load_cart();
    }

    $cart_item_key = sanitize_text_field( $request->get_param( 'cart_item_key' ) );

    if ( empty( $cart_item_key ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Clave de item inválida' ], 400 );
    }

    $removed = WC()->cart->remove_cart_item( $cart_item_key );

    if ( $removed ) {
        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'count' => WC()->cart->get_cart_contents_count(),
                'total' => WC()->cart->get_cart_total(),
                'items' => akibara_rest_get_cart_items(),
            ],
        ], 200 );
    }

    return new WP_REST_Response( [ 'success' => false, 'message' => 'No se pudo eliminar' ], 400 );
}

/**
 * POST /cart/update-qty — Update item quantity
 */
function akibara_rest_cart_update_qty( WP_REST_Request $request ): WP_REST_Response {
    if ( ! akibara_rest_verify_cart_nonce( $request ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Nonce inválido' ], 403 );
    }

    if ( ! WC()->cart ) {
        wc_load_cart();
    }

    $cart_item_key = sanitize_text_field( $request->get_param( 'cart_item_key' ) );
    $quantity      = absint( $request->get_param( 'quantity' ) );

    if ( empty( $cart_item_key ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Clave inválida' ], 400 );
    }

    if ( $quantity < 1 ) {
        WC()->cart->remove_cart_item( $cart_item_key );
    } else {
        WC()->cart->set_quantity( $cart_item_key, $quantity );
    }

    return new WP_REST_Response( [
        'success' => true,
        'data'    => [
            'count' => WC()->cart->get_cart_contents_count(),
            'total' => WC()->cart->get_cart_total(),
            'items' => akibara_rest_get_cart_items(),
        ],
    ], 200 );
}

/**
 * GET /cart — Get current cart contents
 */
function akibara_rest_cart_get( WP_REST_Request $request ): WP_REST_Response {
    if ( ! WC()->cart ) {
        wc_load_cart();
    }

    return new WP_REST_Response( [
        'success' => true,
        'data'    => [
            'count' => WC()->cart->get_cart_contents_count(),
            'total' => WC()->cart->get_cart_total(),
            'items' => akibara_rest_get_cart_items(),
        ],
    ], 200 );
}

/**
 * Helper: format cart items for JSON response
 */
function akibara_rest_get_cart_items(): array {
    $items = [];
    foreach ( WC()->cart->get_cart() as $key => $item ) {
        $product = $item['data'];
        $items[] = [
            'key'        => $key,
            'product_id' => $item['product_id'],
            'name'       => $product->get_name(),
            'quantity'   => $item['quantity'],
            'price'      => $product->get_price(),
            'total'      => WC()->cart->get_product_subtotal( $product, $item['quantity'] ),
            'image'      => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
        ];
    }
    return $items;
}

/**
 * POST /session/keep-alive — Silent session/nonce refresh.
 *
 * Called by the checkout heartbeat when the user is active. Forces WC to
 * persist the current session (touching WC_Session) so the cart, address
 * and nonces remain valid during long checkout sessions.
 *
 * Returns a lightweight payload with a fresh checkout nonce so the client
 * can keep submitting requests without a hard reload.
 */
function akibara_rest_session_keep_alive( WP_REST_Request $request ): WP_REST_Response {
    // CA-2 (Sprint 2): Rate limit por IP para evitar DB bloat por bots que
    // crean/mantienen sesiones indefinidamente. 30 req/min es holgado para
    // el heartbeat legítimo (~1 cada 30-60s) pero corta abusers.
    $ip = akibara_rest_get_client_ip();
    if ( $ip ) {
        $key     = 'akb_ka_' . md5( $ip );
        $count   = (int) get_transient( $key );
        $limit   = 30; // máximo de requests por ventana
        $window  = 60; // ventana en segundos
        if ( $count >= $limit ) {
            return new WP_REST_Response( [
                'success' => false,
                'code'    => 'rate_limited',
                'message' => 'Too many keep-alive requests',
            ], 429 );
        }
        set_transient( $key, $count + 1, $window );
    }

    if ( function_exists( 'WC' ) && WC()->session ) {
        // Touch the session so its expiry gets extended.
        WC()->session->set( 'akb_last_activity', time() );
    }

    return new WP_REST_Response( [
        'success' => true,
        'data'    => [
            'ts'    => time(),
            'nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
        ],
    ], 200 );
}

/**
 * Resuelve la IP del cliente considerando proxies comunes (Cloudflare,
 * X-Forwarded-For). Devuelve '' si no es determinable.
 */
function akibara_rest_get_client_ip(): string {
    $candidates = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ( $candidates as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            // X-Forwarded-For puede ser lista; tomar la primera
            $ip = trim( explode( ',', (string) $_SERVER[ $key ] )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
    }
    return '';
}
