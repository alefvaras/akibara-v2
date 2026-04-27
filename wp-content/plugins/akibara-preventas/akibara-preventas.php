<?php
/**
 * Plugin Name: Akibara Preventas
 * Plugin URI: https://github.com/alefvaras/akibara-v2
 * Description: Preventas + encargos + notificaciones de volúmenes/series
 * Version: 1.0.0
 * Author: Akibara
 * Requires Plugins: akibara-core
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: akibara-preventas
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

// File-level guard — group wrap pattern (Sprint 2 postmortem, REDESIGN.md §9).
if ( defined( 'AKB_PREVENTAS_LOADED' ) ) {
    return;
}
define( 'AKB_PREVENTAS_LOADED', '1.0.0' );

// ─── Constants (always defined, idempotent) ──────────────────────────────────
if ( ! defined( 'AKB_PREVENTAS_VERSION' ) ) {
    define( 'AKB_PREVENTAS_VERSION', '1.0.0' );
}
if ( ! defined( 'AKB_PREVENTAS_DIR' ) ) {
    define( 'AKB_PREVENTAS_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'AKB_PREVENTAS_URL' ) ) {
    define( 'AKB_PREVENTAS_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'AKB_PREVENTAS_FILE' ) ) {
    define( 'AKB_PREVENTAS_FILE', __FILE__ );
}

// DB table name constants — use $GLOBALS['wpdb'] to avoid depends on global $wpdb at parse time.
if ( ! defined( 'AKB_PREVENTAS_TABLE_PREORDERS' ) ) {
    define( 'AKB_PREVENTAS_TABLE_PREORDERS', $GLOBALS['wpdb']->prefix . 'akb_preorders' );
}
if ( ! defined( 'AKB_PREVENTAS_TABLE_BATCHES' ) ) {
    define( 'AKB_PREVENTAS_TABLE_BATCHES', $GLOBALS['wpdb']->prefix . 'akb_preorder_batches' );
}
if ( ! defined( 'AKB_PREVENTAS_TABLE_SPECIAL_ORDERS' ) ) {
    define( 'AKB_PREVENTAS_TABLE_SPECIAL_ORDERS', $GLOBALS['wpdb']->prefix . 'akb_special_orders' );
}

// DB version sentinel used by dbDelta upgrade path.
if ( ! defined( 'AKB_PREVENTAS_DB_VERSION' ) ) {
    define( 'AKB_PREVENTAS_DB_VERSION', '1.0' );
}

// ─── WooCommerce HPOS + Checkout Blocks compatibility ────────────────────────
// Group wrap: declare_compatibility hooks must be registered before WC init.
if ( ! function_exists( 'akb_preventas_declare_wc_compat' ) ) {

    function akb_preventas_declare_wc_compat(): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                AKB_PREVENTAS_FILE,
                true
            );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                AKB_PREVENTAS_FILE,
                true
            );
        }
    }

    add_action( 'before_woocommerce_init', 'akb_preventas_declare_wc_compat' );

} // end group wrap

// ─── Bootstrap on akibara_core_init (Sprint 2 HANDOFF §6) ───────────────────
// Group wrap: all bootstrap functions + hook registration.
if ( ! function_exists( 'akb_preventas_init' ) ) {

    /**
     * Main init: fires on akibara_core_init (priority 10, after core priority 5).
     * Registers services and modules into the core Bootstrap.
     *
     * @param \Akibara\Core\Bootstrap $bootstrap Core bootstrap singleton.
     */
    function akb_preventas_init( \Akibara\Core\Bootstrap $bootstrap ): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Register repository with ServiceLocator.
        $bootstrap->services()->register(
            'preventas.repository',
            new \Akibara\Preventas\Repository\PreorderRepository()
        );

        // Declare module to ModuleRegistry.
        $bootstrap->modules()->declare_module( 'akibara-preventas', AKB_PREVENTAS_VERSION, 'addon' );

        // Load class files.
        akb_preventas_load_classes();

        // Init subsystems.
        Akibara_Reserva_Product::class;     // autoloaded via PSR-4 + includes.
        Akibara_Reserva_Cart::init();
        Akibara_Reserva_Orders::init();
        Akibara_Reserva_Editor::init();
        Akibara_Reserva_Frontend::init();
        Akibara_Reserva_Admin::init();
        Akibara_Reserva_Cron::init();
        Akibara_Reserva_Stock::init();
        Akibara_Reserva_MyAccount::init();
        Akibara_Reserva_Email_Queue::init();

        // Run idempotent migration (admin only to avoid frontend overhead).
        if ( is_admin() ) {
            Akibara_Reserva_Migration::maybe_unify_types();
        }

        // Load modules.
        akb_preventas_load_modules();
    }

    add_action( 'akibara_core_init', 'akb_preventas_init', 10 );

} // end group wrap

// ─── File loader ─────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_preventas_load_classes' ) ) {

    function akb_preventas_load_classes(): void {
        $dir = AKB_PREVENTAS_DIR;

        // Legacy include files (procedural helpers + classes from akibara-reservas).
        require_once $dir . 'includes/functions.php';
        require_once $dir . 'includes/class-reserva-product.php';
        require_once $dir . 'includes/class-reserva-email-queue.php';
        require_once $dir . 'includes/class-reserva-orders.php';
        require_once $dir . 'includes/class-reserva-editor.php';
        require_once $dir . 'includes/class-reserva-frontend.php';
        require_once $dir . 'includes/class-reserva-admin.php';
        require_once $dir . 'includes/class-reserva-cron.php';
        require_once $dir . 'includes/class-reserva-stock.php';
        require_once $dir . 'includes/class-reserva-myaccount.php';
        require_once $dir . 'includes/class-reserva-cart.php';
        require_once $dir . 'includes/class-reserva-migration.php';
    }

} // end group wrap

