<?php
/**
 * WooCommerce Integration
 *
 * @package Akibara
 */

defined('ABSPATH') || exit;


/**
 * Products per page
 */
add_filter('loop_shop_per_page', function () {
    return 24;
});

/**
 * Checkout: añadir thumbnail del producto en la tabla "Tu Pedido".
 * Solo en checkout (no en cart) para no duplicar imagen con la columna de cart nativa.
 */
add_filter('woocommerce_cart_item_name', function ($name, $cart_item, $cart_item_key) {
    if ( ! is_checkout() || is_cart() ) return $name;
    if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) return $name;
    $product = $cart_item['data'];
    // 'woocommerce_thumbnail' (~300px) garantiza nitidez Retina hasta 64×64 CSS.
    $thumb   = $product->get_image(
        'woocommerce_thumbnail',
        [ 'class' => 'aki-co-review__thumb', 'loading' => 'lazy', 'alt' => esc_attr( $product->get_name() ) ]
    );
    if ( empty( $thumb ) ) return $name;
    return '<span class="aki-co-review__thumb-wrap">' . $thumb . '</span>' .
           '<span class="aki-co-review__name">' . $name . '</span>';
}, 10, 3);

/**
 * Product columns
 */
add_filter('loop_shop_columns', function () {
    return 4;
});

/**
 * Remove default WC wrappers
 */
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

add_action('woocommerce_before_main_content', function () {
    echo '<main class="site-content">';
}, 10);

add_action('woocommerce_after_main_content', function () {
    echo '</main>';
}, 10);

/**
 * Remove default sidebar
 */
remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);

/**
 * Product card customization — remove defaults and use our template
 */
remove_action('woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10);
remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5);
remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10);
remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10);
remove_action('woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10);
remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5);
remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);
remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);

// Note: woocommerce_after_single_product_summary hook is disabled in single-product.php
// (template renders tabs + related products manually)

/**
 * Single Product summary — remove defaults.
 *
 * template-parts/single-product/info.php renderiza manualmente title, rating,
 * price, excerpt, add-to-cart, meta y sharing. Luego dispara el hook
 * `woocommerce_single_product_summary` para que plugins third-party puedan
 * engancharse. Sin estos remove_action se duplicaría cada bloque (incluyendo
 * un segundo <h1 class="product_title entry-title"> con font-display a 80px,
 * rompiendo SEO y jerarquía visual).
 */
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50 );

/**
 * Custom product card output
 */
add_action('woocommerce_before_shop_loop_item', 'akibara_product_card_open', 10);
add_action('woocommerce_before_shop_loop_item', 'akibara_product_card_image_open', 15);
add_action('woocommerce_before_shop_loop_item', 'akibara_product_card_badges', 20);
add_action('woocommerce_before_shop_loop_item', 'akibara_product_card_thumbnail', 25);
add_action('woocommerce_before_shop_loop_item', 'akibara_product_card_wishlist', 27);
add_action('woocommerce_before_shop_loop_item', 'akibara_product_card_actions', 30);
add_action('woocommerce_before_shop_loop_item', 'akibara_product_card_image_close', 35);
add_action('woocommerce_before_shop_loop_item_title', 'akibara_product_card_body_open', 10);
add_action('woocommerce_shop_loop_item_title', 'akibara_product_card_category', 5);
add_action('woocommerce_shop_loop_item_title', 'akibara_product_card_title', 10);
add_action('woocommerce_after_shop_loop_item_title', 'akibara_product_card_price', 10);
add_action('woocommerce_after_shop_loop_item', 'akibara_product_card_body_close', 10);
add_action('woocommerce_after_shop_loop_item', 'akibara_product_card_close', 15);

// Batch-warm post meta cache before iterating cards (eliminates N+1 for _akb_* custom fields)
add_action( 'woocommerce_product_loop_start', function () {
    global $wp_query;
    if ( empty( $wp_query->posts ) ) return;
    $ids = array_filter( array_unique( array_map(
        fn( $p ) => is_object( $p ) ? $p->ID : (int) $p,
        $wp_query->posts
    ) ) );
    if ( $ids ) {
        update_meta_cache( 'post', $ids );
        update_object_term_cache( $ids, 'product' );
    }
} );

function akibara_product_card_open() {
    echo '<div class="product-card">';
}

function akibara_product_card_close() {
    echo '</div>';
}

function akibara_product_card_image_open() {
    global $product;
    echo '<a href="' . esc_url(get_the_permalink()) . '" class="product-card__image">';
}

function akibara_product_card_image_close() {
    echo '</a>';
}

function akibara_product_card_badges() {
    global $product;
    akibara_render_badges($product);
}

function akibara_product_card_thumbnail() {
    global $product;
    $img_id = $product->get_image_id();
    if ($img_id) {
        echo wp_get_attachment_image($img_id, 'product-card', false, [
            'loading' => 'lazy',
            'alt'     => get_the_title(),
        ]);
    } else {
        echo '<img src="' . esc_url(wc_placeholder_img_src('product-card')) . '" alt="' . esc_attr(get_the_title()) . '" loading="lazy">';
    }
}

/**
 * Wishlist button in product card (WC loop)
 */
function akibara_product_card_wishlist() {
    global $product;
    echo '<button class="product-card__wishlist js-wishlist" data-product-id="' . esc_attr($product->get_id()) . '" title="Guardar" aria-label="Guardar en favoritos">';
    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>';
    echo '</button>';
}

function akibara_product_card_actions() {
    global $product;
    if (!$product->is_in_stock()) return;

    // Helper centralizado: "Agregar al carrito" / "Reservar ahora" según tipo.
    $quick_title = function_exists( 'akibara_get_atc_text' )
        ? akibara_get_atc_text( $product )
        : 'Agregar al carrito';

    echo '<div class="product-card__actions">';
    echo '<button class="product-card__quick-add" data-product-id="' . esc_attr($product->get_id()) . '" title="' . esc_attr( $quick_title ) . '">';
    echo akibara_icon('plus', 16);
    echo '</button>';
    echo '</div>';
}

function akibara_product_card_body_open() {
    echo '<div class="product-card__body">';
}

function akibara_product_card_body_close() {
    echo '</div>';
}

function akibara_product_card_category() {
    global $product;
    $cats = get_the_terms($product->get_id(), 'product_cat');
    if ($cats && !is_wp_error($cats)) {
        $primary = $cats[0];
        echo '<span class="product-card__category">' . esc_html($primary->name) . '</span>';
    }
}

