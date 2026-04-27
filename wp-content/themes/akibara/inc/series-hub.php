<?php
/**
 * Akibara — Hub de Franquicias (Deferred + branding)
 *
 * Renderiza un bloque ligero en la ficha y carga la coleccion por AJAX
 * para proteger TTFB y mejorar conversion.
 *
 * @package Akibara
 * @version 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'akibara_series_hub_cache_version' ) ) {
    function akibara_series_hub_cache_version(): int {
        $version = (int) get_option( 'akb_series_hub_cache_version', 1 );
        return $version > 0 ? $version : 1;
    }
}

if ( ! function_exists( 'akibara_series_hub_bump_cache_version' ) ) {
    function akibara_series_hub_bump_cache_version( $arg1 = null, $arg2 = null, $arg3 = null ): void {
        if ( is_numeric( $arg1 ) ) {
            $post_id = (int) $arg1;
            if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
                return;
            }
        }

        update_option( 'akb_series_hub_cache_version', akibara_series_hub_cache_version() + 1, false );
    }
}

add_action( 'save_post_product', 'akibara_series_hub_bump_cache_version', 20, 3 );
add_action( 'woocommerce_product_set_stock', 'akibara_series_hub_bump_cache_version', 20 );
add_action( 'woocommerce_product_set_stock_status', 'akibara_series_hub_bump_cache_version', 20, 3 );

add_filter( 'litespeed_optm_inline_js_defer_exc', function ( $exc ): array {
    $exc   = is_array( $exc ) ? $exc : [];
    $exc[] = 'akibaraSeriesHubReady';
    $exc[] = 'akibara_load_series_hub';
    $exc[] = 'akibara-series-hub';
    $exc[] = 'series-hub-v2.js';
    return $exc;
} );

add_filter( 'litespeed_optm_js_defer_exc', function ( $exc ): array {
    if ( ! is_array( $exc ) ) {
        $exc = preg_split( '/[\r\n]+/', (string) $exc ) ?: [];
    }
    $exc   = array_values( array_filter( array_map( 'trim', $exc ) ) );
    $exc[] = 'akibaraSeriesHubReady';
    $exc[] = 'akibara_load_series_hub';
    $exc[] = 'akibara-series-hub';
    $exc[] = 'series-hub-v2.js';
    return $exc;
} );

add_filter( 'litespeed_optimize_js_excludes', function ( $exc ): array {
    if ( ! is_array( $exc ) ) {
        $exc = preg_split( '/[\r\n]+/', (string) $exc ) ?: [];
    }
    $exc   = array_values( array_filter( array_map( 'trim', $exc ) ) );
    $exc[] = 'akibaraSeriesHubReady';
    $exc[] = 'akibara_load_series_hub';
    $exc[] = 'akibara-series-hub';
    $exc[] = 'series-hub-v2.js';
    return $exc;
} );

add_action( 'wp_enqueue_scripts', function (): void {
    if ( ! is_product() ) {
        return;
    }

    $ver = defined( 'AKIBARA_THEME_VERSION' ) ? AKIBARA_THEME_VERSION . '.sh3' : '1.0.0';
    wp_enqueue_script(
        'akibara-series-hub',
        get_template_directory_uri() . '/assets/js/series-hub-v2.js',
        [],
        $ver,
        true
    );
}, 25 );

add_filter( 'script_loader_tag', function ( string $tag, string $handle ): string {
    if ( $handle !== 'akibara-series-hub' ) {
        return $tag;
    }

    if ( strpos( $tag, 'data-no-optimize' ) === false ) {
        $tag = str_replace( '<script ', '<script data-no-optimize="1" ', $tag );
    }
    if ( strpos( $tag, 'data-no-defer' ) === false ) {
        $tag = str_replace( '<script ', '<script data-no-defer="1" ', $tag );
    }

    return $tag;
}, 20, 2 );

if ( ! function_exists( 'akibara_series_hub_get_source' ) ) {
    function akibara_series_hub_get_source( int $product_id ): array {
        $series_terms = get_the_terms( $product_id, 'pa_serie' );
        if ( $series_terms && ! is_wp_error( $series_terms ) ) {
            $term = reset( $series_terms );
            if ( $term instanceof WP_Term ) {
                return [
                    'type'  => 'term',
                    'value' => (int) $term->term_id,
                    'label' => $term->name,
                ];
            }
        }

        $serie_norm = trim( (string) get_post_meta( $product_id, '_akibara_serie_norm', true ) );
        if ( $serie_norm === '' ) {
            return [];
        }

        return [
            'type'  => 'meta',
            'value' => $serie_norm,
            'label' => ucwords( str_replace( [ '_', '-' ], ' ', $serie_norm ) ),
        ];
    }
}

if ( ! function_exists( 'akibara_series_hub_get_data' ) ) {
    function akibara_series_hub_get_data( int $product_id ): array {
        $source = akibara_series_hub_get_source( $product_id );
        if ( empty( $source ) ) {
            return [];
        }

        $series_key = $source['type'] . '|' . (string) $source['value'];
        $cache_key  = 'akb_sh_' . akibara_series_hub_cache_version() . '_' . substr( md5( $series_key ), 0, 16 );
        $cached     = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $args = [
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => 80,
            'fields'                 => 'ids',
            'meta_key'               => '_akibara_numero',
            'orderby'                => 'meta_value_num',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if ( $source['type'] === 'term' ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'pa_serie',
                    'field'    => 'term_id',
                    'terms'    => [ (int) $source['value'] ],
                ],
            ];
        } else {
            $args['meta_query'] = [
                [
                    'key'   => '_akibara_serie_norm',
                    'value' => (string) $source['value'],
                ],
            ];
        }

        $query = new WP_Query( $args );
        if ( ! $query->have_posts() || count( $query->posts ) < 2 ) {
            set_transient( $cache_key, [], 6 * HOUR_IN_SECONDS );
            return [];
        }

        $items = [];
        foreach ( $query->posts as $series_product_id ) {
            $series_product = wc_get_product( $series_product_id );
            if ( ! $series_product ) {
                continue;
            }

            $reserva_tipo = (string) get_post_meta( $series_product_id, '_akb_reserva_tipo', true );
            $is_preorder  = ( $reserva_tipo === 'preventa' );
            if ( ! $is_preorder && function_exists( 'akb_reserva_esta_activa' ) ) {
                $is_preorder = akb_reserva_esta_activa( $series_product ) && ( (string) $series_product->get_meta( '_akb_reserva_tipo' ) === 'preventa' );
            }

            $status = 'outofstock';
            if ( $is_preorder ) {
                $status = 'preorder';
            } elseif ( $series_product->is_in_stock() && $series_product->is_purchasable() ) {
                $status = 'instock';
            }

            $image_id  = $series_product->get_image_id();
            $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );
            $number    = trim( (string) get_post_meta( $series_product_id, '_akibara_numero', true ) );
            $number    = $number !== '' ? $number : '?';

            $items[] = [
                'id'        => (int) $series_product_id,
                'title'     => $series_product->get_name(),
                'url'       => get_permalink( $series_product_id ),
                'notify'    => get_permalink( $series_product_id ) . '#aki-notify-single',
                'number'    => $number,
                'image'     => $image_url,
                'price'     => $series_product->get_price_html(),
                'price_raw' => (float) wc_get_price_to_display( $series_product ),
                'status'    => $status,
            ];
        }

        if ( count( $items ) < 2 ) {
            set_transient( $cache_key, [], 6 * HOUR_IN_SECONDS );
            return [];
        }

        $payload = [
            'label' => (string) $source['label'],
            'items' => $items,
        ];

        set_transient( $cache_key, $payload, 6 * HOUR_IN_SECONDS );
        return $payload;
    }
}

if ( ! function_exists( 'akibara_series_hub_get_user_purchased_ids' ) ) {
    function akibara_series_hub_get_user_purchased_ids( int $user_id ): array {
        if ( $user_id <= 0 ) return [];

        $cache_key = 'akb_purch_' . $user_id;
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) return $cached;

        $orders = wc_get_orders( [
            'customer' => $user_id,
            'status'   => [ 'completed', 'processing' ],
            'limit'    => 100,
            'return'   => 'ids',
            'orderby'  => 'date',
            'order'    => 'DESC',
        ] );

        $product_ids = [];
        foreach ( $orders as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;
            foreach ( $order->get_items() as $item ) {
                $pid = (int) $item->get_product_id();
                if ( $pid > 0 ) $product_ids[] = $pid;
            }
        }

        $product_ids = array_values( array_unique( $product_ids ) );
        set_transient( $cache_key, $product_ids, 30 * MINUTE_IN_SECONDS );
        return $product_ids;
    }
}

if ( ! function_exists( 'akibara_series_hub_render_markup' ) ) {
    function akibara_series_hub_render_markup( array $hub_data, int $current_product_id, array $owned_ids = [] ): string {
        $items = $hub_data['items'] ?? [];
        if ( count( $items ) < 2 ) {
            return '';
        }

        $bundle_ids   = [];
        $bundle_total = 0.0;
        $series_label = (string) ( $hub_data['label'] ?? '' );

        ob_start();
        ?>
        <div class="akb-series-hub__loaded">
            <div class="akb-series-hub__nav-wrap">
                <button type="button" class="akb-series-hub__nav-btn akb-series-hub__nav-btn--prev js-akb-sh-nav" data-dir="-1" aria-label="Anterior">
                    <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
                
                <div class="akb-series-hub__track js-akb-sh-track" role="list" aria-label="Tomos de la serie">
                <?php foreach ( $items as $item ) :
                    $is_current = ( (int) $item['id'] === $current_product_id );
                    $status     = (string) $item['status'];

                    if ( ! $is_current && $status === 'instock' ) {
                        $bundle_ids[] = (int) $item['id'];
                        $bundle_total += (float) $item['price_raw'];
                    }

                    $is_owned   = ! $is_current && in_array( (int) $item['id'], $owned_ids, true );
                    $card_class = 'akb-sh-card';
                    if ( $is_current ) {
                        $card_class .= ' akb-sh-card--current';
                    } elseif ( $is_owned ) {
                        $card_class .= ' akb-sh-card--owned';
                    } elseif ( $status === 'preorder' ) {
                        $card_class .= ' akb-sh-card--pre';
                    } elseif ( $status === 'outofstock' ) {
                        $card_class .= ' akb-sh-card--out';
                    }
                    ?>
                    <article class="<?php echo esc_attr( $card_class ); ?>" role="listitem">
                        <a href="<?php echo esc_url( $item['url'] ); ?>" class="akb-sh-card__cover" title="<?php echo esc_attr( $item['title'] ); ?>">
                            <img src="<?php echo esc_url( $item['image'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" loading="lazy">
                            <span class="akb-sh-card__vol">Vol. <?php echo esc_html( $item['number'] ); ?></span>

                            <?php if ( $is_current ) : ?>
                                <span class="akb-sh-card__badge akb-sh-card__badge--current">Actual</span>
                            <?php elseif ( $is_owned ) : ?>
                                <span class="akb-sh-card__badge akb-sh-card__badge--owned">Ya tienes</span>
                            <?php elseif ( $status === 'preorder' ) : ?>
                                <span class="akb-sh-card__badge akb-sh-card__badge--pre">Preventa</span>
                            <?php elseif ( $status === 'outofstock' ) : ?>
                                <span class="akb-sh-card__badge akb-sh-card__badge--out">Agotado</span>
                            <?php endif; ?>
                        </a>

                        <div class="akb-sh-card__meta">
                            <a href="<?php echo esc_url( $item['url'] ); ?>" class="akb-sh-card__title"><?php echo esc_html( $item['title'] ); ?></a>
                            <?php if ( $status === 'outofstock' ) : ?>
                                <span class="akb-sh-card__price akb-sh-card__price--out">Agotado</span>
                            <?php else : ?>
                                <span class="akb-sh-card__price"><?php echo wp_kses_post( $item['price'] ); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="akb-sh-card__actions">
                            <?php if ( ! $is_current && $status === 'instock' ) : ?>
                                <button type="button" class="btn btn--secondary btn--sm js-quick-add" data-product-id="<?php echo esc_attr( $item['id'] ); ?>">
                                    <span>Agregar</span>
                                </button>
                            <?php elseif ( ! $is_current && $status === 'outofstock' ) : ?>
                                <a href="<?php echo esc_url( $item['notify'] ); ?>" class="akb-sh-card__notify">Avisame</a>
                            <?php elseif ( ! $is_current && $status === 'preorder' ) : ?>
                                <a href="<?php echo esc_url( $item['url'] ); ?>" class="akb-sh-card__notify">Ver preventa</a>
                            <?php else : ?>
                                <span class="akb-sh-card__current-label">Tomo actual</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>

                <button type="button" class="akb-series-hub__nav-btn akb-series-hub__nav-btn--next js-akb-sh-nav" data-dir="1" aria-label="Siguiente">
                    <svg viewBox="0 0 24 24" width="24" height="24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>
            </div>

            <?php if ( ! empty( $bundle_ids ) ) : ?>
                <div class="akb-series-hub__footer">
                    <button type="button" class="btn btn--primary akb-sh-bundle-btn js-akb-series-bundle" data-ids="<?php echo esc_attr( wp_json_encode( $bundle_ids ) ); ?>">
                        <span>Anadir <?php echo esc_html( count( $bundle_ids ) ); ?> tomos en stock (+<?php echo wp_kses_post( wc_price( $bundle_total ) ); ?>)</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if ( ! function_exists( 'akibara_render_series_hub' ) ) {
    function akibara_render_series_hub( $product_id ): void {
        $product_id = absint( $product_id );
        if ( $product_id <= 0 ) {
            return;
        }

        $source = akibara_series_hub_get_source( $product_id );
        if ( empty( $source ) ) {
            return;
        }
        ?>
        <section class="akb-series-hub js-akb-series-hub"
            data-product-id="<?php echo esc_attr( $product_id ); ?>"
            data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
            data-load-nonce="<?php echo esc_attr( wp_create_nonce( 'akibara-series-hub' ) ); ?>"
            data-pack-nonce="<?php echo esc_attr( wp_create_nonce( 'akibara-pack-nonce' ) ); ?>">

            <div class="akb-series-hub__header">
                <h2 class="akb-series-hub__title">Completa tu Colección</h2>
                <p class="akb-series-hub__subtitle">Cargando tomos de <?php echo esc_html( (string) $source['label'] ); ?>...</p>
            </div>

            <div class="akb-series-hub__skeleton" aria-hidden="true">
                <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                    <div class="akb-series-hub__skeleton-item">
                        <div class="akb-series-hub__skeleton-cover"></div>
                        <div class="akb-series-hub__skeleton-line"></div>
                        <div class="akb-series-hub__skeleton-line akb-series-hub__skeleton-line--short"></div>
                    </div>
                <?php endfor; ?>
            </div>

            <div class="akb-series-hub__content" aria-live="polite"></div>
        </section>
        <?php
    }
}

if ( ! function_exists( 'akibara_ajax_load_series_hub' ) ) {
    function akibara_ajax_load_series_hub(): void {
        check_ajax_referer( 'akibara-series-hub', 'nonce' );

        $product_id = absint( $_POST['product_id'] ?? 0 );
        if ( $product_id <= 0 || get_post_type( $product_id ) !== 'product' ) {
            wp_send_json_error( [ 'message' => 'Producto invalido.' ] );
        }

        $hub_data = akibara_series_hub_get_data( $product_id );
        if ( empty( $hub_data['items'] ) ) {
            wp_send_json_success( [ 'html' => '', 'count' => 0 ] );
        }

        $series_label = (string) ( $hub_data['label'] ?? '' );
        $subtitle     = count( $hub_data['items'] ) . ' tomos · ' . $series_label;

        $owned_ids = [];
        if ( is_user_logged_in() && function_exists( 'akibara_series_hub_get_user_purchased_ids' ) ) {
            $owned_ids = akibara_series_hub_get_user_purchased_ids( get_current_user_id() );
        }

        wp_send_json_success( [
            'html'     => akibara_series_hub_render_markup( $hub_data, $product_id, $owned_ids ),
            'count'    => count( $hub_data['items'] ),
            'subtitle' => $subtitle,
        ] );
    }
}

add_action( 'wp_ajax_akibara_load_series_hub', 'akibara_ajax_load_series_hub' );
add_action( 'wp_ajax_nopriv_akibara_load_series_hub', 'akibara_ajax_load_series_hub' );
