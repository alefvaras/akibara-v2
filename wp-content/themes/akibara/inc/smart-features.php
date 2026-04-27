<?php
/**
 * Akibara — Smart Features
 *
 * 1. "Completar tu serie" homepage section (logged-in users)
 * 2. Smart search (trending + recent searches)
 * 3. Enhanced 404 page
 *
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// 1. COMPLETAR TU SERIE — Homepage section for logged-in users
// ══════════════════════════════════════════════════════════════════

add_action( 'akibara_homepage_after_latest', 'akibara_complete_your_series', 10 );

function akibara_complete_your_series(): void {
    if ( ! is_user_logged_in() || ! is_front_page() ) return;

    $user_id = get_current_user_id();
    $cache_key = "akibara_series_" . $user_id;
    $missing_products = get_transient( $cache_key );

    if ( false === $missing_products ) {
        $orders = wc_get_orders( [
            "customer_id" => $user_id,
            "status"      => [ "completed", "processing" ],
            "limit"       => 5,
            "orderby"     => "date",
            "order"       => "DESC",
        ] );

        if ( empty( $orders ) ) { set_transient( $cache_key, [], HOUR_IN_SECONDS ); return; }

        $series = [];
        $purchased_ids = [];
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                $purchased_ids[] = $product_id;
                $serie_norm = get_post_meta( $product_id, "_akibara_serie_norm", true );
                if ( ! empty( $serie_norm ) && ! isset( $series[ $serie_norm ] ) ) {
                    $series[ $serie_norm ] = get_post_meta( $product_id, "_akibara_serie", true ) ?: ucwords( str_replace( "_", " ", $serie_norm ) );
                }
            }
        }

        if ( empty( $series ) ) { set_transient( $cache_key, [], HOUR_IN_SECONDS ); return; }

        $missing_products = [];
        foreach ( array_slice( $series, 0, 3 ) as $serie_norm => $serie_name ) {
            $volumes = new WP_Query( [
                "post_type"      => "product",
                "posts_per_page" => 20,
                "post_status"    => "publish",
                "fields"         => "ids",
                "meta_key"       => "_akibara_numero",
                "orderby"        => "meta_value_num",
                "order"          => "ASC",
                "no_found_rows"  => true,
                "meta_query"     => [ [ "key" => "_akibara_serie_norm", "value" => $serie_norm ] ],
                "post__not_in"   => $purchased_ids,
            ] );
            if ( $volumes->have_posts() ) {
                foreach ( array_slice( $volumes->posts, 0, 4 ) as $vid ) {
                    $missing_products[] = $vid;
                }
            }
            wp_reset_postdata();
        }

        set_transient( $cache_key, $missing_products, HOUR_IN_SECONDS );
    }

    if ( empty( $missing_products ) ) return;

    ?>
    <section class="home-products home-products--complete aki-reveal">
        <div class="container">
            <div class="section-header">
                <h2 class="section-header__title">Completa tu Serie</h2>
                <a href="<?php echo esc_url( wc_get_account_endpoint_url( "orders" ) ); ?>" class="section-header__link">Ver mis pedidos <?php echo akibara_icon( "arrow", 16 ); ?></a>
            </div>
            <p style="color:var(--aki-gray-400);font-size:var(--text-sm);margin-bottom:var(--space-4)">Estos volumenes te faltan de las series que ya compraste</p>
            <div class="product-grid product-grid--large">
                <?php
                $missing_query = new WP_Query( [
                    "post_type"      => "product",
                    "post__in"       => $missing_products,
                    "orderby"        => "post__in",
                    "posts_per_page" => count( $missing_products ),
                    "no_found_rows"  => true,
                ] );
                while ( $missing_query->have_posts() ) :
                    $missing_query->the_post();
                    get_template_part( "template-parts/content/product-card" );
                endwhile;
                wp_reset_postdata();
                ?>
            </div>
        </div>
    </section>
    <?php
}

// ══════════════════════════════════════════════════════════════════
// 2. SMART SEARCH — Trending searches + recent searches
// ══════════════════════════════════════════════════════════════════

/**
 * Track search queries for trending
 */
