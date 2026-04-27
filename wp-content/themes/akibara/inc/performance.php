<?php
/**
 * Performance Optimizations
 *
 * @package Akibara
 * @version 1.1.0 — Enhanced caching + cleanup
 */

defined('ABSPATH') || exit;

// Remove emoji scripts
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('admin_print_styles', 'print_emoji_styles');

// Remove WP embed
remove_action('wp_head', 'wp_oembed_add_discovery_links');

// Remove RSD link
remove_action('wp_head', 'rsd_link');

// Remove WLW Manifest
remove_action('wp_head', 'wlwmanifest_link');

// Remove WP generator tag
remove_action('wp_head', 'wp_generator');

// Remove shortlink
remove_action('wp_head', 'wp_shortlink_wp_head');

// Remove REST API link
remove_action('wp_head', 'rest_output_link_wp_head');

// Disable WooCommerce styles we override completely
add_filter('woocommerce_enqueue_styles', function ($styles) {
    unset($styles['woocommerce-general']);
    unset($styles['woocommerce-layout']);
    unset($styles['woocommerce-smallscreen']);
    return $styles;
});

// Disable WC scripts on non-WC pages
add_action('wp_enqueue_scripts', function () {
    if (!is_woocommerce() && !is_cart() && !is_checkout() && !is_account_page()) {
        wp_dequeue_script('wc-cart-fragments');
    }
}, 99);

// Disable Gutenberg block CSS if not needed
add_action('wp_enqueue_scripts', function () {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('wc-blocks-style');
    wp_dequeue_style('global-styles');
}, 100);

// Image loading optimization (single consolidated filter)
add_filter('wp_get_attachment_image_attributes', function ($attr) {
    if (isset($attr['fetchpriority']) && $attr['fetchpriority'] === 'high') {
        $attr['data-no-lazy'] = '1';
        $attr['loading'] = 'eager';
    } elseif (!isset($attr['loading'])) {
        $attr['loading'] = 'lazy';
    }
    return $attr;
});

// Exclude hero from LiteSpeed lazy loading (kept for legacy, ahora el JS lazy
// queda apagado globalmente — esta regla solo aplica si alguien reactiva LS lazy)
add_filter('litespeed_media_lazy_img_excludes', function ($excludes) {
    $excludes[] = 'fetchpriority';
    $excludes[] = 'product-card__img';
    $excludes[] = 'attachment-woocommerce';
    return $excludes;
});

// Excluir imágenes de producto del JS lazy de LiteSpeed (bug: ~10% imgs quedan
// stuck en placeholder 1×1 cuando IntersectionObserver no dispara bien — confirmado
// 2026-04-21 en related products + scroll rápido). Las imgs llevan loading="lazy"
// nativo, el browser lo maneja de forma robusta.
add_filter('litespeed_media_lazy_img_cls_excludes', function ($excludes) {
    $extra = [
        'attachment-woocommerce_thumbnail',
        'attachment-woocommerce_single',
        'attachment-woocommerce_gallery_thumbnail',
        'attachment-shop_catalog',
        'attachment-product-card',
        'size-product-card',
        'wp-post-image',
        'product-card__img',
        'akb-nv-widget__cover',
        'woocommerce-loop-product__link',
        'woocommerce-product-gallery__image',
    ];
    return array_unique(array_merge((array) $excludes, $extra));
});

// Excluir también imágenes cuyo padre tiene estas classes
add_filter('litespeed_media_lazy_img_parent_cls_excludes', function ($excludes) {
    $extra = [
        'product-card',
        'product-card__image',
        'product-card__link',
        'akb-nv-widget__cover-link',
        'akb-nv-widget__body',
        'footer-brand__logo',
        'footer-brand',
        'logo',
    ];
    return array_unique(array_merge((array) $excludes, $extra));
});

// Disable XML-RPC
add_filter('xmlrpc_enabled', '__return_false');

// Remove dashicons on frontend for non-admins
add_action('wp_enqueue_scripts', function () {
    if (!is_admin_bar_showing()) {
        wp_deregister_style('dashicons');
    }
});