function akibara_product_card_title() {
    echo '<h3 class="product-card__title"><a href="' . esc_url(get_the_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3>';
}

function akibara_product_card_price() {
    global $product;
    echo '<div class="product-card__price">' . wp_kses_post( $product->get_price_html() ) . '</div>';
}

/**
 * AJAX add to cart
 */
add_action('wp_ajax_akibara_add_to_cart', 'akibara_ajax_add_to_cart');
add_action('wp_ajax_nopriv_akibara_add_to_cart', 'akibara_ajax_add_to_cart');

// ═════════════════════════════════════════════════════════════
// WC AJAX — Mirror endpoints for ?wc-ajax= (bypasses CDN block on admin-ajax.php)
// ═════════════════════════════════════════════════════════════
add_filter( 'woocommerce_ajax_get_endpoint', function( $url, $request ) {
    return $url;
}, 10, 2 );

add_action( 'wc_ajax_akibara_add_to_cart',      'akibara_ajax_add_to_cart' );
add_action( 'wc_ajax_akibara_get_cart',          'akibara_ajax_get_cart' );
add_action( 'wc_ajax_akibara_remove_from_cart',  'akibara_ajax_remove_from_cart' );
add_action( 'wc_ajax_akibara_update_cart_qty',   'akibara_ajax_update_cart_qty' );


function akibara_ajax_add_to_cart() {
    // Note: WooCommerce intentionally does not require nonce for 'add to cart'
    // to support full page caching where nonces might expire.
    // See WC_AJAX::add_to_cart().

    $product_id = absint($_POST['product_id'] ?? 0);
    $quantity = absint($_POST['quantity'] ?? 1);

    if (!$product_id) {
        wp_send_json_error(['message' => 'ID de producto inválido']); return;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(['message' => 'Producto no encontrado']); return;
    }

    // Detect if already in cart
    $cart_item_key = WC()->cart->generate_cart_id($product_id);
    $existing_key  = WC()->cart->find_product_in_cart($cart_item_key);

    // Amazon/Shopify pattern: if product is already at max stock, don't error — confirm it's in cart
    if ($existing_key) {
        $existing_item  = WC()->cart->get_cart_item($existing_key);
        $current_qty    = $existing_item ? $existing_item['quantity'] : 0;
        $manages_stock  = $product->managing_stock();
        $stock_qty      = $product->get_stock_quantity();
        $sold_individual = $product->is_sold_individually();

        $at_max = $sold_individual
            || ($manages_stock && $stock_qty !== null && $current_qty >= $stock_qty);

        if ($at_max) {
            $available_stock = $manages_stock && $stock_qty !== null
                ? (int) $stock_qty
                : max(1, (int) $current_qty);
            wc_clear_notices();
            wp_send_json_success([
                'already_in_cart' => true,
                'message'         => 'Ya tienes ' . $product->get_name() . ' en tu carrito. Solo quedan ' . number_format_i18n($available_stock) . ' unidades disponibles.',
                'count'           => WC()->cart->get_cart_contents_count(),
                'total'           => WC()->cart->get_cart_total(),
                'fragments'       => apply_filters('woocommerce_add_to_cart_fragments', []),
            ]);
        }
    }

    $added = WC()->cart->add_to_cart($product_id, $quantity);

    if ($added) {
        $new_qty = 0;
        $cart_item = WC()->cart->get_cart_item($added);
        if ($cart_item) {
            $new_qty = $cart_item['quantity'];
        }

        $message = $existing_key
            ? $product->get_name() . ' — cantidad actualizada (' . $new_qty . ')'
            : $product->get_name() . ' agregado al carrito';

        wp_send_json_success([
            'message' => $message,
            'count'   => WC()->cart->get_cart_contents_count(),
            'total'   => WC()->cart->get_cart_total(),
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', []),
        ]);
    } else {
        $notices = wc_get_notices('error');
        wc_clear_notices();
        $error_msg = !empty($notices)
            ? html_entity_decode(wp_strip_all_tags($notices[0]['notice'] ?? $notices[0]), ENT_QUOTES, 'UTF-8')
            : 'No se pudo agregar al carrito';
        wp_send_json_error(['message' => $error_msg]);
    }
}

/**
 * AJAX get mini cart
 */
add_action('wp_ajax_akibara_get_cart', 'akibara_ajax_get_cart');
add_action('wp_ajax_nopriv_akibara_get_cart', 'akibara_ajax_get_cart');

function akibara_ajax_get_cart() {
    check_ajax_referer('akibara-cart-nonce', 'nonce');

    ob_start();
    get_template_part('template-parts/content/mini-cart');
    $html = ob_get_clean();

    wp_send_json_success([
        'html'  => $html,
        'count' => WC()->cart->get_cart_contents_count(),
        'total' => WC()->cart->get_cart_total(),
    ]);
}

/**
 * Custom breadcrumb args
 */
add_filter('woocommerce_breadcrumb_defaults', function () {
    return [
        'delimiter'   => ' <span class="separator">/</span> ',
        'wrap_before' => '<nav class="woocommerce-breadcrumb" aria-label="Ruta de navegación">',
        'wrap_after'  => '</nav>',
        'before'      => '',
        'after'       => '',
        'home'        => _x('Inicio', 'breadcrumb', 'akibara'),
    ];
});

// A11y: Add hidden label for orderby select
add_action( "woocommerce_before_shop_loop", function() {
    echo "<label for=\"orderby\" class=\"screen-reader-text\">Ordenar productos</label>";
}, 29 );

/**
 * Texto consistente del CTA "Agregar al carrito" / "Reservar ahora" / "Encargar"
 * según tipo de producto. Single source of truth: archive (product-card.php),
 * single (info.php via filter), mini-cart, etc.
 *
 * Producto preventa: "Reservar ahora" (la cobertura definitiva la aplica
 * Akibara_Reserva_Frontend::button_text con priority 20 sobre el filter
 * woocommerce_product_single_add_to_cart_text; este helper duplica la
 * decisión para los call sites que NO pasan por filter — product-card.php
 * en archive/loop usa el helper directo).
 *
 * Producto agotado con backorders: "Encargar".
 *
 * Default: "Agregar al carrito" (minúscula 'c', voz chilena neutra).
 *
 * @param WC_Product|null $product Producto (default: $GLOBALS['product'] o post actual).
 * @return string Texto del CTA.
 */
function akibara_get_atc_text( $product = null ) {
    if ( ! $product instanceof WC_Product ) {
        $product = is_object( $product ) ? wc_get_product( $product ) : null;
        if ( ! $product instanceof WC_Product ) {
            global $post;
            $product = isset( $post->ID ) ? wc_get_product( $post->ID ) : null;
        }
    }

    if ( ! $product instanceof WC_Product ) {
        return 'Agregar al carrito';
    }

    // Preventa (akibara-reservas plugin)
    if ( class_exists( 'Akibara_Reserva_Product' )
        && Akibara_Reserva_Product::is_reserva( $product ) ) {
        return 'Reservar ahora';
    }

    // Agotado con backorders permitidos -> encargo
    if ( ! $product->is_in_stock() && $product->backorders_allowed() ) {
        return 'Encargar';
    }

    return 'Agregar al carrito';
}

/**
 * Filter PDP single product CTA text. Delegamos al helper para mantener
 * consistencia con archive/loop. El plugin akibara-reservas tiene su propio
 * filter en priority 20 que override este resultado para preventas — los dos
 * llegan al mismo string ("Reservar ahora"), así que no hay race condition.
 */
add_filter( 'woocommerce_product_single_add_to_cart_text', function ( $text, $product = null ) {
    if ( function_exists( 'akibara_get_atc_text' ) ) {
        return akibara_get_atc_text( $product );
    }
    return $text;
}, 10, 2 );

add_filter('woocommerce_product_add_to_cart_text', function () {
    return __('Agregar', 'akibara');
});

/**
 * Related products args
 */
add_filter('woocommerce_output_related_products_args', function ($args) {
    $args['posts_per_page'] = 6;
    $args['columns'] = 6;
    return $args;
});

/**
 * AJAX remove from cart
 */
add_action('wp_ajax_akibara_remove_from_cart', 'akibara_ajax_remove_from_cart');
add_action('wp_ajax_nopriv_akibara_remove_from_cart', 'akibara_ajax_remove_from_cart');

function akibara_ajax_remove_from_cart() {
    check_ajax_referer('akibara-cart-nonce', 'nonce');

    $cart_key = sanitize_text_field($_POST['cart_key'] ?? '');

    if ($cart_key && WC()->cart->remove_cart_item($cart_key)) {
        wp_send_json_success([
            'count' => WC()->cart->get_cart_contents_count(),
            'total' => WC()->cart->get_cart_total(),
        ]);
    }

    wp_send_json_error(['message' => 'No se pudo eliminar']);
}

/**
 * Override WooCommerce product loop start to use our grid
 */
add_filter('woocommerce_product_loop_start', function () {
    return '<div class="product-grid product-grid--large aki-reveal">';
});

add_filter('woocommerce_product_loop_end', function () {
    return '</div>';
});


// Checkout layout is now handled by inc/checkout-accordion.php

/**
 * Single product: enable WC reviews tab
 */
add_filter('woocommerce_product_tabs', function ($tabs) {
    // Ensure reviews tab is present
    if (isset($tabs['reviews'])) {
        $tabs['reviews']['title'] = 'Reseñas';
        $tabs['reviews']['priority'] = 30;
    }
    return $tabs;
}, 20);


// ──────────────────────────────────────────────────────────────────────────────
// P0 Fix — Email product thumbnails: WC core hardcodea 32-48px.
// Capa 1: args → image_size 'woocommerce_thumbnail' (350px pre-generado, retina-ready).
// Capa 2: inline style + remoción del attr height en <img> para Gmail (ignora <style>).
//         Portadas de manga son portrait; height:auto da ~113px para 80px de ancho.
// Capa 3: CSS en email-styles.php (email clients que respetan <style>).
// ──────────────────────────────────────────────────────────────────────────────
add_filter( 'woocommerce_email_order_items_args', function ( array $args ): array {
    $args['show_image'] = true;
    $args['image_size'] = 'woocommerce_thumbnail'; // 350px — 4× calidad vs el 80px display
    return $args;
} );

add_filter( 'woocommerce_order_item_thumbnail', function ( string $image ): string {
    if ( ! $image ) {
        return $image;
    }
    // Remover attr height para evitar distorsión cuando width override con CSS.
    $image = preg_replace( '/\s+height="\d*"/i', '', $image );
    // Inline style: funciona en Gmail que stripea bloques <style>.
    return str_replace(
        '<img ',
        '<img width="80" style="width:80px;height:auto;max-height:120px;display:block;border-radius:4px;object-fit:cover;" ',
        $image
    );
} );

/**
 * BlueX: Enhance tracking email with direct Blue Express link
 */
add_filter( 'woocommerce_correios_email_tracking_core_url', function( $url, $tracking_code, $order ) {
    $direct_url = 'https://tracking-unificado.blue.cl/?n_seguimiento=' . urlencode( $tracking_code );
    return sprintf(
        '<a href="%s">%s</a> — <a href="%s" style="color:#D90010;font-weight:bold">Rastrear en Blue Express</a>',
        $order->get_view_order_url() . '#wc-correios-tracking',
        esc_html( $tracking_code ),
        esc_url( $direct_url )
    );
}, 10, 3 );

/**
 * Flag global para saber si estamos en el funnel de checkout (excluye thank-you).
 * Usado por header.php, banner y cart-drawer para suprimir elementos distractores.
 */
function akibara_checkout_in_funnel(): bool {
    return function_exists( 'is_checkout' )
        && is_checkout()
        && ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) );
}

