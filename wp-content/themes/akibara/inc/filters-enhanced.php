<?php
/**
 * Akibara — Enhanced Filters
 *
 * Mejoras al sistema de filtros:
 *  1. Active filter chips (muestra qué filtros están aplicados)
 *  2. Filter count badge en el botón mobile
 *  3. Price filter visible en /tienda/
 *  4. Conteo de productos por editorial en shop
 *  5. "Limpiar filtros" button
 *
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// 1. ACTIVE FILTER CHIPS — above product grid
// ══════════════════════════════════════════════════════════════════

add_action( 'woocommerce_before_shop_loop', 'akibara_render_active_filters', 5 );

function akibara_render_active_filters(): void {
    $active = akibara_get_active_filters();
    if ( empty( $active ) ) return;

    $base_url = get_pagenum_link( 1, false );
    // Strip all filter params for "clear all"
    $clear_url = remove_query_arg( [ 'stock', 'preorder', 'min_price', 'max_price', 'filter_product_brand', 'filter_pa_genero', 'filter_pa_serie', 'orderby' ], $base_url );

    echo '<div class="akb-active-filters">';
    foreach ( $active as $filter ) {
        $remove_url = remove_query_arg( $filter['param'], $base_url );
        echo '<a href="' . esc_url( $remove_url ) . '" class="akb-filter-chip">';
        echo esc_html( $filter['label'] );
        echo ' <span class="akb-filter-chip__x">✕</span>';
        echo '</a>';
    }
    echo '<a href="' . esc_url( $clear_url ) . '" class="akb-filter-clear">Limpiar filtros</a>';
    echo '</div>';
}

function akibara_get_active_filters(): array {
    $active = [];

    if ( isset( $_GET['stock'] ) ) {
        $active[] = [ 'label' => 'Solo disponibles', 'param' => 'stock' ];
    }
    if ( isset( $_GET['preorder'] ) ) {
        $active[] = [ 'label' => 'Preventas', 'param' => 'preorder' ];
    }
    if ( isset( $_GET['min_price'] ) ) {
        $min = (int) $_GET['min_price'];
        $max = isset( $_GET['max_price'] ) ? (int) $_GET['max_price'] : '';
        $label = $max ? '$' . number_format( $min, 0, ',', '.' ) . ' - $' . number_format( $max, 0, ',', '.' ) : 'Más de $' . number_format( $min, 0, ',', '.' );
        $active[] = [ 'label' => $label, 'param' => 'min_price' ];
    }

    // Taxonomy filters
    $taxonomies = [ 'filter_product_brand' => 'product_brand', 'filter_pa_genero' => 'pa_genero', 'filter_pa_serie' => 'pa_serie' ];
    foreach ( $taxonomies as $param => $tax ) {
        if ( ! isset( $_GET[ $param ] ) ) continue;
        $slugs = explode( ',', sanitize_text_field( $_GET[ $param ] ) );
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, $tax );
            if ( $term ) {
                $active[] = [ 'label' => $term->name, 'param' => $param ];
            }
        }
    }

    return $active;
}

// ══════════════════════════════════════════════════════════════════
// 2. FILTER COUNT BADGE — on mobile "Filtros" button
// ══════════════════════════════════════════════════════════════════

add_action( 'wp_footer', 'akibara_filter_count_badge', 30 );

function akibara_filter_count_badge(): void {
    if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) return;

    $count = count( akibara_get_active_filters() );
    if ( $count === 0 ) return;
    ?>
    <script>
    (function(){
        var btn = document.getElementById('filter-toggle');
        if (!btn) return;
        var badge = document.createElement('span');
        badge.className = 'akb-filter-badge';
        badge.textContent = '<?php echo (int) $count; ?>';
        btn.appendChild(badge);
    })();
    </script>
    <?php
}

// ══════════════════════════════════════════════════════════════════
