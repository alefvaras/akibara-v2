<?php
/**
 * Single Product — Tabs partial
 *
 * Tabs nativos WooCommerce: Description, Additional Info, Reviews.
 * Usa el filter 'woocommerce_product_tabs' para mantener compatibilidad
 * con plugins que agregan tabs (ej: Rank Math, reviews enhancers).
 *
 * @package Akibara
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

$product_tabs = apply_filters( 'woocommerce_product_tabs', [] );
if ( empty( $product_tabs ) ) return;
?>
<div class="product-tabs">
    <div class="product-tabs__nav">
        <?php $first = true; foreach ( $product_tabs as $key => $tab ) : ?>
            <button class="product-tabs__tab <?php echo $first ? 'product-tabs__tab--active' : ''; ?>" data-tab="<?php echo esc_attr( $key ); ?>">
                <?php echo esc_html( $tab['title'] ); ?>
            </button>
        <?php $first = false; endforeach; ?>
    </div>
    <div class="product-tabs__content">
        <?php $first = true; foreach ( $product_tabs as $key => $tab ) : ?>
            <div class="product-tabs__panel <?php echo $first ? 'product-tabs__panel--active' : ''; ?>" id="tab-<?php echo esc_attr( $key ); ?>">
                <?php if ( isset( $tab['callback'] ) ) call_user_func( $tab['callback'], $key, $tab ); ?>
            </div>
        <?php $first = false; endforeach; ?>
    </div>
</div>
