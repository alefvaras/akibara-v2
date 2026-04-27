<?php
/**
 * Customer Refunded Order Email — Akibara Override
 *
 * Tono: empático y transparente. Confirma el reembolso, informa el timeframe
 * bancario (5-10 días hábiles en Chile) y deja CTA a WhatsApp.
 *
 * Override de woocommerce/templates/emails/customer-refunded-order.php v10.4.0.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 10.4.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

// Preheader: confirma inmediatamente que el reembolso está en marcha.
add_filter(
	'akibara_email_preheader',
	static function () use ( $order ): string {
		return 'Reembolso pedido #' . $order->get_order_number() . ' en proceso — llega en 5-10 días hábiles.';
	}
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p>
<?php
$first_name = ! empty( $order->get_billing_first_name() ) ? $order->get_billing_first_name() : '';
if ( $first_name ) {
	printf( esc_html__( 'Hola, %s:', 'akibara' ), esc_html( $first_name ) );
} else {
	esc_html_e( 'Hola,', 'akibara' );
}
?>
</p>

<p>
<?php
printf(
	/* translators: %s: número de orden */
	esc_html__( 'El reembolso de tu pedido #%s fue procesado. Lamentamos que no haya salido como esperabas.', 'akibara' ),
	esc_html( $order->get_order_number() )
);
?>
</p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<!-- Timeline de reembolso — expectativas claras sobre plazos bancarios -->
<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"
	   style="margin:24px 0; border-left:3px solid #D90010;">
	<tr>
		<td style="padding:12px 16px;">
			<p style="margin:0 0 4px; font-family:'Helvetica Neue', Arial, sans-serif; font-size:13px; font-weight:bold; color:#D90010; text-transform:uppercase; letter-spacing:0.05em;">
				<?php esc_html_e( '¿Cuándo llega el dinero?', 'akibara' ); ?>
			</p>
			<p style="margin:10px 0 0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:13px; line-height:1.5; color:#C0C0C0;">
				<span style="color:#4CAF50; font-weight:bold; margin-right:6px;">✓</span>
				<strong style="color:#E0E0E0;"><?php esc_html_e( 'Reembolso procesado por Akibara', 'akibara' ); ?></strong>
				&mdash; <?php esc_html_e( 'ya está en manos de tu banco o de Mercado Pago.', 'akibara' ); ?>
			</p>
			<p style="margin:10px 0 0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:13px; line-height:1.5; color:#C0C0C0;">
				<span style="color:#666; font-weight:bold; margin-right:6px;">↓</span>
				<strong style="color:#E0E0E0;"><?php esc_html_e( 'Acreditación en tu cuenta', 'akibara' ); ?></strong>
				&mdash; <?php esc_html_e( 'entre 5 y 10 días hábiles según tu banco. Es el tiempo estándar en Chile, no depende de nosotros.', 'akibara' ); ?>
			</p>
			<p style="margin:10px 0 0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:12px; line-height:1.5; color:#888888;">
				<?php esc_html_e( 'Mercado Pago y transferencias suelen ser más rápidos (1-3 días hábiles).', 'akibara' ); ?>
			</p>
		</td>
	</tr>
</table>

<?php
$wa_url = function_exists( 'akibara_wa_url' ) ? akibara_wa_url() : home_url( '/contacto/' );
?>

<p style="font-size:13px; color:#888888; margin:0 0 8px;">
	<?php
	printf(
		wp_kses(
			/* translators: %s: URL de WhatsApp */
			__( '¿Pasaron más de 10 días y no ves el reembolso? <a href="%s" style="color:#25D366;">Escríbenos por WhatsApp</a> y lo revisamos contigo.', 'akibara' ),
			[ 'a' => [ 'href' => [], 'style' => [] ] ]
		),
		esc_url( $wa_url )
	);
	?>
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
