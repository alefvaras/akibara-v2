<?php
/**
 * Akibara Preventas — Módulo Series Notify
 *
 * Suscripción a series de manga para notificación cuando sale un nuevo tomo.
 *
 * Lifted from server-snapshot/plugins/akibara/modules/series-notify/module.php v1.0.0
 * Adapted: uses AKB_PREVENTAS_LOADED sentinel, updated plugin_dir path reference.
 *
 * @package    Akibara\Preventas
 * @subpackage SeriesNotify
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_PREVENTAS_LOADED' ) ) {
    return;
}

if ( defined( 'AKB_PREVENTAS_SERIES_NOTIFY_LOADED' ) ) {
    return;
}
define( 'AKB_PREVENTAS_SERIES_NOTIFY_LOADED', '1.0.0' );

// ══════════════════════════════════════════════════════════════════
// DB TABLE INSTALL
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_series_notify_maybe_install' ) ) {

    function akb_series_notify_maybe_install(): void {
        if ( get_option( 'akb_series_notify_db_ver' ) !== '1.0' ) {
            akb_series_notify_install();
        }
    }

    function akb_series_notify_install(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'akb_series_subs';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            serie_slug VARCHAR(255) NOT NULL,
            serie_name VARCHAR(255) NOT NULL DEFAULT '',
            token VARCHAR(64) NOT NULL,
            status ENUM('active','unsubscribed') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_notified_at DATETIME DEFAULT NULL,
            last_notified_product_id BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY email_serie (email, serie_slug),
            KEY serie_slug (serie_slug),
            KEY status (status),
            KEY token (token)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'akb_series_notify_db_ver', '1.0' );
    }

    add_action( 'plugins_loaded', 'akb_series_notify_maybe_install' );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// AJAX SUBSCRIBE
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_series_subscribe_handler' ) ) {

    function akb_series_subscribe_handler( array $post ): void {
        $email      = sanitize_email( $post['email'] ?? '' );
        $serie      = sanitize_text_field( $post['serie_slug'] ?? '' );
        $serie_name = sanitize_text_field( $post['serie_name'] ?? '' );

        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => 'Correo no válido' ) );
            return;
        }

        if ( empty( $serie ) ) {
            wp_send_json_error( array( 'message' => 'Serie no detectada' ) );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'akb_series_subs';

        // Rate limit: max 10 active subscriptions per email.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE email = %s AND status = 'active'",
                $email
            )
        );

        if ( $count >= 10 ) {
            wp_send_json_error( array( 'message' => 'Límite de suscripciones alcanzado (máx. 10 series)' ) );
            return;
        }

        $token = wp_generate_password( 32, false );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status FROM {$table} WHERE email = %s AND serie_slug = %s",
                $email,
                $serie
            )
        );

        if ( $existing ) {
            if ( 'active' === $existing->status ) {
                wp_send_json_success( array( 'message' => 'Ya estás suscrito a esta serie' ) );
                return;
            }
            $wpdb->update(
                $table,
                array( 'status' => 'active', 'token' => $token ),
                array( 'id' => $existing->id )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'email'      => $email,
                    'serie_slug' => $serie,
                    'serie_name' => $serie_name,
                    'token'      => $token,
                    'status'     => 'active',
                )
            );
        }

        wp_send_json_success( array( 'message' => 'Te notificaremos cuando salga un nuevo tomo de ' . esc_html( $serie_name ) ) );
    }

    // Register AJAX endpoints.
    add_action( 'wp_ajax_akb_series_subscribe', 'akb_series_subscribe_ajax_handler' );
    add_action( 'wp_ajax_nopriv_akb_series_subscribe', 'akb_series_subscribe_ajax_handler' );

    function akb_series_subscribe_ajax_handler(): void {
        check_ajax_referer( 'akb-series-notify', 'nonce' );
        akb_series_subscribe_handler( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
    }

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// UNSUBSCRIBE
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_series_notify_unsubscribe' ) ) {

    function akb_series_notify_unsubscribe(): void {
        if ( ! isset( $_GET['akb_unsub_series'] ) ) {
            return;
        }

        $token = sanitize_text_field( wp_unslash( $_GET['akb_unsub_series'] ) );
        if ( 32 !== strlen( $token ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'akb_series_subs';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, serie_name FROM {$table} WHERE token = %s AND status = 'active'",
                $token
            )
        );

        if ( $row ) {
            $wpdb->update( $table, array( 'status' => 'unsubscribed' ), array( 'id' => $row->id ) );
            add_action(
                'wp_footer',
                static function () use ( $row ): void {
                    echo '<script>document.addEventListener("DOMContentLoaded",function(){var m=document.createElement("div");m.innerHTML=\'<div style="position:fixed;top:0;left:0;right:0;z-index:9999;background:#161618;color:#fff;text-align:center;padding:16px;border-bottom:2px solid #D90010">Suscripción a <strong>' . esc_js( $row->serie_name ) . '</strong> cancelada exitosamente.</div>\';document.body.appendChild(m);setTimeout(function(){m.remove()},5000)});</script>';
                }
            );
        }
    }

    add_action( 'init', 'akb_series_notify_unsubscribe' );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// CRON + PROCESSOR
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_series_notify_schedule' ) ) {

    function akb_series_notify_schedule(): void {
        if ( ! wp_next_scheduled( 'akb_series_notify_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'akb_series_notify_cron' );
        }
    }

    function akb_series_notify_process(): void {
        if ( ! class_exists( 'AkibaraBrevo' ) ) {
            return;
        }

        $api_key = AkibaraBrevo::get_api_key();
        if ( empty( $api_key ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'akb_series_subs';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $series_slugs = $wpdb->get_col( "SELECT DISTINCT serie_slug FROM {$table} WHERE status = 'active'" );
        if ( empty( $series_slugs ) ) {
            return;
        }

        $sent_total = 0;

        foreach ( $series_slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, 'pa_serie' );
            if ( ! $term ) {
                continue;
            }

            $newest = get_posts(
                array(
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'date_query'     => array( array( 'after' => '48 hours ago' ) ),
                    'tax_query'      => array(
                        array(
                            'taxonomy' => 'pa_serie',
                            'field'    => 'slug',
                            'terms'    => $slug,
                        ),
                    ),
                    'fields'         => 'ids',
                )
            );

            if ( empty( $newest ) ) {
                continue;
            }

            $product_id = (int) $newest[0];
            $product    = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }

            $remaining = 50 - $sent_total;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $subscribers = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, email, serie_name, token FROM {$table}
                     WHERE serie_slug = %s AND status = 'active'
                     AND (last_notified_product_id IS NULL OR last_notified_product_id != %d)
                     ORDER BY id ASC LIMIT %d",
                    $slug,
                    $product_id,
                    max( 1, $remaining )
                )
            );

            if ( empty( $subscribers ) ) {
                continue;
            }

            $img_id       = $product->get_image_id();
            $is_reserva   = get_post_meta( $product_id, '_akb_reserva', true ) === 'yes';
            $reserva_tipo = get_post_meta( $product_id, '_akb_reserva_tipo', true );

            $product_info = array(
                'name'       => $product->get_name(),
                'price'      => '$' . number_format( (float) $product->get_price(), 0, ',', '.' ),
                'url'        => get_permalink( $product_id ),
                'image'      => $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '',
                'is_reserva' => $is_reserva,
                'cta'        => $is_reserva ? ( 'preventa' === $reserva_tipo ? 'Reservar ahora' : 'Encargar' ) : 'Comprar ahora',
            );

            foreach ( $subscribers as $sub ) {
                $sent = akb_series_notify_send_email(
                    $sub->email,
                    $sub->serie_name ?: $term->name,
                    $product_info,
                    $sub->token,
                    $api_key
                );

                if ( $sent ) {
                    $wpdb->update(
                        $table,
                        array(
                            'last_notified_at'         => current_time( 'mysql' ),
                            'last_notified_product_id' => $product_id,
                        ),
                        array( 'id' => $sub->id )
                    );
                    ++$sent_total;
                    usleep( 200000 );
                }

                if ( $sent_total >= 50 ) {
                    return;
                }
            }
        }
    }

    function akb_series_notify_send_email( string $email, string $serie_name, array $product, string $token, string $api_key ): bool {
        if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
            return false;
        }

        $unsub_url  = add_query_arg( 'akb_unsub_series', $token, home_url() );
        $body_html  = AkibaraEmailTemplate::open();
        $body_html .= AkibaraEmailTemplate::header( 'Nuevo tomo de ' . $serie_name . ' disponible en Akibara' );
        $body_html .= AkibaraEmailTemplate::content_open();
        $body_html .= '<p style="text-align:center;font-size:12px;color:' . AkibaraEmailTemplate::GOLD . ';text-transform:uppercase;letter-spacing:0.14em;margin:0 0 8px;font-weight:700;font-family:' . AkibaraEmailTemplate::FONT_HEADING . '">¡Salió el nuevo tomo!</p>';
        $body_html .= AkibaraEmailTemplate::headline( $serie_name );
        $body_html .= AkibaraEmailTemplate::intro( '¡Ya llegó al distrito! El tomo que estabas esperando de <strong style="color:' . AkibaraEmailTemplate::TEXT_PRIMARY . '">' . esc_html( $serie_name ) . '</strong> está listo para sumar a tu colección. Asegúralo rápido antes que se agote.' );
        $body_html .= AkibaraEmailTemplate::product_card(
            array(
                'name'  => $product['name'],
                'image' => $product['image'],
                'url'   => $product['url'],
                'price' => $product['price'],
                'qty'   => 1,
            ),
            'cart'
        );
        $status_color = $product['is_reserva'] ? AkibaraEmailTemplate::GOLD : AkibaraEmailTemplate::GREEN;
        $status_text  = $product['is_reserva'] ? 'En preventa · 5% de descuento por reservar' : 'En stock · envío inmediato';
        $body_html   .= '<p style="text-align:center;font-size:13px;color:' . $status_color . ';font-weight:700;margin:8px 0 16px;letter-spacing:0.04em;font-family:' . AkibaraEmailTemplate::FONT_HEADING . '">' . esc_html( $status_text ) . '</p>';
        $body_html   .= AkibaraEmailTemplate::cta( $product['cta'], $product['url'], 'series-notify' );
        $body_html   .= AkibaraEmailTemplate::paragraph( 'Todos nuestros mangas vienen con funda protectora — cuidamos tu colección como la nuestra.', 'center' );
        $body_html   .= AkibaraEmailTemplate::signature();
        $body_html   .= AkibaraEmailTemplate::content_close();
        $body_html   .= '<tr><td style="padding:28px;border-top:1px solid ' . AkibaraEmailTemplate::BORDER . ';text-align:center">';
        $body_html   .= '<p style="color:' . AkibaraEmailTemplate::TEXT_MUTED . ';font-size:11px;line-height:1.6;margin:0 0 14px">Recibes este email porque te suscribiste a notificaciones de <strong style="color:' . AkibaraEmailTemplate::TEXT_SECONDARY . '">' . esc_html( $serie_name ) . '</strong>.</p>';
        $body_html   .= '<p style="margin:8px 0 0"><a href="' . esc_url( $unsub_url ) . '" style="color:' . AkibaraEmailTemplate::TEXT_MUTED . ';font-size:11px;text-decoration:underline">Cancelar suscripción a ' . esc_html( $serie_name ) . '</a></p>';
        $body_html   .= '</td></tr>';
        $body_html   .= AkibaraEmailTemplate::close();

        $response = wp_remote_post(
            'https://api.brevo.com/v3/smtp/email',
            array(
                'headers' => array(
                    'api-key'      => $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'sender'      => array( 'name' => 'Akibara', 'email' => 'contacto@akibara.cl' ),
                        'to'          => array( array( 'email' => AkibaraBrevo::test_recipient( $email ) ) ),
                        'subject'     => 'Nuevo tomo de ' . $serie_name . ' en Akibara',
                        'htmlContent' => $body_html,
                    )
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            update_option( 'akibara_brevo_tx_last_error', array( 'ts' => time(), 'ctx' => 'series-notify', 'reason' => $response->get_error_message() ) );
            return false;
        }

        return ( wp_remote_retrieve_response_code( $response ) >= 200 && wp_remote_retrieve_response_code( $response ) < 300 );
    }

    add_action( 'init', 'akb_series_notify_schedule' );
    add_action( 'akb_series_notify_cron', 'akb_series_notify_process' );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// FRONTEND BUTTON
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_series_notify_button' ) ) {

    function akb_series_notify_button(): void {
        global $product;
        if ( ! $product ) {
            return;
        }

        $series_terms = get_the_terms( $product->get_id(), 'pa_serie' );
        if ( ! $series_terms || is_wp_error( $series_terms ) ) {
            return;
        }

        $serie      = $series_terms[0];
        $user_email = is_user_logged_in() ? wp_get_current_user()->user_email : '';
        ?>
        <?php // TODO Cell H: replace with mockup spec (encargos checkbox styling from UI-SPECS-preventas.md). ?>
        <div class="aki-series-notify" id="aki-series-notify">
            <button type="button" class="aki-series-notify__toggle" id="aki-series-notify-toggle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                <span>Seguir <?php echo esc_html( $serie->name ); ?></span>
            </button>
            <div class="aki-series-notify__form" id="aki-series-notify-form" style="display:none">
                <p class="aki-series-notify__desc">Te avisamos cuando salga un nuevo tomo de <strong><?php echo esc_html( $serie->name ); ?></strong></p>
                <div class="aki-series-notify__row">
                    <input type="email" id="aki-series-email" class="aki-series-notify__input"
                            placeholder="Tu correo" value="<?php echo esc_attr( $user_email ); ?>" required>
                    <button type="button" id="aki-series-submit" class="aki-series-notify__btn"
                            data-serie-slug="<?php echo esc_attr( $serie->slug ); ?>"
                            data-serie-name="<?php echo esc_attr( $serie->name ); ?>">
                        Suscribirme
                    </button>
                </div>
                <p class="aki-series-notify__status" id="aki-series-status"></p>
            </div>
        </div>
        <?php
    }

    add_action( 'woocommerce_single_product_summary', 'akb_series_notify_button', 45 );

    add_action(
        'wp_enqueue_scripts',
        static function (): void {
            if ( ! is_product() ) {
                return;
            }
            wp_enqueue_script(
                'akb-series-notify',
                AKB_PREVENTAS_URL . 'modules/series-notify/series-notify.js',
                array(),
                AKB_PREVENTAS_VERSION,
                true
            );
            wp_localize_script(
                'akb-series-notify',
                'akbSeriesNotify',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce'   => wp_create_nonce( 'akb-series-notify' ),
                )
            );
        }
    );

} // end group wrap
