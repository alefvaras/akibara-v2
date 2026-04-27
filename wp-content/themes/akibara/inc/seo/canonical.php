<?php
defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════
// CANONICAL URL — Avoid duplicate content
// ═══════════════════════════════════════════════════════════════
add_action('wp_head', function () {
    if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION')) return;

    if (is_archive() || is_home() || is_shop()) {
        $paged = max(1, (int) get_query_var('paged'));
        echo '<link rel="canonical" href="' . esc_url(get_pagenum_link($paged)) . '" />' . "\n";
    } elseif (is_singular()) {
        echo '<link rel="canonical" href="' . esc_url(get_permalink()) . '" />' . "\n";
    }
}, 1);

// ═══════════════════════════════════════════════════════════════
// CANONICAL URL CONSOLIDATION — Fix duplicate pages in Google Search Console
// Override Rank Math canonicals for:
//   - Shop page with filter/sort query parameters -> /tienda/
//   - Shop paginated pages (/tienda/page/N/) -> /tienda/
//   - Taxonomy archives with query parameters -> clean taxonomy URL
//   - Taxonomy paginated pages -> page 1 of taxonomy
// Does NOT touch: single products, blog posts, static pages
// @since 2.1.0  2026-04-11
// ═══════════════════════════════════════════════════════════════
add_filter("rank_math/frontend/canonical", function (string $canonical): string {
    $paged = max(1, (int) get_query_var('paged'));

    // -- Shop page (WooCommerce) --
    if (function_exists("is_shop") && is_shop()) {
        $shop_url = wc_get_page_permalink("shop");
        if ($shop_url) {
            $base = trailingslashit($shop_url);
            return $paged > 1 ? $base . 'page/' . $paged . '/' : $base;
        }
    }

    // -- Product taxonomy archives --
    // Covers: product_cat, product_brand, product_tag, pa_genero,
    //         pa_autor, pa_serie, pa_encuadernacion, pa_pais
    if (function_exists("is_product_category") && (is_product_category() || is_product_tag() || is_product_taxonomy())) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $term_link = get_term_link($term);
            if (!is_wp_error($term_link)) {
                $base = trailingslashit($term_link);
                return $paged > 1 ? $base . 'page/' . $paged . '/' : $base;
            }
        }
    }

    // -- Any other WooCommerce product archive with query params (not paginated) --
    if (function_exists("is_post_type_archive") && is_post_type_archive("product") && !empty($_SERVER["QUERY_STRING"])) {
        $shop_url = wc_get_page_permalink("shop");
        if ($shop_url) {
            return trailingslashit($shop_url);
        }
    }

    return $canonical;

}, 20);

// ═══════════════════════════════════════════════════════════════
// HREFLANG — Single source of truth (Rank Math free no emite
// hreflangs; los duplicados venían de functions.php — ya removido).
// ═══════════════════════════════════════════════════════════════
add_action( 'wp_head', function (): void {
    if ( is_admin() || wp_doing_cron() ) return;
    $url = esc_url( home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '/' ) ) );
    $clean = trailingslashit( strtok( $url, '?' ) );
    echo '<link rel="alternate" hreflang="es-CL" href="' . $clean . '" />' . "\n";
    echo '<link rel="alternate" hreflang="x-default" href="' . $clean . '" />' . "\n";
}, 1 );

// ═══════════════════════════════════════════════════════════════
// OG:URL CONSOLIDATION — Keep Open Graph URL in sync with canonical
// ═══════════════════════════════════════════════════════════════
add_filter("rank_math/opengraph/url", function (string $url): string {

    if (function_exists("is_shop") && is_shop()) {
        $shop_url = wc_get_page_permalink("shop");
        if ($shop_url) {
            return trailingslashit($shop_url);
        }
    }

    if (function_exists("is_product_category") && (is_product_category() || is_product_tag() || is_product_taxonomy())) {
        $term = get_queried_object();
        if ($term instanceof WP_Term) {
            $term_link = get_term_link($term);
            if (!is_wp_error($term_link)) {
                return trailingslashit($term_link);
            }
        }
    }

    if (function_exists("is_post_type_archive") && is_post_type_archive("product") && !empty($_SERVER["QUERY_STRING"])) {
        $shop_url = wc_get_page_permalink("shop");
        if ($shop_url) {
            return trailingslashit($shop_url);
        }
    }

    return $url;

}, 20);

// ═══════════════════════════════════════════════════════════════
// REL NEXT/PREV CLEANUP — Strip query params from pagination links
// Rank Math and theme rel=next/prev leak filter params
// e.g. /tienda/page/2/?stock=instock -> /tienda/page/2/
// ═══════════════════════════════════════════════════════════════
add_filter("get_pagenum_link", function (string $link): string {

    // Only clean up on shop/taxonomy pages with active query params
    if (!function_exists("is_shop")) {
        return $link;
    }
    if (!is_shop() && !is_product_category() && !is_product_tag() && !is_product_taxonomy()) {
        return $link;
    }

    // Strip all query parameters from pagination links
    $clean = strtok($link, "?");
    if ($clean) {
        return trailingslashit($clean);
    }

    return $link;

}, 20);

// ═══════════════════════════════════════════════════════════════
// RANK MATH REL NEXT/PREV CLEANUP — Strip query params from
// Rank Math-generated pagination links on shop/taxonomy pages.
// Rank Math uses rank_math/frontend/{next|prev}_rel_link filters.
// ═══════════════════════════════════════════════════════════════
$akibara_clean_rel_link = function (string $link): string {
    if (!function_exists('is_shop')) {
        return $link;
    }
    if (!is_shop() && !is_product_category() && !is_product_tag() && !is_product_taxonomy()) {
        return $link;
    }

    // Strip query parameters from the href inside the link tag
    return preg_replace_callback('/href="([^"]+)"/', function ($matches) {
        $clean = strtok($matches[1], '?');
        return 'href="' . trailingslashit($clean) . '"';
    }, $link);
};
add_filter('rank_math/frontend/next_rel_link', $akibara_clean_rel_link, 20);
add_filter('rank_math/frontend/prev_rel_link', $akibara_clean_rel_link, 20);
