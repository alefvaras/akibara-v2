<?php
/**
 * Order received — heading personalizado Akibara.
 *
 * Reemplaza el genérico "Thank you. Your order has been received." por un
 * mensaje con nombre del cliente + confirmación emocional + próximos pasos
 * contextualizados al método de envío elegido (retiro San Miguel o courier).
 *
 * @see woocommerce/templates/checkout/order-received.php
 * @package Akibara
 *
 * @var WC_Order|false $order
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order ) {
	echo '<p class="woocommerce-notice woocommerce-notice--success woocommerce-thankyou-order-received">';
	echo esc_html__( '¡Gracias! Tu pedido ha sido recibido.', 'akibara' );
	echo '</p>';
	return;
}

$first_name = trim( (string) $order->get_billing_first_name() );
$greeting   = $first_name !== '' ? sprintf( '¡Gracias, %s!', esc_html( $first_name ) ) : '¡Gracias por tu compra!';

$shipping_methods = $order->get_shipping_methods();
$shipping_method_id = '';
if ( ! empty( $shipping_methods ) ) {
	$first_shipping = reset( $shipping_methods );
	$shipping_method_id = strtolower( (string) $first_shipping->get_method_id() );
}

// Next-step copy según método de envío.
$is_pickup = strpos( $shipping_method_id, 'local_pickup' ) === 0;
$is_pudo   = strpos( $shipping_method_id, 'bluex' ) === 0 && strpos( $shipping_method_id, 'pudo' ) !== false;

if ( $is_pickup ) {
	$next_step = 'Te contactaremos por WhatsApp en las próximas 24 horas para coordinar el retiro en Metro San Miguel.';
} elseif ( $is_pudo ) {
	$next_step = 'Recibirás un correo con el código de seguimiento y las instrucciones para retirar en tu punto Blue Express.';
} else {
	$next_step = 'Recibirás un correo con el código de seguimiento apenas despachemos tu pedido.';
}
?>

<div class="akb-thankyou-header">
	<h1 class="akb-thankyou-header__title">
		<span class="akb-thankyou-header__icon" aria-hidden="true">🎉</span>
		<?php echo esc_html( $greeting ); ?>
	</h1>
	<p class="akb-thankyou-header__sub">
		Tu pedido <strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong> ha sido confirmado.
	</p>
	<p class="akb-thankyou-header__next">
		<?php echo esc_html( $next_step ); ?>
	</p>
</div>
