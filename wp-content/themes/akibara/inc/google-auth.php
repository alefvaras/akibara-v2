<?php
/**
 * Google OAuth 2.0 — Akibara (no plugin required)
 *
 * SETUP (one-time, ~5 min):
 * 1. https://console.cloud.google.com → New project → APIs & Services → Credentials
 * 2. Create OAuth 2.0 Client ID (Web application)
 * 3. Authorized redirect URI: https://akibara.cl/?akibara_google_callback=1
 * 4. Copy Client ID and Client Secret, then run:
 *    wp option add akibara_google_client_id "PASTE_CLIENT_ID_HERE"
 *    wp option add akibara_google_client_secret "PASTE_CLIENT_SECRET_HERE"
 *
 * @package Akibara
 */
defined('ABSPATH') || exit;

define('AKIBARA_GOOGLE_REDIRECT', home_url('/?akibara_google_callback=1'));

/**
 * Build the Google OAuth URL
 */
function akibara_google_auth_url( string $intent = 'login' ): string {
    $client_id = get_option('akibara_google_client_id');
    if ( ! $client_id ) return '#';

    $nonce = wp_create_nonce('akibara_google_state');
    $state = base64_encode( $nonce . '|' . $intent );

    return add_query_arg( [
        'client_id'     => $client_id,
        'redirect_uri'  => AKIBARA_GOOGLE_REDIRECT,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'prompt'        => 'select_account',
        'access_type'   => 'online',
    ], 'https://accounts.google.com/o/oauth2/v2/auth' );
}

/**
 * Handle OAuth callback
 */
add_action('init', function () {
    if ( ! isset( $_GET['akibara_google_callback'] ) ) return;

    $client_id     = get_option('akibara_google_client_id');
    $client_secret = get_option('akibara_google_client_secret');
    $login_url     = wc_get_page_permalink('myaccount');

    // Config check
    if ( ! $client_id || ! $client_secret ) {
        wc_add_notice( 'Google login no está configurado.', 'error' );
        wp_redirect( $login_url ); exit;
    }

    // CSRF state check
    $raw = base64_decode( sanitize_text_field( $_GET['state'] ?? '' ) );
    [ $nonce, $intent ] = array_pad( explode( '|', $raw, 2 ), 2, 'login' );

    if ( ! wp_verify_nonce( $nonce, 'akibara_google_state' ) ) {
        wc_add_notice( 'Sesión expirada. Intenta de nuevo.', 'error' );
        wp_redirect( $login_url ); exit;
    }

    $code = sanitize_text_field( $_GET['code'] ?? '' );
    if ( ! $code ) {
        wc_add_notice( 'Autorización cancelada.', 'error' );
        wp_redirect( $login_url ); exit;
    }

    // Exchange code → access token
    $token_res = wp_remote_post( 'https://oauth2.googleapis.com/token', [
        'body'    => [
            'code'          => $code,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri'  => AKIBARA_GOOGLE_REDIRECT,
            'grant_type'    => 'authorization_code',
        ],
        'timeout' => 20,
    ]);

    if ( is_wp_error( $token_res ) ) {
        wc_add_notice( 'Error al conectar con Google. Intenta de nuevo.', 'error' );
        wp_redirect( $login_url ); exit;
    }

    $token_data   = json_decode( wp_remote_retrieve_body( $token_res ), true );
    $access_token = $token_data['access_token'] ?? '';
    if ( ! $access_token ) {
        wc_add_notice( 'No se pudo obtener autorización de Google.', 'error' );
        wp_redirect( $login_url ); exit;
    }

    // Get user info
    $info_res = wp_remote_get( 'https://www.googleapis.com/oauth2/v3/userinfo', [
        'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
        'timeout' => 20,
    ]);

    if ( is_wp_error( $info_res ) ) {
        wc_add_notice( 'No se pudo obtener tu información de Google.', 'error' );
        wp_redirect( $login_url ); exit;
    }

    $info      = json_decode( wp_remote_retrieve_body( $info_res ), true );
    $google_id = sanitize_text_field( $info['sub']        ?? '' );
    $email     = sanitize_email(      $info['email']      ?? '' );
    $given     = sanitize_text_field( $info['given_name'] ?? '' );
    $family    = sanitize_text_field( $info['family_name']?? '' );
    $avatar    = esc_url_raw(         $info['picture']    ?? '' );

    if ( ! $email || ! is_email( $email ) ) {
        wc_add_notice( 'No se pudo obtener tu email de Google.', 'error' );
        wp_redirect( $login_url ); exit;
    }

    // Find or create WP user
    $user = get_user_by( 'email', $email );

    if ( ! $user ) {
        // Build unique username from given name or email
        $base = sanitize_user( strtolower( str_replace( ' ', '', $given ?: explode( '@', $email )[0] ) ), true );
        $base = preg_replace('/[^a-z0-9_]/', '', $base) ?: 'usuario';
        $username = $base;
        $i = 1;
        while ( username_exists( $username ) ) { $username = $base . $i++; }

        $user_id = wc_create_new_customer( $email, $username, wp_generate_password( 32, true, true ) );

        if ( is_wp_error( $user_id ) ) {
            error_log( 'akibara google-auth: user create failed: ' . $user_id->get_error_message() ); wc_add_notice( 'No fue posible crear tu cuenta. Por favor intenta de nuevo.', 'error' );
            wp_redirect( $login_url ); exit;
        }

        // Set name + meta
        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $given,
            'last_name'    => $family,
            'display_name' => $given ?: explode( '@', $email )[0],
        ]);
        update_user_meta( $user_id, 'billing_first_name', $given );
        update_user_meta( $user_id, 'billing_last_name',  $family );
        update_user_meta( $user_id, 'akibara_google_id',     $google_id );
        update_user_meta( $user_id, 'akibara_google_avatar', $avatar );
        update_user_meta( $user_id, 'akibara_is_new_user',   '1' );

        $user = get_user_by( 'id', $user_id );
    } else {
        // Update Google meta on returning users
        update_user_meta( $user->ID, 'akibara_google_id',     $google_id );
        update_user_meta( $user->ID, 'akibara_google_avatar', $avatar );
    }

    // Log in
    wp_set_current_user( $user->ID );
    wp_set_auth_cookie( $user->ID, true );
    do_action( 'wp_login', $user->user_login, $user );

    $redirect = apply_filters( 'woocommerce_login_redirect', wc_get_page_permalink('myaccount'), $user );
    wp_redirect( $redirect );
    exit;
}, 5 );