// ═══════════════════════════════════════════════════════════════
// SHARED DATA CACHE — eliminates duplicate queries in header/front-page
// ═══════════════════════════════════════════════════════════════

/**
 * Get shared category data (manga, comics, demographics, subs).
 * Called by both header.php and front-page.php — cached per request.
 */
function akibara_get_shared_cats(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $manga_cat  = get_term_by('slug', 'manga', 'product_cat');
    $comics_cat = get_term_by('slug', 'comics', 'product_cat');

    $manga_demos = $manga_cat ? get_terms([
        'taxonomy' => 'product_cat',
        'parent'   => $manga_cat->term_id,
        'hide_empty' => true,
        'orderby'  => 'count',
        'order'    => 'DESC',
    ]) : [];

    $comics_subs = $comics_cat ? get_terms([
        'taxonomy' => 'product_cat',
        'parent'   => $comics_cat->term_id,
        'hide_empty' => true,
        'orderby'  => 'count',
        'order'    => 'DESC',
    ]) : [];

    $cache = compact('manga_cat', 'comics_cat', 'manga_demos', 'comics_subs');
    return $cache;
}

/**
 * Get editorial menu from product_brand taxonomy — cached in transient for 1 hour.
 * Sorted by product count (most products first) for relevance.
 */
function akibara_get_editorial_menu(): array {
    $cached = get_transient('akibara_editorial_menu');
    if ($cached !== false) return $cached;

    $terms = get_terms([
        'taxonomy'   => 'product_brand',
        'hide_empty' => true,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ]);

    if (is_wp_error($terms) || empty($terms)) return [];

    // Convert to format compatible with header template ($item->title, $item->url)
    $items = [];
    foreach ($terms as $term) {
        $item = new stdClass();
        $item->title   = $term->name;
        $item->url     = get_term_link($term);
        $item->ID      = $term->term_id;
        $item->slug    = $term->slug;
        $item->count   = $term->count;
        $item->country = get_term_meta($term->term_id, 'country', true) ?: '';
        $items[] = $item;
    }

    set_transient('akibara_editorial_menu', $items, HOUR_IN_SECONDS);
    return $items;
}

// Invalidate editorial brands cache when product_brand terms change
add_action('created_product_brand', function () {
    delete_transient('akibara_editorial_menu');
    delete_transient('akibara_home_editorial_brands'); // legacy key
    delete_transient('akibara_editorial_brands_v1');
});
add_action('edited_product_brand', function () {
    delete_transient('akibara_editorial_menu');
    delete_transient('akibara_home_editorial_brands'); // legacy key
    delete_transient('akibara_editorial_brands_v1');
});
add_action('delete_product_brand', function () {
    delete_transient('akibara_editorial_menu');
    delete_transient('akibara_home_editorial_brands'); // legacy key
    delete_transient('akibara_editorial_brands_v1');
});
// Invalidate when product count changes or bulk import completes
add_action('save_post_product',               'akibara_bust_editorial_brands_cache');
add_action('woocommerce_product_importer_done','akibara_bust_editorial_brands_cache');

function akibara_bust_editorial_brands_cache(): void {
    delete_transient('akibara_editorial_brands_v1');
}

/**
 * Get cached editorial brands for homepage grid.
 * Shape: {name, slug, url, img, count, country}. Sorted by count DESC.
 * Returns [] on error — template hides section with if ($brand_items).
 *
 * Feature flag: wp option update akibara_homepage_editorials_enabled 0
 */
