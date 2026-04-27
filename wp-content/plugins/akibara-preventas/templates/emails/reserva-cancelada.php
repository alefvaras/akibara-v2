<?php
/**
 * Template: Reserva cancelada.
 * Migrado a AkibaraEmailTemplate helpers (2026-04-19).
 * Approach SIMPLE: mala noticia — breve, empatético, foco en contacto.
 *
 * Variables: $order, $email_heading, $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$first_name  = $order->get_billing_first_name() ?: $order->get_formatted_billing_full_name();
$order_url   = $order->get_view_order_url();
$order_num   = $order->get_order_number();
$wa_url      = akb_reserva_whatsapp_url( 'Hola, tengo una consulta sobre la cancelacion de mi reserva #' . $order_num );
$has_template = class_exists( 'AkibaraEmailTemplate' );

if ( $has_template ) {
	echo AkibaraEmailTemplate::headline( 'Reserva cancelada' );
	echo AkibaraEmailTemplate::paragraph( 'Hola <strong>' . esc_html( $first_name ) . '</strong>, lamentamos informarte que tu reserva <strong>#' . esc_html( $order_num ) . '</strong> ha sido cancelada.' );
	echo AkibaraEmailTemplate::paragraph( 'Si esto fue un error o necesitas más información, estamos para ayudarte. Responderemos por el canal que prefieras.' );

	if ( $wa_url ) {
		echo AkibaraEmailTemplate::cta( '💬 Contactar por WhatsApp', $wa_url, 'reserva-cancelada-wa' );
	} else {
		echo AkibaraEmailTemplate::cta( 'Ver mi pedido', $order_url, 'reserva-cancelada' );
	}
} else {
	// Fallback ?>
	<p>Hola <?php echo esc_html( $first_name ); ?>,</p>
	<p>Lamentamos informarte que tu reserva ha sido cancelada.</p>
	<p>Pedido: <a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $order_num ); ?></a></p>
	<?php if ( $wa_url ) : ?>
		<p><a href="<?php echo esc_url( $wa_url ); ?>">Contactar por WhatsApp</a></p>
	<?php endif;
}

do_action( 'woocommerce_email_footer', $email );