add_action( 'pre_get_posts', 'akibara_track_search_query' );

function akibara_track_search_query( $query ): void {
    if ( ! $query->is_search() || is_admin() || ! $query->is_main_query() ) return;

    $search = sanitize_text_field( $query->get( 's' ) );
    if ( strlen( $search ) < 2 ) return;

    // Buffer searches in transient to avoid DB write on every search
    $buffer = get_transient( 'akibara_search_buffer' ) ?: [];
    $buffer[] = mb_strtolower( $search );
    set_transient( 'akibara_search_buffer', $buffer, 300 ); // 5 min TTL

    // Flush buffer to trending option every 20 searches or on cron
    if ( count( $buffer ) >= 20 ) {
        akibara_flush_search_buffer();
    }
}

function akibara_flush_search_buffer(): void {
    $buffer = get_transient( 'akibara_search_buffer' );
    if ( empty( $buffer ) || ! is_array( $buffer ) ) return;

    delete_transient( 'akibara_search_buffer' );

    $trending = get_option( 'akibara_trending_searches', [] );
    foreach ( $buffer as $term ) {
        $trending[ $term ] = ( $trending[ $term ] ?? 0 ) + 1;
    }
    arsort( $trending );
    $trending = array_slice( $trending, 0, 50, true );
    update_option( 'akibara_trending_searches', $trending, false );
}

/**
 * Inject trending searches data into search popup
 */
add_action( 'wp_footer', 'akibara_smart_search_js', 30 );

