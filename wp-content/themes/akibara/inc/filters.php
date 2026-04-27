<?php
/**
 * Akibara Contextual Filters v3
 * Features:
 *  - AJAX filtering with pushState (no page reload)
 *  - Collapsible accordion sections
 *  - Multi-select on editorial/genre/series
 *  - Sort integration
 *  - Product count display
 *  - Mobile bottom sheet
 *
 * @package Akibara
 * @version 3.0.0
 */

defined('ABSPATH') || exit;

// ══════════════════════════════════════════════════════════════
// CONTEXT DETECTION
// ══════════════════════════════════════════════════════════════

function akibara_get_filter_context(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $manga  = get_term_by('slug', 'manga', 'product_cat');
    $comics = get_term_by('slug', 'comics', 'product_cat');
    $manga_id  = $manga ? (int) $manga->term_id : 0;
    $comics_id = $comics ? (int) $comics->term_id : 0;

    $obj      = get_queried_object();
    $is_cat   = is_product_category();
    $is_brand = is_tax('product_brand');
    $term     = ($is_cat && $obj instanceof WP_Term) ? $obj : null;
    $brand_term = ($is_brand && $obj instanceof WP_Term) ? $obj : null;
    $context  = 'shop';

    // Also detect category from URL parameter (for /tienda/?product_cat=manga)
    if (!$term && !empty($_GET['product_cat'])) {
        $url_cat = get_term_by('slug', sanitize_text_field($_GET['product_cat']), 'product_cat');
        if ($url_cat) {
            $term = $url_cat;
            $is_cat = true;
        }
    }

    if ($is_cat && $term) {
        if ($term->term_id === $manga_id)       $context = 'manga';
        elseif ($term->parent === $manga_id)    $context = 'demo';
        elseif ($term->term_id === $comics_id)  $context = 'comics';
        elseif ($term->parent === $comics_id)   $context = 'csub';
    } elseif ($is_brand && $brand_term) {
        $context = 'brand';
    }

    // Search without category/brand context → standalone search sidebar.
    // Search INSIDE category/brand → keep category/brand context so editorial/genre
    // pills render scoped. Sidebar links still preserve `s` (see akibara_render_filters).
    if (is_search() && !$term && !$brand_term) $context = 'search';

    $cached = compact('context', 'term', 'brand_term') + ['manga_id' => $manga_id, 'comics_id' => $comics_id];
    return $cached;
}

// ══════════════════════════════════════════════════════════════
// SCOPED TERM QUERIES
// ══════════════════════════════════════════════════════════════

function akibara_scoped_count(string $taxonomy, int $term_id, int $cat_id): int {
    global $wpdb;
    static $cache = [];

    $key = "{$taxonomy}_{$term_id}_{$cat_id}";
    if (isset($cache[$key])) return $cache[$key];

    $count = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
            AND tt1.taxonomy = 'product_cat'
            AND tt1.term_id IN (
                SELECT term_id FROM {$wpdb->term_taxonomy}
                WHERE taxonomy = 'product_cat'
                AND (term_id = %d OR parent = %d)
            )
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
            AND tt2.taxonomy = %s AND tt2.term_id = %d
        WHERE p.post_type = 'product' AND p.post_status = 'publish'
    ", $cat_id, $cat_id, $taxonomy, $term_id));

    $cache[$key] = $count;
    return $count;
}

function akibara_get_scoped_terms(string $taxonomy, int $cat_scope_id = 0, int $limit = 0): array {
    // Transient cache: 2 hours
    $cache_key = 'akf_st_' . md5($taxonomy . $cat_scope_id . $limit);
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $args = [
        'taxonomy'   => $taxonomy,
        'hide_empty' => true,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ];
    if ($limit > 0) $args['number'] = $limit * 3;

    $terms = get_terms($args);
    if (is_wp_error($terms) || empty($terms)) return [];

    if ($cat_scope_id > 0) {
        $scoped = [];
        foreach ($terms as $t) {
            $count = akibara_scoped_count($taxonomy, $t->term_id, $cat_scope_id);
            if ($count > 0) {
                $t->scoped_count = $count;
                $scoped[] = $t;
            }
        }
        usort($scoped, fn($a, $b) => $b->scoped_count - $a->scoped_count);
        $terms = $scoped;
    }

    if ($limit > 0) $terms = array_slice($terms, 0, $limit);
    set_transient($cache_key, $terms, 2 * HOUR_IN_SECONDS);
    return $terms;
}

// ══════════════════════════════════════════════════════════════
// RENDER FILTERS (with accordion + multi-select)
// ══════════════════════════════════════════════════════════════

