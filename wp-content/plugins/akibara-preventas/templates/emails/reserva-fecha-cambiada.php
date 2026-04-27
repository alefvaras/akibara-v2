<?php
/**
 * Template: Fecha de reserva cambiada.
 * Migrado a AkibaraEmailTemplate helpers (2026-04-19).
 * Approach MEDIUM: info neutral pero destaca visualmente las fechas old → new.
 *
 * Variables: $order, $email_heading, $email, $old_fecha, $new_fecha, $product_id
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$product = wc_get_product( $product_id );
$product_name = $product ? $product->get_name() : 'Producto';
$first_name   = $order->get_billing_first_name() ?: $order->get_formatted_billing_full_name();
$order_url    = $order->get_view_order_url();
$order_num    = $order->get_order_number();
$wa_url       = akb_reserva_whatsapp_url( 'Hola, consulta sobre el cambio de fecha de mi reserva #' . $order_num );
$has_template = class_exists( 'AkibaraEmailTemplate' );

if ( $has_template ) {
	echo AkibaraEmailTemplate::headline( '📅 Nueva fecha estimada' );
	echo AkibaraEmailTemplate::paragraph( 'Hola <strong>' . esc_html( $first_name ) . '</strong>, la fecha estimada de disponibilidad de <strong style="color:' . AkibaraEmailTemplate::TEXT_PRIMARY . '">' . esc_html( $product_name ) . '</strong> ha cambiado:' );

	// Date comparison box
	echo '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0"><tr><td>';
	echo '<div style="background:' . AkibaraEmailTemplate::BG_CARD . ';border:1px solid ' . AkibaraEmailTemplate::BORDER . ';border-radius:8px;padding:20px;text-align:center">';
	echo '<div style="color:' . AkibaraEmailTemplate::TEXT_MUTED . ';font-size:12px;text-transform:uppercase;letter-spacing:0.1em;margin:0 0 4px">Fecha anterior</div>';
	echo '<div style="color:' . AkibaraEmailTemplate::TEXT_SECONDARY . ';font-size:16px;text-decoration:line-through;margin:0 0 16px">' . esc_html( akb_reserva_fecha( $old_fecha ) ) . '</div>';
	echo '<div style="color:' . AkibaraEmailTemplate::ACCENT . ';font-size:12px;text-transform:uppercase;letter-spacing:0.1em;margin:0 0 4px;font-weight:600">Nueva fecha</div>';
	echo '<div style="color:' . AkibaraEmailTemplate::TEXT_PRIMARY . ';font-size:22px;font-weight:700">' . esc_html( akb_reserva_fecha( $new_fecha ) ) . '</div>';
	echo '</div>';
	echo '</td></tr></table>';

	echo AkibaraEmailTemplate::paragraph( 'Pedimos disculpas por el inconveniente. Tu reserva sigue activa y no tienes que hacer nada — te avisaremos apenas esté disponible.' );
	echo AkibaraEmailTemplate::cta( 'Ver mi pedido #' . $order_num, $order_url, 'reserva-fecha-cambiada' );

	if ( $wa_url ) {
		echo AkibaraEmailTemplate::paragraph(
			'<a href="' . esc_url( $wa_url ) . '" style="color:' . AkibaraEmailTemplate::ACCENT . ';text-decoration:none;font-weight:600">💬 Consultar por WhatsApp</a>',
			'center'
		);
	}
} else {
	// Fallback ?>
	<p>Hola <?php echo esc_html( $first_name ); ?>,</p>
	<p>La fecha estimada de <strong><?php echo esc_html( $product_name ); ?></strong> cambió:</p>
	<p>Antes: <?php echo esc_html( akb_reserva_fecha( $old_fecha ) ); ?><br>
	Ahora: <strong><?php echo esc_html( akb_reserva_fecha( $new_fecha ) ); ?></strong></p>
	<p><a href="<?php echo esc_url( $order_url ); ?>">Ver pedido #<?php echo esc_html( $order_num ); ?></a></p>
	<?php if ( $wa_url ) : ?>
		<p><a href="<?php echo esc_url( $wa_url ); ?>">WhatsApp</a></p>
	<?php endif;
}

do_action( 'woocommerce_email_footer', $email );
