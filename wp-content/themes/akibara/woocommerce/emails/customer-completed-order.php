<?php
/**
 * Customer Completed Order Email — Akibara Override
 *
 * Tono: celebración. "¡Tu pedido llegó!" — invita a dejar review y seguir
 * en Instagram. CTA a WhatsApp si algo llegó mal.
 *
 * Override de woocommerce/templates/emails/customer-completed-order.php v10.4.0.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 10.4.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

// Preheader: celebración breve — primer texto visible en bandeja.
add_filter(
	'akibara_email_preheader',
	static function () use ( $order ): string {
		return '¡Pedido #' . $order->get_order_number() . ' completado! Esperamos que lo disfrutes harto.';
	}
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p>
<?php
$first_name = ! empty( $order->get_billing_first_name() ) ? $order->get_billing_first_name() : '';
if ( $first_name ) {
	printf( esc_html__( '¡Hola, %s!', 'akibara' ), esc_html( $first_name ) );
} else {
	esc_html_e( '¡Hola!', 'akibara' );
}
?>
</p>

<p><?php esc_html_e( 'Tu pedido fue completado — ¡ya llegó tu manga!', 'akibara' ); ?></p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
// ── Bloque de reseña — el momento post-entrega es el de mayor satisfacción ──
$shop_url = get_permalink( wc_get_page_id( 'shop' ) );
$wa_url   = function_exists( 'akibara_wa_url' ) ? akibara_wa_url() : home_url( '/contacto/' );

// Tomamos el primer item para proponer la reseña directamente.
$review_link = '';
foreach ( $order->get_items() as $item ) {
	$product = $item->get_product();
	if ( $product && $product->get_review_count() >= 0 ) {
		$review_link = get_permalink( $product->get_id() ) . '#reviews';
		break;
	}
}
?>

<!-- Invitación a reseña con CTA neon (mismo patrón que processing-order) -->
<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"
	   style="margin:24px 0; border-left:3px solid #D90010;">
	<tr>
		<td style="padding:12px 16px;">
			<p style="margin:0 0 4px; font-family:'Helvetica Neue', Arial, sans-serif; font-size:13px; font-weight:bold; color:#D90010; text-transform:uppercase; letter-spacing:0.05em;">
				<?php esc_html_e( '¿Te gustó lo que llegó?', 'akibara' ); ?>
			</p>
			<p style="margin:10px 0 0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:13px; line-height:1.5; color:#C0C0C0;">
				<span style="color:#4CAF50; font-weight:bold; margin-right:6px;">★</span>
				<strong style="color:#E0E0E0;"><?php esc_html_e( 'Deja tu reseña', 'akibara' ); ?></strong>
				&mdash; <?php esc_html_e( 'ayudas a otros lectores a elegir bien y nos apoyas harto.', 'akibara' ); ?>
			</p>
			<p style="margin:10px 0 0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:13px; line-height:1.5; color:#C0C0C0;">
				<span style="color:#666; font-weight:bold; margin-right:6px;">📸</span>
				<strong style="color:#E0E0E0;"><?php esc_html_e( 'Comparte tu haul', 'akibara' ); ?></strong>
				&mdash; <?php
				printf(
					wp_kses(
						/* translators: %s: URL de Instagram */
						__( 'etiquétanos en <a href="%s" style="color:#E1306C;">@akibara.cl</a> en Instagram.', 'akibara' ),
						[ 'a' => [ 'href' => [], 'style' => [] ] ]
					),
					'https://www.instagram.com/akibara.cl/'
				); ?>
			</p>
		</td>
	</tr>
</table>

<?php if ( $review_link ) : ?>
<p style="text-align:center; margin:0 0 24px;">
	<a href="<?php echo esc_url( $review_link ); ?>"
	   style="display:inline-block; background:#D90010; color:#ffffff; font-family:'Helvetica Neue', Arial, sans-serif; font-size:14px; font-weight:bold; text-decoration:none; padding:12px 28px; border-radius:4px;">
		<?php esc_html_e( 'Dejar reseña →', 'akibara' ); ?>
	</a>
</p>
<?php endif; ?>

<p style="font-size:13px; color:#888888; margin:0 0 24px;">
	<?php
	printf(
		wp_kses(
			/* translators: %s: URL de WhatsApp */
			__( '¿Llegó algo mal? No hay drama — <a href="%s" style="color:#25D366;">escríbenos por WhatsApp</a> y lo solucionamos al instante.', 'akibara' ),
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