function akibara_render_filters(): void {
    $ctx = akibara_get_filter_context();
    $c = $ctx['context'];
    $term = $ctx['term'];
    $brand_term = $ctx['brand_term'] ?? null;
    $cat_id = $term ? (int) $term->term_id : 0;
    if ($c === 'brand' && $brand_term) {
        $base_url = get_term_link($brand_term);
    } else {
        $base_url = $term ? get_term_link($term) : get_permalink(wc_get_page_id('shop'));
    }
    if (is_wp_error($base_url)) {
        $base_url = get_permalink(wc_get_page_id('shop'));
    }

    // Preserve search query across filter navigation (flujo search → filtro/toggle/clear)
    $search_q = is_search() ? trim((string) get_search_query(false)) : '';
    if ($search_q !== '') {
        $base_url = add_query_arg(['s' => $search_q, 'post_type' => 'product'], $base_url);
    }

    // ── Clear all filters ──
    $filter_params = ['filter_product_brand', 'filter_pa_genero', 'filter_pa_serie', 'min_price', 'max_price', 'stock', 'preorder', 'sale', 'nuevo', 'orderby'];
    $has_active = false;
    foreach ($filter_params as $p) {
        if (isset($_GET[$p])) { $has_active = true; break; }
    }
    if ($has_active) {
        echo '<div class="sidebar-widget sidebar-widget--clear">';
        echo '<a href="' . esc_url($base_url) . '" class="filter-clear-btn" data-filter-clear>';
        echo '&#215; Limpiar todos los filtros';
        echo '</a></div>';
    }

    // ── Stock toggle prominente (movido arriba) ──
    $stock_active = isset($_GET['stock']);
    $stock_url = $stock_active ? remove_query_arg('stock', $base_url) : add_query_arg('stock', 'instock', $base_url);
    echo '<div class="sidebar-widget sidebar-widget--stock-top">';
    echo '<a href="' . esc_url($stock_url) . '" class="filter-toggle-link' . ($stock_active ? ' filter-toggle-link--active' : '') . '" data-param="stock" data-value="instock">';
    echo '<span class="filter-toggle-switch' . ($stock_active ? ' filter-toggle-switch--on' : '') . '"></span>';
    echo '<span>Solo disponibles</span>';
    echo '</a></div>';

    // ── Demographics (category pills) ──
    if ($c === 'manga') {
        akibara_render_accordion_pills('Demografia', $ctx['manga_id'], $cat_id, true);
    } elseif ($c === 'comics') {
        akibara_render_accordion_pills('Tipo', $ctx['comics_id'], $cat_id, true);
    }

    // ── Editorial (multi-select) ──
    $editorial_scope = $cat_id;
    if ($c === 'demo') $editorial_scope = $ctx['manga_id'];
    if ($c === 'csub') $editorial_scope = $ctx['comics_id'];

    if (in_array($c, ['manga', 'comics', 'demo', 'csub'])) {
        $brands = akibara_get_scoped_terms('product_brand', $editorial_scope, 12);
        if ($brands) {
            akibara_render_accordion_checklist_grouped('Editorial', $brands, 'product_brand', $base_url, true, true);
        }
    } elseif ($c === 'shop' || $c === 'brand' || $c === 'search') {
        $brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 12]);
        if ($brands && !is_wp_error($brands)) {
            akibara_render_accordion_checklist_grouped('Editorial', $brands, 'product_brand', $base_url, true, true);
        }
    }

    // ── Genre (multi-select) — abierto por defecto ──
    if (in_array($c, ["manga", "demo", "comics", "csub", "shop", "brand", "search"])) {
        if ($c === 'demo') { $scope_for_genre = $cat_id; } elseif ($c === 'manga') { $scope_for_genre = $ctx['manga_id']; } elseif ($c === 'comics' || $c === 'csub') { $scope_for_genre = $ctx['comics_id']; } else { $scope_for_genre = 0; }
        $genres = akibara_get_scoped_terms('pa_genero', $scope_for_genre, 10);
        if ($genres) {
            akibara_render_accordion_checklist('Género', $genres, 'pa_genero', $base_url, true, true);
        }
    }

    // ── Series (multi-select, demo only) — cerrado por defecto ──
    if ($c === 'demo') {
        $series = akibara_get_scoped_terms('pa_serie', $cat_id, 15);
        if ($series) {
            akibara_render_accordion_checklist('Serie', $series, 'pa_serie', $base_url, true, false);
        }
    }

    // ── Price — cerrado por defecto ──
    akibara_render_accordion_price($base_url, false);

    // ── Toggles (sin "Solo en stock" que ya se movio arriba) ──
    echo '<div class="sidebar-widget sidebar-widget--toggles">';
    echo '<h3 class="sidebar-widget__title sidebar-widget__title--static">Filtros rapidos</h3>';
    akibara_render_toggle('Preventas', 'preorder', '1', $base_url);
    akibara_render_toggle('Novedades', 'nuevo', '1', $base_url);
    akibara_render_toggle('En oferta', 'sale', '1', $base_url);
    echo '</div>';
}

// ══════════════════════════════════════════════════════════════
// ACCORDION WRAPPERS
// ══════════════════════════════════════════════════════════════

function akibara_render_accordion_pills(string $label, int $parent, int $current_id, bool $open = true): void {
    $args = [
        'taxonomy'   => 'product_cat',
        'parent'     => $parent,
        'hide_empty' => true,
        'orderby'    => 'count',
        'order'      => 'DESC',
        'exclude'    => array_filter([get_option('default_product_cat')]),
    ];
    $terms = get_terms($args);
    if (empty($terms) || is_wp_error($terms)) return;

    $open_attr = $open ? ' open' : '';
    echo '<details class="sidebar-widget sidebar-accordion"' . $open_attr . '>';
    echo '<summary class="sidebar-widget__title sidebar-accordion__toggle">' . esc_html($label) . '<svg aria-hidden="true" focusable="false" class="accordion-chevron" width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" fill="none"/></svg></summary>';
    echo '<div class="sidebar-accordion__body"><div class="filter-pills">';
    $search_q = is_search() ? trim((string) get_search_query(false)) : '';
    foreach ($terms as $t) {
        $active = ($current_id === $t->term_id) ? ' filter-pill--active' : '';
        $pill_url = get_term_link($t);
        if (!is_wp_error($pill_url) && $search_q !== '') {
            $pill_url = add_query_arg(['s' => $search_q, 'post_type' => 'product'], $pill_url);
        }
        echo '<a href="' . esc_url(is_wp_error($pill_url) ? '#' : $pill_url) . '" class="filter-pill' . $active . '">' . esc_html($t->name) . '</a>';
    }
    echo '</div></div></details>';
}

