<?php
/**
 * Page template: /checkout/
 *
 * WordPress lo carga automáticamente cuando el slug de la página es "checkout".
 * Usa header-checkout.php / footer-checkout.php (versiones minimalistas) para
 * el funnel de conversión. En la thank-you page (order-received) caemos al
 * header/footer normales porque allí las distracciones ya no perjudican.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

$akb_is_thankyou = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' );

if ( $akb_is_thankyou ) {
    // Thank-you: header/footer completo es OK (compra ya hecha, oportunidad de engagement).
    get_header();
} else {
    get_header( 'checkout' );
}
?>

<main class="site-content" id="main-content">
    <div class="akb-checkout-container">
        <?php
        while ( have_posts() ) :
            the_post();
            the_content();
        endwhile;
        ?>
    </div>
</main>

<?php if ( ! $akb_is_thankyou ) : ?>
<!-- Sticky mobile bar: total + CTA del paso actual (visible solo ≤768px vía CSS) -->
<div class="aki-mobile-sticky" id="akb-mobile-sticky" role="status" aria-live="polite">
    <div class="aki-mobile-sticky__total">
        <span class="aki-mobile-sticky__total-label">Total</span>
        <span class="aki-mobile-sticky__total-value" id="akb-mobile-sticky-total">
            <?php
            // wc_price() ya devuelve HTML seguro escapeado por WooCommerce.
            // wp_kses_post defensivo si WC no está listo en algún edge case.
            echo wp_kses_post( WC()->cart ? WC()->cart->get_total() : '—' );
            ?>
        </span>
    </div>
    <!-- #aki-co-sticky-btn: el JS de checkout-steps.js lo usa para abrir el drawer de pedido. -->
    <button type="button" class="aki-mobile-sticky__pedido-btn" id="aki-co-sticky-btn" aria-label="Ver resumen del pedido">
        Ver pedido
    </button>
    <button type="button" class="btn btn--primary" id="akb-mobile-sticky-cta" aria-label="Continuar con el paso actual">
        Continuar
    </button>
</div>
<?php
// El JS del sticky mobile se encola como archivo externo (cacheable).
// Ver: akibara_enqueue_checkout_sticky() en inc/woocommerce.php.
endif; ?>

<?php
if ( $akb_is_thankyou ) {
    get_footer();
} else {
    get_footer( 'checkout' );
}