// ─── Module loader ───────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_preventas_load_modules' ) ) {

    function akb_preventas_load_modules(): void {
        $dir = AKB_PREVENTAS_DIR;

        require_once $dir . 'modules/next-volume/module.php';
        require_once $dir . 'modules/series-notify/module.php';
        require_once $dir . 'modules/editorial-notify/module.php';
        require_once $dir . 'modules/encargos/module.php';
    }

} // end group wrap

// ─── WooCommerce email classes ───────────────────────────────────────────────
if ( ! function_exists( 'akb_preventas_register_emails' ) ) {

    function akb_preventas_register_emails( array $emails ): array {
        if ( ! defined( 'AKB_PREVENTAS_DIR' ) ) {
            return $emails;
        }

        $emails['Akibara_Email_Confirmada']     = require AKB_PREVENTAS_DIR . 'emails/class-email-confirmada.php';
        $emails['Akibara_Email_Lista']          = require AKB_PREVENTAS_DIR . 'emails/class-email-lista.php';
        $emails['Akibara_Email_Cancelada']      = require AKB_PREVENTAS_DIR . 'emails/class-email-cancelada.php';
        $emails['Akibara_Email_Fecha_Cambiada'] = require AKB_PREVENTAS_DIR . 'emails/class-email-fecha-cambiada.php';
        $emails['Akibara_Email_Nueva_Reserva']  = require AKB_PREVENTAS_DIR . 'emails/class-email-nueva-reserva.php';

        return $emails;
    }

    add_filter( 'woocommerce_email_classes', 'akb_preventas_register_emails' );

} // end group wrap

// ─── DB install / upgrade ─────────────────────────────────────────────────────
if ( ! function_exists( 'akb_preventas_install_db' ) ) {

    /**
     * Idempotent dbDelta install for the 3 new tables.
     * Called on activation hook and checked on every load via version sentinel.
     */
    function akb_preventas_install_db(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // 1. wp_akb_preorders — core preorder records.
        $sql_preorders = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}akb_preorders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer_email VARCHAR(255) NOT NULL DEFAULT '',
            qty SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM('pending','confirmed','shipping','delivered','cancelled') NOT NULL DEFAULT 'pending',
            batch_id BIGINT UNSIGNED DEFAULT NULL,
            expected_date DATETIME DEFAULT NULL,
            fulfilled_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY status (status),
            KEY customer_email (customer_email(20)),
            KEY batch_id (batch_id)
        ) {$charset};";

        // 2. wp_akb_preorder_batches — admin grouping of preorders per product/date.
        $sql_batches = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}akb_preorder_batches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('open','ordered','shipping','closed','cancelled') NOT NULL DEFAULT 'open',
            expected_date DATETIME DEFAULT NULL,
            received_at DATETIME DEFAULT NULL,
            supplier_notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY status (status)
        ) {$charset};";

        // 3. wp_akb_special_orders — encargos as a subtype.
        $sql_special = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}akb_special_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            nombre VARCHAR(255) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '',
            titulo VARCHAR(255) NOT NULL DEFAULT '',
            editorial VARCHAR(255) NOT NULL DEFAULT '',
            volumenes VARCHAR(255) NOT NULL DEFAULT '',
            notas TEXT DEFAULT NULL,
            status ENUM('pendiente','en_gestion','lista','cancelada') NOT NULL DEFAULT 'pendiente',
            order_id BIGINT UNSIGNED DEFAULT NULL,
            admin_notes TEXT DEFAULT NULL,
            fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY email (email(20)),
            KEY status (status),
            KEY fecha (fecha)
        ) {$charset};";

        dbDelta( $sql_preorders );
        dbDelta( $sql_batches );
        dbDelta( $sql_special );

        update_option( 'akb_preventas_db_version', AKB_PREVENTAS_DB_VERSION );
    }

} // end group wrap

// ─── DB upgrade check on load ─────────────────────────────────────────────────
if ( ! function_exists( 'akb_preventas_maybe_upgrade_db' ) ) {

    function akb_preventas_maybe_upgrade_db(): void {
        if ( get_option( 'akb_preventas_db_version' ) !== AKB_PREVENTAS_DB_VERSION ) {
            akb_preventas_install_db();
        }
    }

    add_action( 'plugins_loaded', 'akb_preventas_maybe_upgrade_db', 15 );

} // end group wrap

// ─── Activation hook ─────────────────────────────────────────────────────────
register_activation_hook(
    __FILE__,
    static function (): void {
        akb_preventas_install_db();

        if ( ! wp_next_scheduled( 'akb_reservas_check_dates' ) ) {
            wp_schedule_event( time(), 'akb_fifteen_minutes', 'akb_reservas_check_dates' );
        }
        if ( ! wp_next_scheduled( 'akb_reservas_daily_digest' ) ) {
            wp_schedule_event( time(), 'daily', 'akb_reservas_daily_digest' );
        }

        add_rewrite_endpoint( 'mis-reservas', EP_ROOT | EP_PAGES );
        flush_rewrite_rules();
    }
);

// ─── Deactivation hook ───────────────────────────────────────────────────────
register_deactivation_hook(
    __FILE__,
    static function (): void {
        wp_clear_scheduled_hook( 'akb_reservas_check_dates' );
        wp_clear_scheduled_hook( 'akb_reservas_daily_digest' );
        // NOTE: module cron hooks (akibara_next_volume_check, akb_series_notify_cron) cleared separately.
        wp_clear_scheduled_hook( 'akibara_next_volume_check' );
        wp_clear_scheduled_hook( 'akb_series_notify_cron' );
        flush_rewrite_rules();
    }
);