function akibara_render_accordion_checklist(string $label, array $terms, string $taxonomy, string $base_url, bool $show_count, bool $open = true): void {
    $active_slugs = isset($_GET['filter_' . $taxonomy]) ? explode(',', sanitize_text_field($_GET['filter_' . $taxonomy])) : [];
    $has_active = !empty($active_slugs);
    $param = 'filter_' . $taxonomy;
    $visible_limit = 5;
    $total = count($terms);
    // Sprint 7 D5: ID único para aria-controls del botón "Ver más" (a11y).
    $checklist_id = 'filter-checklist-' . sanitize_html_class($param);

    $open_attr = ($open || $has_active) ? ' open' : '';
    echo '<details class="sidebar-widget sidebar-accordion"' . $open_attr . '>';
    echo '<summary class="sidebar-widget__title sidebar-accordion__toggle">' . esc_html($label);
    if ($has_active) echo '<span class="sidebar-accordion__badge">' . count($active_slugs) . '</span>';
    echo '<svg aria-hidden="true" focusable="false" class="accordion-chevron" width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" fill="none"/></svg></summary>';
    echo '<div class="sidebar-accordion__body"><ul class="filter-checklist" id="' . esc_attr($checklist_id) . '" data-taxonomy="' . esc_attr($param) . '">';

    $i = 0;
    foreach ($terms as $t) {
        $is_active = in_array($t->slug, $active_slugs);
        $count = isset($t->scoped_count) ? $t->scoped_count : $t->count;

        // Build URL: toggle this term in/out of the comma-separated list
        if ($is_active) {
            $new_slugs = array_diff($active_slugs, [$t->slug]);
            $url = empty($new_slugs) ? remove_query_arg($param, $base_url) : add_query_arg($param, implode(',', $new_slugs), $base_url);
        } else {
            $new_slugs = array_merge($active_slugs, [$t->slug]);
            $url = add_query_arg($param, implode(',', $new_slugs), $base_url);
        }

        $checked = $is_active ? ' checked' : '';
        $overflow_class = ($i >= $visible_limit && !$is_active) ? ' filter-check--overflow' : '';
        echo '<li class="' . trim($overflow_class) . '">';
        echo '<a href="' . esc_url($url) . '" class="filter-check' . ($is_active ? ' filter-check--active' : '') . '" data-slug="' . esc_attr($t->slug) . '" data-param="' . esc_attr($param) . '">';
        echo '<span class="filter-checkbox' . $checked . '"></span>';
        echo '<span class="filter-check__label">' . esc_html($t->name) . '</span>';
        if ($show_count) {
            echo '<span class="filter-check__count">' . absint($count) . '</span>';
        }
        echo '</a></li>';
        $i++;
    }
    echo '</ul>';

    // "Ver mas" button — Sprint 7 D5: a11y mejora (aria-expanded + aria-controls)
    if ($total > $visible_limit) {
        $overflow_count = 0;
        foreach ($terms as $idx => $t) {
            if ($idx >= $visible_limit && !in_array($t->slug, $active_slugs)) $overflow_count++;
        }
        if ($overflow_count > 0) {
            // aria-expanded + aria-controls vinculan al UL ID definido arriba.
            echo '<button type="button" class="filter-show-more" data-expanded="false" aria-expanded="false" aria-controls="' . esc_attr($checklist_id) . '">Ver ' . $overflow_count . ' m&aacute;s <svg aria-hidden="true" focusable="false" width="10" height="10" viewBox="0 0 12 12"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" fill="none"/></svg></button>';
        }
    }

    echo '</div></details>';
}

function akibara_render_accordion_price(string $base_url, bool $open = true): void {
    $ranges = [
        ['label' => 'Hasta $8.000', 'min' => 0, 'max' => 8000],
        ['label' => '$8.000 - $12.000', 'min' => 8000, 'max' => 12000],
        ['label' => '$12.000 - $18.000', 'min' => 12000, 'max' => 18000],
        ['label' => '$18.000 - $25.000', 'min' => 18000, 'max' => 25000],
        ['label' => 'Más de $25.000', 'min' => 25000, 'max' => ''],
    ];
    $current_min = isset($_GET['min_price']) ? (int) $_GET['min_price'] : null;

    $open_attr = ($open || $current_min !== null) ? ' open' : '';
    echo '<details class="sidebar-widget sidebar-accordion"' . $open_attr . '>';
    echo '<summary class="sidebar-widget__title sidebar-accordion__toggle">Precio';
    if ($current_min !== null) echo '<span class="sidebar-accordion__badge">1</span>';
    echo '<svg aria-hidden="true" focusable="false" class="accordion-chevron" width="12" height="12" viewBox="0 0 12 12"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" fill="none"/></svg></summary>';
    echo '<div class="sidebar-accordion__body"><ul class="filter-checklist">';

    foreach ($ranges as $r) {
        $is_active = ($current_min !== null && $current_min === $r['min']);
        if ($is_active) {
            $url = remove_query_arg(['min_price', 'max_price'], $base_url);
        } else {
            $url = add_query_arg(['min_price' => $r['min'], 'max_price' => $r['max']], $base_url);
        }
        echo '<li><a href="' . esc_url($url) . '" class="filter-check' . ($is_active ? ' filter-check--active' : '') . '">';
        echo '<span class="filter-checkbox' . ($is_active ? ' checked' : '') . '"></span>';
        echo '<span class="filter-check__label">' . esc_html($r['label']) . '</span>';
        echo '</a></li>';
    }
    echo '</ul></div></details>';
}

function akibara_render_toggle(string $label, string $param, string $value, string $base_url): void {
    $is_active = isset($_GET[$param]);
    $url = $is_active ? remove_query_arg($param, $base_url) : add_query_arg($param, $value, $base_url);

    echo '<a href="' . esc_url($url) . '" class="filter-toggle-link' . ($is_active ? ' filter-toggle-link--active' : '') . '" data-param="' . esc_attr($param) . '" data-value="' . esc_attr($value) . '">';
    echo '<span class="filter-toggle-switch' . ($is_active ? ' filter-toggle-switch--on' : '') . '"></span>';
    echo '<span>' . esc_html($label) . '</span>';
    echo '</a>';
}

// ══════════════════════════════════════════════════════════════
// FACETED COUNTS — dynamic cross-filter counting
// ══════════════════════════════════════════════════════════════

/**
 * Compute faceted counts for each taxonomy filter section.
 *
 * For each section, counts products matching ALL active filters
 * EXCEPT the current section's filter. This tells users how many
 * products they'd see if they changed that particular filter.
 *
 * Uses a single SQL COUNT per section for performance (~15ms each).
 *
 * @return array ['filter_product_brand' => ['slug' => count], 'filter_pa_genero' => ['slug' => count]]
 */
