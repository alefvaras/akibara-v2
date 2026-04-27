<?php
/**
 * Akibara — Pack Serie CTA
 *
 * Muestra un CTA de "Pack Inicio" en la ficha de producto
 * cuando la serie tiene tomos 1, 2 y 3 disponibles en stock.
 * Uses dedicated akibara_add_pack_to_cart AJAX endpoint with its own nonce.
 *
 * @package Akibara
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Obtiene los datos del pack de inicio de una serie.
 * Cachea con transients para performance.
 */
function akibara_get_pack_inicio( string $serie_norm ): ?array {
    $transient_key = 'akb_pack_' . md5( $serie_norm );
    $cached = get_transient( $transient_key );

    if ( $cached !== false ) {
        return $cached ?: null;
    }

    global $wpdb;

    // PACK-2 decision (verified 2026-04-20 against production catalog):
    // IN('1','2','3') is the correct and intentional filter. Evaluated options:
    //   Option A (detect integral/deluxe by title): redundant — integrals use numero=0 and
    //             are already excluded by this IN clause. Title-matching adds fragile string logic.
    //   Option B (first 3 existing volumes by ascending numero): rejected — Golden Kamuy and
    //             Vinland Saga only carry vols 4+ in catalog. A pack of "tomos 4-5-6" labeled
    //             "Pack Inicio" is semantically wrong; customers need vol 1 to start the series.
    //   Option C (keep as-is + document): chosen. Every in-catalog series with vol 1-2-3 uses
    //             sequential numbering. Series without vol 1 in catalog should not have a pack.
    // Re-evaluate if a series enters with non-1 start numbering AND a pack makes business sense.
    $results = $wpdb->get_results( $wpdb->prepare( "
        SELECT pm1.post_id, pm2.meta_value AS numero
        FROM {$wpdb->postmeta} pm1
        INNER JOIN {$wpdb->postmeta} pm2
            ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_akibara_numero'
        INNER JOIN {$wpdb->posts} p
            ON pm1.post_id = p.ID AND p.post_type = 'product' AND p.post_status = 'publish'
        WHERE pm1.meta_key = '_akibara_serie_norm'
            AND pm1.meta_value = %s
            AND pm2.meta_value IN ('1','2','3')
    ", $serie_norm ) );

    if ( ! $results || count( $results ) < 3 ) {
        set_transient( $transient_key, 0, 6 * HOUR_IN_SECONDS );
        return null;
    }

    $volumes = [];
    foreach ( $results as $row ) {
        $num = (int) $row->numero;
        if ( $num >= 1 && $num <= 3 ) {
            $volumes[ $num ] = (int) $row->post_id;
        }
    }

    if ( ! isset( $volumes[1], $volumes[2], $volumes[3] ) ) {
        set_transient( $transient_key, 0, 6 * HOUR_IN_SECONDS );
        return null;
    }

    $pack_products = [];
    $total_regular = 0;

    for ( $i = 1; $i <= 3; $i++ ) {
        $product = wc_get_product( $volumes[ $i ] );
        if ( ! $product || ! $product->is_in_stock() || ! $product->is_purchasable() ) {
            set_transient( $transient_key, 0, 2 * HOUR_IN_SECONDS );
            return null;
        }

        $regular = (float) $product->get_regular_price( 'edit' );
        $price   = (float) $product->get_price();
        $effective = $price > 0 ? min( $regular, $price ) : $regular;

        $pack_products[ $i ] = [
            'id'        => $volumes[ $i ],
            'name'      => $product->get_name(),
            'price'     => $effective,
            'regular'   => $regular,
            'image_id'  => $product->get_image_id(),
            'permalink' => get_permalink( $volumes[ $i ] ),
        ];

        $total_regular += $effective;
    }

    $discount_pct  = (int) apply_filters( 'akibara_pack_discount_pct', (int) get_option( 'akibara_pack_discount_pct', 5 ), $serie_norm );
    $discount_amt  = (int) round( $total_regular * $discount_pct / 100 );
    $pack_price    = $total_regular - $discount_amt;

    $pack_data = [
        'products'      => $pack_products,
        'total_regular' => $total_regular,
        'discount_pct'  => $discount_pct,
        'discount_amt'  => $discount_amt,
        'pack_price'    => $pack_price,
        'product_ids'   => array_values( array_map( function( $p ) { return $p['id']; }, $pack_products ) ),
    ];

    set_transient( $transient_key, $pack_data, 6 * HOUR_IN_SECONDS );
    return $pack_data;
}

/**
 * Render the pack CTA block.
 */
function akibara_render_pack_cta( int $product_id ): void {
    $serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
    if ( empty( $serie_norm ) ) return;

    $pack = akibara_get_pack_inicio( $serie_norm );
    if ( ! $pack ) return;

    $current_num = (int) get_post_meta( $product_id, '_akibara_numero', true );
    $is_prominent = ( $current_num >= 1 && $current_num <= 3 );

    $serie_terms = get_the_terms( $product_id, 'pa_serie' );
    $serie_name = ( $serie_terms && ! is_wp_error( $serie_terms ) )
        ? $serie_terms[0]->name
        : ucwords( str_replace( [ '_', '-' ], ' ', $serie_norm ) );

    $ids_csv = implode( ',', $pack['product_ids'] );
    $compact_class = $is_prominent ? '' : ' pack-cta--compact';
    ?>
    <div
        class="pack-cta<?php echo esc_attr( $compact_class ); ?>"
        id="pack-serie-cta"
        data-pack-ids="<?php echo esc_attr( $ids_csv ); ?>"
        data-pack-total="<?php echo esc_attr( (string) $pack['pack_price'] ); ?>"
        data-pack-serie="<?php echo esc_attr( $serie_name ); ?>"
        data-pack-serie-id="<?php echo esc_attr( $serie_norm ); ?>"
    >
        <div class="pack-cta__header">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            <h3 class="pack-cta__title">Pack Inicio &mdash; <?php echo esc_html( $serie_name ); ?></h3>
        </div>

        <?php if ( $is_prominent ) : ?>
        <div class="pack-cta__covers">
            <?php foreach ( $pack['products'] as $num => $vol ) : ?>
                <a href="<?php echo esc_url( $vol['permalink'] ); ?>" class="pack-cta__cover">
                    <?php if ( $vol['image_id'] ) {
                        echo wp_get_attachment_image( $vol['image_id'], 'thumbnail', false, [ 'loading' => 'lazy' ] );
                    } ?>
                    <span class="pack-cta__vol-badge">Vol. <?php echo esc_html( $num ); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="pack-cta__pricing">
            <div class="pack-cta__row">
                <span>Tomos 1 al 3</span>
                <span class="pack-cta__regular">
                    <?php echo wc_price( $pack['total_regular'] ); ?>
                </span>
            </div>
            <div class="pack-cta__row pack-cta__row--total">
                <span>Precio pack <span class="pack-cta__badge">-<?php echo esc_html( $pack['discount_pct'] ); ?>%</span></span>
                <span class="pack-cta__pack-price"><?php echo wc_price( $pack['pack_price'] ); ?></span>
            </div>
            <div class="pack-cta__savings">
                Ahorras <?php echo wc_price( $pack['discount_amt'] ); ?>
            </div>
        </div>

        <button type="button" class="btn btn--primary pack-cta__btn" id="pack-add-btn">
            <span class="pack-cta__btn-text">Agregar Pack al Carrito</span>
            <span class="pack-cta__btn-loading">
                <svg class="pack-cta__spinner" width="18" height="18" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.4 31.4" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" dur="0.8s" from="0 12 12" to="360 12 12" repeatCount="indefinite"/></circle></svg>
                Agregando...
            </span>
            <span class="pack-cta__btn-done">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                Pack agregado
            </span>
        </button>
    </div>
    <?php
}

/**
 * Helper: delete the pack transient for the series a product belongs to.
 * Used by save_post_product (WP-CLI-safe) and woocommerce_product_set_stock.
 */
function akibara_invalidate_pack_transient_by_product( int $product_id ): void {
    $serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
    if ( ! empty( $serie_norm ) ) {
        delete_transient( 'akb_pack_' . md5( $serie_norm ) );
    }
}

/**
 * Flush all pack transients when the global discount % option changes.
 * Needed because pack_data (including discount_pct) is cached per-serie transient.
 */
add_action( 'updated_option', function( string $option, $old_value, $value ): void {
    if ( 'akibara_pack_discount_pct' !== $option ) return;
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_akb\_pack\_%' OR option_name LIKE '\_transient\_timeout\_akb\_pack\_%'" );
}, 10, 3 );

/**
 * Invalidate pack transient when a product in a series is updated.
 * Fires on admin save, REST API and WP-CLI — unlike updated_option.
 */
add_action( 'save_post_product', function( int $post_id ): void {
    akibara_invalidate_pack_transient_by_product( $post_id );
}, 20 );

/**
 * Invalidate when stock changes.
 */
add_action( 'woocommerce_product_set_stock', function( $product ): void {
    akibara_invalidate_pack_transient_by_product( $product->get_id() );
} );

/**
 * Enqueue pack CTA CSS and JS on single product pages.
 */
add_action( 'wp_enqueue_scripts', function(): void {
    if ( ! is_product() ) {
        return;
    }

    $ver = defined( 'AKIBARA_THEME_VERSION' ) ? AKIBARA_THEME_VERSION . '.11' : '1.0.0';

    wp_enqueue_style(
        'akibara-pack-serie',
        AKIBARA_THEME_URI . '/assets/css/pack-serie.css',
        [],
        $ver
    );

    wp_enqueue_script(
        'akibara-pack-serie',
        AKIBARA_THEME_URI . '/assets/js/pack-serie.js',
        [ 'akibara-cart' ],
        $ver,
        true
    );

    // Pack config (nonce + ajax URL)
    wp_localize_script( 'akibara-pack-serie', 'akibaraPack', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'akibara-pack-nonce' ),
    ] );
}, 20 );
