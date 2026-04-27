<?php
/**
 * Template: Reserva lista / producto disponible.
 * Migrado a AkibaraEmailTemplate helpers (2026-04-19).
 * Approach CELEBRATION: "¡tu manga llegó!" — emoción + CTA prominente para coordinar.
 *
 * Variables: $order, $email_heading, $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$first_name  = $order->get_billing_first_name() ?: $order->get_formatted_billing_full_name();
$order_url   = $order->get_view_order_url();
$order_num   = $order->get_order_number();
$wa_url      = akb_reserva_whatsapp_url( 'Hola, mi reserva #' . $order_num . ' ya esta lista, quiero coordinar el despacho.' );
$has_template = class_exists( 'AkibaraEmailTemplate' );

if ( $has_template ) {
	echo AkibaraEmailTemplate::headline( '📦 ¡Tu manga llegó!' );
	echo AkibaraEmailTemplate::paragraph( 'Hola <strong>' . esc_html( $first_name ) . '</strong>, la espera terminó. Estos productos de tu reserva ya están disponibles y listos para despacho:' );

	// Product cards
	foreach ( $order->get_items() as $item ) {
		if ( 'yes' !== $item->get_meta( '_akb_item_reserva' ) ) continue;
		$product = $item->get_product();
		if ( ! $product ) continue;
		$img_id = $product->get_image_id();
		$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';

		echo AkibaraEmailTemplate::product_card( [
			'name'  => $item->get_name(),
			'image' => $img_url,
			'url'   => $product->get_permalink(),
			'qty'   => (int) $item->get_quantity(),
			'price' => '',
		], 'cart' );
	}

	echo AkibaraEmailTemplate::urgency( 'Despachamos en 1-2 días hábiles' );
	echo AkibaraEmailTemplate::cta( 'Ver mi pedido #' . $order_num, $order_url, 'reserva-lista' );

	if ( $wa_url ) {
		echo AkibaraEmailTemplate::paragraph(
			'<a href="' . esc_url( $wa_url ) . '" style="color:' . AkibaraEmailTemplate::ACCENT . ';text-decoration:none;font-weight:600">💬 Coordinar despacho por WhatsApp</a>',
			'center'
		);
	}
} else {
	// Fallback ?>
	<p>Hola <?php echo esc_html( $first_name ); ?>,</p>
	<p><strong>Tu manga ya está disponible.</strong></p>
	<p><a href="<?php echo esc_url( $order_url ); ?>">Ver pedido #<?php echo esc_html( $order_num ); ?></a></p>
	<?php if ( $wa_url ) : ?>
		<p><a href="<?php echo esc_url( $wa_url ); ?>">Coordinar por WhatsApp</a></p>
	<?php endif;
}

do_action( 'woocommerce_email_footer', $email );