function akibara_compute_facet_counts(): array {
    global $wpdb;

    // Transient cache: misma combinación de filtros → mismos counts.
    // Clave por hash de query vars relevantes (user-scoped keys no aplican: facet counts
    // son globales por combinación de filtros). TTL corto porque cambios de stock o
    // save_post_product disparan invalidación.
    $cache_key = 'akf_fc_' . md5( wp_json_encode( [
        's'                    => $_GET['s']                    ?? '',
        'product_cat'          => $_GET['product_cat']          ?? '',
        'product_brand'        => $_GET['product_brand']        ?? '',
        'filter_product_brand' => $_GET['filter_product_brand'] ?? '',
        'filter_pa_genero'     => $_GET['filter_pa_genero']     ?? '',
        'filter_pa_serie'      => $_GET['filter_pa_serie']      ?? '',
        'min_price'            => $_GET['min_price']            ?? '',
        'max_price'            => $_GET['max_price']            ?? '',
        'stock'                => $_GET['stock']                ?? '',
        'preorder'             => $_GET['preorder']             ?? '',
        'nuevo'                => $_GET['nuevo']                ?? '',
        'sale'                 => $_GET['sale']                 ?? '',
    ] ) );
    $cached = get_transient( $cache_key );
    if ( is_array( $cached ) ) return $cached;

    // Taxonomy sections that show counts
    $sections = [
        'filter_product_brand' => 'product_brand',
        'filter_pa_genero'     => 'pa_genero',
    ];

    $result = [];

    foreach ( $sections as $param => $taxonomy ) {
        // Build WP_Query args with ALL current filters EXCEPT this section
        $args = akibara_build_filter_args_except( $param );
        $args['posts_per_page'] = -1;
        $args['fields']         = 'ids';
        $args['no_found_rows']  = true;

        $q = new WP_Query( $args );
        $ids = $q->posts;
        wp_reset_postdata();

        if ( empty( $ids ) ) {
            $result[ $param ] = [];
            continue;
        }

        // Count products per term in this taxonomy using SQL
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = $wpdb->prepare(
            "SELECT t.slug, COUNT(DISTINCT tr.object_id) AS cnt
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = %s
             INNER JOIN {$wpdb->terms} t
                ON tt.term_id = t.term_id
             WHERE tr.object_id IN ($placeholders)
             GROUP BY t.slug
             ORDER BY cnt DESC",
            array_merge( [ $taxonomy ], $ids )
        );

        $rows = $wpdb->get_results( $sql );
        $section_counts = [];
        foreach ( $rows as $row ) {
            $section_counts[ $row->slug ] = (int) $row->cnt;
        }
        $result[ $param ] = $section_counts;
    }

    set_transient( $cache_key, $result, 10 * MINUTE_IN_SECONDS );
    return $result;
}

/**
 * Build WP_Query args from current request, excluding one filter param.
 *
 * @param string $exclude_param The filter param to exclude (e.g., 'filter_product_brand')
 * @return array WP_Query args
 */
function akibara_build_filter_args_except( string $exclude_param ): array {
    $args = [
        'post_type'   => 'product',
        'post_status' => 'publish',
    ];

    // Search term — keep facet counts scoped to the current search query
    if ( ! empty( $_GET['s'] ) ) {
        $args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
    }

    // Category
    $tax_query = [];
    if ( ! empty( $_GET['product_cat'] ) ) {
        $cat = get_term_by( 'slug', sanitize_text_field( $_GET['product_cat'] ), 'product_cat' );
        if ( $cat ) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => array_merge( [ $cat->term_id ], get_term_children( $cat->term_id, 'product_cat' ) ),
            ];
        }
    }

    // Brand context (from /marca/<slug>/ URL) — same rationale as in the AJAX
    // filter endpoint: keep facet counts scoped to the brand archive.
    if ( ! empty( $_GET['product_brand'] ) && $exclude_param !== 'filter_product_brand' ) {
        $brand_term = get_term_by( 'slug', sanitize_text_field( $_GET['product_brand'] ), 'product_brand' );
        if ( $brand_term ) {
            $tax_query[] = [
                'taxonomy' => 'product_brand',
                'field'    => 'term_id',
                'terms'    => [ (int) $brand_term->term_id ],
            ];
        }
    }

    // Taxonomy filters (skip the excluded one)
    $tax_filters = [
        'filter_product_brand' => 'product_brand',
        'filter_pa_genero'     => 'pa_genero',
        'filter_pa_serie'      => 'pa_serie',
    ];
    foreach ( $tax_filters as $param => $taxonomy ) {
        if ( $param === $exclude_param ) continue;
        if ( ! empty( $_GET[ $param ] ) ) {
            $slugs = array_map( 'sanitize_text_field', explode( ',', $_GET[ $param ] ) );
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $slugs,
            ];
        }
    }

    if ( $tax_query ) {
        $tax_query['relation'] = 'AND';
        $args['tax_query'] = $tax_query;
    }

    // Price
    $meta_query = [];
    if ( isset( $_GET['min_price'] ) || isset( $_GET['max_price'] ) ) {
        $min = isset( $_GET['min_price'] ) ? (int) $_GET['min_price'] : 0;
        $max = isset( $_GET['max_price'] ) ? (int) $_GET['max_price'] : PHP_INT_MAX;
        if ( $max > 0 ) {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => [ $min, $max ],
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ];
        } else {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => $min,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ];
        }
    }

    // Stock
    if ( isset( $_GET['stock'] ) && $_GET['stock'] === 'instock' ) {
        $meta_query[] = [ 'key' => '_stock_status', 'value' => 'instock' ];
    }

    // Preorder
    if ( isset( $_GET['preorder'] ) ) {
        $meta_query[] = [ 'key' => '_akb_reserva', 'value' => 'yes' ];
    }

    // Nuevos
    if ( isset( $_GET['nuevo'] ) ) {
        $args['date_query'] = [ [ 'after' => '30 days ago', 'inclusive' => true ] ];
    }

    // Sale
    if ( isset( $_GET['sale'] ) ) {
        $sale_ids = wc_get_product_ids_on_sale();
        $args['post__in'] = ! empty( $sale_ids ) ? $sale_ids : [ 0 ];
    }

    if ( $meta_query ) {
        $meta_query['relation'] = 'AND';
        $args['meta_query'] = $meta_query;
    }

    return $args;
}

