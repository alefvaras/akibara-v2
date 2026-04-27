<?php
/**
 * Akibara — Accordion Checkout Support
 *
 * Provides hooks that complement the template override at
 * woocommerce/checkout/form-checkout.php:
 *   - Express checkout wrapper (before form)
 *   - Sticky bar + drawer shell (after form, mobile)
 *   - AJAX fragment for shipping methods sync
 *   - Enqueue checkout-steps.js
 *
 * @package Akibara
 * @version 2.0.0
 */

defined( 'ABSPATH' ) || exit;

function akibara_checkout_js_critical_tokens(): array {
    return [
        'akibara-checkout-steps',
        'akibara-checkout-shipping',
        'akibara-ship-grid',
        'akibara-ship-free-progress',
        'akibara-ship-pudo-map',
        'akibara-ship-tracking',
        'checkout-steps.js',
        'checkout-shipping-enhancer.js',
        'ship-grid.js',
        'ship-free-progress.js',
        'ship-pudo-map.js',
        'ship-tracking.js',
        'woocommerce-google-analytics-integration',
        'woocommerce-google-analytics-integration-data',
        'woocommerce-google-analytics-integration-gtag',
        'wp-i18n',
        'wp-hooks',
        'wp-includes/js/dist/i18n',
        'wp-includes/js/dist/hooks',
        'plugins/woocommerce-google-analytics-integration/assets/js/build/main.js',
    ];
}

// ══════════════════════════════════════════════════════════════════
// EXPRESS CHECKOUT + BACK LINK  (before checkout form)
// ══════════════════════════════════════════════════════════════════

add_action( 'woocommerce_before_checkout_form', function () {
    ?>
    <div class="aki-checkout-v2">
        <!-- Back to cart -->
        <div class="aki-co__back">
            <a href="<?php echo esc_url( wc_get_cart_url() ); ?>">&#8592; Volver al carrito</a>
        </div>

        <!-- Express checkout -->
        <div class="aki-co__express" id="aki-co-express">
            <div id="aki-co-express-buttons">
                <!-- express buttons hook removed - lives in template -->
            </div>
            <div class="aki-co__divider">
                <span>o completar manualmente</span>
            </div>
        </div>
    <?php
}, 1 );

// ══════════════════════════════════════════════════════════════════
// STICKY BAR + DRAWER SHELL  (after checkout form)
// ══════════════════════════════════════════════════════════════════

add_action( 'woocommerce_after_checkout_form', function () {
    ?>
    </div><!-- .aki-checkout-v2 -->

    <!-- Order summary drawer (mobile) — triggered by #aki-co-sticky-btn en .aki-mobile-sticky.
         A11Y (WCAG 2.4.3 Focus Order + 4.1.2 Name Role Value): drawer parte cerrado con
         aria-hidden=true; checkout-steps.js openDrawer() promueve a role=dialog/aria-modal,
         hace focus trap, marca <main> como inert y restaura foco al cerrar. -->
    <div class="aki-co__drawer-overlay" id="aki-co-drawer-overlay" hidden></div>
    <div class="aki-co__drawer"
         id="aki-co-drawer"
         aria-hidden="true"
         aria-labelledby="aki-co-drawer-title"
         tabindex="-1">
        <div class="aki-co__drawer-header">
            <span id="aki-co-drawer-title">Tu Pedido</span>
            <button type="button" id="aki-co-drawer-close" aria-label="Cerrar resumen del pedido">&#10005;</button>
        </div>
        <div class="aki-co__drawer-body">
            <!-- JS will clone/move #order_review here on mobile -->
        </div>
    </div>
    <?php
}, 99 );

// ══════════════════════════════════════════════════════════════════
// PREVENT PAYMENT DUPLICATION
// (Also done in template, but this ensures it runs early enough)
// ══════════════════════════════════════════════════════════════════

add_action( 'woocommerce_before_checkout_form', function () {
    remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );
}, 0 );

