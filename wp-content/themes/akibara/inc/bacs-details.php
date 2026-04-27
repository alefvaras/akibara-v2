<?php
/**
 * Akibara – BACS bank details customization
 *
 * Adds RUT, account type, and email to bank transfer details
 * shown in emails and thank-you page.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// B-S1-PAY-01 (2026-04-27): los valores vienen de wp_options (source of truth).
// Si la option no existe, fallback al valor histórico (backward-compatible).
// Para editar: wp option update akibara_business_rut "<nuevo>"
add_filter( 'woocommerce_bacs_account_fields', function( $fields, $order_id ) {
    $new_fields = [];
    foreach ( $fields as $key => $field ) {
        $new_fields[ $key ] = $field;
        if ( $key === 'bank_name' ) {
            $new_fields['rut'] = [
                'label' => 'RUT',
                'value' => (string) get_option( 'akibara_business_rut', '78.274.225-6' ),
            ];
            $new_fields['account_type'] = [
                'label' => 'Tipo de cuenta',
                'value' => (string) get_option( 'akibara_business_account_type', 'FAN Emprende' ),
            ];
        }
    }
    $new_fields['email'] = [
        'label' => 'Email para comprobante',
        'value' => (string) get_option( 'akibara_business_email_comprobante', 'contacto@akibara.cl' ),
    ];

    return $new_fields;
}, 10, 2 );

// Update the account name to match official business name
add_filter( 'woocommerce_bacs_accounts', function( $accounts, $order_id ) {
    $legal_name = (string) get_option( 'akibara_business_legal_name', 'AKIBARA SpA' );
    foreach ( $accounts as &$account ) {
        if ( ! empty( $account['account_name'] ) ) {
            $account['account_name'] = $legal_name;
        }
    }
    return $accounts;
}, 10, 2 );