function akibara_get_homepage_editorial_brands(): array {
    if ( ! get_option( 'akibara_homepage_editorials_enabled', 1 ) ) {
        return [];
    }

    $cached = get_transient('akibara_editorial_brands_v1');
    if (false !== $cached) {
        return is_array($cached) ? $cached : [];
    }

    $terms = get_terms([
        'taxonomy'   => 'product_brand',
        'hide_empty' => true,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ]);

    $items = [];
    if (!is_wp_error($terms)) {
        foreach ($terms as $term) {
            if ($term->count < 1) continue;
            $thumb_id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
            if (!$thumb_id) continue;
            $img = wp_get_attachment_image_url($thumb_id, 'medium');
            if (!$img) continue;

            // Country: use term meta, fall back to name-based detection
            $country = (string) get_term_meta($term->term_id, 'country', true);
            if (!$country) {
                $lower = mb_strtolower($term->name, 'UTF-8');
                if (strpos($lower, 'argentina') !== false || strpos($lower, 'ovni') !== false) {
                    $country = 'AR';
                } elseif (strpos($lower, 'espa') !== false || strpos($lower, 'milky') !== false || strpos($lower, 'arechi') !== false || strpos($lower, 'norma') !== false) {
                    $country = 'ES';
                }
            }

            $items[] = [
                'name'    => $term->name,
                'slug'    => $term->slug,
                'url'     => get_term_link($term),
                'img'     => $img,
                'count'   => (int) $term->count,
                'country' => $country,
            ];
        }
    }

    set_transient('akibara_editorial_brands_v1', $items, 12 * HOUR_IN_SECONDS);
    return $items;
}

// ═══════════════════════════════════════════════════════════════
// HOMEPAGE QUERY CACHE — transients for expensive WP_Query calls
// ═══════════════════════════════════════════════════════════════

/**
 * Get cached homepage section products.
 * Invalidated when any product is published/updated.
 */
