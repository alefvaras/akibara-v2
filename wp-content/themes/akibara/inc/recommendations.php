<?php
/**
 * Akibara — Smart Recommendations Engine
 *
 * 1. Smart "También te puede gustar" (replaces wc_get_related_products)
 * 2. Genre-based popular products section
 * 3. "Empieza una nueva serie" homepage section
 *
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the current recommendation cache generation number.
 * Incrementing this effectively invalidates all genre_pop and rec transients
 * because the generation is embedded in the cache key.
 * Works correctly with both DB-based and external object caches.
 *
 * @return int Current generation number
 */
function akibara_rec_cache_generation(): int {
    $gen = get_option( 'akibara_rec_cache_gen', 1 );
    return (int) $gen;
}

/**
 * Get smart recommendations for a product.
 *
 * Priority:
 *  1. Same serie, different volume (excluding current)
 *  2. Same genre + same editorial (product_brand)
 *  3. Same genre, ordered by total_sales (popular)
 *
 * Falls back to wc_get_related_products() on failure.
 *
 * @param int $product_id
 * @param int $limit
 * @return int[] Product IDs
 */
function akibara_get_smart_recommendations( int $product_id, int $limit = 6 ): array {
    $gen       = akibara_rec_cache_generation();
    $cache_key = 'akibara_rec_' . $product_id . '_' . $limit . '_g' . $gen;
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    try {
        $result   = [];
        $exclude  = [ $product_id ];

        // --- Layer 1: Same serie, different volume ---
        $serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
        if ( ! empty( $serie_norm ) ) {
            $serie_q = new WP_Query( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                'post__not_in'   => $exclude,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [
                    [
                        'key'   => '_akibara_serie_norm',
                        'value' => $serie_norm,
                    ],
                ],
                'meta_key' => 'total_sales',
                'orderby'  => 'meta_value_num',
                'order'    => 'DESC',
            ] );
            if ( $serie_q->posts ) {
                $result  = array_merge( $result, $serie_q->posts );
                $exclude = array_merge( $exclude, $serie_q->posts );
            }
            wp_reset_postdata();
        }

        if ( count( $result ) >= $limit ) {
            $result = array_slice( $result, 0, $limit );
            set_transient( $cache_key, $result, HOUR_IN_SECONDS );
            return $result;
        }

        // Get product genre terms
        $genres = wp_get_post_terms( $product_id, 'pa_genero', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $genres ) ) {
            $genres = [];
        }

        // Get product brand terms
        $brands = wp_get_post_terms( $product_id, 'product_brand', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $brands ) ) {
            $brands = [];
        }

        $remaining = $limit - count( $result );

        // --- Layer 2: Same genre + same editorial ---
        if ( $remaining > 0 && ! empty( $genres ) && ! empty( $brands ) ) {
            $genre_brand_q = new WP_Query( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $remaining,
                'post__not_in'   => $exclude,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => [
                    'relation' => 'AND',
                    [
                        'taxonomy' => 'pa_genero',
                        'field'    => 'term_id',
                        'terms'    => $genres,
                    ],
                    [
                        'taxonomy' => 'product_brand',
                        'field'    => 'term_id',
                        'terms'    => $brands,
                    ],
                ],
                'meta_key' => 'total_sales',
                'orderby'  => 'meta_value_num',
                'order'    => 'DESC',
            ] );
            if ( $genre_brand_q->posts ) {
                $result  = array_merge( $result, $genre_brand_q->posts );
                $exclude = array_merge( $exclude, $genre_brand_q->posts );
            }
            wp_reset_postdata();
        }

        $remaining = $limit - count( $result );

        // --- Layer 3: Same genre, popular ---
        if ( $remaining > 0 && ! empty( $genres ) ) {
            $genre_pop_q = new WP_Query( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $remaining,
                'post__not_in'   => $exclude,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => [
                    [
                        'taxonomy' => 'pa_genero',
                        'field'    => 'term_id',
                        'terms'    => $genres,
                    ],
                ],
                'meta_key' => 'total_sales',
                'orderby'  => 'meta_value_num',
                'order'    => 'DESC',
            ] );
            if ( $genre_pop_q->posts ) {
                $result  = array_merge( $result, $genre_pop_q->posts );
                $exclude = array_merge( $exclude, $genre_pop_q->posts );
            }
            wp_reset_postdata();
        }

        $result = array_slice( $result, 0, $limit );
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );
        return $result;

    } catch ( \Throwable $e ) {
        // Fallback to WC native
        return wc_get_related_products( $product_id, $limit );
    }
}

