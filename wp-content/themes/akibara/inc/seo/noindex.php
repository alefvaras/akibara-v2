<?php
defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════
// NOINDEX — Block junk URLs from being indexed
//
// B-S1-SEO-01 (2026-04-27): migrado de echo directo `<meta robots>` a filter
// `rank_math/frontend/robots`. Antes Rank Math + theme emitían 2 metas
// conflictivos (ej. /manga/?orderby=price servía noindex,nofollow del theme +
// index,follow de Rank Math). Ahora Rank Math es la única fuente y este filter
// inyecta los directives custom en su pipeline.
// ═══════════════════════════════════════════════════════════════
add_filter('rank_math/frontend/robots', function ($robots) {
    if (!is_array($robots)) {
        $robots = [];
    }

    $force_noindex = false;
    $follow_only   = false; // true => noindex pero permite follow

    if (is_feed())            { $force_noindex = true; $follow_only = true; }
    elseif (is_search())      { $force_noindex = true; $follow_only = true; }
    elseif (is_author())      { $force_noindex = true; $follow_only = true; }
    elseif (is_tag())         { $force_noindex = true; $follow_only = true; }
    elseif (is_date())        { $force_noindex = true; $follow_only = true; }
    elseif (is_attachment())  { $force_noindex = true; $follow_only = true; }

    if (!$force_noindex) {
        $junk_params = ['add-to-cart', 'orderby', 'per_page', 'rating', 'layout', 'filter_', 'removed_item', 'undo_item'];
        $qs          = $_SERVER['QUERY_STRING'] ?? '';
        foreach ($junk_params as $param) {
            if (isset($_GET[$param]) || ($qs !== '' && strpos($qs, $param) !== false)) {
                $force_noindex = true;
                $follow_only   = false; // junk params: nofollow también.
                break;
            }
        }
    }

    if ($force_noindex) {
        $robots['index']  = 'noindex';
        $robots['follow'] = $follow_only ? 'follow' : 'nofollow';
    }

    return $robots;
}, 20);

// Fallback para sitios sin Rank Math (defensivo — Rank Math está activo en prod).
if (!defined('RANK_MATH_VERSION')) {
    add_action('wp_head', function () {
        $force = false;
        $follow_only = false;
        if (is_feed() || is_search() || is_author() || is_tag() || is_date() || is_attachment()) {
            $force = true;
            $follow_only = true;
        } else {
            $junk_params = ['add-to-cart', 'orderby', 'per_page', 'rating', 'layout', 'filter_', 'removed_item', 'undo_item'];
            $qs          = $_SERVER['QUERY_STRING'] ?? '';
            foreach ($junk_params as $param) {
                if (isset($_GET[$param]) || ($qs !== '' && strpos($qs, $param) !== false)) {
                    $force = true;
                    break;
                }
            }
        }
        if ($force) {
            $directive = $follow_only ? 'noindex, follow' : 'noindex, nofollow';
            echo '<meta name="robots" content="' . esc_attr($directive) . '" />' . "\n";
        }
    }, 1);
}

// ═══════════════════════════════════════════════════════════════
// B-S1-SEO-01 (2026-04-27): fix BreadcrumbList JSON-LD position string→int.
// Rank Math emite "position":"1" (string) por defecto. Schema.org spec dice
// que position debe ser Integer. Google tolera strings pero es warning.
//
// Hook directo de Rank Math en class-breadcrumbs.php:63 que pasa el entity
// BreadcrumbList completo antes de mergearlo al @graph.
// ═══════════════════════════════════════════════════════════════
// Nota: el filter para fix BreadcrumbList position string→int se registra en
// mu-plugin akibara-seo-breadcrumb-fix (carga antes del theme y persiste aún
// si el theme se cambia).

// ═══════════════════════════════════════════════════════════════
// DISABLE FEEDS — We don't need RSS feeds indexed
// ═══════════════════════════════════════════════════════════════
add_action('template_redirect', function () {
    if (is_feed() && !is_admin()) {
        // Allow main blog feed but disable product/comment feeds
        if (is_singular('product') || is_post_type_archive('product') || is_comment_feed()) {
            wp_redirect(home_url('/'), 301);
            exit;
        }
    }
});

// ═══════════════════════════════════════════════════════════════
// REMOVE FEED LINKS from <head> — Reduce crawl noise
// ═══════════════════════════════════════════════════════════════
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'feed_links_extra', 3);

// ═══════════════════════════════════════════════════════════════
// EXCLUDE TAXONOMIES FROM SITEMAPS — thin content without SEO value
// pa_serie: replaced by custom /serie/ landing pages
// pa_encuadernacion: thin content, not valuable for SEO
// pa_pais: thin content, not valuable for SEO
// ═══════════════════════════════════════════════════════════════
add_filter("rank_math/sitemap/exclude_taxonomy", function ($exclude, $taxonomy) {
    if (in_array($taxonomy, ['pa_serie', 'pa_encuadernacion', 'pa_pais'], true)) return true;
    return $exclude;
}, 10, 2);

// ═══════════════════════════════════════════════════════════════
// DEEP PAGINATION NOINDEX — Prevent thin content pages from indexing
// Pages beyond page 5 of any archive are thin content with minimal SEO value.
// This complements robots.txt Disallow rules as a belt-and-suspenders approach.
// ═══════════════════════════════════════════════════════════════
add_action('wp_head', function () {
    if (!is_paged()) return;

    $paged = max(1, (int) get_query_var('paged'));

    // Noindex pages deeper than page 5
    if ($paged > 5) {
        echo '<meta name="robots" content="noindex, follow" />' . "\n";
    }
}, 1);

// ═══════════════════════════════════════════════════════════════
// 404 PAGINATION HANDLER — Return 404 for impossibly deep pages
// If a paged archive exceeds the actual number of pages, serve 404
// instead of empty archive template (which wastes crawl budget).
// ═══════════════════════════════════════════════════════════════
add_action('template_redirect', function () {
    if (!is_paged()) return;

    global $wp_query;
    $paged     = max(1, (int) get_query_var('paged'));
    $max_pages = (int) $wp_query->max_num_pages;

    if ($max_pages > 0 && $paged > $max_pages) {
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
});
