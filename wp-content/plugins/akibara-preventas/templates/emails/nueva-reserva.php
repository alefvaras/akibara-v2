<?php
/**
 * Template: Nueva reserva (al admin).
 * Migrado a AkibaraEmailTemplate helpers (2026-04-19).
 * Approach SIMPLE ADMIN: notificación interna al staff. Prioriza info accionable
 * (tabla scan-friendly) sobre visual branding. Admin mails ≠ customer mails.
 *
 * Variables: $order, $email_heading, $email
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$order_num    = $order->get_order_number();
$cliente      = $order->get_formatted_billing_full_name();
$cliente_email = $order->get_billing_email();
$edit_url     = $order->get_edit_order_url();
$panel_url    = admin_url( 'admin.php?page=akb-reservas' );
$has_template = class_exists( 'AkibaraEmailTemplate' );

if ( $has_template ) {
	echo AkibaraEmailTemplate::headline( '🔔 Nueva reserva recibida' );
	echo AkibaraEmailTemplate::paragraph(
		'<strong>Pedido #' . esc_html( $order_num ) . '</strong> · ' .
		esc_html( $cliente ) . ' (' . esc_html( $cliente_email ) . ')'
	);
}

// Tabla accionable para admin — scan rápido, no cards visuales
?>
<table cellspacing="0" cellpadding="8" style="width:100%;border-collapse:collapse;margin:16px 0;background:<?php echo $has_template ? AkibaraEmailTemplate::BG_CARD : '#fff'; ?>;border:1px solid <?php echo $has_template ? AkibaraEmailTemplate::BORDER : '#ddd'; ?>;border-radius:6px;overflow:hidden">
	<thead>
		<tr style="background:<?php echo $has_template ? AkibaraEmailTemplate::BG_CARD_ALT : '#f5f5f5'; ?>">
			<th style="text-align:left;padding:10px;color:<?php echo $has_template ? AkibaraEmailTemplate::TEXT_PRIMARY : '#333'; ?>;font-size:13px;text-transform:uppercase;letter-spacing:0.05em">Producto</th>
			<th style="text-align:left;padding:10px;color:<?php echo $has_template ? AkibaraEmailTemplate::TEXT_PRIMARY : '#333'; ?>;font-size:13px;text-transform:uppercase;letter-spacing:0.05em">Tipo</th>
			<th style="text-align:left;padding:10px;color:<?php echo $has_template ? AkibaraEmailTemplate::TEXT_PRIMARY : '#333'; ?>;font-size:13px;text-transform:uppercase;letter-spacing:0.05em">Cant.</th>
			<th style="text-align:left;padding:10px;color:<?php echo $has_template ? AkibaraEmailTemplate::TEXT_PRIMARY : '#333'; ?>;font-size:13px;text-transform:uppercase;letter-spacing:0.05em">Fecha</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $order->get_items() as $item ) :
			if ( 'yes' !== $item->get_meta( '_akb_item_reserva' ) ) continue;
			$fecha = (int) $item->get_meta( '_akb_item_fecha_estimada' );
		?>
		<tr>
			<td style="padding:10px;border-top:1px solid <?php echo $has_template ? AkibaraEmailTemplate::BORDER : '#eee'; ?>;color:<?php echo $has_template ? AkibaraEmailTemplate::TEXT_SECONDARY : '#555'; ?>;font-size:14px"><?php echo esc_html( $item->get_name() ); ?></td>
			<td style="padding:10px;border-top:1px solid <?php echo $has_template ? AkibaraEmailTemplate::BORDER : '#eee'; ?>;color:<?php echo $has_template ? AkibaraEmailTemplate::TEXT_SECONDARY : '#555'; ?>;font-size:14px">PREVENTA</td>
			<td style="padding:10px;border-top:1px solid <?php echo $has_template ? AkibaraEmailTemplate::BORDER : '#eee'; ?>;color:<?php echo $has_template ? AkibaraEmailTemplate::TEXT_SECONDARY : '#555'; ?>;font-size:14px"><?php echo esc_html( $item->get_quantity() ); ?></td>
			<td style="padding:10px;border-top:1px solid <?php echo $has_template ? AkibaraEmailTemplate::BORDER : '#eee'; ?>;color:<?php echo $has_template ? AkibaraEmailTemplate::TEXT_SECONDARY : '#555'; ?>;font-size:14px"><?php echo esc_html( $fecha > 0 ? akb_reserva_fecha( $fecha ) : 'Sin fecha' ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php

if ( $has_template ) {
	echo AkibaraEmailTemplate::cta( 'Ver panel de reservas', $panel_url, 'nueva-reserva-admin' );
} else {
	echo '<p><a href="' . esc_url( $panel_url ) . '">Ver panel de reservas</a></p>';
}

do_action( 'woocommerce_email_footer', $email );