/**
 * Get popular products from the same genre, excluding given IDs.
 *
 * @param int   $product_id  Current product
 * @param int[] $exclude_ids IDs to exclude (already-shown recommendations + current)
 * @param int   $limit
 * @return int[] Product IDs
 */
function akibara_get_genre_popular( int $product_id, array $exclude_ids = [], int $limit = 4 ): array {
    $genres = wp_get_post_terms( $product_id, 'pa_genero', [ 'fields' => 'ids' ] );
    if ( is_wp_error( $genres ) || empty( $genres ) ) {
        return [];
    }

    // Exclude products from the same serie
    $serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
    $serie_exclude = [];
    if ( ! empty( $serie_norm ) ) {
        $serie_q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [ 'key' => '_akibara_serie_norm', 'value' => $serie_norm ],
            ],
        ] );
        $serie_exclude = $serie_q->posts ?: [];
        wp_reset_postdata();
    }

    $all_exclude = array_unique( array_merge( $exclude_ids, $serie_exclude, [ $product_id ] ) );

    $gen       = akibara_rec_cache_generation();
    $cache_key = 'akibara_genre_pop_v3_' . md5( implode( ',', $genres ) . '_' . implode( ',', $all_exclude ) . '_' . $limit . '_g' . $gen );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }

    // Fetch more than needed to allow dedup by serie
    $q = new WP_Query( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $limit * 8,
        'post__not_in'   => $all_exclude,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [
            [ 'taxonomy' => 'pa_genero', 'field' => 'term_id', 'terms' => $genres ],
        ],
        'meta_key' => 'total_sales',
        'orderby'  => 'meta_value_num',
        'order'    => 'DESC',
    ] );

    $candidates = $q->posts ?: [];
    wp_reset_postdata();

    if ( empty( $candidates ) ) {
        set_transient( $cache_key, [], HOUR_IN_SECONDS );
        return [];
    }

    // Dedup: keep only 1 product per serie (the most popular one)
    $seen_series = [];
    $selected    = [];

    foreach ( $candidates as $pid ) {
        $s = get_post_meta( $pid, '_akibara_serie_norm', true );
        if ( empty( $s ) ) {
            $selected[] = $pid;
        } elseif ( ! isset( $seen_series[ $s ] ) ) {
            $seen_series[ $s ] = true;
            $selected[] = $pid;
        }
        if ( count( $selected ) >= $limit ) break;
    }

    // Swap each selected product for vol 1 of its serie (better entry point)
    global $wpdb;
    $result = [];
    foreach ( $selected as $pid ) {
        $s = get_post_meta( $pid, '_akibara_serie_norm', true );
        if ( empty( $s ) ) {
            $result[] = $pid;
            continue;
        }
        $vol1_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm1.post_id
             FROM {$wpdb->postmeta} pm1
             JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
                AND pm2.meta_key = '_akibara_numero' AND pm2.meta_value = '1'
             JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
                AND p.post_type = 'product' AND p.post_status = 'publish'
             WHERE pm1.meta_key = '_akibara_serie_norm' AND pm1.meta_value = %s
             LIMIT 1",
            $s
        ) );

        if ( $vol1_id && ! in_array( (int) $vol1_id, $all_exclude, true ) ) {
            $vol1_product = wc_get_product( (int) $vol1_id );
            if ( $vol1_product && $vol1_product->is_in_stock() ) {
                $result[] = (int) $vol1_id;
                continue;
            }
        }
        $result[] = $pid;
    }

    set_transient( $cache_key, $result, HOUR_IN_SECONDS );
    return $result;
}

/**
 * Get the primary genre name for a product (first pa_genero term).
 *
 * @param int $product_id
 * @return string Genre name or empty
 */
function akibara_get_primary_genre_name( int $product_id ): string {
    $genres = wp_get_post_terms( $product_id, 'pa_genero', [ 'fields' => 'names' ] );
    if ( is_wp_error( $genres ) || empty( $genres ) ) {
        return '';
    }
    return $genres[0];
}