// ══════════════════════════════════════════════════════════════════
// SHIPPING METHODS — Custom renderer (clean <ul>, valid HTML)
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akibara_render_shipping_methods' ) ) {
    function akibara_render_shipping_methods(): void {
        if ( ! WC()->cart || ! WC()->cart->needs_shipping() ) {
            return;
        }
        $packages = WC()->shipping()->get_packages();
        if ( empty( $packages ) ) {
            return;
        }
        foreach ( $packages as $i => $package ) {
            $available = $package['rates'] ?? [];
            $chosen    = wc_get_chosen_shipping_method_for_package( $i, $package );
            $index     = wc_esc_json( wp_json_encode( $i ) );

            if ( empty( $available ) ) {
                echo '<p class="aki-ship-empty">No hay métodos de envío disponibles para esta dirección.</p>';
                continue;
            }

            echo '<ul id="shipping_method" class="woocommerce-shipping-methods">';
            foreach ( $available as $method ) {
                /** @var WC_Shipping_Rate $method */
                $mid       = $method->id;
                $checked   = checked( $method->id, $chosen, false );
                $label     = wc_cart_totals_shipping_method_label( $method );

                // Exponer costos home/pudo al frontend para que la card
                // virtual PUDO muestre el precio correcto sin recalcular.
                $meta       = method_exists( $method, 'get_meta_data' ) ? $method->get_meta_data() : [];
                $data_attrs = '';
                if ( isset( $meta['aki_home_cost'] ) ) {
                    $data_attrs .= ' data-home-cost="' . esc_attr( (string) $meta['aki_home_cost'] ) . '"';
                }
                if ( isset( $meta['aki_pudo_cost'] ) ) {
                    $data_attrs .= ' data-pudo-cost="' . esc_attr( (string) $meta['aki_pudo_cost'] ) . '"';
                }

                printf(
                    '<li%6$s><input type="radio" name="shipping_method[%1$s]" data-index="%1$s" id="shipping_method_%1$s_%2$s" value="%3$s" class="shipping_method" %4$s /><label for="shipping_method_%1$s_%2$s">%5$s</label></li>',
                    esc_attr( $i ),
                    esc_attr( sanitize_title( $method->id ) ),
                    esc_attr( $method->id ),
                    $checked,
                    wp_kses_post( $label ),
                    $data_attrs
                );
            }
            echo '</ul>';
        }
    }
}

// ══════════════════════════════════════════════════════════════════
// SHIPPING — Threshold de envío gratis
// Centraliza "Envío gratis sobre $55.000" aplicándolo a los couriers
// pagados (actualmente bluex-*).
// ══════════════════════════════════════════════════════════════════

add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
    if ( empty( $rates ) || ! function_exists( 'akibara_get_free_shipping_threshold' ) ) {
        return $rates;
    }

    // Subtotal post-cupón: usa line_total (precio real tras descuentos) en lugar de
    // line_subtotal (pre-cupón) para que el umbral de envío gratis se evalúe
    // después de aplicar cupones virtuales akibara_cart_* y akibara_tramo_*.
    $subtotal = 0.0;
    foreach ( $package['contents'] as $item ) {
        $qty   = (int) ( $item['quantity'] ?? 0 );
        $line  = isset( $item['line_total'] ) ? (float) $item['line_total'] : 0;
        if ( $line <= 0 && ! empty( $item['data'] ) && is_object( $item['data'] ) ) {
            $line = (float) $item['data']->get_price() * $qty;
        }
        $subtotal += $line;
    }

    $threshold      = (float) akibara_get_free_shipping_threshold();
    $free_threshold = $threshold > 0 && $subtotal >= $threshold;

    if ( ! $free_threshold ) {
        return $rates;
    }

    foreach ( $rates as $rate_id => $rate ) {
        $method_id = method_exists( $rate, 'get_method_id' ) ? $rate->get_method_id() : '';

        if ( in_array( $method_id, [ 'bluex-ex', 'bluex-py', 'bluex-md' ], true ) ) {
            $rate->cost = 0;
            $taxes = $rate->get_taxes();
            if ( is_array( $taxes ) ) {
                foreach ( $taxes as $k => $v ) $taxes[ $k ] = 0;
                $rate->set_taxes( $taxes );
            }
            $label = $rate->get_label();
            if ( stripos( $label, 'gratis' ) === false ) {
                $rate->set_label( $label . ' — Envío gratis' );
            }
        }
    }

    return $rates;
}, 30, 2 );

// ══════════════════════════════════════════════════════════════════
// SHIPPING — PUDO pricing (Retiro en punto Blue Express)
//
// Estrategia: doble llamada a la API real de BlueX (familiaProducto
// 'PAQU' para domicilio, 'PUDO' para retiro), con caché transient
// agresivo (1h por destination + service + cart_hash) para evitar
// hits duplicados.
//
// Si la API no responde, PUDO se muestra al mismo costo que home
// (sin descuento estimado). NO se inventan precios — el cliente ve
// la verdad o no ve descuento. Mejor que cobrar mal.
// ══════════════════════════════════════════════════════════════════

