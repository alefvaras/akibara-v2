<?php
/**
 * Customer Completed Order Email — Plain Text (Akibara Override)
 *
 * @package Akibara
 * @version 10.4.0
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . wp_strip_all_tags( $email_heading ) . " =\n\n";

$first_name = ! empty( $order->get_billing_first_name() ) ? $order->get_billing_first_name() : '';
if ( $first_name ) {
	/* translators: %s: nombre del cliente */
	echo sprintf( esc_html__( '¡Hola, %s!', 'akibara' ), esc_html( $first_name ) ) . "\n\n";
} else {
	echo esc_html__( '¡Hola!', 'akibara' ) . "\n\n";
}

echo esc_html__( 'Tu pedido fue completado — ¡ya llegó tu manga!', 'akibara' ) . "\n\n";
echo "---\n\n";

echo esc_html__( '¿Te gustó lo que llegó?', 'akibara' ) . "\n";
echo esc_html__( '- Deja tu reseña en la tienda — ayudas a otros lectores y nos apoyas harto.', 'akibara' ) . "\n";
echo esc_html__( '- Comparte tu haul en Instagram @akibara.cl', 'akibara' ) . "\n\n";

echo "---\n\n";

echo esc_html__( '¿Llegó algo mal? No hay drama — escríbenos por WhatsApp y lo solucionamos al instante.', 'akibara' ) . "\n";
if ( function_exists( 'akibara_wa_url' ) ) {
	echo esc_url( akibara_wa_url() ) . "\n\n";
}

echo "---\n\n";
echo esc_html__( 'Detalles de tu pedido:', 'akibara' ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo "\n---\n";
echo esc_html__( 'Equipo Akibara · tu distrito del manga y cómics', 'akibara' ) . "\n";
echo esc_url( home_url( '/' ) ) . "\n";
