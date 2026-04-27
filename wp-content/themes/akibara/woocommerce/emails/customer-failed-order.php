<?php
/**
 * Customer Failed Order Email — Akibara Override
 *
 * Email enviado al cliente cuando el pago falla. Reemplaza el template genérico
 * de WooCommerce (en inglés) por copy empático con instrucciones claras de
 * qué hacer a continuación y CTA para reintentar.
 *
 * Override de woocommerce/templates/emails/customer-failed-order.php v10.4.0.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 10.4.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

// Preheader: tranquiliza al cliente de entrada — el pedido no se perdió.
add_filter(
    'akibara_email_preheader',
    static function (): string {
        return 'No perdiste nada — tu pedido sigue guardado. Reintentar es fácil.';
    }
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p>
<?php
$first_name = ! empty( $order->get_billing_first_name() ) ? $order->get_billing_first_name() : '';
if ( $first_name ) {
    /* translators: %s: nombre del cliente */
    printf( esc_html__( 'Hola, %s:', 'akibara' ), esc_html( $first_name ) );
} else {
    esc_html_e( 'Hola,', 'akibara' );
}
?>
</p>

<p><?php esc_html_e( 'Algo no salió bien con el pago de tu pedido — pero tranquilo, no perdiste nada.', 'akibara' ); ?></p>

<p><?php esc_html_e( 'Tu pedido quedó guardado. Puedes intentar de nuevo con:', 'akibara' ); ?></p>

<ul style="margin:0 0 16px 0; padding-left:20px; font-family:'Helvetica Neue', Arial, sans-serif; font-size:14px; line-height:1.7; color:#C0C0C0;">
    <li><?php esc_html_e( 'La misma tarjeta (a veces es un error temporal del banco)', 'akibara' ); ?></li>
    <li><?php esc_html_e( 'Otra tarjeta de crédito o débito', 'akibara' ); ?></li>
    <li><?php esc_html_e( 'Mercado Pago o transferencia bancaria', 'akibara' ); ?></li>
</ul>

<?php
// CTA para reintentar el pago directamente.
$pay_url = $order->get_checkout_payment_url();
if ( $pay_url ) :
?>
<p style="text-align:center; margin:24px 0;">
    <a href="<?php echo esc_url( $pay_url ); ?>"
       style="display:inline-block; background:#D90010; color:#ffffff; font-family:'Helvetica Neue', Arial, sans-serif; font-size:15px; font-weight:bold; text-decoration:none; padding:14px 32px; border-radius:4px;">
        <?php esc_html_e( 'Reintentar pago →', 'akibara' ); ?>
    </a>
</p>
<?php endif; ?>

<p style="font-size:13px; color:#888888;">
    <?php
    printf(
        /* translators: %s: URL de WhatsApp */
        wp_kses(
            __( '¿Sigues con problemas? <a href="%s" style="color:#25D366;">Escríbenos por WhatsApp</a> y lo resolvemos al instante.', 'akibara' ),
            [ 'a' => [ 'href' => [], 'style' => [] ] ]
        ),
        esc_url( function_exists( 'akibara_wa_url' ) ? akibara_wa_url() : home_url( '/contacto/' ) )
    );
    ?>
</p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
    echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
    echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