// ══════════════════════════════════════════════════════════════════
// HOMEPAGE: "Empieza una nueva serie" — Volume #1 bestsellers
// ══════════════════════════════════════════════════════════════════

add_action( 'akibara_homepage_after_bestsellers', 'akibara_start_a_series', 10 );

function akibara_start_a_series(): void {
    if ( ! is_front_page() ) return;

    $gen       = akibara_rec_cache_generation();
    $cache_key = 'akibara_start_series_v1_g' . $gen;
    $vol1_ids  = get_transient( $cache_key );

    if ( false === $vol1_ids ) {
        $q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 6,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_akibara_numero',
                    'value'   => '1',
                    'compare' => '=',
                ],
                [
                    'key'     => '_stock_status',
                    'value'   => 'instock',
                    'compare' => '=',
                ],
            ],
            'meta_key' => 'total_sales',
            'orderby'  => 'meta_value_num',
            'order'    => 'DESC',
        ] );

        $vol1_ids = $q->posts ?: [];
        wp_reset_postdata();

        set_transient( $cache_key, $vol1_ids, HOUR_IN_SECONDS );
    }

    if ( count( $vol1_ids ) < 3 ) return;

    ?>
    <section class="home-products home-products--start-series aki-reveal">
        <div class="container">
            <div class="section-header">
                <h2 class="section-header__title">Empieza una Nueva Serie</h2>
                <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) . '?orderby=popularity' ); ?>" class="section-header__link">Ver todo <?php echo akibara_icon( 'arrow', 16 ); ?></a>
            </div>
            <p style="color:var(--aki-gray-400);font-size:var(--text-sm);margin-bottom:var(--space-4)">Los tomos #1 mas vendidos — el punto de partida perfecto</p>
            <div class="product-grid product-grid--large">
                <?php
                $vol1_query = new WP_Query( [
                    'post_type'      => 'product',
                    'post__in'       => $vol1_ids,
                    'orderby'        => 'post__in',
                    'posts_per_page' => count( $vol1_ids ),
                    'no_found_rows'  => true,
                ] );
                while ( $vol1_query->have_posts() ) :
                    $vol1_query->the_post();
                    get_template_part( 'template-parts/content/product-card' );
                endwhile;
                wp_reset_postdata();
                ?>
            </div>
        </div>
    </section>
    <?php
}

// ══════════════════════════════════════════════════════════════════
// TRANSIENT INVALIDATION: Clear recommendation caches on stock change
// ══════════════════════════════════════════════════════════════════

/**
 * When a product's stock status changes (in_stock <-> out_of_stock),
 * invalidate all recommendation transients.
 *
 * Since we cannot determine exactly which transient keys include a given
 * product (the genre_pop and rec transients contain hashed cache keys),
 * we clear all recommendation transients. They are cheap to regenerate
 * (cached for 1hr, only hit on first page load after clear).
 *
 * @param int    $product_id  Product ID
 * @param string $new_status  New stock status (instock, outofstock, onbackorder)
 * @param object $product     WC_Product instance
 */
add_action( 'woocommerce_product_set_stock_status', function( int $product_id, string $new_status, $product ): void {
    akibara_invalidate_recommendation_transients( $product_id );
}, 10, 3 );

/**
 * Also hook into stock quantity changes that might trigger status changes.
 * This catches programmatic stock adjustments (order completion, admin edits).
 */
add_action( 'woocommerce_product_set_stock', function( $product ): void {
    if ( $product && method_exists( $product, 'get_id' ) ) {
        akibara_invalidate_recommendation_transients( $product->get_id() );
    }
} );

/**
 * Invalidate all recommendation transients by incrementing the cache generation.
 *
 * Since all recommendation transients embed the generation number in their key
 * (e.g., akibara_rec_{id}_{limit}_g{gen}), incrementing the generation causes
 * all old transients to become unreachable. They will expire naturally via TTL.
 *
 * This approach is compatible with external object caches (Redis, Memcached, LiteSpeed)
 * where direct DB-based LIKE queries cannot reach cached transients.
 *
 * @param int $product_id Product whose stock changed (logged for debugging)
 */
function akibara_invalidate_recommendation_transients( int $product_id ): void {
    $current = (int) get_option( 'akibara_rec_cache_gen', 1 );
    update_option( 'akibara_rec_cache_gen', $current + 1, true );
}
