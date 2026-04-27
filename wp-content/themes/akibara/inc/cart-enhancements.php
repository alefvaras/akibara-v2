<?php
/**
 * Cart UX Enhancements
 *
 * Progress indicator, free-shipping bar, delivery estimate,
 * cross-sell "Completa tu serie", coupon nudge,
 * "Guardar para después" wishlist button, mobile sticky CTA.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// ── 1. Progress indicator ─────────────────────────────────────────────────────

add_action( 'woocommerce_before_cart', function () {
    ?>
    <nav class="akb-cart-progress" aria-label="Pasos del pedido">
        <ol class="akb-cart-progress__steps">
            <li class="akb-cart-progress__step akb-cart-progress__step--active" aria-current="step">
                <span class="akb-cart-progress__dot"></span>
                <span class="akb-cart-progress__label">Carrito</span>
            </li>
            <li class="akb-cart-progress__step">
                <span class="akb-cart-progress__dot"></span>
                <span class="akb-cart-progress__label">Datos</span>
            </li>
            <li class="akb-cart-progress__step">
                <span class="akb-cart-progress__dot"></span>
                <span class="akb-cart-progress__label">Pago</span>
            </li>
            <li class="akb-cart-progress__step">
                <span class="akb-cart-progress__dot"></span>
                <span class="akb-cart-progress__label">Listo</span>
            </li>
        </ol>
    </nav>
    <?php
}, 5 );

// ── 2. Free shipping progress bar ─────────────────────────────────────────────
// Centralizada en inc/filters.php → akibara_shipping_progress_bar()
// Posiciones activas:
//   • Mini-cart: template-parts/content/mini-cart.php (llamada directa)
//   • Carrito:   add_action('woocommerce_before_cart_table', ...) en filters.php
//   • Checkout:  ship-free-progress.js (sidebar "TU PEDIDO")

// WC fragments: actualiza .shipping-progress y .akb-cart-sticky vía AJAX
function akibara_cart_ship_bar_fragment(): string {
    if ( ! function_exists( 'akibara_shipping_progress_bar' ) || ! WC()->cart ) {
        return '';
    }
    ob_start();
    akibara_shipping_progress_bar();
    return (string) ob_get_clean();
}

add_filter( 'woocommerce_add_to_cart_fragments', function ( array $fragments ): array {
    $bar = akibara_cart_ship_bar_fragment();
    if ( $bar ) {
        $fragments['.shipping-progress'] = $bar;
    }
    $fragments['.akb-cart-sticky'] = akibara_cart_sticky_html();
    return $fragments;
} );

// ── 3. Delivery estimate ──────────────────────────────────────────────────────

add_action( 'woocommerce_cart_totals_after_order_total', function () {
    ?>
    <tr class="akb-delivery-row">
        <td colspan="2" class="akb-delivery-row__cell">
            <span class="akb-delivery-row__icon">⚡</span>
            <span class="akb-delivery-row__text">
                Despacho el mismo día en la RM con pedidos antes de las 14:00 hrs.
            </span>
        </td>
    </tr>
    <?php
} );

// ── 4. Cross-sell "Completa tu serie" ────────────────────────────────────────

add_action( 'woocommerce_cart_collaterals', function () {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return;

    $cart_items = WC()->cart->get_cart();
    $cart_pids  = array_column( $cart_items, 'product_id' );
    $suggestions = [];

    foreach ( $cart_items as $ci ) {
        $norm = trim( (string) get_post_meta( (int) $ci['product_id'], '_akibara_serie_norm', true ) );
        if ( ! $norm ) continue;

        $q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 4,
            'post__not_in'   => $cart_pids,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_akibara_serie_norm', 'value' => $norm ],
                [ 'key' => '_stock_status',       'value' => 'instock' ],
            ],
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ] );

        foreach ( $q->posts as $pid ) {
            if ( ! isset( $suggestions[ $pid ] ) ) {
                $suggestions[ $pid ] = wc_get_product( (int) $pid );
                if ( count( $suggestions ) >= 4 ) break 2;
            }
        }
        wp_reset_postdata();
    }

    if ( empty( $suggestions ) ) return;
    ?>
    <div class="akb-cart-serie">
        <h3 class="akb-cart-serie__title">📚 Completa tu serie</h3>
        <div class="akb-cart-serie__list">
            <?php foreach ( $suggestions as $sid => $sp ) :
                if ( ! $sp ) continue;
                ?>
            <div class="akb-cart-serie__item">
                <a href="<?php echo esc_url( get_permalink( $sid ) ); ?>" class="akb-cart-serie__img-link">
                    <?php echo wp_kses_post( $sp->get_image( 'thumbnail' ) ); ?>
                </a>
                <div class="akb-cart-serie__info">
                    <a href="<?php echo esc_url( get_permalink( $sid ) ); ?>" class="akb-cart-serie__name">
                        <?php echo esc_html( wp_trim_words( $sp->get_name(), 6, '…' ) ); ?>
                    </a>
                    <span class="akb-cart-serie__price"><?php echo wp_kses_post( $sp->get_price_html() ); ?></span>
                </div>
                <button class="js-quick-add akb-cart-serie__add"
                        data-product-id="<?php echo esc_attr( (string) $sid ); ?>"
                        aria-label="Agregar <?php echo esc_attr( $sp->get_name() ); ?> al carrito"
                        title="Agregar al carrito">+</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}, 20 );

// ── 5. Coupon nudge ───────────────────────────────────────────────────────────

add_action( 'woocommerce_before_cart_collaterals', function () {
    // No mostrar si ya hay cupón aplicado
    if ( ! WC()->cart || WC()->cart->get_coupons() ) return;

    $user_id = get_current_user_id();

    // Usuario logueado con módulo de bienvenida disponible
    if ( $user_id && class_exists( 'Akibara_WD_Coupon' ) ) {
        $email = (string) get_userdata( $user_id )->user_email;
        $code  = Akibara_WD_Coupon::build_code( $email );
        // Solo mostrar si el cupón existe en WC (fue generado/enviado)
        if ( $code && wc_get_coupon_id_by_code( $code ) ) {
            ?>
            <div class="akb-coupon-nudge" id="akb-coupon-nudge">
                <button class="akb-coupon-nudge__close" aria-label="Cerrar"
                        onclick="this.closest('.akb-coupon-nudge').style.display='none'">×</button>
                <span class="akb-coupon-nudge__icon">🎁</span>
                <span class="akb-coupon-nudge__text">
                    Tienes un descuento de bienvenida. Usa el código
                    <strong class="akb-coupon-nudge__code"><?php echo esc_html( $code ); ?></strong>
                    al pagar.
                </span>
            </div>
            <?php
            return;
        }
    }

    // Guest o sin cupón asignado: nudge genérico
    ?>
    <div class="akb-coupon-nudge" id="akb-coupon-nudge">
        <button class="akb-coupon-nudge__close" aria-label="Cerrar"
                onclick="this.closest('.akb-coupon-nudge').style.display='none'">×</button>
        <span class="akb-coupon-nudge__icon">🏷️</span>
        <span class="akb-coupon-nudge__text">¿Tienes un código de descuento? Ingrésalo al final del formulario.</span>
    </div>
    <?php
}, 10 );

// ── 6. "Guardar para después" en cada ítem del carrito ───────────────────────

add_action( 'woocommerce_after_cart_item_name', function ( array $cart_item ) {
    $pid = (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] );
    ?>
    <div class="akb-save-later">
        <button class="js-wishlist akb-save-later__btn"
                data-product-id="<?php echo esc_attr( (string) $pid ); ?>"
                aria-label="Guardar para después"
                title="Guardar para después">
            <svg class="akb-save-later__icon" width="14" height="14" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <span class="akb-save-later__text">Guardar para después</span>
        </button>
    </div>
    <?php
}, 10, 1 );

// ── 7. Mobile sticky CTA ──────────────────────────────────────────────────────

function akibara_cart_sticky_html(): string {
    if ( ! WC()->cart || WC()->cart->is_empty() ) return '';

    $total = WC()->cart->get_cart_total();

    return '<div class="akb-cart-sticky">'
        . '<div class="akb-cart-sticky__inner">'
        . '<div class="akb-cart-sticky__total">'
        . '<span class="akb-cart-sticky__label">Total</span>'
        . '<span class="akb-cart-sticky__amount">' . wp_kses_post( $total ) . '</span>'
        . '</div>'
        . '<a href="' . esc_url( wc_get_checkout_url() ) . '" class="akb-cart-sticky__cta btn btn--primary">'
        . 'Confirmar y pagar'
        . '</a>'
        . '</div>'
        . '</div>';
}

add_action( 'woocommerce_after_cart', function () {
    echo akibara_cart_sticky_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} );