// ══════════════════════════════════════════════════════════════
// AJAX ENDPOINT — returns partial HTML (products + pagination + sidebar)
// ══════════════════════════════════════════════════════════════

add_action('wp_ajax_akibara_filter', 'akibara_ajax_filter');
add_action('wp_ajax_nopriv_akibara_filter', 'akibara_ajax_filter');

function akibara_ajax_filter(): void {
    // F1 fix (tech-debt S1): $base_url disponible para empty state "Limpiar filtros"
    // Sin esto, L783 generaba E_WARNING + link roto cuando 0 resultados.
    $shop_page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
    $base_url     = $shop_page_id > 0 ? get_permalink( $shop_page_id ) : home_url( '/tienda/' );

    // ── Response cache (HTML + total + pages + counts) por hash de params ──
    // Mismo combo de filtros → misma respuesta. TTL 10 min, invalidado en save_post_product.
    // No cachea cuando hay búsqueda libre (`s=`) para no acumular claves infinitas.
    $cache_response = empty( $_GET['s'] );
    if ( $cache_response ) {
        $response_key = 'akf_resp_' . md5( wp_json_encode( [
            'paged'                => (int) ( $_GET['paged'] ?? 1 ),
            'product_cat'          => $_GET['product_cat']          ?? '',
            'product_brand'        => $_GET['product_brand']        ?? '',
            'filter_product_brand' => $_GET['filter_product_brand'] ?? '',
            'filter_pa_genero'     => $_GET['filter_pa_genero']     ?? '',
            'filter_pa_serie'      => $_GET['filter_pa_serie']      ?? '',
            'min_price'            => $_GET['min_price']            ?? '',
            'max_price'            => $_GET['max_price']            ?? '',
            'stock'                => $_GET['stock']                ?? '',
            'preorder'             => $_GET['preorder']             ?? '',
            'nuevo'                => $_GET['nuevo']                ?? '',
            'sale'                 => $_GET['sale']                 ?? '',
            'orderby'              => $_GET['orderby']              ?? '',
        ] ) );
        $cached_response = get_transient( $response_key );
        if ( is_array( $cached_response ) ) {
            wp_send_json_success( $cached_response );
            return;
        }
    }

    // Build WP_Query args from request params
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 24,
        'paged'          => max(1, (int) ($_GET['paged'] ?? 1)),
    ];

    // Search term (preserves ?s= when paginating/filtering from search results)
    if (!empty($_GET['s'])) {
        $args['s'] = sanitize_text_field(wp_unslash($_GET['s']));
    }

    // Category
    $tax_query = [];
    if (!empty($_GET['product_cat'])) {
        $cat = get_term_by('slug', sanitize_text_field($_GET['product_cat']), 'product_cat');
        if ($cat) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => array_merge([$cat->term_id], get_term_children($cat->term_id, 'product_cat')),
            ];
        }
    }

    // Brand context (from /marca/<slug>/ URL). The current brand term must
    // constrain the query the same way WP does on a native brand archive;
    // otherwise paginating/filtering via AJAX leaks products from other brands.
    if (!empty($_GET['product_brand'])) {
        $brand_term = get_term_by('slug', sanitize_text_field($_GET['product_brand']), 'product_brand');
        if ($brand_term) {
            $tax_query[] = [
                'taxonomy' => 'product_brand',
                'field'    => 'term_id',
                'terms'    => [(int) $brand_term->term_id],
            ];
        }
    }

    // Taxonomy filters (multi-select via comma)
    $tax_filters = ['filter_product_brand' => 'product_brand', 'filter_pa_genero' => 'pa_genero', 'filter_pa_serie' => 'pa_serie'];
    foreach ($tax_filters as $param => $taxonomy) {
        if (!empty($_GET[$param])) {
            $slugs = array_map('sanitize_text_field', explode(',', $_GET[$param]));
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $slugs,
            ];
        }
    }

    if ($tax_query) {
        $tax_query['relation'] = 'AND';
        $args['tax_query'] = $tax_query;
    }

    // Price
    $meta_query = [];
    if (isset($_GET['min_price']) || isset($_GET['max_price'])) {
        $min = isset($_GET['min_price']) ? (int) $_GET['min_price'] : 0;
        $max = isset($_GET['max_price']) ? (int) $_GET['max_price'] : PHP_INT_MAX;
        if ($max > 0) {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => [$min, $max],
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ];
        } else {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => $min,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ];
        }
    }

    // Stock
    if (isset($_GET['stock']) && $_GET['stock'] === 'instock') {
        $meta_query[] = ['key' => '_stock_status', 'value' => 'instock'];
    }

    // Preorder
    if (isset($_GET['preorder'])) {
        $meta_query[] = ['key' => '_akb_reserva', 'value' => 'yes'];
    }

    // Nuevos (last 30 days)
    if (isset($_GET['nuevo'])) {
        $args['date_query'] = [['after' => '30 days ago', 'inclusive' => true]];
    }

    // Sale
    if (isset($_GET['sale'])) {
        $sale_ids = wc_get_product_ids_on_sale();
        $args['post__in'] = !empty($sale_ids) ? $sale_ids : [0];
    }

    if ($meta_query) {
        $meta_query['relation'] = 'AND';
        $args['meta_query'] = $meta_query;
    }

    // Orderby
    $orderby = sanitize_text_field($_GET['orderby'] ?? 'date');
    switch ($orderby) {
        case 'price':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_price';
            $args['order'] = 'ASC';
            break;
        case 'price-desc':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_price';
            $args['order'] = 'DESC';
            break;
        case 'popularity':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = 'total_sales';
            $args['order'] = 'DESC';
            break;
        case 'rating':
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_wc_average_rating';
            $args['order'] = 'DESC';
            break;
        default: // date
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
    }

    $query = new WP_Query($args);

    // Priming de meta cache: una sola query para todos los meta de los posts de la página.
    // Evita N+1 en `wc_get_product()` + `_akb_reserva` + `_price` + `_stock_status` dentro
    // del loop de product-card.php (se llaman 24 veces por response).
    if ( ! empty( $query->posts ) ) {
        $post_ids = wp_list_pluck( $query->posts, 'ID' );
        update_meta_cache( 'post', $post_ids );
        update_object_term_cache( $post_ids, 'product' );
    }

    ob_start();

    // Products HTML
    if ($query->have_posts()) {
        echo '<div class="product-grid product-grid--large aki-reveal">';
        set_query_var('akibara_card_context', 'catalog');
        while ($query->have_posts()) {
            $query->the_post();
            global $product;
            $product = wc_get_product(get_the_ID());
            get_template_part('template-parts/content/product-card');
        }
        set_query_var('akibara_card_context', '');
        echo '</div>';

        // Pagination
        $total_pages = (int) $query->max_num_pages;
        if ($total_pages > 1) {
            $current_page = max(1, (int) ($_GET['paged'] ?? 1));
            echo '<nav class="aki-pagination">';
            echo paginate_links([
                'base'      => '%_%',
                'format'    => '?paged=%#%',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '&laquo; Anterior',
                'next_text' => 'Siguiente &raquo;',
                'mid_size'  => 2,
                'end_size'  => 1,
                'type'      => 'list',
            ]);
            echo '</nav>';
        }
    } else {
        $clear_url = esc_url( remove_query_arg( ['stock','preorder','sale','nuevo','min_price','max_price','filter_product_brand','filter_pa_genero','filter_pa_serie'], $base_url ) );
        echo '<div class="shop-empty">';
        echo '<h3>Sin resultados para estos filtros</h3>';
        echo '<p class="shop-empty__desc">Prueba con otros criterios o limpia los filtros para ver todo el catálogo.</p>';
        echo '<a href="' . $clear_url . '" class="btn btn--secondary shop-empty__cta" data-filter-clear>Limpiar filtros</a>';
        echo '</div>';
    }

    $products_html = ob_get_clean();
    wp_reset_postdata();

    // ── Faceted counts: recount each filter section excluding its own filter ──
    $facet_counts = akibara_compute_facet_counts();

    $payload = [
        'html'   => $products_html,
        'total'  => (int) $query->found_posts,
        'pages'  => (int) $query->max_num_pages,
        'counts' => $facet_counts,
    ];

    if ( isset( $response_key ) && $cache_response ) {
        set_transient( $response_key, $payload, 10 * MINUTE_IN_SECONDS );
    }

    wp_send_json_success( $payload );
}

