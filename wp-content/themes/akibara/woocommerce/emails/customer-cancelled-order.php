<?php
/**
 * Customer Cancelled Order Email — Akibara Override
 *
 * Tono empático: lo lamentamos, CTA a volver, WA disponible.
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
		return 'Tu pedido #' . $order->get_order_number() . ' fue cancelado — seguimos acá si quieres volver.';
	}
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p>
<?php
$first_name = $order->get_billing_first_name();
if ( $first_name ) {
	echo 'Hola, <strong>' . esc_html( $first_name ) . '</strong>:';
} else {
	echo 'Hola:';
}
?>
</p>

<p>Tu pedido <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> fue cancelado. Lo lamentamos — si fue un error o quieres retomarlo, estamos a un mensaje de distancia.</p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
$wa_url = function_exists( 'akibara_wa_url' ) ? akibara_wa_url() : home_url( '/contacto/' );
?>
<p style="font-size:13px; color:#888888; margin:16px 0 8px;">
	¿Quieres saber qué pasó o rehacer el pedido? <a href="<?php echo esc_url( $wa_url ); ?>" style="color:#25D366;">Escríbenos por WhatsApp</a> — lo resolvemos al instante.
</p>

<!-- CTA volver a la tienda -->
<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin:20px 0;">
	<tr>
		<td align="center">
			<!--[if mso]>
			<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
			  style="height:48px;v-text-anchor:middle;width:240px" arcsize="8%"
			  strokecolor="#D90010" strokeweight="2px" filled="f">
			  <center style="color:#D90010;font-family:Arial;font-size:16px;font-weight:700">Volver a la tienda</center>
			</v:roundrect>
			<![endif]-->
			<!--[if !mso]><!-->
			<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
			   style="display:inline-block;background:transparent;color:#D90010;text-decoration:none;
			          padding:14px 36px;font-family:Impact,'Arial Black',sans-serif;font-size:16px;
			          text-transform:uppercase;letter-spacing:0.1em;border:2px solid #D90010;
			          border-radius:6px;font-weight:700;box-shadow:0 0 16px rgba(217,0,16,0.3);">
				Volver a la tienda
			</a>
			<!--<![endif]-->
		</td>
	</tr>
</table>

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