/**
 * Normaliza string como lo hace el plugin BlueX (acentos → ASCII).
 */
if ( ! function_exists( 'akibara_bluex_normalize_string' ) ) {
    function akibara_bluex_normalize_string( string $s ): string {
        $from = ['Á','À','Â','Ä','á','à','ä','â','ª','É','È','Ê','Ë','é','è','ë','ê','Í','Ì','Ï','Î','í','ì','ï','î','Ó','Ò','Ö','Ô','ó','ò','ö','ô','Ú','Ù','Û','Ü','ú','ù','ü','û','Ñ','ñ','Ç','ç'];
        $to   = ['A','A','A','A','a','a','a','a','a','E','E','E','E','e','e','e','e','I','I','I','I','i','i','i','i','O','O','O','O','o','o','o','o','U','U','U','U','u','u','u','u','N','n','C','c'];
        return str_replace( $from, $to, $s );
    }
}

/**
 * Mapea method_id (bluex-ex / bluex-py / bluex-md) → service_type API.
 */
if ( ! function_exists( 'akibara_bluex_method_to_service' ) ) {
    function akibara_bluex_method_to_service( string $method_id ): string {
        $map = [
            'bluex-ex' => 'EX',
            'bluex-py' => 'PY',
            'bluex-md' => 'MD',
        ];
        return $map[ $method_id ] ?? 'EX';
    }
}

/**
 * Llama directamente a BlueX API para obtener pricing real con la
 * familia indicada (PAQU = domicilio, PUDO = retiro en punto).
 *
 * Cache: transient 1h por destination + service + familia + cart_hash.
 * Circuit breaker: si BlueX falló recientemente (15m), no reintenta.
 *
 * @return float|null Cost en CLP o null si falla.
 */
if ( ! function_exists( 'akibara_fetch_bluex_rate' ) ) {
    function akibara_fetch_bluex_rate( array $package, string $service_type, string $familia ): ?float {
        if ( ! class_exists( 'BlueX_API_Client' ) || ! class_exists( 'WC_Correios_Settings' ) ) {
            return null;
        }

        $dest  = $package['destination'] ?? [];
        $state = (string) ( $dest['state'] ?? '' );
        $city  = (string) ( $dest['city'] ?? '' );
        if ( $state === '' || $city === '' ) {
            return null;
        }

        $cart_hash = ( WC()->cart ) ? WC()->cart->get_cart_hash() : 'no-cart';
        $cache_key = 'akb_bxrate_' . md5( $state . '|' . $city . '|' . $service_type . '|' . $familia . '|' . $cart_hash );
        $breaker_key = 'akb_bxrate_breaker';

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return ( $cached === 'NULL' ) ? null : (float) $cached;
        }

        // Circuit breaker: si BlueX falló hace <15m, no reintentar.
        if ( get_transient( $breaker_key ) ) {
            return null;
        }

        try {
            $api_client = new BlueX_API_Client();
            $settings   = WC_Correios_Settings::get_instance();
            $userData   = $settings->get_settings();

            if ( empty( $userData['districtCode'] ) ) {
                return null;
            }

            // Geo: resolver region/district codes.
            $regionCode = ( strpos( $state, 'CL-' ) === 0 ) ? substr( $state, 3 ) : $state;
            $city_norm  = akibara_bluex_normalize_string( $city );

            $bxGeo = $api_client->get_geolocation( $city_norm, $regionCode, null, true );
            if ( is_wp_error( $bxGeo ) || empty( $bxGeo['regionCode'] ) || empty( $bxGeo['districtCode'] ) ) {
                set_transient( $breaker_key, 1, 15 * MINUTE_IN_SECONDS );
                return null;
            }

            // Bultos.
            $bultos = [];
            $price  = 0.0;
            foreach ( $package['contents'] as $item ) {
                $data = $item['data'] ?? null;
                if ( ! ( $data instanceof WC_Product ) ) continue;

                $price += (float) $data->get_price( 'edit' ) * (int) $item['quantity'];

                $ancho = (float) $data->get_width();   if ( $ancho <= 0 ) $ancho = 10.0;
                $largo = (float) $data->get_length();  if ( $largo <= 0 ) $largo = 10.0;
                $alto  = (float) $data->get_height();  if ( $alto  <= 0 ) $alto  = 10.0;
                $peso  = (float) $data->get_weight();  if ( $peso  <= 0 ) $peso  = 0.010;

                $bultos[] = [
                    'ancho'      => (int) $ancho,
                    'largo'      => (int) $largo,
                    'alto'       => (int) $alto,
                    'pesoFisico' => (float) $peso,
                    'cantidad'   => (int) $item['quantity'],
                ];
            }
            if ( empty( $bultos ) ) return null;

            if ( WC()->cart ) {
                $price -= (float) WC()->cart->get_discount_total();
            }

            $from = [ 'country' => 'CL', 'district' => $userData['districtCode'] ];
            $to   = [
                'country'  => 'CL',
                'state'    => $bxGeo['regionCode'],
                'district' => $bxGeo['districtCode'],
            ];

            $resp = $api_client->get_pricing( $from, $to, $service_type, $bultos, $price, $familia, $userData );

            if ( is_wp_error( $resp ) || empty( $resp['data'] ) ) {
                set_transient( $breaker_key, 1, 15 * MINUTE_IN_SECONDS );
                return null;
            }

            $code = (string) ( $resp['code'] ?? '' );
            if ( ! in_array( $code, [ '00', '01' ], true ) ) {
                return null;
            }

            $cost = (float) ( $resp['data']['total'] ?? 0 );
            if ( $cost <= 0 ) return null;

            set_transient( $cache_key, $cost, HOUR_IN_SECONDS );
            return $cost;

        } catch ( Throwable $e ) {
            set_transient( $breaker_key, 1, 15 * MINUTE_IN_SECONDS );
            return null;
        }
    }
}