/**
 * Encolar JS del sticky mobile bar del checkout (solo en funnel, no thank-you).
 * Se carga como archivo externo cacheable en lugar de inline (mejor perf).
 */
add_action( 'wp_enqueue_scripts', 'akibara_enqueue_checkout_sticky', 20 );
function akibara_enqueue_checkout_sticky(): void {
    if ( ! akibara_checkout_in_funnel() ) return;

    $ver = defined( 'AKIBARA_THEME_VERSION' ) ? AKIBARA_THEME_VERSION : '1.0';

    wp_enqueue_script(
        'akb-checkout-sticky-mobile',
        AKIBARA_THEME_URI . '/assets/js/checkout-sticky-mobile.js',
        [ 'jquery' ],
        $ver,
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );

    // Tooltip toggle del chip summary de descuentos (mobile + accesibilidad).
    wp_enqueue_script(
        'akb-checkout-discount-chips',
        AKIBARA_THEME_URI . '/assets/js/checkout-discount-chips.js',
        [],
        $ver,
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );

    // Copy dinámico del botón "Continuar a Mercado Pago" + toggle del
    // banner de cuotas según método de pago seleccionado (paso 3).
    wp_enqueue_script(
        'akb-checkout-payment-ux',
        AKIBARA_THEME_URI . '/assets/js/checkout-payment-ux.js',
        [ 'jquery' ],
        $ver,
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );

    // A4 (a11y): screen readers anuncian errores inline en focusout/change
    // populando un .checkout-field-hint container que tiene role="alert" y
    // aria-live="polite". El container es injectado por akibara_checkout_field_aria_hint
    // (filter woocommerce_form_field) y permanece vacío hasta que JS detecta
    // un fallo de validación.
    wp_enqueue_script(
        'akb-checkout-validation',
        AKIBARA_THEME_URI . '/assets/js/checkout-validation.js',
        [ 'jquery' ],
        $ver,
        [ 'strategy' => 'defer', 'in_footer' => true ]
    );
}

