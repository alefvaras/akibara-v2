<?php
/**
 * Mini Cart Content
 *
 * @package Akibara
 */

defined('ABSPATH') || exit;

if (!function_exists('WC') || !WC()->cart) return;

$cart_items = WC()->cart->get_cart();

if (empty($cart_items)) : ?>
    <div class="cart-empty">
        <svg aria-hidden="true" focusable="false" class="cart-empty__icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        <p class="cart-empty__title">Tu carrito está vacío</p>
        <p class="cart-empty__desc">Explora nuestro catálogo y encuentra tu próximo manga</p>
        <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="btn btn--primary btn--sm">
            <span>Ver Catálogo</span>
        </a>
    </div>
<?php else : ?>
    <!-- .ciq styles moved to woocommerce.css -->

    <?php if (function_exists('akibara_shipping_progress_bar')) akibara_shipping_progress_bar(); ?>

    <?php foreach ($cart_items as $cart_item_key => $cart_item) :
        $product    = $cart_item['data'];
        $product_id = $cart_item['product_id'];
        $quantity   = $cart_item['quantity'];
        $max_qty    = $product->get_stock_quantity() ?? 99;
    ?>
        <div class="cart-item" data-cart-key="<?php echo esc_attr($cart_item_key); ?>">
            <div class="cart-item__image">
                <?php echo $product->get_image('thumbnail'); ?>
            </div>
            <div class="cart-item__info">
                <span class="cart-item__name"><?php echo esc_html($product->get_name()); ?></span>
                <span class="cart-item__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
                <div class="cart-item__qty-row">
                    <button class="ciq js-cart-qty-minus"
                            data-cart-key="<?php echo esc_attr($cart_item_key); ?>"
                            data-qty="<?php echo esc_attr($quantity - 1); ?>"
                            aria-label="Reducir cantidad"
                            <?php echo $quantity <= 1 ? 'disabled' : ''; ?>>−</button>
                    <span class="cart-item__qty-num"><?php echo esc_html($quantity); ?></span>
                    <button class="ciq js-cart-qty-plus"
                            data-cart-key="<?php echo esc_attr($cart_item_key); ?>"
                            data-qty="<?php echo esc_attr($quantity + 1); ?>"
                            aria-label="Aumentar cantidad"
                            <?php echo ($quantity >= $max_qty) ? 'disabled' : ''; ?>>+</button>
                </div>
                <button class="cart-item__remove" data-cart-key="<?php echo esc_attr($cart_item_key); ?>">Eliminar</button>
            </div>
        </div>
    <?php endforeach;

    // ── Completa tu serie ──────────────────────────────────────
    $_cart_pids  = array_column( $cart_items, 'product_id' );
    $_suggestions = [];

    foreach ( $cart_items as $_ci ) {
        $_norm = get_post_meta( $_ci['product_id'], '_akibara_serie_norm', true );
        if ( ! $_norm ) continue;

        $_q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 4,
            'post__not_in'   => $_cart_pids,
            'meta_query'     => [
                [ 'key' => '_akibara_serie_norm', 'value' => $_norm ],
                [ 'key' => '_stock_status',       'value' => 'instock' ],
            ],
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ] );

        foreach ( $_q->posts as $_pid ) {
            if ( ! isset( $_suggestions[ $_pid ] ) ) {
                $_suggestions[ $_pid ] = wc_get_product( $_pid );
                if ( count( $_suggestions ) >= 3 ) break 2;
            }
        }
    }

    if ( ! empty( $_suggestions ) ) : ?>
    <div class="cart-complete-serie">
        <p class="cart-complete-serie__title">&#128218; Completa tu serie</p>
        <div class="cart-complete-serie__list">
            <?php foreach ( $_suggestions as $_sid => $_sp ) : ?>
            <div class="cart-complete-serie__item">
                <a href="<?php echo esc_url( get_permalink( $_sid ) ); ?>" class="cart-complete-serie__img">
                    <?php echo $_sp->get_image( 'thumbnail' ); ?>
                </a>
                <div class="cart-complete-serie__info">
                    <span class="cart-complete-serie__name"><?php echo esc_html( wp_trim_words( $_sp->get_name(), 5, '…' ) ); ?></span>
                    <span class="cart-complete-serie__price"><?php echo wp_kses_post( $_sp->get_price_html() ); ?></span>
                </div>
                <button class="js-quick-add cart-complete-serie__add"
                        data-product-id="<?php echo esc_attr( $_sid ); ?>"
                        aria-label="Agregar <?php echo esc_attr( $_sp->get_name() ); ?> al carrito"
                        title="Agregar al carrito">+</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif;

endif; ?>
