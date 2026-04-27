<?php
/**
 * Akibara — AJAX handler para tracking público de pedidos
 * Se carga desde functions.php del tema.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_akibara_track_order', 'akibara_ajax_track_order' );
add_action( 'wp_ajax_nopriv_akibara_track_order', 'akibara_ajax_track_order' );

function akibara_ajax_track_order(): void {
    check_ajax_referer( 'akibara_track_order', 'nonce' );

    $order_id = absint( $_POST['order_id'] ?? 0 );
    $email    = sanitize_email( $_POST['email'] ?? '' );

    if ( ! $order_id || ! is_email( $email ) ) {
        wp_send_json_error( 'No encontramos una orden con esos datos.' ); return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_send_json_error( 'No encontramos una orden con esos datos.' ); return;
    }

    // Verificar email
    if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
        wp_send_json_error( 'No encontramos una orden con esos datos.' ); return;
    }

    // Status mapping
    $status_map = [
        'pending'    => [ 'label' => 'Pendiente de pago',   'class' => 'on-hold',    'icon' => '⏳', 'desc' => 'Tu orden está reservada. Completa el pago para procesarla.' ],
        'on-hold'    => [ 'label' => 'Pago pendiente',      'class' => 'on-hold',    'icon' => '⏳', 'desc' => 'Tu orden está reservada, pero aún no recibimos la confirmación de pago.' ],
        'processing' => [ 'label' => 'Preparando tu pedido','class' => 'processing', 'icon' => '📦', 'desc' => 'Estamos armando tu envío. Te avisaremos por correo cuando salga.' ],
        'completed'  => [ 'label' => 'Entregado',           'class' => 'completed',  'icon' => '✅', 'desc' => 'Tu pedido fue entregado.' ],
        'cancelled'  => [ 'label' => 'Cancelado',           'class' => 'cancelled',  'icon' => '❌', 'desc' => 'Esta orden fue cancelada.' ],
        'refunded'   => [ 'label' => 'Reembolsado',         'class' => 'cancelled',  'icon' => '↩️', 'desc' => 'Esta orden fue reembolsada.' ],
        'failed'     => [ 'label' => 'Pago fallido',        'class' => 'cancelled',  'icon' => '❌', 'desc' => 'El pago no se pudo procesar.' ],
    ];

    $wc_status  = str_replace( 'wc-', '', $order->get_status() );
    $status     = $status_map[ $wc_status ] ?? [ 'label' => ucfirst( $wc_status ), 'class' => 'on-hold', 'icon' => '📋', 'desc' => '' ];

    // Tracking unificado multi-courier (orchestrator del plugin akibara)
    // Con fallback graceful si el módulo shipping está deshabilitado.
    $tracking = null;
    if ( function_exists( 'akb_ship_get_tracking_info' ) ) {
        $tracking = akb_ship_get_tracking_info( $order );
    } elseif ( function_exists( 'wc_correios_getTrackingCodes' ) ) {
        // Degradación: plugin akibara desactivado, solo BlueX via plugin standalone.
        $codes = wc_correios_getTrackingCodes( $order_id );
        if ( ! empty( $codes ) ) {
            $tracking = [
                'courier'       => 'bluex',
                'courier_label' => 'Blue Express',
                'code'          => $codes[0],
                'codes'         => $codes,
                'url'           => 'https://tracking-unificado.blue.cl/?n_seguimiento=' . urlencode( $codes[0] ),
                'tracking_url'  => 'https://tracking-unificado.blue.cl/?n_seguimiento=' . urlencode( $codes[0] ),
            ];
        }
    }

    $tracking_codes  = $tracking['codes'] ?? [];
    $courier_label   = $tracking['courier_label'] ?? '';
    $tracking_url    = $tracking['url'] ?? ( $tracking['tracking_url'] ?? '' );

    // Si tiene tracking y está en processing, mostrar como "en camino"
    if ( ! empty( $tracking_codes ) && $wc_status === 'processing' ) {
        $status = [ 'label' => 'En camino', 'class' => 'shipped', 'icon' => '🚀', 'desc' => 'Tu pedido ya salió de nuestra bodega y va en ruta con ' . $courier_label . '.' ];
    }

    // Items
    $items_html = '';
    foreach ( $order->get_items() as $item ) {
        $qty  = $item->get_quantity();
        $name = $item->get_name();
        $items_html .= '<div class="aki-track__item"><span>' . esc_html( $name ) . ' &times; ' . esc_html( $qty ) . '</span></div>';
    }

    // Build HTML
    $html = '';
    $html .= '<div class="aki-track__result-header">';
    $html .= '<span class="aki-track__order-num">Orden #' . esc_html( $order_id ) . '</span>';
    $html .= '<span class="aki-track__status aki-track__status--' . esc_attr( $status['class'] ) . '"><span aria-hidden="true">' . esc_html( $status['icon'] ) . '</span> ' . esc_html( $status['label'] ) . '</span>';
    $html .= '</div>';

    // Status description
    if ( ! empty( $status['desc'] ) ) {
        $html .= '<p style="color:var(--aki-gray-400);font-size:var(--text-sm);margin-bottom:var(--space-3)">' . esc_html( $status['desc'] ) . '</p>';
    }

    // Order date
    $date_created = $order->get_date_created();
    if ( $date_created ) {
        $tz = new DateTimeZone( 'America/Santiago' );
        $dt = clone $date_created;
        $dt->setTimezone( $tz );
        $html .= '<p style="font-size:var(--text-xs);color:var(--aki-gray-500);margin-bottom:var(--space-2)">Fecha: ' . esc_html( $dt->format( 'd/m/Y H:i' ) ) . '</p>';
    }

    // Items
    if ( $items_html ) {
        $html .= '<div class="aki-track__items">' . $items_html . '</div>';
    }

    // Total
    $html .= '<div class="aki-track__total"><span>Total</span><span>$' . esc_html( number_format( (float) $order->get_total(), 0, ',', '.' ) ) . '</span></div>';

    // Tracking multi-courier (BlueX + 12 Horas)
    if ( ! empty( $tracking_codes ) ) {
        foreach ( $tracking_codes as $code ) {
            $html .= '<div class="aki-track__tracking">';
            $html .= '<span aria-hidden="true">📦</span>';
            $html .= '<span class="aki-track__tracking-code">' . esc_html( $code ) . '</span>';
            if ( ! empty( $tracking_url ) ) {
                $html .= '<a href="' . esc_url( $tracking_url ) . '" target="_blank" rel="noopener noreferrer" class="aki-track__tracking-link" aria-label="Seguir mi envío (se abre en una nueva pestaña)">Seguir mi envío →</a>';
            } else {
                $html .= '<span class="aki-track__tracking-link aki-track__tracking-link--no-url">Te notificaremos por correo</span>';
            }
            $html .= '</div>';
        }
    } elseif ( in_array( $wc_status, [ 'processing', 'on-hold' ] ) ) {
        $html .= '<p class="aki-track__delivery">Estamos preparando tu pedido. Te avisaremos por correo cuando salga a despacho.</p>';
    }

    // Shipping method
    $shipping_methods = $order->get_shipping_methods();
    foreach ( $shipping_methods as $method ) {
        $html .= '<p style="font-size:var(--text-xs);color:var(--aki-gray-500);margin-top:var(--space-2)">Envío: ' . esc_html( $method->get_name() ) . '</p>';
        break;
    }

    wp_send_json_success( [ 'html' => $html ] );
}
