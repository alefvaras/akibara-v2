<?php
/**
 * Akibara — AJAX handler para formulario de encargos
 * Envía notificación por email al admin + opcionalmente a Brevo.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_akibara_encargo_submit', 'akibara_ajax_encargo_submit' );
add_action( 'wp_ajax_nopriv_akibara_encargo_submit', 'akibara_ajax_encargo_submit' );

function akibara_ajax_encargo_submit(): void {
    check_ajax_referer( 'akibara_encargo', 'encargo_nonce' );

    $nombre    = sanitize_text_field( wp_unslash( $_POST['nombre'] ) ?? '' );
    $email     = sanitize_email( wp_unslash( $_POST['email'] ) ?? '' );
    $titulo    = sanitize_text_field( wp_unslash( $_POST['titulo'] ) ?? '' );
    $editorial = sanitize_text_field( wp_unslash( $_POST['editorial'] ) ?? '' );
    $volumenes = sanitize_text_field( wp_unslash( $_POST['volumenes'] ) ?? '' );
    $notas     = sanitize_textarea_field( $_POST['notas'] ?? '' );

    if ( empty( $nombre ) || ! is_email( $email ) || empty( $titulo ) ) {
        wp_send_json_error( 'Completa los campos obligatorios.' );
    }

    // B-S1-SEC-07 (2026-04-27): rate limit anti-abuse.
    // - 3 encargos / hora por IP (margen para envíos legítimos)
    // - 2 encargos / día por email (evita spam con mismo destinatario)
    if ( function_exists( 'akb_rate_limit' ) ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        if ( ! akb_rate_limit( 'encargo_ip:' . md5( $ip ), 3, HOUR_IN_SECONDS ) ) {
            wp_send_json_error( 'Demasiadas solicitudes. Intenta en una hora.', 429 );
        }
        if ( ! akb_rate_limit( 'encargo_email:' . md5( strtolower( $email ) ), 2, DAY_IN_SECONDS ) ) {
            wp_send_json_error( 'Ya enviaste demasiados encargos hoy con este email. Intenta mañana.', 429 );
        }
    }

    // Construir email al admin
    $admin_email = get_option( 'admin_email' );
    $subject     = 'Nuevo encargo: ' . $titulo;

    $body  = "Nuevo encargo recibido desde akibara.cl/encargos/\n\n";
    $body .= "Nombre: {$nombre}\n";
    $body .= "Email: {$email}\n";
    $body .= "Titulo: {$titulo}\n";
    $body .= "Editorial: " . ( $editorial ?: 'No especificada' ) . "\n";
    $body .= "Volumenes: " . ( $volumenes ?: 'No especificados' ) . "\n";
    $body .= "Notas: " . ( $notas ?: 'Sin notas' ) . "\n\n";
    $body .= "---\n";
    $body .= "Responder directamente a: {$email}\n";

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $email,
    ];

    $sent = wp_mail( $admin_email, $subject, $body, $headers );

    // Guardar en wp_options como registro
    $encargos = get_option( 'akibara_encargos_log', [] );
    if ( ! is_array( $encargos ) ) $encargos = [];

    $encargos[] = [
        'nombre'    => $nombre,
        'email'     => $email,
        'titulo'    => $titulo,
        'editorial' => $editorial,
        'volumenes' => $volumenes,
        'notas'     => $notas,
        'fecha'     => current_time( 'mysql' ),
        'status'    => 'pendiente',
    ];

    // Mantener solo los últimos 200
    if ( count( $encargos ) > 200 ) {
        $encargos = array_slice( $encargos, -200 );
    }

    update_option( 'akibara_encargos_log', $encargos, false );

    // Suscribir a Brevo lista "Encargos" si hay API key
    $api_key = function_exists( 'akb_brevo_get_api_key' )
        ? akb_brevo_get_api_key()
        : (string) get_option( 'akibara_brevo_api_key', '' );
    if ( ! empty( $api_key ) ) {
        wp_remote_post( 'https://api.brevo.com/v3/contacts', [
            'headers' => [
                'api-key'      => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode( [
                'email'         => $email,
                'listIds'       => [ 2 ],
                'updateEnabled' => true,
                'attributes'    => [
                    'NOMBRE'         => $nombre,
                    'CONTACT_SOURCE' => 'encargo_form',
                    'LAST_ENCARGO'   => $titulo,
                ],
            ] ),
            'timeout' => 5,
        ] );
    }

    if ( $sent ) {
        wp_send_json_success( [ 'message' => 'Encargo enviado' ] );
    } else {
        // Guardamos en log de todas formas, solo falló el email
        wp_send_json_success( [ 'message' => 'Encargo registrado' ] );
    }
}
