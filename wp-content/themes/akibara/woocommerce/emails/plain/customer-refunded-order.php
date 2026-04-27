<?php
/**
 * Customer Refunded Order Email — Plain Text (Akibara Override)
 *
 * @package Akibara
 * @version 10.4.0
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . wp_strip_all_tags( $email_heading ) . " =\n\n";

$first_name = ! empty( $order->get_billing_first_name() ) ? $order->get_billing_first_name() : '';
if ( $first_name ) {
	echo sprintf( esc_html__( 'Hola, %s:', 'akibara' ), esc_html( $first_name ) ) . "\n\n";
} else {
	echo esc_html__( 'Hola,', 'akibara' ) . "\n\n";
}

echo sprintf(
	/* translators: %s: número de orden */
	esc_html__( 'El reembolso de tu pedido #%s fue procesado. Lamentamos que no haya salido como esperabas.', 'akibara' ),
	esc_html( $order->get_order_number() )
) . "\n\n";

echo "---\n\n";
echo esc_html__( '¿Cuándo llega el dinero?', 'akibara' ) . "\n";
echo esc_html__( '✓ Reembolso procesado por Akibara — ya está en manos de tu banco o Mercado Pago.', 'akibara' ) . "\n";
echo esc_html__( '↓ Acreditación en tu cuenta: entre 5 y 10 días hábiles según tu banco.', 'akibara' ) . "\n";
echo esc_html__( '  Mercado Pago y transferencias suelen ser más rápidos (1-3 días hábiles).', 'akibara' ) . "\n\n";

echo "---\n\n";
echo esc_html__( '¿Pasaron más de 10 días y no ves el reembolso? Escríbenos por WhatsApp:', 'akibara' ) . "\n";
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