function akibara_smart_search_js(): void {
    if ( is_admin() ) return;

    $trending = get_option( 'akibara_trending_searches', [] );
    arsort( $trending );
    $top = array_slice( array_keys( $trending ), 0, 6 );

    if ( empty( $top ) ) {
        $top = [ 'chainsaw man', 'jujutsu kaisen', 'one piece', 'berserk', 'dan da dan', 'spy x family' ];
    }

    // Top series by product count (cached 6h) — fuente: _akibara_serie_norm meta
    // (fuente canónica: incluye series con landing /serie/{slug}/ que no están en pa_serie).
    // Bump cache key a v2 para invalidar el cache v1 con datos incompletos.
    $top_series = get_transient( 'akibara_top_series_v2' );
    if ( false === $top_series ) {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS serie_slug,
                    COUNT(DISTINCT p.ID) AS tomos,
                    MAX(pm_name.meta_value) AS serie_name
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             LEFT JOIN {$wpdb->postmeta} pm_name ON p.ID = pm_name.post_id AND pm_name.meta_key = '_akibara_serie'
             WHERE pm.meta_key = '_akibara_serie_norm'
               AND pm.meta_value != ''
               AND p.post_type = 'product'
               AND p.post_status = 'publish'
             GROUP BY pm.meta_value
             HAVING tomos > 0
             ORDER BY tomos DESC, LENGTH(pm.meta_value) ASC
             LIMIT 5"
        );
        $top_series = [];
        $name_map   = function_exists( 'akibara_serie_name_map' ) ? akibara_serie_name_map() : [];
        foreach ( (array) $rows as $row ) {
            $slug_form = strtolower( $row->serie_slug );
            $norm_form = preg_replace( '/[^a-z0-9]/', '', $slug_form );
            if ( isset( $name_map[ $norm_form ] ) )       $label = $name_map[ $norm_form ];
            elseif ( isset( $name_map[ $slug_form ] ) )   $label = $name_map[ $slug_form ];
            elseif ( ! empty( $row->serie_name ) )        $label = $row->serie_name;
            else                                          $label = ucwords( str_replace( [ '_', '-' ], ' ', $slug_form ) );
            $count = (int) $row->tomos;
            $top_series[] = [
                'label' => $label,
                'url'   => home_url( '/serie/' . $slug_form . '/' ),
                'meta'  => $count . ( $count === 1 ? ' tomo' : ' tomos' ),
            ];
        }
        set_transient( 'akibara_top_series_v2', $top_series, 6 * HOUR_IN_SECONDS );
    }

    // Categorías destacadas para empty state (estáticas, resueltas a permalinks)
    $categories = get_transient( 'akibara_search_categories_v1' );
    if ( false === $categories ) {
        $categories = [];
        $cat_slugs  = [ 'shonen', 'seinen', 'shojo', 'manhwa', 'comics', 'preventas' ];
        foreach ( $cat_slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) continue;
            $link = get_term_link( $term );
            if ( is_wp_error( $link ) || ! $link ) continue;
            $categories[] = [ 'label' => $term->name, 'url' => $link ];
        }
        set_transient( 'akibara_search_categories_v1', $categories, DAY_IN_SECONDS );
    }
    ?>
    <script>
    (function(){
        // Store trending for the search popup to use
        window.akibaraTrending   = <?php echo wp_json_encode( $top, JSON_HEX_TAG ); ?>;
        window.akibaraTopSeries  = <?php echo wp_json_encode( $top_series, JSON_HEX_TAG ); ?>;
        window.akibaraCategories = <?php echo wp_json_encode( $categories, JSON_HEX_TAG ); ?>;

        // Recent searches from localStorage
        window.akibaraGetRecent = function() {
            try { return JSON.parse(localStorage.getItem('akb_recent_search') || '[]').slice(0, 4); }
            catch(e) { return []; }
        };

        window.akibaraSaveSearch = function(q) {
            if (!q || q.length < 2) return;
            try {
                var recent = JSON.parse(localStorage.getItem('akb_recent_search') || '[]');
                recent = recent.filter(function(r) { return r !== q; });
                recent.unshift(q);
                localStorage.setItem('akb_recent_search', JSON.stringify(recent.slice(0, 10)));
            } catch(e) {}
        };

        // Hook into search form submit
        document.addEventListener('submit', function(e) {
            var form = e.target;
            var input = form.querySelector('input[name="s"], input[type="search"]');
            if (input && input.value) window.akibaraSaveSearch(input.value.trim());
        });
    })();
    </script>
    <?php
}

// ══════════════════════════════════════════════════════════════════
// Dynamic Meta Descriptions for SEO
// ══════════════════════════════════════════════════════════════════

function akibara_dynamic_meta_description( $description ) {
    if ( is_product() ) {
        global $product;
        if ( is_a( $product, 'WC_Product' ) ) {
            $title = $product->get_name();
            $price = wc_price( $product->get_price() );
            $serie_terms = wp_get_post_terms( $product->get_id(), 'pa_serie', array( 'fields' => 'names' ) );
            $serie = ! is_wp_error( $serie_terms ) && ! empty( $serie_terms ) ? $serie_terms[0] : '';
            $description = "Compra $title en Akibara. Serie $serie. $price. Envío a todo Chile.";
        }
    }
    return $description;
}
add_filter( 'rank_math/frontend/description', 'akibara_dynamic_meta_description' );

// ══════════════════════════════════════════════════════════════════
// 3. ENHANCED 404 — Products + search + encargos
// ══════════════════════════════════════════════════════════════════

// This is handled by the 404.php template — we add a helper function
function akibara_get_404_suggestions(): array {
    // Get 6 popular products
    $popular = get_transient( 'akibara_404_products' );
    if ( false === $popular ) {
        $q = new WP_Query( [
            'post_type'      => 'product',
            'posts_per_page' => 6,
            'post_status'    => 'publish',
            'meta_key'       => 'total_sales',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ] );
        $popular = $q->posts;
        set_transient( 'akibara_404_products', $popular, 6 * HOUR_IN_SECONDS );
    }
    return $popular;
}
