<?php
/**
 * Akibara Preventas — Módulo "Siguiente Tomo de tu Serie"
 *
 * Cron diario: revisa órdenes completadas hace 7 días, detecta la serie comprada,
 * encuentra el siguiente tomo y envía email via Brevo.
 *
 * Lifted from server-snapshot/plugins/akibara/modules/next-volume/module.php v1.0.0
 * Adapted to akibara-preventas: uses AKB_PREVENTAS_LOADED sentinel instead of AKIBARA_V10_LOADED.
 *
 * @package    Akibara\Preventas
 * @subpackage NextVolume
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Only load when the parent plugin has booted.
if ( ! defined( 'AKB_PREVENTAS_LOADED' ) ) {
    return;
}

if ( defined( 'AKB_PREVENTAS_NEXT_VOL_LOADED' ) ) {
    return;
}
define( 'AKB_PREVENTAS_NEXT_VOL_LOADED', '1.0.0' );

// ══════════════════════════════════════════════════════════════════
// CRON SCHEDULE
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_preventas_next_vol_schedule' ) ) {

    function akb_preventas_next_vol_schedule(): void {
        if ( ! wp_next_scheduled( 'akibara_next_volume_check' ) ) {
            wp_schedule_event( time(), 'daily', 'akibara_next_volume_check' );
        }
    }

    add_action( 'init', 'akb_preventas_next_vol_schedule' );
    add_action( 'akibara_next_volume_check', 'akibara_process_next_volume_emails' );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// MAIN PROCESSOR
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akibara_process_next_volume_emails' ) ) {

    function akibara_process_next_volume_emails(): void {
        // Guard: requires AkibaraBrevo class (from akibara-core or standalone).
        if ( ! class_exists( 'AkibaraBrevo' ) ) {
            error_log( '[akibara-preventas] next-volume: AkibaraBrevo not available.' );
            return;
        }

        $api_key = AkibaraBrevo::get_api_key();
        if ( empty( $api_key ) ) {
            return;
        }

        $date_start = gmdate( 'Y-m-d', strtotime( '-8 days' ) );
        $date_end   = gmdate( 'Y-m-d', strtotime( '-6 days' ) );

        $orders = wc_get_orders(
            array(
                'status'      => array( 'completed' ),
                'date_after'  => $date_start,
                'date_before' => $date_end . ' 23:59:59',
                'limit'       => 50,
                'orderby'     => 'date',
                'order'       => 'DESC',
            )
        );

        if ( empty( $orders ) ) {
            return;
        }

        // Batch-warm meta cache to eliminate N×M queries.
        $_all_pids = array();
        foreach ( $orders as $_o ) {
            foreach ( $_o->get_items() as $_i ) {
                $pid = (int) $_i->get_product_id();
                if ( $pid > 0 ) {
                    $_all_pids[] = $pid;
                }
            }
        }
        if ( $_all_pids ) {
            update_meta_cache( 'post', array_unique( $_all_pids ) );
        }
        unset( $_all_pids, $_o, $_i, $pid );

        $sent_count = 0;

        foreach ( $orders as $order ) {
            if ( $order->get_meta( '_akb_next_vol_sent' ) === 'yes' ) {
                continue;
            }

            $email      = $order->get_billing_email();
            $first_name = $order->get_billing_first_name();
            if ( empty( $email ) ) {
                continue;
            }

            $series_bought = array();
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                $serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
                $numero     = (int) get_post_meta( $product_id, '_akibara_numero', true );

                if ( ! empty( $serie_norm ) && $numero > 0 ) {
                    if ( ! isset( $series_bought[ $serie_norm ] ) || $numero > $series_bought[ $serie_norm ]['numero'] ) {
                        $series_bought[ $serie_norm ] = array(
                            'numero'     => $numero,
                            'serie_name' => get_post_meta( $product_id, '_akibara_serie', true ) ?: ucwords( str_replace( '_', ' ', $serie_norm ) ),
                        );
                    }
                }
            }

            if ( empty( $series_bought ) ) {
                $order->update_meta_data( '_akb_next_vol_sent', 'skip' );
                $order->save();
                continue;
            }

            $recommendation = null;

            foreach ( $series_bought as $serie_norm => $info ) {
                $next_num = $info['numero'] + 1;
                $next     = akibara_find_volume( $serie_norm, $next_num );
                if ( ! $next ) {
                    continue;
                }
                if ( akibara_customer_owns_product( $email, $next['id'] ) ) {
                    continue;
                }

                $recommendation = array(
                    'serie_name' => $info['serie_name'],
                    'bought_num' => $info['numero'],
                    'next_num'   => $next_num,
                    'product'    => $next,
                );
                break;
            }

            if ( ! $recommendation ) {
                $order->update_meta_data( '_akb_next_vol_sent', 'no_next' );
                $order->save();
                continue;
            }

            $sent = akibara_send_next_volume_email( $email, $first_name, $recommendation, $api_key );

            $order->update_meta_data( '_akb_next_vol_sent', $sent ? 'yes' : 'failed' );
            if ( $sent ) {
                $order->update_meta_data( '_akb_next_vol_sent_date', gmdate( 'Y-m-d H:i:s' ) );
            }
            $order->save();

            if ( $sent ) {
                ++$sent_count;
                usleep( 300000 ); // 300ms Brevo rate limit.
            }

            if ( $sent_count >= 20 ) {
                break;
            }
        }
    }

    function akibara_find_volume( string $serie_norm, int $numero ): ?array {
        global $wpdb;

        $product_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT pm1.post_id
                FROM {$wpdb->postmeta} pm1
                INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
                INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
                WHERE pm1.meta_key = '_akibara_serie_norm' AND pm1.meta_value = %s
                AND pm2.meta_key = '_akibara_numero' AND pm2.meta_value = %s
                AND p.post_type = 'product' AND p.post_status = 'publish'
                LIMIT 1",
                $serie_norm,
                (string) $numero
            )
        );

        if ( ! $product_id ) {
            return null;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return null;
        }

        $img_id     = $product->get_image_id();
        $is_reserva = get_post_meta( $product_id, '_akb_reserva', true ) === 'yes';

        if ( $product->is_in_stock() && ! $is_reserva ) {
            $status      = 'in_stock';
            $status_text = 'Disponible';
            $cta_text    = 'Comprar ahora';
        } elseif ( $is_reserva ) {
            $status      = 'preorder';
            $status_text = 'En preventa';
            $cta_text    = 'Reservar ahora';
        } else {
            $status      = 'out_of_stock';
            $status_text = 'Agotado — disponible por encargo';
            $cta_text    = 'Solicitar encargo';
        }

        return array(
            'id'          => (int) $product_id,
            'name'        => $product->get_name(),
            'price'       => '$' . number_format( (float) $product->get_price(), 0, ',', '.' ),
            'url'         => get_permalink( $product_id ),
            'image'       => $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '',
            'status'      => $status,
            'status_text' => $status_text,
            'cta_text'    => $cta_text,
            'cta_url'     => 'out_of_stock' === $status ? home_url( '/encargos/' ) : get_permalink( $product_id ),
        );
    }

    function akibara_customer_owns_product( string $email, int $product_id ): bool {
        $orders = wc_get_orders(
            array(
                'billing_email' => $email,
                'status'        => array( 'completed', 'processing' ),
                'limit'         => 20,
            )
        );

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( (int) $item->get_product_id() === $product_id ) {
                    return true;
                }
            }
        }

        return false;
    }

    function akibara_send_next_volume_email( string $email, string $name, array $rec, string $api_key ): bool {
        $product = $rec['product'];
        $serie   = $rec['serie_name'];
        $name    = $name ?: 'Lector';

        $subjects = array(
            'in_stock'     => "{$name}, el Vol. {$rec['next_num']} de {$serie} te espera",
            'preorder'     => "{$name}, reserva el Vol. {$rec['next_num']} de {$serie}",
            'out_of_stock' => "{$name}, podemos conseguir el Vol. {$rec['next_num']} de {$serie}",
        );
        $subject = $subjects[ $product['status'] ] ?? $subjects['in_stock'];

        // Graceful degradation if AkibaraEmailTemplate is not available.
        if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
            error_log( '[akibara-preventas] next-volume: AkibaraEmailTemplate not available, skip email.' );
            return false;
        }

        $status_colors = array(
            'in_stock'     => AkibaraEmailTemplate::GREEN,
            'preorder'     => AkibaraEmailTemplate::GOLD,
            'out_of_stock' => AkibaraEmailTemplate::HOT,
        );
        $status_color = $status_colors[ $product['status'] ] ?? AkibaraEmailTemplate::GREEN;

        $preheader = 'El siguiente tomo de ' . $serie . ' está listo para ti.';

        $html  = AkibaraEmailTemplate::open();
        $html .= AkibaraEmailTemplate::header( $preheader );
        $html .= AkibaraEmailTemplate::content_open();
        $html .= '<p style="text-align:center;font-size:12px;color:' . AkibaraEmailTemplate::TEXT_MUTED . ';text-transform:uppercase;letter-spacing:0.14em;margin:0 0 4px;font-weight:700;font-family:' . AkibaraEmailTemplate::FONT_HEADING . '">Continúa tu colección</p>';
        $html .= AkibaraEmailTemplate::headline( '¿Ya leíste el Vol. ' . $rec['bought_num'] . '?' );
        $html .= AkibaraEmailTemplate::intro( 'El siguiente tomo de <strong style="color:' . AkibaraEmailTemplate::TEXT_PRIMARY . '">' . esc_html( $serie ) . '</strong> está listo para ti.' );
        $html .= AkibaraEmailTemplate::product_card(
            array(
                'name'  => $product['name'],
                'image' => $product['image'],
                'url'   => $product['url'],
                'price' => $product['price'],
                'qty'   => 1,
            ),
            'cart'
        );
        $html .= '<p style="text-align:center;font-size:13px;color:' . $status_color . ';font-weight:700;margin:8px 0 16px;letter-spacing:0.04em;font-family:' . AkibaraEmailTemplate::FONT_HEADING . '">' . esc_html( $product['status_text'] ) . '</p>';
        $html .= AkibaraEmailTemplate::cta( $product['cta_text'], $product['cta_url'], 'next-volume' );
        $html .= AkibaraEmailTemplate::signature();
        $html .= AkibaraEmailTemplate::content_close();
        $html .= AkibaraEmailTemplate::footer( $email, 'akb_email_unsub' );
        $html .= AkibaraEmailTemplate::close();

        $response = wp_remote_post(
            'https://api.brevo.com/v3/smtp/email',
            array(
                'headers' => array(
                    'api-key'      => $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode(
                    array(
                        'sender'      => array(
                            'name'  => 'Akibara',
                            'email' => 'contacto@akibara.cl',
                        ),
                        'to'          => array(
                            array(
                                'email' => AkibaraBrevo::test_recipient( $email ),
                                'name'  => $name,
                            ),
                        ),
                        'subject'     => $subject,
                        'htmlContent' => $html,
                    )
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            update_option(
                'akibara_brevo_tx_last_error',
                array(
                    'ts'     => time(),
                    'ctx'    => 'next-volume',
                    'reason' => $response->get_error_message(),
                )
            );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        return ( $code >= 200 && $code < 300 );
    }

} // end group wrap

// ── Widget + admin panel ────────────────────────────────────────────────────
// These hooks are read-only for functionality; group wrap applied.
if ( ! function_exists( 'akibara_next_volume_widget_enqueue' ) ) {

    function akibara_next_volume_widget_enqueue(): void {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        wp_enqueue_style(
            'akibara-next-volume-widget',
            AKB_PREVENTAS_URL . 'assets/css/next-volume-widget.css',
            array(),
            AKB_PREVENTAS_VERSION
        );
    }

    function akibara_next_volume_widget_data( int $product_id ): ?array {
        $cache_key = 'akb_nv_widget_' . $product_id;
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached ?: null;
        }

        $serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
        $numero     = (int) get_post_meta( $product_id, '_akibara_numero', true );

        if ( empty( $serie_norm ) || $numero <= 0 ) {
            set_transient( $cache_key, 0, 2 * HOUR_IN_SECONDS );
            return null;
        }

        $next_num = $numero + 1;
        $next     = akibara_find_volume( $serie_norm, $next_num );

        if ( ! $next ) {
            set_transient( $cache_key, 0, 2 * HOUR_IN_SECONDS );
            return null;
        }

        $after_next = akibara_find_volume( $serie_norm, $next_num + 1 );
        $is_last    = ( null === $after_next );

        $serie_terms = get_the_terms( $product_id, 'pa_serie' );
        $serie_name  = ( $serie_terms && ! is_wp_error( $serie_terms ) )
            ? $serie_terms[0]->name
            : ( get_post_meta( $product_id, '_akibara_serie', true )
                ?: ucwords( str_replace( array( '_', '-' ), ' ', $serie_norm ) ) );

        $data = array(
            'serie_norm' => $serie_norm,
            'serie_name' => $serie_name,
            'bought_num' => $numero,
            'next_num'   => $next_num,
            'next'       => $next,
            'is_last'    => $is_last,
        );

        set_transient( $cache_key, $data, 2 * HOUR_IN_SECONDS );
        return $data;
    }

    function akibara_next_volume_widget_render(): void {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        global $product;
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $product_id = $product->get_id();
        $data       = akibara_next_volume_widget_data( $product_id );
        if ( ! $data ) {
            return;
        }

        $user = wp_get_current_user();
        if ( $user && $user->exists() && ! empty( $user->user_email ) ) {
            if ( akibara_customer_owns_product( $user->user_email, (int) $data['next']['id'] ) ) {
                return;
            }
        }

        $next       = $data['next'];
        $next_num   = (int) $data['next_num'];
        $bought_num = (int) $data['bought_num'];

        $badge = '';
        if ( 1 === $bought_num ) {
            $badge = '¡Empiezas la aventura!';
        } elseif ( $data['is_last'] ) {
            $badge = 'Cierra la serie';
        }
        ?>
        <?php // TODO Cell H: replace with mockup spec (UI-SPECS-preventas.md — preventa card 4 estados). ?>
        <aside
            class="akb-nv-widget"
            aria-label="Tu próximo tomo en la serie"
            data-serie="<?php echo esc_attr( $data['serie_norm'] ); ?>"
            data-from="<?php echo esc_attr( (string) $product_id ); ?>"
            data-to="<?php echo esc_attr( (string) $next['id'] ); ?>"
        >
            <div class="akb-nv-widget__inner">
                <header class="akb-nv-widget__header">
                    <span class="akb-nv-widget__kicker">Tu próximo tomo en</span>
                    <h2 class="akb-nv-widget__serie"><?php echo esc_html( $data['serie_name'] ); ?></h2>
                </header>

                <div class="akb-nv-widget__body">
                    <?php if ( ! empty( $next['image'] ) ) : ?>
                        <a
                            class="akb-nv-widget__cover-link"
                            href="<?php echo esc_url( $next['url'] ); ?>"
                            aria-label="<?php echo esc_attr( $next['name'] ); ?>"
                        >
                            <img
                                class="akb-nv-widget__cover"
                                src="<?php echo esc_url( $next['image'] ); ?>"
                                alt="<?php echo esc_attr( $next['name'] ); ?>"
                                loading="lazy"
                                width="160"
                                height="240"
                            />
                        </a>
                    <?php endif; ?>

                    <div class="akb-nv-widget__info">
                        <span class="akb-nv-widget__volume-label">Vol. <?php echo esc_html( (string) $next_num ); ?></span>
                        <h3 class="akb-nv-widget__title">
                            <a href="<?php echo esc_url( $next['url'] ); ?>"><?php echo esc_html( $next['name'] ); ?></a>
                        </h3>
                        <p class="akb-nv-widget__price"><?php echo esc_html( $next['price'] ); ?></p>
                        <p class="akb-nv-widget__status akb-nv-widget__status--<?php echo esc_attr( $next['status'] ); ?>">
                            <?php echo esc_html( $next['status_text'] ); ?>
                        </p>
                        <?php if ( '' !== $badge ) : ?>
                            <p class="akb-nv-widget__badge"><?php echo esc_html( $badge ); ?></p>
                        <?php endif; ?>
                        <a class="akb-nv-widget__cta" href="<?php echo esc_url( $next['cta_url'] ); ?>">
                            <?php echo esc_html( $next['cta_text'] ); ?>
                        </a>
                    </div>
                </div>
            </div>
        </aside>
        <?php
    }

    function akibara_next_volume_admin(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sin permisos' );
        }

        if ( isset( $_POST['akb_run_next_vol'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'akb_next_vol' ) ) {
            akibara_process_next_volume_emails();
            echo '<div class="notice notice-success"><p>Procesamiento completado.</p></div>';
        }

        global $wpdb;
        $use_hpos   = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
                    && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $meta_table = $use_hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;

        $sent    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = '_akb_next_vol_sent' AND meta_value = 'yes'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $skipped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = '_akb_next_vol_sent' AND meta_value IN ('skip','no_next')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ?>
        <div class="akb-page-header">
            <h2 class="akb-page-header__title">Email Siguiente Tomo</h2>
            <p class="akb-page-header__desc">Recomendación automática del siguiente volumen de la serie comprada.</p>
        </div>
        <div class="akb-stats">
            <div class="akb-stat">
                <div class="akb-stat__value akb-stat__value--success"><?php echo (int) $sent; ?></div>
                <div class="akb-stat__label">Emails enviados</div>
            </div>
            <div class="akb-stat">
                <div class="akb-stat__value"><?php echo (int) $skipped; ?></div>
                <div class="akb-stat__label">Sin siguiente tomo</div>
            </div>
        </div>
        <form method="post">
            <?php wp_nonce_field( 'akb_next_vol' ); ?>
            <button type="submit" name="akb_run_next_vol" value="1" class="akb-btn akb-btn--primary">
                Ejecutar ahora (manual)
            </button>
        </form>
        <?php
    }

    add_action( 'woocommerce_after_single_product', 'akibara_next_volume_widget_render', 7 );
    add_action( 'wp_enqueue_scripts', 'akibara_next_volume_widget_enqueue' );

    add_action(
        'woocommerce_product_set_stock',
        static function ( $p ): void {
            if ( $p instanceof WC_Product ) {
                delete_transient( 'akb_nv_widget_' . $p->get_id() );
            }
        }
    );

    add_action(
        'woocommerce_variation_set_stock',
        static function ( $p ): void {
            if ( $p instanceof WC_Product ) {
                $pid = $p->get_parent_id();
                if ( $pid ) {
                    delete_transient( 'akb_nv_widget_' . $pid );
                }
            }
        }
    );

    add_action(
        'save_post_product',
        static function ( int $post_id ): void {
            delete_transient( 'akb_nv_widget_' . $post_id );
        },
        20
    );

    add_action(
        'admin_menu',
        static function (): void {
            if ( ! defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
                add_submenu_page(
                    'woocommerce',
                    'Siguiente Tomo',
                    'Siguiente Tomo',
                    'manage_woocommerce',
                    'akibara-next-volume',
                    'akibara_next_volume_admin'
                );
            }
        }
    );

} // end group wrap