add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
    if ( empty( $rates ) ) {
        return $rates;
    }

    // Detectar estado de PUDO desde POST data del checkout AJAX.
    $is_pudo         = false;
    $has_agency_post = false;
    if ( isset( $_POST['post_data'] ) ) {
        $parsed = [];
        parse_str( wp_unslash( $_POST['post_data'] ), $parsed );
        if ( isset( $parsed['akibara_delivery_mode'] ) && $parsed['akibara_delivery_mode'] === 'pudo' ) {
            $is_pudo = true;
        }
        if ( isset( $parsed['isPudoSelected'] ) && $parsed['isPudoSelected'] === 'pudoShipping' ) {
            $is_pudo = true;
        }
        if ( ! empty( $parsed['agencyId'] ) ) {
            $has_agency_post = true;
        }
    }

    foreach ( $rates as $rate_id => $rate ) {
        $method_id = method_exists( $rate, 'get_method_id' ) ? $rate->get_method_id() : '';
        if ( ! in_array( $method_id, [ 'bluex-ex', 'bluex-py', 'bluex-md' ], true ) ) {
            continue;
        }

        $current_cost = (float) $rate->get_cost();
        $service      = akibara_bluex_method_to_service( $method_id );

        // Llamar API real para AMBAS familias (cached).
        $api_home = akibara_fetch_bluex_rate( $package, $service, 'PAQU' );
        $api_pudo = akibara_fetch_bluex_rate( $package, $service, 'PUDO' );

        // El plugin BlueX puede haber devuelto PAQU o PUDO según
        // si había agencyId en POST. Sabemos cuál fue:
        // - $has_agency_post=true → plugin devolvió PUDO
        // - $has_agency_post=false → plugin devolvió PAQU
        $plugin_returned_pudo = $has_agency_post;

        // Determinar costos: preferir API directa (canónica), si falla
        // usar el cost que devolvió el plugin (también API real, pero solo
        // tenemos una de las dos familias según agencyId en POST).
        // Si no hay info, igualar PUDO a home (cero descuento, no inventar).
        $home_cost = ( $api_home !== null && $api_home > 0 )
            ? $api_home
            : ( $plugin_returned_pudo ? null : $current_cost );

        $pudo_cost = ( $api_pudo !== null && $api_pudo > 0 )
            ? $api_pudo
            : ( $plugin_returned_pudo ? $current_cost : ( $home_cost ?? $current_cost ) );

        if ( $home_cost === null ) {
            // Plugin devolvió PUDO + API home falló. Mostrar PUDO en ambos.
            $home_cost = $pudo_cost;
        }

        // Guardar metadata para frontend.
        $rate->add_meta_data( 'aki_home_cost', $home_cost );
        $rate->add_meta_data( 'aki_pudo_cost', $pudo_cost );

        // Aplicar el cost correcto al rate según mode actual.
        // - Si plugin ya devolvió PUDO (had agency), no tocar.
        // - Si plugin devolvió PAQU pero usuario está en PUDO (sin agencia),
        //   override al pudo_cost para que el total reflieje el descuento
        //   en la preview.
        if ( $is_pudo && ! $plugin_returned_pudo && $pudo_cost < $current_cost ) {
            $rate->set_cost( $pudo_cost );
            $taxes = $rate->get_taxes();
            if ( is_array( $taxes ) && $current_cost > 0 ) {
                $ratio = $pudo_cost / $current_cost;
                foreach ( $taxes as $k => $v ) {
                    $taxes[ $k ] = (float) $v * $ratio;
                }
                $rate->set_taxes( $taxes );
            }
        }
    }

    return $rates;
}, 35, 2 );

