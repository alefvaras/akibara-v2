<?php
/**
 * Akibara Preventas — Módulo Editorial Notify
 *
 * Suscripción a editoriales para notificación cuando llegan novedades.
 * Clientes eligen una o más editoriales (Ivrea, Panini, Planeta, etc.) y
 * reciben un email cuando un producto de esa editorial se publica/repone.
 *
 * No existe fuente en server-snapshot (módulo nuevo en Sprint 3).
 * Consume API pública akibara-core: akb_editorial_pattern(), AkibaraBrevo.
 *
 * @package    Akibara\Preventas
 * @subpackage EditorialNotify
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Only load when the parent plugin has booted.
if ( ! defined( 'AKB_PREVENTAS_LOADED' ) ) {
    return;
}

if ( defined( 'AKB_PREVENTAS_EDITORIAL_NOTIFY_LOADED' ) ) {
    return;
}
define( 'AKB_PREVENTAS_EDITORIAL_NOTIFY_LOADED', '1.0.0' );

// ══════════════════════════════════════════════════════════════════
// DB TABLE INSTALL
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_notify_maybe_install' ) ) {

    function akb_editorial_notify_maybe_install(): void {
        if ( get_option( 'akb_editorial_notify_db_ver' ) !== '1.0' ) {
            akb_editorial_notify_install();
        }
    }

    function akb_editorial_notify_install(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'akb_editorial_subs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            editorial_slug VARCHAR(100) NOT NULL,
            editorial_name VARCHAR(255) NOT NULL DEFAULT '',
            token VARCHAR(64) NOT NULL,
            status ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_notified_at DATETIME DEFAULT NULL,
            last_notified_product_id BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email_editorial (email, editorial_slug),
            KEY editorial_slug (editorial_slug),
            KEY status (status),
            KEY token (token)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'akb_editorial_notify_db_ver', '1.0' );
    }

    add_action( 'plugins_loaded', 'akb_editorial_notify_maybe_install', 20 );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// EDITORIAL LIST (mirrors Brevo IDs from Cell B — READ-ONLY here)
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_notify_get_list' ) ) {

    /**
     * Returns the list of subscribable editoriales.
     *
     * Keyed by slug (used in DB). Label is display name.
     * Matches the 8 Brevo editorial lists in akibara-marketing (IDs 24-31).
     * Filterable to add future editoriales without modifying core list.
     *
     * @return array<string, string> slug => label
     */
    function akb_editorial_notify_get_list(): array {
        return (array) apply_filters( 'akb_editorial_notify_list', array(
            'ivrea-ar'   => 'Ivrea Argentina',
            'panini-ar'  => 'Panini Argentina',
            'planeta-es' => 'Planeta España',
            'milky-way'  => 'Milky Way Ediciones',
            'ovni-press' => 'OVNI Press',
            'ivrea-es'   => 'Ivrea España',
            'panini-es'  => 'Panini España',
            'arechi'     => 'Arechi Manga',
        ) );
    }

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// AJAX — SUBSCRIBE
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_notify_subscribe_ajax' ) ) {

    function akb_editorial_notify_subscribe_ajax(): void {
        check_ajax_referer( 'akb_editorial_sub', '_nonce' );

        $email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $editorial = sanitize_key( wp_unslash( $_POST['editorial'] ?? '' ) );

        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Correo no válido.' ) );
            return;
        }

        $list = akb_editorial_notify_get_list();
        if ( ! array_key_exists( $editorial, $list ) ) {
            wp_send_json_error( array( 'message' => 'Editorial no reconocida.' ) );
            return;
        }

        // Rate limit: max 8 active subscriptions per email (one per editorial).
        global $wpdb;
        $table = $wpdb->prefix . 'akb_editorial_subs';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE email = %s AND status = 'active'",
                $email
            )
        );

        if ( $count >= 8 ) {
            wp_send_json_error( array( 'message' => 'Ya tienes el máximo de suscripciones activas (8 editoriales).' ) );
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status FROM {$table} WHERE email = %s AND editorial_slug = %s",
                $email,
                $editorial
            )
        );

        if ( $existing ) {
            if ( 'active' === $existing->status ) {
                wp_send_json_success( array( 'message' => 'Ya estás suscrito a esta editorial.' ) );
                return;
            }

            // Reactivate.
            $wpdb->update(
                $table,
                array( 'status' => 'active', 'token' => wp_generate_password( 32, false ) ),
                array( 'id' => $existing->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'email'          => $email,
                    'editorial_slug' => $editorial,
                    'editorial_name' => $list[ $editorial ],
                    'token'          => wp_generate_password( 32, false ),
                    'status'         => 'active',
                ),
                array( '%s', '%s', '%s', '%s', '%s' )
            );
        }

        wp_send_json_success( array( 'message' => 'Te avisaremos cuando lleguen novedades de ' . esc_html( $list[ $editorial ] ) . '.' ) );
    }

    add_action( 'wp_ajax_akb_editorial_subscribe',        'akb_editorial_notify_subscribe_ajax' );
    add_action( 'wp_ajax_nopriv_akb_editorial_subscribe', 'akb_editorial_notify_subscribe_ajax' );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// AJAX — UNSUBSCRIBE (token-based, no login required)
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_notify_unsubscribe_ajax' ) ) {

    function akb_editorial_notify_unsubscribe_ajax(): void {
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        if ( empty( $token ) ) {
            wp_die( 'Token no válido.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'akb_editorial_subs';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, email, editorial_name FROM {$table} WHERE token = %s AND status = 'active'",
                $token
            )
        );

        if ( ! $row ) {
            wp_die( 'El enlace de baja ya no es válido o ya se procesó.' );
        }

        $wpdb->update(
            $table,
            array( 'status' => 'unsubscribed' ),
            array( 'id'     => $row->id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_safe_redirect( home_url( '/?akb_editorial_unsub=1' ) );
        exit;
    }

    add_action( 'wp_ajax_akb_editorial_unsub',        'akb_editorial_notify_unsubscribe_ajax' );
    add_action( 'wp_ajax_nopriv_akb_editorial_unsub', 'akb_editorial_notify_unsubscribe_ajax' );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// CRON — DISPATCH NOTIFICATIONS ON PRODUCT PUBLISH / RESTOCK
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_notify_on_product_publish' ) ) {

    /**
     * Hook: when a product transitions to 'publish', check if its editorial
     * has active subscribers and dispatch notification emails.
     *
     * Uses a transient lock (1h TTL) per product to avoid duplicate sends
     * if admin saves the product multiple times in a row.
     *
     * @param int $product_id
     */
    function akb_editorial_notify_on_product_publish( int $product_id ): void {
        // Dedup: skip if we already sent for this product in the last hour.
        $lock_key = 'akb_editorial_notify_sent_' . $product_id;
        if ( get_transient( $lock_key ) ) {
            return;
        }

        $editorial_slug = akb_editorial_notify_detect_editorial( $product_id );
        if ( empty( $editorial_slug ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'akb_editorial_subs';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $subscribers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT email, token, editorial_name FROM {$table} WHERE editorial_slug = %s AND status = 'active'",
                $editorial_slug
            )
        );

        if ( empty( $subscribers ) ) {
            return;
        }

        // Prevent duplicate notifications within 1 hour.
        set_transient( $lock_key, 1, HOUR_IN_SECONDS );

        // Queue async dispatch via WP cron to avoid blocking the admin save.
        wp_schedule_single_event(
            time() + 30,
            'akb_editorial_notify_dispatch',
            array( $product_id, $editorial_slug, array_column( $subscribers, null, 'email' ) )
        );
    }

    add_action(
        'transition_post_status',
        static function ( string $new_status, string $old_status, \WP_Post $post ): void {
            if ( 'publish' !== $new_status || 'publish' === $old_status ) {
                return;
            }
            if ( 'product' !== $post->post_type ) {
                return;
            }
            akb_editorial_notify_on_product_publish( $post->ID );
        },
        10,
        3
    );

    // Also trigger when stock goes from 0 → positive (back in stock).
    add_action(
        'woocommerce_product_set_stock',
        static function ( \WC_Product $product ): void {
            if ( $product->get_stock_quantity() > 0 ) {
                akb_editorial_notify_on_product_publish( $product->get_id() );
            }
        }
    );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// DETECT EDITORIAL SLUG FROM PRODUCT ATTRIBUTES / TITLE
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_notify_detect_editorial' ) ) {

    /**
     * Attempts to detect the editorial slug from product meta, terms, or title.
     *
     * Resolution order:
     * 1. Product attribute `pa_editorial` (most reliable).
     * 2. `_akibara_editorial` post meta (set by akibara-core index).
     * 3. Title pattern match via akb_editorial_pattern() (fallback).
     *
     * @param int $product_id
     * @return string slug from akb_editorial_notify_get_list(), or '' if not found.
     */
    function akb_editorial_notify_detect_editorial( int $product_id ): string {
        static $slug_map = null;

        // Build reverse map: canonical name fragment → slug.
        if ( null === $slug_map ) {
            $slug_map = array(
                'ivrea argentina' => 'ivrea-ar',
                'ivrea ar'        => 'ivrea-ar',
                'panini argentina'=> 'panini-ar',
                'panini ar'       => 'panini-ar',
                'planeta'         => 'planeta-es',
                'milky way'       => 'milky-way',
                'milkyway'        => 'milky-way',
                'ovni'            => 'ovni-press',
                'ivrea'           => 'ivrea-es',
                'panini'          => 'panini-es',
                'arechi'          => 'arechi',
            );
        }

        // 1. Check product attribute pa_editorial.
        $terms = get_the_terms( $product_id, 'pa_editorial' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $name_lower = strtolower( $term->name );
                foreach ( $slug_map as $fragment => $slug ) {
                    if ( str_contains( $name_lower, $fragment ) ) {
                        return $slug;
                    }
                }
            }
        }

        // 2. Check _akibara_editorial meta (set by core indexer).
        $meta_editorial = strtolower( (string) get_post_meta( $product_id, '_akibara_editorial', true ) );
        if ( ! empty( $meta_editorial ) ) {
            foreach ( $slug_map as $fragment => $slug ) {
                if ( str_contains( $meta_editorial, $fragment ) ) {
                    return $slug;
                }
            }
        }

        // 3. Title pattern fallback (akibara-core API).
        if ( function_exists( 'akb_extract_info' ) ) {
            $title = get_the_title( $product_id );
            $info  = akb_extract_info( $title );
            if ( ! empty( $info['editorial'] ) ) {
                $editorial_lower = strtolower( $info['editorial'] );
                foreach ( $slug_map as $fragment => $slug ) {
                    if ( str_contains( $editorial_lower, $fragment ) ) {
                        return $slug;
                    }
                }
            }
        }

        return '';
    }

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// CRON HANDLER — SEND NOTIFICATION EMAILS
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_notify_dispatch' ) ) {

    /**
     * Cron handler: sends editorial notification emails in batches.
     *
     * @param int    $product_id      WC product ID.
     * @param string $editorial_slug  Slug from akb_editorial_notify_get_list().
     * @param array  $subscribers     Keyed by email: ['email','token','editorial_name']
     */
    function akb_editorial_notify_dispatch( int $product_id, string $editorial_slug, array $subscribers ): void {
        if ( ! class_exists( 'AkibaraBrevo' ) ) {
            error_log( '[akibara-preventas] editorial-notify: AkibaraBrevo not available, skip dispatch.' );
            return;
        }

        $api_key = AkibaraBrevo::get_api_key();
        if ( empty( $api_key ) ) {
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        $product_name = $product->get_name();
        $product_url  = get_permalink( $product_id );
        $img_id       = $product->get_image_id();
        $image_url    = $img_id ? (string) wp_get_attachment_image_url( $img_id, 'medium' ) : '';
        $price        = '$' . number_format( (float) $product->get_price(), 0, ',', '.' );

        $list          = akb_editorial_notify_get_list();
        $editorial_name = $list[ $editorial_slug ] ?? $editorial_slug;

        $is_reserva = get_post_meta( $product_id, '_akb_reserva', true ) === 'yes';
        $cta_text   = $is_reserva ? 'Reservar ahora' : 'Ver producto';

        global $wpdb;
        $table    = $wpdb->prefix . 'akb_editorial_subs';
        $sent     = 0;

        foreach ( $subscribers as $row ) {
            $email = $row->email ?? ( $row['email'] ?? '' );
            $token = $row->token ?? ( $row['token'] ?? '' );

            if ( empty( $email ) ) {
                continue;
            }

            $unsub_url = admin_url( 'admin-ajax.php' ) . '?action=akb_editorial_unsub&token=' . rawurlencode( $token );

            if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
                // Graceful degradation: use wp_mail plain text.
                $subject = 'Novedad de ' . $editorial_name . ': ' . $product_name;
                $body    = "Hola,\n\nHay una novedad de {$editorial_name} que podría interesarte: {$product_name}\n\n{$product_url}\n\nPara dejar de recibir estas notificaciones: {$unsub_url}";
                wp_mail( $email, $subject, $body );
            } else {
                $subject    = 'Novedad de ' . $editorial_name . ': ' . $product_name;
                $preheader  = 'Llega ' . $product_name . ' — directo a tu lista de seguimiento.';

                $html  = AkibaraEmailTemplate::open();
                $html .= AkibaraEmailTemplate::header( $preheader );
                $html .= AkibaraEmailTemplate::content_open();
                $html .= '<p style="text-align:center;font-size:12px;color:' . AkibaraEmailTemplate::TEXT_MUTED . ';text-transform:uppercase;letter-spacing:0.14em;margin:0 0 4px;font-weight:700;font-family:' . AkibaraEmailTemplate::FONT_HEADING . '">Novedad de ' . esc_html( $editorial_name ) . '</p>';
                $html .= AkibaraEmailTemplate::headline( '¡Llegó a Akibara!' );
                $html .= AkibaraEmailTemplate::intro( '<strong style="color:' . AkibaraEmailTemplate::TEXT_PRIMARY . '">' . esc_html( $product_name ) . '</strong> ya está disponible.' );
                $html .= AkibaraEmailTemplate::product_card(
                    array(
                        'name'  => $product_name,
                        'image' => $image_url,
                        'url'   => $product_url,
                        'price' => $price,
                        'qty'   => 1,
                    ),
                    'cart'
                );
                $html .= AkibaraEmailTemplate::cta( $cta_text, $product_url, 'editorial-notify' );
                $html .= AkibaraEmailTemplate::signature();
                $html .= AkibaraEmailTemplate::content_close();
                $html .= AkibaraEmailTemplate::footer( $email, 'akb_editorial_unsub' );
                $html .= AkibaraEmailTemplate::close();

                wp_remote_post(
                    'https://api.brevo.com/v3/smtp/email',
                    array(
                        'headers' => array(
                            'api-key'      => $api_key,
                            'Content-Type' => 'application/json',
                        ),
                        'body'    => wp_json_encode(
                            array(
                                'sender'      => array( 'name' => 'Akibara', 'email' => 'contacto@akibara.cl' ),
                                'to'          => array( array(
                                    'email' => AkibaraBrevo::test_recipient( $email ),
                                    'name'  => '',
                                ) ),
                                'subject'     => $subject,
                                'htmlContent' => $html,
                            )
                        ),
                        'timeout' => 10,
                    )
                );
            }

            // Update last_notified_at + last_notified_product_id.
            $wpdb->update(
                $table,
                array(
                    'last_notified_at'         => current_time( 'mysql', 1 ),
                    'last_notified_product_id' => $product_id,
                ),
                array( 'email' => $email, 'editorial_slug' => $editorial_slug ),
                array( '%s', '%d' ),
                array( '%s', '%s' )
            );

            ++$sent;
            usleep( 300000 ); // 300ms Brevo rate limit.

            if ( $sent >= 50 ) {
                // P1 FIX (mesa-22 + mesa-11): cap 50 sin requeue dejaba >50 suscriptores
                // sin notificación. Ahora encolamos el remainder en otro cron tick.
                $remainder = array_slice( $subscribers, $sent );
                if ( ! empty( $remainder ) ) {
                    wp_schedule_single_event(
                        time() + 60, // +60s para respetar rate limits Brevo
                        'akb_editorial_notify_dispatch',
                        array( $product_id, $editorial_slug, $remainder )
                    );
                    if ( function_exists( 'akb_log' ) ) {
                        akb_log(
                            'editorial-notify',
                            'info',
                            sprintf( 'Batch capped at 50 — requeued %d remaining', count( $remainder ) ),
                            array( 'product_id' => $product_id, 'editorial' => $editorial_slug )
                        );
                    }
                }
                break;
            }
        }
    }

    add_action( 'akb_editorial_notify_dispatch', 'akb_editorial_notify_dispatch', 10, 3 );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// ADMIN PANEL
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_notify_admin_page' ) ) {

    function akb_editorial_notify_admin_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sin permisos.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'akb_editorial_subs';

        // Stats por editorial (existente).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats = $wpdb->get_results(
            "SELECT editorial_slug, editorial_name, COUNT(*) AS total,
             SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS activos,
             SUM(CASE WHEN status='unsubscribed' THEN 1 ELSE 0 END) AS bajas,
             MAX(last_notified_at) AS ultimo_envio
             FROM {$table} GROUP BY editorial_slug ORDER BY activos DESC, total DESC"
        );

        // KPIs globales.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $kpi_total_subs    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $kpi_active_subs   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active'" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $kpi_editoriales   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT editorial_slug) FROM {$table} WHERE status='active'" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $kpi_recent_notif  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE last_notified_at > DATE_SUB(NOW(), INTERVAL 7 DAY)" );
        $kpi_churn_pct     = $kpi_total_subs > 0 ? round( ( ( $kpi_total_subs - $kpi_active_subs ) / $kpi_total_subs ) * 100, 1 ) : 0;
        ?>
        <div class="wrap akb-admin-page">
            <div class="akb-page-header">
                <h1 class="akb-page-header__title">📨 Notificaciones de Editorial</h1>
                <p class="akb-page-header__desc">
                    Suscriptores que reciben aviso cuando publicamos un producto nuevo de su editorial favorita.
                    Despachado vía Brevo + WP Cron.
                </p>
            </div>

            <!-- KPIs -->
            <div class="akb-stats">
                <div class="akb-stat">
                    <div class="akb-stat__value akb-stat__value--info"><?php echo number_format( $kpi_active_subs ); ?></div>
                    <div class="akb-stat__label">Suscriptores Activos</div>
                </div>
                <div class="akb-stat">
                    <div class="akb-stat__value"><?php echo number_format( $kpi_total_subs ); ?></div>
                    <div class="akb-stat__label">Total Histórico</div>
                </div>
                <div class="akb-stat">
                    <div class="akb-stat__value akb-stat__value--success"><?php echo number_format( $kpi_editoriales ); ?></div>
                    <div class="akb-stat__label">Editoriales Activas</div>
                </div>
                <div class="akb-stat">
                    <div class="akb-stat__value akb-stat__value--info"><?php echo number_format( $kpi_recent_notif ); ?></div>
                    <div class="akb-stat__label">Notif. (últ. 7d)</div>
                </div>
                <div class="akb-stat">
                    <div class="akb-stat__value <?php echo $kpi_churn_pct > 30 ? 'akb-stat__value--warning' : ( $kpi_churn_pct > 50 ? 'akb-stat__value--error' : 'akb-stat__value--success' ); ?>"><?php echo number_format( $kpi_churn_pct, 1 ); ?>%</div>
                    <div class="akb-stat__label">Tasa de Bajas</div>
                </div>
            </div>

            <!-- Tabla por editorial -->
            <div class="akb-card akb-card--section">
                <h2 class="akb-section-title">📊 Suscriptores por Editorial</h2>

                <?php if ( empty( $stats ) ) : ?>
                    <div class="akb-notice akb-notice--info">
                        <p>📭 <strong>Sin suscriptores aún.</strong> El módulo está activo y listo para recibir suscripciones desde el frontend (componente "Avísame cuando llegue de [editorial]").</p>
                    </div>
                <?php else : ?>
                    <table class="akb-table">
                        <thead>
                            <tr>
                                <th>Editorial</th>
                                <th style="text-align:right">Activos</th>
                                <th style="text-align:right">Bajas</th>
                                <th style="text-align:right">Total</th>
                                <th style="text-align:right">% Activos</th>
                                <th>Último envío</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $stats as $row ) :
                                $activos    = (int) $row->activos;
                                $bajas      = (int) $row->bajas;
                                $total      = (int) $row->total;
                                $pct_active = $total > 0 ? round( ( $activos / $total ) * 100, 0 ) : 0;
                                $ultimo     = $row->ultimo_envio ? human_time_diff( strtotime( $row->ultimo_envio ), current_time( 'timestamp', true ) ) . ' atrás' : '—';
                                $health     = $activos === 0 ? 'akb-badge--inactive' : ( $pct_active >= 80 ? 'akb-badge--active' : 'akb-badge--warning' );
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html( $row->editorial_name ?: $row->editorial_slug ); ?></strong>
                                        <br><small style="color:var(--aki-text-muted, #8A8A8A);"><code><?php echo esc_html( $row->editorial_slug ); ?></code></small>
                                    </td>
                                    <td style="text-align:right"><span class="akb-badge <?php echo esc_attr( $health ); ?>"><?php echo $activos; ?></span></td>
                                    <td style="text-align:right"><?php echo $bajas; ?></td>
                                    <td style="text-align:right"><?php echo $total; ?></td>
                                    <td style="text-align:right"><strong><?php echo $pct_active; ?>%</strong></td>
                                    <td><?php echo esc_html( $ultimo ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Cómo funciona -->
            <div class="akb-card akb-card--section">
                <h2 class="akb-section-title">⚙️ Cómo funciona</h2>
                <ol style="margin:0;padding-left:24px;line-height:1.9">
                    <li>Cliente se suscribe en frontend (input email + selección editorial).</li>
                    <li>Cuando publicás un producto nuevo de esa editorial, se programa cron <code>akb_editorial_notify_dispatch</code> +30s.</li>
                    <li>El cron envía emails vía Brevo (max 50 por tick — si hay más, <strong>se requeue automático</strong>).</li>
                    <li>Cliente puede darse de baja con link único en el email (token de 32 chars).</li>
                </ol>
                <p style="margin-top:14px;color:var(--aki-text-muted, #8A8A8A);font-size:12px">
                    Tabla DB: <code>wp_akb_editorial_subs</code> — Brevo guard activo (testing mode redirige a alejandro.fvaras@gmail.com).
                </p>
            </div>
        </div>
        <?php
    }

    add_action(
        'admin_menu',
        static function (): void {
            if ( ! defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
                add_submenu_page(
                    'akibara',
                    'Notif. Editoriales',
                    '📨 Notif. Editoriales',
                    'manage_woocommerce',
                    'akibara-editorial-notify',
                    'akb_editorial_notify_admin_page'
                );
            }
        }
    );

} // end group wrap
