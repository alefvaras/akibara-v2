<?php
/**
 * Customer On-Hold Order Email — Akibara Override
 *
 * Pedido en espera de confirmación de pago. Tono tranquilizador:
 * el pedido está guardado, estamos verificando el pago.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 10.4.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

add_filter(
	'akibara_email_preheader',
	static function () use ( $order ): string {
		return 'Pedido #' . $order->get_order_number() . ' recibido — verificando tu pago.';
	}
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p>
<?php
$first_name = $order->get_billing_first_name();
if ( $first_name ) {
	echo '¡Hola, <strong>' . esc_html( $first_name ) . '</strong>!';
} else {
	echo '¡Hola!';
}
?>
</p>

<p>Recibimos tu pedido <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> y lo tenemos guardado. Estamos verificando la confirmación del pago — en cuanto esté ok te avisamos y comenzamos a prepararlo.</p>

<?php if ( $email_improvements_enabled ) : ?>
	<p>¿Tienes alguna duda? Escríbenos por WhatsApp y te ayudamos al instante.</p>
<?php endif; ?>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<!-- Métodos de pago / instrucciones -->
<?php
$wa_url = function_exists( 'akibara_wa_url' ) ? akibara_wa_url() : home_url( '/contacto/' );
?>
<p style="font-size:13px; color:#888888; margin:0 0 8px;">
	¿Necesitas ayuda con tu pago? <a href="<?php echo esc_url( $wa_url ); ?>" style="color:#25D366;">Escríbenos por WhatsApp</a> y lo resolvemos juntos.
</p>

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