// ══════════════════════════════════════════════════════════════════
// STOCK — Early check al entrar a /checkout/
// Si algún item del carrito no tiene stock suficiente, redirigimos al
// carrito con un notice accionable antes de que el cliente complete los
// 3 pasos y reciba el error al momento de pagar (pésima UX).
// Hook `woocommerce_check_cart_items` corre en carrito y checkout; al
// detectar stock issue, en checkout redirigimos al /carrito/ donde los
// notices son visibles y el usuario puede quitar el item en un clic.
// ══════════════════════════════════════════════════════════════════
add_action( 'template_redirect', 'akibara_checkout_early_stock_check', 20 );
function akibara_checkout_early_stock_check(): void {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( is_wc_endpoint_url( 'order-received' ) ) return;
    if ( ! WC()->cart || WC()->cart->is_empty() ) return;

    $has_stock_issue = false;
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product = $cart_item['data'] ?? null;
        if ( ! $product instanceof WC_Product ) continue;
        if ( ! $product->managing_stock() ) continue;

        $qty_in_cart = (int) $cart_item['quantity'];
        $stock       = (int) $product->get_stock_quantity();
        if ( ! $product->backorders_allowed() && $stock < $qty_in_cart ) {
            $has_stock_issue = true;
            wc_add_notice(
                sprintf(
                    /* translators: %s: product name. UX copy fix 2026-04-25 (ux-copy BG): error accionable (qué + cómo arreglar). */
                    __( '"%s" ya no tiene stock suficiente. Quítalo o reduce la cantidad para continuar con tu pedido.', 'akibara' ),
                    $product->get_name()
                ),
                'error'
            );
        }
    }

    if ( $has_stock_issue ) {
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════
// SHIPPING — Smart default selection
// Prioridad: método gratis > más barato.
// Solo actúa cuando el usuario aún no eligió manualmente.
// ══════════════════════════════════════════════════════════════════

add_filter( 'woocommerce_shipping_chosen_method', function ( $chosen, $rates, $current_chosen ) {
    // 1. Si el usuario ya eligió manualmente, respetar su elección.
    if ( ! empty( $current_chosen ) && isset( $rates[ $current_chosen ] ) ) {
        return $current_chosen;
    }
    if ( empty( $rates ) ) {
        return $chosen;
    }

    // 2. Pass 1: método gratis (cost 0), excluyendo local_pickup.
    foreach ( $rates as $id => $rate ) {
        if ( (float) $rate->get_cost() === 0.0 && strpos( $id, 'local_pickup' ) !== 0 ) return $id;
    }

    // 3. Pass 2: más barato (excluyendo local_pickup que obliga coordinar por WhatsApp).
    $candidates = [];
    foreach ( $rates as $id => $rate ) {
        if ( strpos( $id, 'local_pickup' ) === 0 ) continue;
        $candidates[ $id ] = (float) $rate->get_cost();
    }
    if ( ! empty( $candidates ) ) {
        asort( $candidates );
        return array_key_first( $candidates );
    }

    // 4. Fallback: si solo hay local_pickup disponible (ej. antes de completar
    //    dirección, solo la zona default "En todas partes" está activa),
    //    devolvemos VACÍO en lugar del chosen heredado. Esto evita mostrar
    //    "Retiro gratis en San Miguel" como pre-seleccionado en el sidebar
    //    "TU PEDIDO" antes de que el usuario haya decidido algo. El cliente
    //    verá "Completa tu dirección" hasta que los métodos reales aparezcan.
    if ( is_string( $chosen ) && strpos( $chosen, 'local_pickup' ) === 0 ) {
        return '';
    }

    return $chosen;
}, 20, 3 );

// ══════════════════════════════════════════════════════════════════
// AJAX FRAGMENT — Shipping methods sync
// ══════════════════════════════════════════════════════════════════

add_filter( 'woocommerce_update_order_review_fragments', function ( $fragments ) {
    ob_start();
    akibara_render_shipping_methods();
    $fragments['.aki-shipping-inner'] = "<div class=\"aki-shipping-inner\">" . ob_get_clean() . "</div>";
    return $fragments;
} );

// ══════════════════════════════════════════════════════════════════
// CHECKOUT: Friendly label for first purchase coupon
// ══════════════════════════════════════════════════════════════════

add_filter( 'woocommerce_cart_totals_coupon_label', function ( string $label, $coupon ): string {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
        return $label;
    }

    if ( ! $coupon instanceof WC_Coupon ) {
        return $label;
    }

    $target_code = get_option( 'akibara_popup_coupon', 'PRIMERACOMPRA10' );
    if ( function_exists( 'wc_format_coupon_code' ) ) {
        $target_code = wc_format_coupon_code( $target_code );
        $coupon_code = wc_format_coupon_code( $coupon->get_code() );
    } else {
        $target_code = strtolower( $target_code );
        $coupon_code = strtolower( $coupon->get_code() );
    }

    if ( $coupon_code !== $target_code ) {
        return $label;
    }

    return '<span class="akb-first-discount-label">Descuento primera compra</span>';
}, 10, 2 );

