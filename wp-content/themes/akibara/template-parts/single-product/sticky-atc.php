<?php
/**
 * Single Product — Sticky Add-to-Cart (mobile) partial
 *
 * Barra fija bottom en mobile. Aparece cuando el usuario hace scroll
 * más allá del bloque principal de add-to-cart.
 *
 * Inherited from single-product.php: $product.
 *
 * @package Akibara
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

global $product;
$product_id = $product->get_id();
?>
<div class="sticky-add-to-cart" id="sticky-atc">
    <div class="sticky-add-to-cart__info">
        <div class="sticky-add-to-cart__title"><?php echo esc_html( get_the_title( $product_id ) ); ?></div>
        <div class="sticky-add-to-cart__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
    </div>
    <?php if ( $product->is_in_stock() && $product->is_purchasable() ) : ?>
        <button class="btn btn--primary btn--sm js-quick-add aki-speed-lines" data-product-id="<?php echo esc_attr( $product_id ); ?>">
            <span>Agregar</span>
        </button>
    <?php endif; ?>
</div>