function akibara_get_homepage_section(string $section): array {
    $key = 'akibara_home_' . $section;
    $cached = get_transient($key);
    if ($cached !== false) return $cached;

    $args = [
        'post_type'   => 'product',
        'post_status' => 'publish',
        'no_found_rows' => true,
        'fields'      => 'ids',
        'tax_query'   => [['taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => 'exclude-from-catalog', 'operator' => 'NOT IN']],
    ];

    switch ($section) {
        case 'latest':
            $args['posts_per_page'] = 8;
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            $args['meta_query'] = [
                'relation' => 'OR',
                ['key' => '_akb_reserva', 'compare' => 'NOT EXISTS'],
                ['key' => '_akb_reserva', 'value' => 'yes', 'compare' => '!='],
            ];
            // Excluir agotados
            $args['tax_query'][] = [
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => 'outofstock',
                'operator' => 'NOT IN',
            ];
            break;

        case 'preorders':
            $args['posts_per_page'] = 6;
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            $args['meta_query'] = [['key' => '_akb_reserva', 'value' => 'yes']];
            break;

        case 'bestsellers':
            $args['posts_per_page'] = 6;
            $args['meta_key'] = 'total_sales';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'DESC';
            break;
    }

    $query = new WP_Query($args);
    $ids = $query->posts;

    set_transient($key, $ids, 30 * MINUTE_IN_SECONDS);
    return $ids;
}

// Invalidate homepage transients when products change
add_action('save_post_product', function () {
    delete_transient('akibara_home_latest');
    delete_transient('akibara_home_preorders');
    delete_transient('akibara_home_bestsellers');
});

// ═══════════════════════════════════════════════════════════════
// RELATED PRODUCTS — PHP shuffle instead of ORDER BY RAND()
// ═══════════════════════════════════════════════════════════════

add_filter('woocommerce_product_related_posts_query', function ($query) {
    // Remove ORDER BY RAND() — we shuffle in PHP instead
    $query['orderby'] = ' ORDER BY p.post_date DESC';
    return $query;
});

// Fix WooCommerce cache-control headers for CDN caching
add_action('template_redirect', function () {
    if (is_user_logged_in()) return;
    if (is_cart() || is_checkout() || is_account_page()) return;
    if (class_exists('WC_Cache_Helper')) {
        remove_action('template_redirect', array('WC_Cache_Helper', 'set_nocache_headers'));
    }
}, 0);

add_filter('wp_headers', function ($headers) {
    if (is_user_logged_in()) return $headers;
    if (is_cart() || is_checkout() || is_account_page()) return $headers;
    unset($headers['Cache-Control']);
    unset($headers['Pragma']);
    unset($headers['Expires']);
    return $headers;
}, 9999);

add_action('send_headers', function () {
    if (is_user_logged_in()) return;
    if (is_cart() || is_checkout() || is_account_page()) return;
    header_remove('Cache-Control');
    header_remove('Pragma');
    header_remove('Expires');
}, 9999);

// Resource hints — MercadoPago only on checkout
add_action('wp_head', function () {
    if ( ! function_exists("is_checkout") || ! is_checkout() ) return;
    echo '<link rel="preconnect" href="https://http2.mlstatic.com" crossorigin>' . PHP_EOL;
    echo '<link rel="dns-prefetch" href="//http2.mlstatic.com">' . PHP_EOL;
}, 2);

// Disable ai-engine frontend assets
add_action('wp_enqueue_scripts', function () {
    if (!is_admin()) {
        wp_dequeue_script('mwai-chatbot');
        wp_dequeue_style('mwai-chatbot');
    }
}, 999);

// View Transitions API — smooth page transitions with dark bg
add_action("wp_head", function () {
    echo "<meta name=\"view-transition\" content=\"same-origin\">\n";
}, 1);

// Speculation Rules API — PRERENDER for near-instant navigation
add_action("wp_footer", function () {
    if (is_admin()) return;
    $rules = [
        "prerender" => [[
            "where" => [
                "and" => [
                    ["href_matches" => "/*"],
                    ["not" => ["href_matches" => [
                        "/carrito*", "/cart*", "/checkout*", "/finalizar*",
                        "/mi-cuenta*", "/my-account*", "/wp-admin/*", "/wp-login.php",
                        "/*add-to-cart*", "/*remove_item*", "/*logout*"
                    ]]],
                    ["not" => ["selector_matches" => ".js-quick-add, .js-notify-open, [target=_blank], [download]"]]
                ]
            ],
            "eagerness" => "moderate"
        ]]
    ];
    echo "<script type=\"speculationrules\">\n";
    echo json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo "\n</script>\n";
}, 99);

// bfcache — refresh cart on back/forward navigation
add_action("wp_footer", function () {
    if (is_admin()) return;
    ?>
    <script>
    window.addEventListener("pageshow",function(e){
        if(e.persisted){
            var c=document.querySelector("#cart-count,.header-icon__badge");
            if(c){fetch("/?wc-ajax=get_refreshed_fragments",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"}}).then(function(r){return r.json()}).then(function(d){if(d.fragments){for(var k in d.fragments){var el=document.querySelector(k);if(el)el.outerHTML=d.fragments[k]}}}).catch(function(){});}
        }
    });
    </script>
    <?php
}, 100);

// ═══════════════════════════════════════════════════════════════
// SEO: Remove unnecessary HTTP headers
// ═══════════════════════════════════════════════════════════════

// Remove REST API link from HTTP headers
remove_action("template_redirect", "rest_output_link_header", 11);

// Remove shortlink from HTTP headers
remove_action("template_redirect", "wp_shortlink_header", 11);

// Remove X-Pingback header
add_filter("wp_headers", function($headers) {
    unset($headers["X-Pingback"]);
    return $headers;
});


// Remove oEmbed discovery links
remove_action("wp_head", "wp_oembed_add_host_js");

// Remove DNS prefetch for emoji CDN only (keep other resource hints intact)
add_filter( 'wp_resource_hints', function( $urls, $relation_type ) {
    if ( 'dns-prefetch' === $relation_type ) {
        $urls = array_filter( $urls, function( $url ) {
            return strpos( is_array( $url ) ? ( $url['href'] ?? '' ) : $url, 's.w.org' ) === false;
        } );
    }
    return $urls;
}, 10, 2 );

// LiteSpeed CSS/JS combine is DISABLED (litespeed.conf.css_combine=0).
// If re-enabled, combined files with ?ver= trigger PHP 404.
// Keep combine OFF to avoid stale-cache breakage.
