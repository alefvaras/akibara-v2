<?php
/**
 * Footer minimalista para el flujo de checkout.
 *
 * Suprime columnas de navegación, newsletter, bottom-nav mobile y scroll-top
 * floating para evitar fugas durante la conversión. Solo conserva: copyright
 * y links legales mínimos (Términos, Privacidad, Contacto) según GDPR/ley
 * chilena de e-commerce.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;
?>
<footer class="akb-checkout-footer" role="contentinfo">
    <div class="akb-checkout-footer__inner">
        <ul class="akb-checkout-footer__links">
            <li><a href="<?php echo esc_url( home_url( '/terminos-y-condiciones/' ) ); ?>">Términos y condiciones</a></li>
            <li><a href="<?php echo esc_url( home_url( '/preguntas-frecuentes/' ) ); ?>">Preguntas frecuentes</a></li>
            <li><a href="<?php echo esc_url( home_url( '/contacto/' ) ); ?>">Contacto</a></li>
        </ul>
        <div class="akb-checkout-footer__copyright">
            <span>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></span>
            <span class="akb-checkout-footer__trust">Pago seguro · Datos cifrados SSL</span>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