/**
 * A4 (a11y) — Inyecta un container `.checkout-field-hint` después de cada
 * campo del checkout y agrega `aria-describedby` al input/select/textarea.
 *
 * Estrategia:
 *   - Container nace vacío con role="alert" + aria-live="polite". Cuando
 *     checkout-validation.js detecta error, popule el text content y el
 *     screen reader lo anuncia automáticamente.
 *   - Si el campo se vuelve válido, JS limpia el container (texto vacío =
 *     no hay anuncio).
 *   - aria-describedby permanente NO genera ruido cuando el container está
 *     vacío: el SR no anuncia descripciones vacías.
 *
 * Se aplica a todos los campos del form-row visible (billing_*, order_*).
 * Skip silenciosamente cuando WC ya rendea con `description` propia
 * (raro en el checkout — los placeholders de WC no usan ese arg).
 */
add_filter( 'woocommerce_form_field', 'akibara_checkout_field_aria_hint', 99, 4 );
function akibara_checkout_field_aria_hint( string $field, string $key, array $args, $value ): string {
    // Solo en checkout. is_checkout() puede no estar disponible en algunos
    // contextos AJAX, así que también chequeamos el filter context.
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return $field;
    }

    // Ignorar tipos que no tienen control real (hidden, terms checkbox WC ya
    // maneja con su propio sistema, country que es hidden por reorder).
    $type = $args['type'] ?? 'text';
    if ( in_array( $type, [ 'hidden', 'checkbox' ], true ) ) {
        return $field;
    }

    $hint_id = $key . '_description';

    // 1. Inyectar aria-describedby en el input/select/textarea.
    //    Pattern: el primer match de input/select/textarea con id=$key.
    //    Conservamos cualquier aria-describedby pre-existente concatenándolo.
    $field = preg_replace_callback(
        '/<(input|select|textarea)([^>]*\bid=["\']' . preg_quote( $key, '/' ) . '["\'][^>]*)>/i',
        function ( $m ) use ( $hint_id ) {
            $tag   = $m[1];
            $attrs = $m[2];
            // Si ya tiene aria-describedby (set por algún otro filter o WC),
            // appendear el nuevo ID en lugar de reemplazar.
            if ( preg_match( '/\baria-describedby=["\']([^"\']*)["\']/i', $attrs, $existing ) ) {
                $combined = trim( $existing[1] . ' ' . $hint_id );
                $attrs    = preg_replace(
                    '/\baria-describedby=["\'][^"\']*["\']/i',
                    'aria-describedby="' . esc_attr( $combined ) . '"',
                    $attrs
                );
            } else {
                $attrs .= ' aria-describedby="' . esc_attr( $hint_id ) . '"';
            }
            return '<' . $tag . $attrs . '>';
        },
        $field,
        1 // Solo primera ocurrencia (campo principal — no afectar inputs ocultos asociados).
    );

    // 2. Inyectar el container del hint justo antes de cerrar el .form-row.
    //    role="alert" + aria-live="polite" hace que SR anuncie automáticamente
    //    cuando JS popule el textContent.
    $hint_html = sprintf(
        '<p id="%s" class="checkout-field-hint" role="alert" aria-live="polite"></p>',
        esc_attr( $hint_id )
    );

    // El field viene wrappeado por <p class="form-row ..."> — insertamos antes
    // del </p> final. Si no encontramos el cierre (estructura custom de algún
    // plugin), append al final como fallback.
    $field_with_hint = preg_replace(
        '/<\/p>\s*$/',
        $hint_html . '</p>',
        $field,
        1,
        $count
    );

    return $count > 0 ? $field_with_hint : $field . $hint_html;
}

/**
 * Normaliza un código de región chilena al formato canónico "CL-XX".
 *
 * WC Chile nativo guarda como "CL-RM", pero imports legacy, CSV exports o
 * migraciones pueden llegar como "RM". Esta función garantiza un formato
 * único hacia abajo — elimina la necesidad de fallbacks `isset(CL-.$v)` en
 * cada lookup.
 *
 * @param string $raw Código bruto desde user_meta/POST/sesión.
 * @return string Código "CL-XX" válido, o "" si no se reconoce.
 */
function akibara_normalize_chile_state( string $raw ): string {
    $raw = trim( $raw );
    if ( $raw === '' ) return '';
    if ( str_starts_with( $raw, 'CL-' ) ) return $raw;
    // Legacy sin prefijo: "RM" → "CL-RM". Validar que tenga shape [A-Z]{2,3}.
    if ( preg_match( '/^[A-Z]{2,3}$/', $raw ) ) {
        return 'CL-' . $raw;
    }
    return '';
}

/**
 * Defaults de state/city en checkout.
 *
 * Anónimo: vacío. Evita que la ubicación base (Chile · RM) caiga como default
 * y termine enviando pedidos a "Alhué" (primera comuna alfabética) si el
 * cliente no abre los selectores.
 *
 * Logueado con dirección guardada: preservar el valor de user_meta. State
 * pasa por normalize_chile_state() para garantizar formato "CL-XX".
 */
function akibara_checkout_default_from_usermeta( string $meta_key ): string {
    if ( ! is_user_logged_in() ) {
        return '';
    }
    $value = (string) get_user_meta( get_current_user_id(), $meta_key, true );
    if ( str_ends_with( $meta_key, '_state' ) ) {
        $value = akibara_normalize_chile_state( $value );
    }
    return $value;
}

foreach ( [ 'billing_state', 'shipping_state', 'billing_city', 'shipping_city' ] as $akb_co_key ) {
    add_filter( "default_checkout_{$akb_co_key}", function ( $v ) use ( $akb_co_key ) {
        return $v ?: akibara_checkout_default_from_usermeta( $akb_co_key );
    } );
}
unset( $akb_co_key );

/**
 * Checkout field reorder — email first, then RUT, then name, then address.
 * Also removes country field (Chile only) and optimizes autocomplete attributes.
 */
