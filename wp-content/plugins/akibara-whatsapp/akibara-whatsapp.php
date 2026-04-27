<?php
/**
 * Plugin Name:       Akibara WhatsApp
 * Plugin URI:        https://github.com/alefvaras/akibara-v2
 * Description:       Botón flotante de WhatsApp integrado con el diseño de Akibara.
 * Version:           1.4.0
 * Author:            Akibara
 * Text Domain:       akibara-whatsapp
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  akibara-core
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// File-level guard — group wrap pattern (Sprint 2 postmortem, REDESIGN.md §9).
if ( defined( 'AKB_WHATSAPP_LOADED' ) ) {
    return;
}
define( 'AKB_WHATSAPP_LOADED', '1.4.0' );

// ─── Constants (always defined, idempotent) ──────────────────────────────────
if ( ! defined( 'AKB_WHATSAPP_VERSION' ) ) {
    define( 'AKB_WHATSAPP_VERSION', '1.4.0' );
}
if ( ! defined( 'AKB_WHATSAPP_DIR' ) ) {
    define( 'AKB_WHATSAPP_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AKB_WHATSAPP_URL' ) ) {
    define( 'AKB_WHATSAPP_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'AKB_WHATSAPP_FILE' ) ) {
    define( 'AKB_WHATSAPP_FILE', __FILE__ );
}

/** Número de WhatsApp de negocio — fuente canónica del default. */
if ( ! defined( 'AKIBARA_WA_PHONE_DEFAULT' ) ) {
    define( 'AKIBARA_WA_PHONE_DEFAULT', '56944242844' );
}

// ─── PSR-4 autoloader (Akibara\WhatsApp\* → src/*) ──────────────────────────
if ( ! defined( 'AKB_WHATSAPP_AUTOLOADER_REGISTERED' ) ) {
    define( 'AKB_WHATSAPP_AUTOLOADER_REGISTERED', true );

    spl_autoload_register(
        static function ( string $class ): void {
            $prefix = 'Akibara\\WhatsApp\\';
            if ( strpos( $class, $prefix ) !== 0 ) {
                return;
            }
            $relative = substr( $class, strlen( $prefix ) );
            $file     = AKB_WHATSAPP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
            if ( is_readable( $file ) ) {
                require_once $file;
            }
        }
    );
}

// ─── Public function: número de negocio ─────────────────────────────────────
// Preservada como función global — el tema y otros módulos dependen de ella.
// Contrato: solo dígitos, prefijo '56' si ≤9 dígitos, nunca vacío.
if ( ! function_exists( 'akibara_whatsapp_get_business_number' ) ) {

    /**
     * Retorna el número de WhatsApp de negocio configurado en el admin.
     *
     * Lee desde la opción `akibara_whatsapp` (panel WooCommerce → WhatsApp).
     * Cae en AKIBARA_WA_PHONE_DEFAULT si la opción no tiene valor.
     *
     * Normalización:
     *  - Salida contiene solo dígitos [0-9].
     *  - Números con ≤9 dígitos reciben prefijo '56' (código Chile).
     *  - Nunca retorna string vacío.
     *
     * @return string Ej.: '56944242844'
     */
    function akibara_whatsapp_get_business_number(): string {
        $opts  = get_option( 'akibara_whatsapp', [] );
        $phone = preg_replace( '/[^0-9]/', '', $opts['phone'] ?? '' );

        if ( empty( $phone ) ) {
            return AKIBARA_WA_PHONE_DEFAULT;
        }

        // Números locales chilenos tienen 8-9 dígitos; agregar código de país si falta.
        if ( strlen( $phone ) <= 9 ) {
            $phone = '56' . $phone;
        }

        return $phone;
    }

} // end group wrap

// ─── WooCommerce HPOS compatibility ─────────────────────────────────────────
if ( ! function_exists( 'akb_whatsapp_declare_wc_compat' ) ) {

    function akb_whatsapp_declare_wc_compat(): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                AKB_WHATSAPP_FILE,
                true
            );
        }
    }

    add_action( 'before_woocommerce_init', 'akb_whatsapp_declare_wc_compat' );

} // end group wrap

// ─── Bootstrap via AddonContract (post-INCIDENT-01 — type-safe registration) ─
// Plugin class es src/Plugin.php (PSR-4 autoloader arriba).
// Bootstrap::register_addon() envuelve init() en per-addon try/catch; si este
// addon lanza, Core lo desactiva automáticamente y el sitio sigue UP.
if ( ! function_exists( 'akb_whatsapp_register' ) ) {

    function akb_whatsapp_register(): void {
        if ( ! class_exists( '\Akibara\Core\Bootstrap' ) ) {
            return; // akibara-core no activo — nada que registrar.
        }
        \Akibara\Core\Bootstrap::instance()->register_addon( new \Akibara\WhatsApp\Plugin() );
    }

    // Priority 10 = después de core plugins_loaded:5. Bootstrap ya está inicializado.
    add_action( 'plugins_loaded', 'akb_whatsapp_register', 10 );

} // end group wrap

// ─── Uninstall hook ──────────────────────────────────────────────────────────
register_uninstall_hook( __FILE__, 'akb_whatsapp_uninstall' );

if ( ! function_exists( 'akb_whatsapp_uninstall' ) ) {

    function akb_whatsapp_uninstall(): void {
        delete_option( 'akibara_whatsapp' );
    }

} // end group wrap