// ══════════════════════════════════════════════════════════════
// QUERY FILTERS (for non-AJAX page loads) — D1 fix S1-11
// ══════════════════════════════════════════════════════════════
//
// Problema pre-S1: los 5 hooks woocommerce_product_query sólo aplican
// en contextos WC archive (shop, product_cat, product_brand). URLs
// directas tipo /?s=naruto&post_type=product&filter_pa_genero=shonen
// o paths custom no disparan el hook → filtros ignorados server-side.
//
// Fix: helper akibara_apply_filters_to_query() reusable + registrar
// el mismo filtrado tanto en woocommerce_product_query como en
// pre_get_posts (cubre search + post_type_archive + tax queries).
// Guard spl_object_id evita doble aplicación cuando ambos hooks
// disparan sobre la misma query (shop archive dispara ambos).

/**
 * Aplica los filtros de URL ($_GET) a una WP_Query de productos.
 * Usado por woocommerce_product_query (archives WC) y pre_get_posts
 * (search, queries custom). Idempotente: no re-aplica sobre la misma
 * instancia de query.
 *
 * @param WP_Query $query Query a modificar (se mutan tax_query, meta_query, post__in, date_query).
 */
function akibara_apply_filters_to_query( WP_Query $query ): void {
    static $applied = [];
    $id = spl_object_id( $query );
    if ( isset( $applied[ $id ] ) ) {
        return;
    }
    $applied[ $id ] = true;

    // Taxonomy multi-select filters
    $tax_filters = [
        'filter_product_brand' => 'product_brand',
        'filter_pa_genero'     => 'pa_genero',
        'filter_pa_serie'      => 'pa_serie',
    ];
    $tax_query = $query->get( 'tax_query' ) ?: [];
    $tax_added = false;

    foreach ( $tax_filters as $param => $taxonomy ) {
        if ( ! empty( $_GET[ $param ] ) ) {
            $slugs = array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_GET[ $param ] ) ) );
            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => $slugs,
            ];
            $tax_added = true;
        }
    }

    if ( $tax_added ) {
        if ( ! isset( $tax_query['relation'] ) ) {
            $tax_query['relation'] = 'AND';
        }
        $query->set( 'tax_query', $tax_query );
    }

    // Meta query (stock, preorder, price)
    $meta_query = $query->get( 'meta_query' ) ?: [];
    $meta_added = false;

    if ( isset( $_GET['stock'] ) && 'instock' === $_GET['stock'] ) {
        $meta_query[] = [ 'key' => '_stock_status', 'value' => 'instock' ];
        $meta_added   = true;
    }

    if ( isset( $_GET['preorder'] ) && '1' === $_GET['preorder'] ) {
        $meta_query[] = [ 'key' => '_akb_reserva', 'value' => 'yes' ];
        $meta_added   = true;
    }

    $has_min = isset( $_GET['min_price'] ) && $_GET['min_price'] !== '';
    $has_max = isset( $_GET['max_price'] ) && $_GET['max_price'] !== '';
    if ( $has_min || $has_max ) {
        $min = $has_min ? (int) $_GET['min_price'] : 0;
        $max = $has_max ? (int) $_GET['max_price'] : 0;
        if ( $max > 0 ) {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => [ $min, $max ],
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ];
        } elseif ( $min > 0 ) {
            $meta_query[] = [
                'key'     => '_price',
                'value'   => $min,
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ];
        }
        $meta_added = true;
    }

    if ( $meta_added ) {
        if ( ! isset( $meta_query['relation'] ) ) {
            $meta_query['relation'] = 'AND';
        }
        $query->set( 'meta_query', $meta_query );
    }

    // Novedades (last 30 days)
    if ( isset( $_GET['nuevo'] ) && '1' === $_GET['nuevo'] ) {
        $query->set( 'date_query', [ [ 'after' => '30 days ago', 'inclusive' => true ] ] );
    }

    // Sale
    if ( isset( $_GET['sale'] ) && '1' === $_GET['sale'] ) {
        $ids = wc_get_product_ids_on_sale();
        $query->set( 'post__in', ! empty( $ids ) ? $ids : [ 0 ] );
    }
}

