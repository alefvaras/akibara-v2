<?php
/**
 * Template: Reserva confirmada.
 * Migrado a AkibaraEmailTemplate helpers (2026-04-19).
 * Approach RICH: el cliente acaba de reservar — refuerza confianza con product cards visuales + CTA.
 *
 * Variables: $order, $email_heading, $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$first_name  = $order->get_billing_first_name() ?: $order->get_formatted_billing_full_name();
$order_url   = $order->get_view_order_url();
$order_num   = $order->get_order_number();
$wa_url      = akb_reserva_whatsapp_url( 'Hola, consulta sobre mi reserva #' . $order_num );
$has_template = class_exists( 'AkibaraEmailTemplate' );

if ( $has_template ) {
	echo AkibaraEmailTemplate::headline( '✅ ¡Reserva confirmada!' );
	echo AkibaraEmailTemplate::paragraph( 'Hola <strong>' . esc_html( $first_name ) . '</strong>, gracias por reservar con nosotros. Aquí están los detalles:' );

	// Product cards rich con imagen + detalles de reserva
	foreach ( $order->get_items() as $item ) {
		if ( 'yes' !== $item->get_meta( '_akb_item_reserva' ) ) continue;
		$product = $item->get_product();
		if ( ! $product ) continue;
		$fecha = (int) $item->get_meta( '_akb_item_fecha_estimada' );
		$img_id = $product->get_image_id();
		$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';

		echo AkibaraEmailTemplate::product_card( [
			'name'  => $item->get_name() . ' x' . $item->get_quantity(),
			'image' => $img_url,
			'url'   => $product->get_permalink(),
			'price' => 'PREVENTA · ' . esc_html( $fecha > 0 ? akb_reserva_fecha( $fecha ) : 'Por confirmar' ),
		], 'cart' );
	}

	echo AkibaraEmailTemplate::paragraph( 'Te avisaremos por email cuando tu pedido esté listo para el despacho. Mientras tanto, puedes consultarnos cualquier duda por WhatsApp.' );
	echo AkibaraEmailTemplate::cta( 'Ver mi pedido #' . $order_num, $order_url, 'reserva-confirmada' );

	if ( $wa_url ) {
		echo AkibaraEmailTemplate::paragraph(
			'<a href="' . esc_url( $wa_url ) . '" style="color:' . AkibaraEmailTemplate::ACCENT . ';text-decoration:none;font-weight:600">💬 Consultar por WhatsApp</a>',
			'center'
		);
	}
} else {
	// Fallback si AkibaraEmailTemplate no disponible ?>
	<p>Hola <?php echo esc_html( $first_name ); ?>,</p>
	<p>Tu reserva ha sido confirmada.</p>
	<p>Pedido: <a href="<?php echo esc_url( $order_url ); ?>">#<?php echo esc_html( $order_num ); ?></a></p>
	<?php if ( $wa_url ) : ?>
		<p><a href="<?php echo esc_url( $wa_url ); ?>">Consultar por WhatsApp</a></p>
	<?php endif;
}

do_action( 'woocommerce_email_footer', $email );