add_filter( 'woocommerce_checkout_fields', 'akibara_reorder_checkout_fields', 99 );

function akibara_reorder_checkout_fields( array $fields ): array {
    // Reorder billing fields by priority
    if ( isset( $fields['billing']['billing_email'] ) ) {
        $fields['billing']['billing_email']['priority'] = 5;
        $fields['billing']['billing_email']['class'] = [
            'form-row-wide'];
        $fields['billing']['billing_email']['autocomplete'] = 'email';
    }

    // RUT at priority 10 (set in rut module at 25, override here)
    if ( isset( $fields['billing']['billing_rut'] ) ) {
        $fields['billing']['billing_rut']['priority'] = 10;
    }

    // Name fields
    if ( isset( $fields['billing']['billing_first_name'] ) ) {
        $fields['billing']['billing_first_name']['priority'] = 15;
        $fields['billing']['billing_first_name']['autocomplete'] = 'given-name';
    }
    if ( isset( $fields['billing']['billing_last_name'] ) ) {
        $fields['billing']['billing_last_name']['priority'] = 16;
        $fields['billing']['billing_last_name']['autocomplete'] = 'family-name';
    }

    // Phone before address.
    // Nota: el autocomplete del teléfono lo controla el módulo phone
    // (custom_attributes['autocomplete']='tel-national') para evitar
    // duplicar el atributo en el HTML.
    if ( isset( $fields['billing']['billing_phone'] ) ) {
        $fields['billing']['billing_phone']['priority']    = 20;
        $fields['billing']['billing_phone']['placeholder'] = '+56 9 XXXX XXXX';
    }

    // Chile-only: simplificar label "País / Región" → "Región".
    // El country está fijo CL y no se muestra como campo, así que el label
    // compuesto confunde. Mejora también ratio de comprensión local.
    if ( isset( $fields['billing']['billing_state'] ) ) {
        $fields['billing']['billing_state']['label'] = 'Región';
    }
    if ( isset( $fields['shipping']['shipping_state'] ) ) {
        $fields['shipping']['shipping_state']['label'] = 'Región';
    }

    // Placeholder RUT más realista (no "EJ:", formato chileno válido).
    if ( isset( $fields['billing']['billing_rut'] ) ) {
        $fields['billing']['billing_rut']['placeholder'] = '12.345.678-9';
    }

    // Address fields (HTML autocomplete spec §4.10.18.7.2).
    //   address-line1/2 → primera/segunda línea de la dirección postal
    //   address-level1  → nivel admin superior (en Chile, la región)
    //   address-level2  → nivel admin secundario (en Chile, la comuna)
    if ( isset( $fields['billing']['billing_address_1'] ) ) {
        $fields['billing']['billing_address_1']['priority'] = 30;
        $fields['billing']['billing_address_1']['autocomplete'] = 'address-line1';
    }

    if ( isset( $fields['billing']['billing_state'] ) ) {
        $fields['billing']['billing_state']['autocomplete'] = 'address-level1';
    }

    // Chile: el campo "Ciudad" de WC no corresponde a la división administrativa.
    // El courier necesita la comuna para zonas de despacho y tarifas.
    if ( isset( $fields['billing']['billing_city'] ) ) {
        $fields['billing']['billing_city']['label']        = 'Comuna';
        $fields['billing']['billing_city']['placeholder']  = 'Ej: Providencia';
        $fields['billing']['billing_city']['autocomplete'] = 'address-level2';
    }
    if ( isset( $fields['shipping']['shipping_city'] ) ) {
        $fields['shipping']['shipping_city']['label']       = 'Comuna';
        $fields['shipping']['shipping_city']['placeholder'] = 'Ej: Providencia';
    }

    // Remove country field — Chile only
    if ( isset( $fields['billing']['billing_country'] ) ) {
        $fields['billing']['billing_country']['type'] = 'hidden';
        $fields['billing']['billing_country']['default'] = 'CL';
    }

    // Remove company field — not needed for manga store
    unset( $fields['billing']['billing_company'] );

    // Remove postcode — not used in Chile checkout
    unset( $fields['billing']['billing_postcode'] );

    // Address line 2 (Depto / Oficina).
    if ( isset( $fields['billing']['billing_address_2'] ) ) {
        $fields['billing']['billing_address_2']['required']     = false;
        $fields['billing']['billing_address_2']['label']        = 'Depto / Oficina (opcional)';
        $fields['billing']['billing_address_2']['priority']     = 31;
        $fields['billing']['billing_address_2']['autocomplete'] = 'address-line2';
    }

    return $fields;
}

/**
 * AJAX — Update mini-cart item quantity
 */
add_action( 'wp_ajax_akibara_update_cart_qty',        'akibara_ajax_update_cart_qty' );
add_action( 'wp_ajax_nopriv_akibara_update_cart_qty', 'akibara_ajax_update_cart_qty' );

function akibara_ajax_update_cart_qty(): void {
    check_ajax_referer( 'akibara-cart-nonce', 'nonce' );

    $cart_key = sanitize_text_field( $_POST['cart_key'] ?? '' );
    $qty      = max( 0, (int) ( $_POST['quantity'] ?? 0 ) );

    if ( ! $cart_key ) {
        wp_send_json_error( [ 'message' => 'Clave invalida' ] );
        return;
    }

    if ( $qty === 0 ) {
        WC()->cart->remove_cart_item( $cart_key );
    } else {
        WC()->cart->set_quantity( $cart_key, $qty, true );
    }

    ob_start();
    get_template_part( 'template-parts/content/mini-cart' );
    $html = ob_get_clean();

    wp_send_json_success( [
        'html'  => $html,
        'count' => WC()->cart->get_cart_contents_count(),
        'total' => WC()->cart->get_cart_total(),
    ] );
}
// ── #1 Back-in-stock notifications ──────────────────────────
add_action( 'wp_ajax_akibara_notify_stock',        'akibara_notify_stock_handler' );
add_action( 'wp_ajax_nopriv_akibara_notify_stock', 'akibara_notify_stock_handler' );