// Hook 1: WC archive flow (shop, product_cat, product_brand).
add_action( 'woocommerce_product_query', 'akibara_apply_filters_to_query' );

// Hook 2: pre_get_posts cubre URLs directas que no pasan por WC archive
// — search con post_type=product, post_type_archive('product'), custom
// taxonomy queries pa_genero/pa_serie.
add_action( 'pre_get_posts', function ( $query ) {
    if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
        return;
    }

    $post_type = $query->get( 'post_type' );
    $is_product_query = false;

    if ( $post_type === 'product'
         || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) {
        $is_product_query = true;
    } elseif ( $query->is_post_type_archive( 'product' )
               || $query->is_tax( [ 'product_cat', 'product_brand', 'pa_genero', 'pa_serie' ] ) ) {
        $is_product_query = true;
    } elseif ( $query->is_search()
               && ! empty( $_GET['post_type'] )
               && sanitize_text_field( $_GET['post_type'] ) === 'product' ) {
        $is_product_query = true;
    }

    if ( ! $is_product_query ) {
        return;
    }

    akibara_apply_filters_to_query( $query );
}, 11 ); // priority 11 → corre después de hooks core WC priority 10.

// ══════════════════════════════════════════════════════════════
// FREE SHIPPING PROGRESS BAR
// ══════════════════════════════════════════════════════════════

function akibara_shipping_progress_bar(): void {
    if (!function_exists('WC') || !WC()->cart) return;

    $threshold = akibara_get_free_shipping_threshold();
    // CO-1 fix: get_subtotal() devuelve ex_tax, pero el threshold y el precio
    // que ve el usuario están incl_tax. get_displayed_subtotal() respeta
    // wc_prices_include_tax (true en Chile con IVA 19% incluido).
    $total     = (float) WC()->cart->get_displayed_subtotal();
    $remaining = max( 0, $threshold - $total );
    $progress  = $threshold > 0 ? min( 100, (int) round( $total * 100 / $threshold ) ) : 100;

    // Edge case: cupón con free_shipping activo → mostrar logrado aunque no alcance threshold
    $coupon_free_ship = false;
    foreach ( WC()->cart->get_coupons() as $coupon ) {
        if ( $coupon instanceof WC_Coupon && $coupon->get_free_shipping() ) {
            $coupon_free_ship = true;
            break;
        }
    }
    $achieved = $remaining <= 0 || $coupon_free_ship;

    echo '<div class="shipping-progress">';
    if ( ! $achieved ) {
        $msg = 'Te faltan <strong>$' . number_format( $remaining, 0, ',', '.' ) . '</strong> para envío gratis a todo Chile';
        echo '<div class="shipping-progress__text">' . akibara_icon( 'truck', 16 ) . ' ' . $msg . '</div>';
    } else {
        echo '<div class="shipping-progress__text shipping-progress__text--free">';
        echo '✅ <strong>Envío gratis incluido</strong> — despachamos a todo Chile';
        echo '</div>';
    }
    echo '<div class="shipping-progress__bar"><div class="shipping-progress__fill" style="width:' . $progress . '%"></div></div>';
    echo '</div>';
}

add_action('woocommerce_before_cart_table', 'akibara_shipping_progress_bar');
// Checkout: la barra de progreso ya se muestra en el sidebar "TU PEDIDO"
// (JS updateFreeShippingProgress → .aki-ship-freebar). Eliminamos el duplicado
// del área principal para no repetir el mensaje al cliente.

// ══════════════════════════════════════════════════════════════
// CACHE INVALIDATION — Clear filter transients when products change
// ══════════════════════════════════════════════════════════════
add_action("save_post_product", function() {
    global $wpdb;
    // Invalida: scoped terms (`akf_st_`), facet counts (`akf_fc_`), response cache (`akf_resp_`).
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_akf_st_%' OR option_name LIKE '_transient_timeout_akf_st_%' OR option_name LIKE '_transient_akf_fc_%' OR option_name LIKE '_transient_timeout_akf_fc_%' OR option_name LIKE '_transient_akf_resp_%' OR option_name LIKE '_transient_timeout_akf_resp_%'" );
}, 20);

// Invalida cache de response/facets ante cambios de stock (venta, ajuste manual).
add_action('woocommerce_product_set_stock', function() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_akf_resp_%' OR option_name LIKE '_transient_timeout_akf_resp_%' OR option_name LIKE '_transient_akf_fc_%' OR option_name LIKE '_transient_timeout_akf_fc_%'" );
});
add_action('woocommerce_variation_set_stock', function() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_akf_resp_%' OR option_name LIKE '_transient_timeout_akf_resp_%' OR option_name LIKE '_transient_akf_fc_%' OR option_name LIKE '_transient_timeout_akf_fc_%'" );
});

// ══════════════════════════════════════════════════════════════
// GROUPED EDITORIAL FILTER — by country
// ══════════════════════════════════════════════════════════════