// ══════════════════════════════════════════════════════════════════
// ENQUEUE JS
// ══════════════════════════════════════════════════════════════════

add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_checkout() ) return;
    wp_enqueue_script( 'wp-hooks' );
    wp_enqueue_script( 'wp-i18n' );
    wp_enqueue_script(
        'akibara-checkout-steps',
        AKIBARA_THEME_URI . '/assets/js/checkout-steps.js',
        ['jquery'],
        AKIBARA_THEME_VERSION . '.7', // Sprint 6 A5 — handler `button.showcoupon` (a → button a11y).
        true
    );
    // Ship sub-modules (loaded before orchestrator, coordinated via window.AkibaraShipping)
    wp_enqueue_script(
        'akibara-ship-grid',
        AKIBARA_THEME_URI . '/assets/js/ship-grid.js',
        ['jquery'],
        AKIBARA_THEME_VERSION . '.7',
        true
    );
    wp_enqueue_script(
        'akibara-ship-free-progress',
        AKIBARA_THEME_URI . '/assets/js/ship-free-progress.js',
        ['jquery', 'akibara-ship-grid'],
        AKIBARA_THEME_VERSION . '.2',
        true
    );
    wp_enqueue_script(
        'akibara-ship-pudo-map',
        AKIBARA_THEME_URI . '/assets/js/ship-pudo-map.js',
        ['jquery', 'akibara-ship-grid'],
        AKIBARA_THEME_VERSION . '.1',
        true
    );
    wp_enqueue_script(
        'akibara-ship-tracking',
        AKIBARA_THEME_URI . '/assets/js/ship-tracking.js',
        ['jquery', 'akibara-ship-grid'],
        AKIBARA_THEME_VERSION . '.1',
        true
    );
    // Orchestrator (depends on all 4 sub-modules). akibaraCheckoutShipping localize target stays here.
    wp_enqueue_script(
        'akibara-checkout-shipping',
        AKIBARA_THEME_URI . '/assets/js/checkout-shipping-enhancer.js',
        ['jquery', 'akibara-ship-grid', 'akibara-ship-free-progress', 'akibara-ship-pudo-map', 'akibara-ship-tracking'],
        AKIBARA_THEME_VERSION . '.30',
        true
    );
    wp_localize_script(
        'akibara-checkout-shipping',
        'akibaraCheckoutShipping',
        [
            // Feature flag: unified grid (MercadoLibre style).
            // Revertir con: wp option update akibara_ship_unified_grid 0
            'unifiedGrid'           => (bool) apply_filters( 'akibara_ship_unified_grid', (bool) get_option( 'akibara_ship_unified_grid', 1 ) ),
            'freeShippingThreshold' => function_exists( 'akibara_get_free_shipping_threshold' ) ? (int) akibara_get_free_shipping_threshold() : 55000,
            // Metadata de couriers servida desde los adapters (AKB_Courier_UI_Metadata).
            // Blue Express único courier (nacional + PUDO + Metro SM). Ver AGENTS.md Decisiones diferidas #2.
            'couriers'              => function_exists( 'akb_get_couriers_ui_metadata' ) ? akb_get_couriers_ui_metadata() : [],
            // Theme URI para construir URLs de assets desde JS (fallback UI, imágenes).
            'themeUri'              => AKIBARA_THEME_URI,
            // URL canónica de WhatsApp (o fallback /contacto/) servida desde el plugin akibara-whatsapp.
            // Evita hardcodear el número en JS — fuente única en akibara_whatsapp_get_business_number().
            'waUrl'                 => function_exists( 'akibara_wa_url' )
                ? akibara_wa_url( 'Hola, perdí mi carrito. ¿Me ayudan a recuperarlo?' )
                : home_url( '/contacto/' ),
        ]
    );
} );

