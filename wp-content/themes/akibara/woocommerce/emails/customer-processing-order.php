<?php
/**
 * Customer Processing Order Email — Akibara Override
 *
 * Email de confirmación de compra. Agrega sección "Próximos pasos" con
 * línea de tiempo contextualizada al método de envío elegido (igual que
 * la thank-you page), para reducir incertidumbre post-compra.
 *
 * Override de woocommerce/templates/emails/customer-processing-order.php v10.4.0.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 10.4.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

// Preheader: ~50 chars visibles en bandeja antes de abrir el email.
add_filter(
    'akibara_email_preheader',
    static function () use ( $order ): string {
        return 'Pedido #' . $order->get_order_number() . ' recibido — preparando tu despacho.';
    }
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p>
<?php
$first_name = ! empty( $order->get_billing_first_name() ) ? $order->get_billing_first_name() : '';
if ( $first_name ) {
    /* translators: %s: nombre del cliente */
    printf( esc_html__( '¡Hola, %s!', 'akibara' ), esc_html( $first_name ) );
} else {
    esc_html_e( '¡Hola!', 'akibara' );
}
?>
</p>

<p>
<?php
printf(
    /* translators: %s: número de orden */
    esc_html__( 'Recibimos tu pedido #%s y ya está en proceso. Gracias por comprar en Akibara.', 'akibara' ),
    esc_html( $order->get_order_number() )
);
?>
</p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
// ── Próximos pasos contextualizado por método de envío ──────────────────────
// Misma lógica que order-received.php para consistencia entre thank-you page y email.
$shipping_methods   = $order->get_shipping_methods();
$shipping_method_id = '';
if ( ! empty( $shipping_methods ) ) {
    $first_method       = reset( $shipping_methods );
    $shipping_method_id = strtolower( (string) $first_method->get_method_id() );
}

$is_pickup = strpos( $shipping_method_id, 'local_pickup' ) === 0;
$is_pudo   = strpos( $shipping_method_id, 'bluex' ) === 0 && strpos( $shipping_method_id, 'pudo' ) !== false;

$wa_url = function_exists( 'akibara_wa_url' ) ? akibara_wa_url() : home_url( '/contacto/' );

if ( $is_pickup ) {
    $steps = [
        [ '✓', 'Pago recibido', 'Tu pedido está confirmado.' ],
        [ '↓', 'Coordinamos retiro', 'Te contactamos por WhatsApp en las próximas 24 horas para acordar día y hora.' ],
        [ '↓', 'Retiro en Metro San Miguel', 'Lunes a viernes · 10:00–19:00 · coordinado contigo.' ],
    ];
} elseif ( $is_pudo ) {
    $steps = [
        [ '✓', 'Pago recibido', 'Tu pedido está confirmado.' ],
        [ '↓', 'Preparamos tu pedido', 'Embalamos y despachamos el mismo día hábil si compras antes de las 14:00.' ],
        [ '↓', 'Código de retiro', 'Recibirás un correo con el código QR o PIN para retirar en tu punto Blue Express.' ],
        [ '↓', 'Retira cuando quieras', 'Tienes hasta 5 días hábiles para retirar en el punto elegido.' ],
    ];
} else {
    $steps = [
        [ '✓', 'Pago recibido', 'Tu pedido está confirmado.' ],
        [ '↓', 'Preparamos tu pedido', 'Embalamos y despachamos el mismo día hábil si compras antes de las 14:00.' ],
        [ '↓', 'Te avisamos al despachar', 'Recibirás un correo con el número de seguimiento de Blue Express.' ],
        [ '↓', 'Llega a tu puerta', 'En RM: mismo día o al día siguiente. Regiones: 2–5 días hábiles.' ],
    ];
}
?>

<!-- Próximos pasos — línea de tiempo simplificada -->
<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"
       style="margin:24px 0; border-left:3px solid #D90010;">
    <tr>
        <td style="padding:12px 16px;">
            <p style="margin:0 0 4px; font-family:'Helvetica Neue', Arial, sans-serif; font-size:13px; font-weight:bold; color:#D90010; text-transform:uppercase; letter-spacing:0.05em;">
                <?php esc_html_e( 'Qué pasa ahora', 'akibara' ); ?>
            </p>
            <?php foreach ( $steps as $step ) : ?>
            <p style="margin:10px 0 0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:13px; line-height:1.5; color:#C0C0C0;">
                <span style="color:<?php echo $step[0] === '✓' ? '#4CAF50' : '#666'; ?>; font-weight:bold; margin-right:6px;"><?php echo esc_html( $step[0] ); ?></span>
                <strong style="color:#E0E0E0;"><?php echo esc_html( $step[1] ); ?></strong>
                &mdash; <?php echo esc_html( $step[2] ); ?>
            </p>
            <?php endforeach; ?>
            <?php if ( $is_pickup ) : ?>
            <p style="margin:12px 0 0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:12px; color:#888;">
                <?php
                printf(
                    wp_kses(
                        /* translators: %s: URL de WhatsApp */
                        __( 'También puedes escribirnos ahora: <a href="%s" style="color:#25D366;">WhatsApp</a>', 'akibara' ),
                        [ 'a' => [ 'href' => [], 'style' => [] ] ]
                    ),
                    esc_url( $wa_url )
                );
                ?>
            </p>
            <?php endif; ?>
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
