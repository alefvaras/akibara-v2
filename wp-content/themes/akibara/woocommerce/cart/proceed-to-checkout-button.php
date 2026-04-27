<?php
/**
 * Proceed to Checkout Button — Akibara Override
 *
 * Reemplaza "Proceed to checkout" por CTA en español y agrega link
 * "Seguir comprando" para no dejar al usuario sin salida.
 *
 * Override de woocommerce/templates/cart/proceed-to-checkout-button.php v7.0.1.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 7.0.1
 */

defined( 'ABSPATH' ) || exit;
?>

<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>"
   class="checkout-button button alt wc-forward<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>">
	<?php esc_html_e( 'Confirmar y pagar', 'akibara' ); ?>
</a>

<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>"
   class="akb-cart-continue-shopping">
	&larr; <?php esc_html_e( 'Seguir comprando', 'akibara' ); ?>
</a>