function akibara_get_brand_country_groups(array $terms): array {
    $groups = [];
    foreach ($terms as $t) {
        $country = get_term_meta($t->term_id, 'country', true) ?: '__other';
        $groups[$country][] = $t;
    }
    return $groups;
}

function akibara_render_accordion_checklist_grouped(
    string $label,
    array $terms,
    string $taxonomy,
    string $base_url,
    bool $show_count,
    bool $open = true
): void {
    $groups = akibara_get_brand_country_groups($terms);
    $named_groups = array_filter(array_keys($groups), fn($k) => $k !== '__other');

    // Fallback to flat render if no country meta
    if (empty($named_groups)) {
        akibara_render_accordion_checklist($label, $terms, $taxonomy, $base_url, $show_count, $open);
        return;
    }

    $param = 'filter_' . $taxonomy;
    $active_slugs = isset($_GET[$param])
        ? array_filter(explode(',', sanitize_text_field($_GET[$param])))
        : [];
    $has_active = !empty($active_slugs);

    $country_labels = [
        'AR' => 'Edición Argentina',
        'ES' => 'Edición Española',
    ];

    $open_attr = ($open || $has_active) ? ' open' : '';
    echo '<details class="sidebar-widget sidebar-accordion"' . $open_attr . '>';
    echo '<summary class="sidebar-widget__title sidebar-accordion__toggle">' . esc_html($label);
    if ($has_active) {
        echo '<span class="sidebar-accordion__badge">' . count($active_slugs) . '</span>';
    }
    echo '<svg aria-hidden="true" focusable="false" class="accordion-chevron" width="12" height="12" viewBox="0 0 12 12">'
       . '<path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" fill="none"/>'
       . '</svg></summary>';
    echo '<div class="sidebar-accordion__body">';

    // Render named country groups first, then ungrouped
    $group_order = array_merge($named_groups, array_filter(array_keys($groups), fn($k) => $k === '__other')); 

    foreach ($group_order as $country_key) {
        $group_terms = $groups[$country_key];
        if (empty($group_terms)) continue;

        if ($country_key === '__other') {
            echo '<ul class="filter-checklist" data-taxonomy="' . esc_attr($param) . '">';
            foreach ($group_terms as $t) {
                akibara_render_brand_check_item($t, $active_slugs, $param, $base_url, $show_count);
            }
            echo '</ul>';
            continue;
        }

        $group_slugs    = array_map(fn($t) => $t->slug, $group_terms);
        $active_in_group = array_intersect($active_slugs, $group_slugs);
        $all_active     = count($active_in_group) === count($group_slugs);
        $some_active    = !empty($active_in_group) && !$all_active;

        // Group count = sum of children
        $group_count = 0;
        foreach ($group_terms as $t) {
            $group_count += isset($t->scoped_count) ? $t->scoped_count : $t->count;
        }

        // Group header URL: all active → deselect all, else → select all
        if ($all_active) {
            $new_slugs = array_values(array_diff($active_slugs, $group_slugs));
            $header_url = empty($new_slugs)
                ? remove_query_arg($param, $base_url)
                : add_query_arg($param, implode(',', $new_slugs), $base_url);
        } else {
            $new_slugs = array_values(array_unique(array_merge($active_slugs, $group_slugs)));
            $header_url = add_query_arg($param, implode(',', $new_slugs), $base_url);
        }

        // Tri-state checkbox
        $cb_class = 'filter-group__checkbox';
        $group_state = 'none';
        if ($all_active)  { $cb_class .= ' checked'; $group_state = 'all'; }
        elseif ($some_active) { $cb_class .= ' partial'; $group_state = 'partial'; }

        $group_label = $country_labels[$country_key] ?? ('Edición ' . $country_key);

        echo '<div class="filter-group" data-group="' . esc_attr($country_key) . '" data-group-state="' . esc_attr($group_state) . '">';
        echo '<a href="' . esc_url($header_url) . '"'
           . ' class="filter-group__header"'
           . ' data-group-slugs="' . esc_attr(implode(',', $group_slugs)) . '"'
           . ' data-param="' . esc_attr($param) . '">';
        echo '<span class="' . esc_attr($cb_class) . '"></span>';
        echo '<span class="filter-group__label">' . esc_html($group_label) . '</span>';
        if ($show_count) {
            echo '<span class="filter-check__count">' . number_format($group_count, 0, ',', '.') . '</span>';
        }
        echo '</a>';

        echo '<ul class="filter-checklist filter-group__items" data-taxonomy="' . esc_attr($param) . '">';
        foreach ($group_terms as $t) {
            akibara_render_brand_check_item($t, $active_slugs, $param, $base_url, $show_count);
        }
        echo '</ul>';
        echo '</div>';
    }

    echo '</div></details>';
}

function akibara_render_brand_check_item(
    WP_Term $t,
    array $active_slugs,
    string $param,
    string $base_url,
    bool $show_count
): void {
    $is_active = in_array($t->slug, $active_slugs, true);
    $count = isset($t->scoped_count) ? $t->scoped_count : $t->count;

    if ($is_active) {
        $new_slugs = array_values(array_diff($active_slugs, [$t->slug]));
        $url = empty($new_slugs)
            ? remove_query_arg($param, $base_url)
            : add_query_arg($param, implode(',', $new_slugs), $base_url);
    } else {
        $new_slugs = array_values(array_unique(array_merge($active_slugs, [$t->slug])));
        $url = add_query_arg($param, implode(',', $new_slugs), $base_url);
    }

    echo '<li>';
    echo '<a href="' . esc_url($url) . '"'
       . ' class="filter-check' . ($is_active ? ' filter-check--active' : '') . '"'
       . ' data-slug="' . esc_attr($t->slug) . '"'
       . ' data-param="' . esc_attr($param) . '">';
    echo '<span class="filter-checkbox' . ($is_active ? ' checked' : '') . '"></span>';
    echo '<span class="filter-check__label">' . esc_html($t->name) . '</span>';
    if ($show_count) {
        echo '<span class="filter-check__count">' . number_format($count, 0, ',', '.') . '</span>';
    }
    echo '</a></li>';
}