function akibara_notify_stock_handler() {
    check_ajax_referer( 'akibara-notify-stock', 'nonce' );

    $product_id = absint( $_POST['product_id'] ?? 0 );
    $email      = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

    if ( ! $product_id || ! is_email( $email ) ) {
        wp_send_json_error( 'Datos inválidos' );
        return;
    }

    $product = wc_get_product( $product_id );
    if ( ! $product || $product->is_in_stock() ) {
        wp_send_json_error( 'Este producto ya está disponible' );
        return;
    }

    $emails = get_post_meta( $product_id, '_akibara_notify_emails', true );
    if ( ! is_array( $emails ) ) $emails = [];

    if ( ! in_array( $email, $emails, true ) ) {
        $emails[] = $email;
        update_post_meta( $product_id, '_akibara_notify_emails', $emails );
    }

    wp_send_json_success( [ 'message' => '¡Te avisamos cuando llegue!' ] );
}

// Queue restock notifications when product comes back in stock (async via WP-Cron)
add_action( 'woocommerce_product_set_stock_status', function ( $product_id, $status, $product ) {
    if ( $status !== 'instock' ) return;

    $emails = get_post_meta( $product_id, '_akibara_notify_emails', true );
    if ( empty( $emails ) || ! is_array( $emails ) ) return;

    // Delete meta FIRST to prevent re-queue if cron runs before completion
    delete_post_meta( $product_id, '_akibara_notify_emails' );

    // Schedule async batch send
    if ( ! wp_next_scheduled( 'akibara_send_restock_batch', [ $product_id, $emails ] ) ) {
        wp_schedule_single_event( time(), 'akibara_send_restock_batch', [ $product_id, $emails ] );
    }
}, 10, 3 );

// Async handler: send restock emails in batches of 20
add_action( 'akibara_send_restock_batch', function ( $product_id, $emails ) {
    $title   = get_the_title( $product_id );
    $url     = get_permalink( $product_id );
    $subject = "¡{$title} ya está disponible en Akibara!";
    $body    = "Hola,\n\nTe avisamos que \"{$title}\" ya está disponible en Akibara.cl.\n\nCómpralo aquí: {$url}\n\n¡Apúrate antes de que se agote!\n\nEquipo Akibara";
    $headers = [ 'Content-Type: text/plain; charset=UTF-8', 'From: Akibara <no-reply@akibara.cl>' ];

    $batch_size = 20;
    $batches    = array_chunk( $emails, $batch_size );

    foreach ( $batches as $batch ) {
        foreach ( $batch as $addr ) {
            if ( is_email( $addr ) ) {
                wp_mail( $addr, $subject, $body, $headers );
            }
        }
        // Small delay between batches to avoid SMTP throttling
        if ( count( $batches ) > 1 ) {
            sleep( 1 );
        }
    }
}, 10, 2 );

// ── Registration: save billing_first_name + validate terms ──────────
add_action('woocommerce_register_post', function ($username, $email, $errors) {
    if (empty($_POST['billing_first_name'])) {
        $errors->add('name_required', 'Por favor ingresa tu nombre.');
    }
    if (empty($_POST['akibara_terms'])) {
        $errors->add('terms_required', 'Debes aceptar los Términos de uso para continuar.');
    }
}, 10, 3);

add_action('woocommerce_created_customer', function ($customer_id) {
    if (!empty($_POST['billing_first_name'])) {
        $name = sanitize_text_field(wp_unslash($_POST['billing_first_name']));
        update_user_meta($customer_id, 'billing_first_name', $name);
        update_user_meta($customer_id, 'first_name',         $name);
        wp_update_user(['ID' => $customer_id, 'display_name' => $name]);
    }
});

// ── Checkout: enable login reminder ─────────────────────────────────
add_filter('option_woocommerce_enable_checkout_login_reminder', function() { return 'yes'; });

// ── Post-registration redirect: welcome notice ───────────────────────
add_filter('woocommerce_registration_redirect', function ($redirect) {
    return add_query_arg('registered', '1', $redirect);
});

add_action('woocommerce_before_customer_login_form', function () {
    if (isset($_GET['registered'])) {
        $user = wp_get_current_user();
        $name = $user->first_name ?: 'por aquí';
        wc_add_notice("¡Bienvenido/a, {$name}! Tu cuenta está activa. Empieza a explorar.", 'success');
    }
    if (isset($_GET['google_error'])) {
        $messages = [
            'invalid' => 'Este enlace ya no es válido. Solicita uno nuevo.',
            'config'   => 'Google login no está configurado.',
            'state'    => 'Sesión expirada. Intenta de nuevo.',
            'nocode'   => 'Autorización cancelada.',
            'token'    => 'Error al conectar con Google.',
            'userinfo' => 'No se pudo obtener tu información de Google.',
            'email'    => 'No se pudo obtener tu email de Google.',
            'create'   => 'Error al crear tu cuenta. Intenta de nuevo.',
        ];
        $key = sanitize_key($_GET['google_error']);
        wc_add_notice($messages[$key] ?? 'Error con Google.', 'error');
    }
    // Magic Link messages
    if (isset($_GET['magic_success'])) {
        $user = wp_get_current_user();
        $name = $user->first_name ?: 'por aquí';
        wc_add_notice("¡Hola, {$name}! Ingresaste con tu enlace mágico. 🎉", 'success');
    }
    if (isset($_GET['magic_error'])) {
        $magic_msgs = [
            'invalid' => 'Este enlace ya no es válido. Solicita uno nuevo.',
            'expired' => 'Este enlace ya expiró (15 min). Solicita uno nuevo.',
            'used'    => 'Este enlace ya fue utilizado. Solicita uno nuevo.',
        ];
        $mk = sanitize_key($_GET['magic_error']);
        wc_add_notice($magic_msgs[$mk] ?? 'Enlace inválido. Solicita uno nuevo.', 'error');
    }
});

// ══════════════════════════════════════════════════════════════
// FREE SHIPPING THRESHOLD — WooCommerce Settings > Shipping
// ══════════════════════════════════════════════════════════════

/**
 * Add configurable free shipping threshold to WooCommerce > Settings > Shipping.
 * This value must match the BlueExpress portal configuration.
 */
add_filter('woocommerce_shipping_settings', function ($settings) {
    $settings[] = [
        'title'    => 'Monto mínimo envío gratis',
        'desc'     => 'Monto mínimo del carrito para mostrar "envío gratis". Debe coincidir con la configuración de BlueExpress.',
        'id'       => 'akibara_free_shipping_threshold',
        'type'     => 'number',
        'default'  => '55000',
        'desc_tip' => true,
        'custom_attributes' => ['min' => '0', 'step' => '1000'],
    ];
    return $settings;
});

/**
 * Cross-sell en thank-you: "Mientras esperas tu pedido…".
 *
 * Usa `akibara_get_smart_recommendations()` con el primer producto del pedido
 * como semilla. Filtra los IDs ya comprados para no sugerir duplicados.
 * Se engancha después del heading personalizado (prioridad 5 es antes de la
 * tabla de resumen, 20 es después).
 */