// NOTE: Antes aquí vivía un hook `wp_head` que inyectaba CSS inline
// para ocultar `.aki-pudo__title` + `.aki-pudo__options` y neutralizar
// el chrome de `.aki-pudo` cuando el unified grid estaba activo. Tras
// el cleanup del 2026-04-19 esos selectores ya no existen en el DOM
// (ver `inc/checkout-pudo.php`) y `.aki-pudo` ya no tiene chrome
// propio en `assets/css/checkout.css` — el hack quedó obsoleto.
// El kill-switch del unified grid ahora actúa solo en `checkout-pudo.php`
// y `ship-grid.js` (flag `akibara_ship_unified_grid`).

add_filter( 'script_loader_tag', function ( $tag, $handle ) {
    if ( ! in_array( $handle, [ 'akibara-checkout-steps', 'akibara-checkout-shipping', 'akibara-ship-grid', 'akibara-ship-free-progress', 'akibara-ship-pudo-map', 'akibara-ship-tracking', 'wp-hooks', 'wp-i18n', 'woocommerce-google-analytics-integration' ], true ) ) {
        return $tag;
    }

    if ( false === strpos( $tag, 'data-no-optimize=' ) ) {
        $tag = str_replace( ' src=', ' data-no-optimize="1" data-no-defer="1" src=', $tag );
    }

    if ( false !== strpos( $tag, ' defer' ) ) {
        $tag = str_replace( ' defer', '', $tag );
    }

    return $tag;
}, 20, 2 );

add_filter( 'litespeed_optm_js_defer_exc', function ( $exc ) {
    if ( ! is_array( $exc ) ) {
        $exc = preg_split( '/[\r\n]+/', (string) $exc ) ?: [];
    }
    $exc = array_merge( $exc, akibara_checkout_js_critical_tokens() );
    return array_values( array_unique( array_filter( array_map( 'trim', $exc ) ) ) );
} );

add_filter( 'litespeed_optm_js_delay_exc', function ( $exc ) {
    if ( ! is_array( $exc ) ) {
        $exc = preg_split( '/[\r\n]+/', (string) $exc ) ?: [];
    }
    $exc = array_merge( $exc, akibara_checkout_js_critical_tokens() );
    return array_values( array_unique( array_filter( array_map( 'trim', $exc ) ) ) );
} );

add_filter( 'litespeed_optimize_js_excludes', function ( $exc ) {
    if ( ! is_array( $exc ) ) {
        $exc = preg_split( '/[\r\n]+/', (string) $exc ) ?: [];
    }
    $exc = array_merge( $exc, akibara_checkout_js_critical_tokens() );
    return array_values( array_unique( array_filter( array_map( 'trim', $exc ) ) ) );
} );

// Noscript fallback for checkout accordion
add_action( 'woocommerce_before_checkout_form', function() {
    ?>
    <noscript>
    <style>
    .aki-checkout-section { display: block !important; max-height: none !important; opacity: 1 !important; }
    .aki-checkout-accordion__toggle { pointer-events: none; }
    .aki-checkout-accordion__icon { display: none; }
    </style>
    <div class="woocommerce-notice woocommerce-notice--info" style="background:#1A1A1A;border:1px solid #D90010;padding:12px 16px;margin:0 0 16px;border-radius:4px;color:#ccc;font-size:14px;">
        Para una mejor experiencia de compra, habilita JavaScript en tu navegador.
    </div>
    </noscript>
    <?php
}, 5 );