add_action( 'woocommerce_thankyou', 'akibara_thankyou_cross_sell', 15 );
function akibara_thankyou_cross_sell( int $order_id ): void {
    if ( ! $order_id || ! function_exists( 'akibara_get_smart_recommendations' ) ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // IDs de productos comprados (para seed + exclude).
    $purchased_ids = [];
    foreach ( $order->get_items() as $item ) {
        $pid = (int) $item->get_product_id();
        if ( $pid ) $purchased_ids[] = $pid;
    }
    if ( empty( $purchased_ids ) ) return;

    // Seed: primer producto del pedido. Pedimos el doble para poder filtrar
    // los ya comprados y aun así mostrar 6 relevantes.
    $seed = $purchased_ids[0];
    $recs = akibara_get_smart_recommendations( $seed, 12 );
    if ( empty( $recs ) ) return;

    $recs = array_values( array_diff( $recs, $purchased_ids ) );
    $recs = array_slice( $recs, 0, 6 );
    if ( empty( $recs ) ) return;

    $query = new WP_Query( [
        'post_type'      => 'product',
        'post__in'       => $recs,
        'orderby'        => 'post__in',
        'posts_per_page' => count( $recs ),
        'no_found_rows'  => true,
    ] );
    if ( ! $query->have_posts() ) return;
    ?>
    <section class="akb-thankyou-crosssell" aria-label="Recomendaciones para ti">
        <div class="section-header">
            <h2 class="section-header__title">Mientras esperas tu pedido…</h2>
            <p class="section-header__sub">Más títulos que te pueden interesar</p>
        </div>
        <div class="product-grid product-grid--large">
            <?php while ( $query->have_posts() ) :
                $query->the_post();
                global $product;
                get_template_part( 'template-parts/content/product-card' );
            endwhile;
            wp_reset_postdata(); ?>
        </div>
    </section>
    <?php
}

/**
 * Copy chileno corto para los labels de impuesto.
 *
 * WooCommerce emite "(incluye $X.XXX Impuesto)" debajo del total usando el
 * filter `woocommerce_countries_tax_or_vat`. En Chile el término universal
 * es "IVA" — cambiarlo reduce verbosidad y alinea con expectativa local.
 *
 * Cubrimos ambos filters porque WC usa uno u otro según el contexto:
 *  - `tax_or_vat`      → dentro del string "(incluye %s ...)"
 *  - `inc_tax_or_vat`  → etiqueta completa en precios individuales
 */
add_filter( 'woocommerce_countries_tax_or_vat',     'akibara_tax_label',     99 );
add_filter( 'woocommerce_countries_inc_tax_or_vat', 'akibara_tax_inc_label', 99 );

function akibara_tax_label( $label ): string {
    return 'IVA';
}
function akibara_tax_inc_label( $label ): string {
    return 'IVA incluido';
}

/**
 * El caso real: `woocommerce_tax_total_display = itemized` + el label de la
 * tax rate en DB es "Impuesto". El template usa `$tax->label` directamente,
 * no `tax_or_vat()`. Este filter intercepta el label de la tax rate.
 */
add_filter( 'woocommerce_rate_label', 'akibara_rate_label_to_iva', 99 );
function akibara_rate_label_to_iva( string $rate_name ): string {
    // Reemplazar variantes comunes del label "Impuesto" por "IVA".
    if ( in_array( $rate_name, [ 'Impuesto', 'Impuestos', 'Tax', 'Taxes' ], true ) ) {
        return 'IVA';
    }
    return $rate_name;
}

/**
 * Copy del botón final "Realizar pedido" (WC default) →
 * "Confirmar y pagar" — imperativo claro, sin ambigüedad.
 */
add_filter( 'woocommerce_order_button_text', function (): string {
    return 'Confirmar y pagar';
} );

/**
 * Helper: get the free shipping threshold from WooCommerce settings.
 * Used across the theme (filters.php, single-product.php, header.php).
 */
function akibara_get_free_shipping_threshold(): int {
    return (int) get_option('akibara_free_shipping_threshold', 55000);
}

/**
 * Detectar región del usuario con fallbacks.
 *
 * Orden de resolución:
 *   1. Meta de usuario logueado (`billing_state`).
 *   2. Sesión de WooCommerce (`WC()->customer->get_billing_state()`).
 *   3. Dato del checkout en curso (POST en `update_order_review`).
 *   4. (Opcional) geolocalización IP — solo da país, no región.
 *
 * Retorna el código de región chilena (`CL-RM`, `CL-VS`, ...) o `''` si
 * no se puede determinar. Cachea por request para evitar queries duplicadas.
 */
function akibara_user_region(): string {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $region = '';

    // 1. Usuario logueado con dirección guardada.
    if ( is_user_logged_in() ) {
        $meta = get_user_meta( get_current_user_id(), 'billing_state', true );
        if ( is_string( $meta ) && $meta !== '' ) {
            $region = 'CL-' . strtoupper( str_replace( 'CL-', '', $meta ) );
        }
    }

    // 2. Sesión WC (incluye guest con dirección parcial ya introducida).
    if ( $region === '' && function_exists( 'WC' ) && WC()->customer ) {
        $session = (string) WC()->customer->get_billing_state();
        if ( $session !== '' ) {
            $region = 'CL-' . strtoupper( str_replace( 'CL-', '', $session ) );
        }
    }

    $cache = $region;
    return $cache;
}

/**
 * ¿El usuario está confirmado fuera de la Región Metropolitana?
 * Útil para suprimir mensajes de "Retiro en San Miguel" que son irrelevantes
 * para gente de regiones (y pueden percibirse como excluyentes).
 */
function akibara_user_is_regiones(): bool {
    $r = akibara_user_region();
    return $r !== '' && $r !== 'CL-RM';
}

/**
 * Helper: contextual WhatsApp URL for a product.
 */
function akibara_get_product_whatsapp_url( int $product_id ): string {
    $product_title = get_the_title( $product_id );
    $message       = sprintf(
        'Hola, tengo una consulta sobre %s',
        $product_title ? $product_title : 'este título'
    );

    if ( function_exists( 'akibara_wa_url' ) ) {
        return (string) akibara_wa_url( $message );
    }

    $fallback_phone = defined( 'AKIBARA_METRO_PICKUP_WA' ) ? AKIBARA_METRO_PICKUP_WA : akibara_whatsapp_get_business_number();

    return 'https://wa.me/' . preg_replace( '/[^0-9]/', '', (string) $fallback_phone ) . '?text=' . rawurlencode( $message );
}

/**
 * Helper: estimación de plazo de encargo en semanas según categoría del producto.
 *
 * Manga (catálogo Argentina/España regular) ~ 2-3 semanas.
 * Cómics europeos / títulos especiales ~ 4-6 semanas.
 * Default genérico ~ 2-6 semanas (alineado a copy /encargos/).
 *
 * Acepta WC_Product opcional; si no se pasa, lo resuelve del loop global.
 *
 * @param \WC_Product|null $product
 * @return string Texto sin sufijo "semanas" — caller decide formato.
 */
function akibara_encargo_estimate_weeks( $product = null ): string {
	if ( ! $product ) {
		global $post;
		if ( $post instanceof \WP_Post ) {
			$product = wc_get_product( $post->ID );
		}
	}

	if ( ! $product instanceof \WC_Product ) {
		return '2-6 semanas';
	}

	$cat_slugs = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );
	if ( is_wp_error( $cat_slugs ) || empty( $cat_slugs ) ) {
		return '2-6 semanas';
	}

	// Manga directo (catálogo Argentina + España, plazo más corto).
	if ( in_array( 'manga', $cat_slugs, true ) ) {
		return '2-3 semanas';
	}
	// Cómics europeos/USA, plazo mayor por logística internacional.
	if ( in_array( 'comics', $cat_slugs, true ) ) {
		return '4-6 semanas';
	}

	return '2-6 semanas';
}

/**
 * Country badge data for single product view.
 *
 * @return array{country:string,flag:string,fallback:string}
 */
function akibara_get_country_badge_data( string $country_name ): array {
    $country_name = trim( $country_name );

    $map = [
        'Argentina'      => [ 'flag' => '🇦🇷', 'fallback' => 'AR' ],
        'España'         => [ 'flag' => '🇪🇸', 'fallback' => 'ES' ],
        'Japón'          => [ 'flag' => '🇯🇵', 'fallback' => 'JP' ],
        'Estados Unidos' => [ 'flag' => '🇺🇸', 'fallback' => 'US' ],
        'Chile'          => [ 'flag' => '🇨🇱', 'fallback' => 'CL' ],
        'México'         => [ 'flag' => '🇲🇽', 'fallback' => 'MX' ],
        'Francia'        => [ 'flag' => '🇫🇷', 'fallback' => 'FR' ],
        'Italia'         => [ 'flag' => '🇮🇹', 'fallback' => 'IT' ],
    ];

    $default_fallback = strtoupper( substr( remove_accents( $country_name ? $country_name : 'INT' ), 0, 2 ) );
    $item             = $map[ $country_name ] ?? [ 'flag' => '🌍', 'fallback' => $default_fallback ];

    return [
        'country'   => $country_name,
        'flag'      => (string) $item['flag'],
        'fallback'  => (string) $item['fallback'],
        'show_flag' => isset( $map[ $country_name ] ),
    ];
}

/**
 * Render star rating markup without inline styles.
 */
function akibara_render_stars( float $rating, int $rating_count = 0 ): string {
    $rating = max( 0.0, min( 5.0, $rating ) );
    $full   = (int) floor( $rating );
    $half   = ( $rating - $full ) >= 0.5 ? 1 : 0;
    $empty  = 5 - $full - $half;

    $aria_label = sprintf( 'Valorado %s de 5', number_format_i18n( $rating, 1 ) );
    $html       = '<span class="akb-stars" role="img" aria-label="' . esc_attr( $aria_label ) . '">';

    for ( $i = 0; $i < $full; $i++ ) {
        $html .= '<span class="akb-stars__star akb-stars__star--full" aria-hidden="true">★</span>';
    }

    if ( $half ) {
        $html .= '<span class="akb-stars__star akb-stars__star--half" aria-hidden="true">';
        $html .= '<span class="akb-stars__star-half">★</span>';
        $html .= '<span class="akb-stars__star-empty">★</span>';
        $html .= '</span>';
    }

    for ( $i = 0; $i < $empty; $i++ ) {
        $html .= '<span class="akb-stars__star akb-stars__star--empty" aria-hidden="true">★</span>';
    }

    $html .= '</span>';

    if ( $rating_count > 0 ) {
        $suffix = $rating_count !== 1 ? 's' : '';
        $html  .= '<span class="screen-reader-text">' . esc_html( $rating_count . ' reseña' . $suffix ) . '</span>';
    }

    return $html;
}

/**
 * Trust signals displayed on single product page.
 *
 * @return array<int,array{icon:string,text:string}>
 */
function akibara_get_product_trust_signals( int $product_id = 0 ): array {
    $signals = [
        [
            'icon' => 'truck',
            'text' => 'Envío gratis en compras sobre $' . number_format( akibara_get_free_shipping_threshold(), 0, ',', '.' ),
        ],
        [
            'icon' => 'package',
            'text' => 'Todos nuestros mangas incluyen funda protectora',
        ],
        [
            'icon' => 'shield',
            'text' => 'Pago 100% seguro · Mercado Pago, Flow y transferencia',
        ],
        [
            'icon' => 'calendar',
            'text' => 'Despacho a todo Chile · mismo día en RM',
        ],
    ];

    return (array) apply_filters( 'akibara_product_trust_signals', $signals, $product_id );
}

// Load WC cart session on REST API requests (needed for cart REST endpoints)
add_action( 'rest_api_init', function() {
    if ( WC()->is_rest_api_request() ) {
        WC()->frontend_includes();
        if ( null === WC()->cart ) {
            wc_load_cart();
        }
    }
}, 5 );

/**
 * Mercado Pago — dequeue scripts del custom checkout en /carrito/.
 *
 * El plugin (v8.7.17) considera /carrito/ una "payments related page" y enqueue todo
 * el bundle de checkout. mp-custom-checkout.js busca el form de pago, no lo encuentra
 * y lanza "No checkout form found after 10 attempts" en consola. En cart no hay form,
 * así que estos scripts son ruido + bytes desperdiciados. Solo los necesitamos en checkout real.
 */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_cart' ) || ! is_cart() || is_checkout() ) return;
    $handles = [
        'wc_mercadopago_security_session',
        'wc_mercadopago_sdk',
        'wc_mercadopago_custom_card_form',
        'wc_mercadopago_custom_elements',
        'wc_mercadopago_custom_checkout',
    ];
    foreach ( $handles as $h ) {
        wp_dequeue_script( $h );
        wp_deregister_script( $h );
    }
}, 100 );

