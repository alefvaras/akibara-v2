<?php
/**
 * Product Archive / Shop Page
 *
 * @package Akibara
 */

defined('ABSPATH') || exit;

get_header();

$current_cat = get_queried_object();
$is_category = is_product_category();
$is_brand    = is_tax("product_brand");
$manga_cat = get_term_by('slug', 'manga', 'product_cat');
$comics_cat = get_term_by('slug', 'comics', 'product_cat');
?>

<main class="site-content" id="main-content">
    <!-- Page Header -->
    <div class="page-header aki-reveal">
        <div class="container">
            <nav class="page-header__breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Inicio</a>
                <span class="separator">/</span>
                <?php if ($is_category) : ?>
                    <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>">Catálogo</a>
                    <span class="separator">/</span>
                    <span><?php echo esc_html($current_cat->name); ?></span>
                <?php elseif ($is_brand) : ?>
                    <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>">Catálogo</a>
                    <span class="separator">/</span>
                    <span>Editoriales</span>
                    <span class="separator">/</span>
                    <span><?php echo esc_html($current_cat->name); ?></span>
                <?php else : ?>
                    <span>Catálogo</span>
                <?php endif; ?>
            </nav>
            <h1 class="page-header__title aki-manga-title">
                <?php echo ($is_category || $is_brand) ? esc_html($current_cat->name) : 'Catálogo'; ?>
            </h1>
            <?php
            // Capturar intro copy SEO — se renderiza debajo del grid (no above-the-fold).
            $intro_context    = $is_category ? 'category' : ( $is_brand ? 'brand' : 'shop' );
            $intro_term       = ( $is_category || $is_brand ) && $current_cat instanceof WP_Term ? $current_cat : null;
            $category_intro_html = function_exists( 'akibara_get_category_intro_html' )
                ? akibara_get_category_intro_html( $intro_term, $intro_context )
                : '';
            ?>
        </div>
    </div>

    <!-- Category pills (top-level) -->
    <?php
    // Preserve search query across pill navigation (flujo search → categoría)
    $search_q = is_search() ? trim((string) get_search_query(false)) : '';
    $pill_extra_args = $search_q !== '' ? ['s' => $search_q, 'post_type' => 'product'] : [];
    $all_href = get_permalink(wc_get_page_id('shop'));
    if ($search_q !== '') {
        $all_href = add_query_arg(['s' => $search_q, 'post_type' => 'product'], home_url('/'));
    }
    ?>
    <?php
    $top_cats = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => true,
        'parent'     => 0,
        'orderby'    => 'count',
        'order'      => 'DESC',
        'exclude'    => [get_option('default_product_cat')],
    ]);
    $total_cat_products = 0;
    if ($top_cats && !is_wp_error($top_cats)) {
        foreach ($top_cats as $c) { $total_cat_products += (int) $c->count; }
    }
    $cat_pills_total = (is_array($top_cats) ? count($top_cats) : 0) + 1; // +1 "Todos"
    ?>
    <div class="container shop-pills-container" data-pills-total="<?php echo (int) $cat_pills_total; ?>">
        <div class="category-pills" role="tablist" aria-label="Categorías de productos">
            <a href="<?php echo esc_url($all_href); ?>"
               role="tab"
               aria-selected="<?php echo (!$is_category && !$is_brand) ? 'true' : 'false'; ?>"
               class="category-pill <?php echo (!$is_category && !$is_brand) ? 'category-pill--active' : ''; ?>">
                <span class="category-pill__name">Todos</span>
                <span class="category-pill__count"><?php echo number_format_i18n((int) $total_cat_products); ?></span>
            </a>
            <?php
            if ($top_cats && !is_wp_error($top_cats)) :
                foreach ($top_cats as $cat) :
                    $active = ($is_category && $current_cat->term_id === $cat->term_id) ? 'category-pill--active' : '';
                    $cat_href = get_term_link($cat);
                    if ($search_q !== '' && !is_wp_error($cat_href)) {
                        $cat_href = add_query_arg($pill_extra_args, $cat_href);
                    }
                    ?>
                    <a href="<?php echo esc_url($cat_href); ?>"
                       role="tab"
                       aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
                       class="category-pill <?php echo $active; ?>">
                        <span class="category-pill__name"><?php echo esc_html($cat->name); ?></span>
                        <span class="category-pill__count"><?php echo number_format_i18n((int) $cat->count); ?></span>
                    </a>
                <?php endforeach;
            endif; ?>
        </div>
        <?php if ($cat_pills_total > 4) : ?>
        <?php // A11Y FIX 2026-04-25 (WCAG 4.1.2): JS inyecta <button> reales con aria-label
              // dentro de este contenedor. aria-hidden=true ocultaba esos botones a SR
              // mientras seguían siendo focusables (axe aria-hidden-focus). Quitado. ?>
        <div class="category-pills-indicator" role="tablist" aria-label="Navegar categorías"></div>
        <?php endif; ?>
    </div>

    <!-- Shop layout -->
    <div class="shop-layout">
        <!-- Contextual Sidebar Filters -->
        <aside class="shop-sidebar" id="shop-sidebar" role="region" aria-label="Filtros de productos" tabindex="0">
            <button class="sidebar-close sidebar-close--hidden" id="sidebar-close" aria-label="Cerrar filtros">← Cerrar</button>
            <?php $ctx_name = (is_product_category() || is_tax("product_brand")) ? single_term_title("", false) : (is_search() ? "Búsqueda" : "Catálogo"); ?>
            <div class="sidebar-context"><?php echo esc_html(strtoupper($ctx_name)); ?></div>
            <?php akibara_render_filters(); ?>

            <?php
            // UX-DRAWER-REDESIGN A1 — Sticky CTA mobile (Baymard 2024 +18% filter-apply rate).
            // Total inicial: leer found_posts del query principal antes que JS lo actualice via AJAX.
            $akb_initial_total = isset($wp_query) && $wp_query instanceof WP_Query ? (int) $wp_query->found_posts : 0;
            if ( $akb_initial_total === 0 && function_exists( 'wc_get_loop_prop' ) ) {
                $akb_initial_total = (int) wc_get_loop_prop( 'total', 0 );
            }
            ?>
            <?php // A11Y FIX 2026-04-25 (WCAG 4.1.2): el CTA sticky vive en el sidebar drawer
                  // (visible sólo cuando el drawer está abierto en mobile). Usamos `inert` para
                  // sacarlo del flow cuando el drawer está cerrado, en lugar de aria-hidden=true
                  // (que con focusables internos disparaba aria-hidden-focus). El JS
                  // filters-ajax sync `inert` con el estado open/close del sidebar. ?>
            <div class="drawer-cta-sticky" inert>
                <button type="button" class="drawer-cta-sticky__clear" id="drawer-clear-filters" data-filter-clear hidden>
                    Limpiar <span class="drawer-cta-sticky__clear-count" aria-hidden="true"></span>
                </button>
                <a href="#main-content" class="drawer-cta-sticky__apply" id="drawer-apply-cta">
                    Ver <span class="drawer-cta-sticky__apply-count" aria-live="polite" aria-atomic="true"><?php echo number_format_i18n( $akb_initial_total ); ?></span> productos
                </a>
            </div>
        </aside>

        <!-- Products -->
        <div class="shop-content">
            <!-- Controls -->
            <div class="shop-controls">
                <div class="shop-controls__row">
                    <button class="filter-toggle" id="filter-toggle">
                        <?php echo akibara_icon('filter', 16); ?> Filtros
                    </button>
                    <?php
                    $stock_active = isset($_GET['stock']);
                    $stock_url = $stock_active
                        ? remove_query_arg('stock')
                        : add_query_arg('stock', 'instock');
                    ?>
                    <a href="<?php echo esc_url($stock_url); ?>" class="stock-toggle <?php echo $stock_active ? 'stock-toggle--active' : ''; ?>">
                        <span class="stock-toggle__dot"></span>
                        Solo en stock
                    </a>
                    <span class="woocommerce-result-count" aria-live="polite" aria-atomic="true">
                        <?php woocommerce_result_count(); ?>
                    </span>
                </div>
                <?php woocommerce_catalog_ordering(); ?>
            </div>

            <?php if (woocommerce_product_loop()) : ?>

                <?php if (function_exists("akibara_render_active_filters")) akibara_render_active_filters(); ?>
                <?php woocommerce_product_loop_start(); ?>
                    <?php
                    set_query_var('akibara_card_context', 'catalog');
                    while (have_posts()) : the_post();
                        global $product;
                        $product = wc_get_product(get_the_ID());
                        get_template_part("template-parts/content/product-card");
                    endwhile;
                    set_query_var('akibara_card_context', ''); 
                    wp_reset_postdata();
                    ?>
                <?php woocommerce_product_loop_end(); ?>
                <?php
                // Standard pagination (24 products per page)
                global $wp_query;
                $total_pages = (int) $wp_query->max_num_pages;
                if ($total_pages > 1) :
                ?>
                <nav class="aki-pagination">
                    <?php
                    $pagination_query_args = array_diff_key(
                        wp_unslash($_GET),
                        array_flip(['paged', 'page'])
                    );
                    echo paginate_links([
                        'base'      => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
                        'format'    => '?paged=%#%',
                        'current'   => max(1, get_query_var('paged')),
                        'total'     => $total_pages,
                        'prev_text' => '&laquo; Anterior',
                        'next_text' => 'Siguiente &raquo;',
                        'mid_size'  => 2,
                        'end_size'  => 1,
                        'type'      => 'list',
                        'add_args'  => $pagination_query_args,
                    ]);
                    ?>
                </nav>
                <?php endif; ?>
            <?php else : ?>
                <div class="shop-empty">
                    <?php if ($is_category && $current_cat instanceof WP_Term) : ?>
                        <h3>No encontramos <?php echo esc_html($current_cat->name); ?> con esos filtros</h3>
                        <p class="shop-empty__desc">Prueba ajustar los filtros o explora otras secciones.</p>
                    <?php elseif (is_search()) : ?>
                        <h3>Sin resultados para &ldquo;<?php echo esc_html(get_search_query()); ?>&rdquo;</h3>
                        <p class="shop-empty__desc">Revisa el nombre o explora el catálogo completo.</p>
                    <?php else : ?>
                        <h3>No encontramos productos con esos filtros</h3>
                        <p class="shop-empty__desc">Prueba quitar algún filtro o explora el catálogo completo.</p>
                    <?php endif; ?>
                    <div class="shop-empty__actions">
                        <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="btn btn--primary">Ver catálogo completo</a>
                        <?php if ($manga_cat && !($is_category && $current_cat instanceof WP_Term && $current_cat->slug === 'manga')) : ?>
                            <a href="<?php echo esc_url(get_term_link($manga_cat)); ?>" class="btn btn--outline">Manga</a>
                        <?php endif; ?>
                        <?php if ($comics_cat && !($is_category && $current_cat instanceof WP_Term && $current_cat->slug === 'comics')) : ?>
                            <a href="<?php echo esc_url(get_term_link($comics_cat)); ?>" class="btn btn--outline">Cómics</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( $category_intro_html ) : ?>
    <div class="container category-intro-container">
        <div class="category-intro-wrap is-collapsed">
            <?php echo $category_intro_html; ?>
            <button type="button" class="category-intro__toggle" aria-expanded="false">
                <span class="category-intro__toggle-label">Leer más</span>
                <span class="category-intro__toggle-icon" aria-hidden="true">&#9660;</span>
            </button>
        </div>
    </div>
    <?php endif; ?>
</main>

<script>
// C2 — Scroll horizontal al pill activo sin mover el viewport vertical.
// scrollIntoView hace scroll del viewport; aquí ajustamos sólo scrollLeft del contenedor
// de pills, así evitamos saltos de la página (en mobile es perceptible).
(function () {
    var pills = document.querySelector('.category-pills');
    var activePill = pills ? pills.querySelector('.category-pill--active') : null;
    if (!pills || !activePill) return;
    var pillsRect  = pills.getBoundingClientRect();
    var activeRect = activePill.getBoundingClientRect();
    var targetLeft = (activeRect.left - pillsRect.left)
                   + pills.scrollLeft
                   - (pills.clientWidth - activePill.offsetWidth) / 2;
    pills.scrollLeft = Math.max(0, targetLeft);
})();

// C4 — Indicador bidireccional de overflow (.pills-at-start / .pills-at-end).
// Ambas clases permiten al CSS decidir qué chevron y qué fade mostrar.
(function () {
    var pills = document.querySelector('.category-pills');
    var container = document.querySelector('.shop-pills-container');
    if (!pills || !container) return;
    function check() {
        var atStart = pills.scrollLeft <= 4;
        var atEnd   = pills.scrollLeft + pills.clientWidth >= pills.scrollWidth - 4;
        container.classList.toggle('pills-at-start', atStart);
        container.classList.toggle('pills-at-end', atEnd);
    }
    check();
    pills.addEventListener('scroll', check, { passive: true });
    window.addEventListener('resize', check, { passive: true });
})();

// UX-PILLS-REDESIGN Opción C — dot indicator dinámico:
// Un dot por pill, click scroll-snap al pill correspondiente, active sigue scroll position.
(function () {
    var container = document.querySelector('.shop-pills-container');
    var pills     = container ? container.querySelector('.category-pills') : null;
    var indicator = container ? container.querySelector('.category-pills-indicator') : null;
    if (!pills || !indicator) return;
    var pillEls = pills.querySelectorAll('.category-pill');
    if (!pillEls.length) return;
    // Solo mostrar dots si hay overflow horizontal real (evita UI innecesaria en desktop).
    function needsDots() { return pills.scrollWidth > pills.clientWidth + 4; }
    // Build dots
    var dots = [];
    pillEls.forEach(function (pill, i) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'category-pills-indicator__dot';
        btn.setAttribute('aria-label', 'Ir a categoría ' + (i + 1) + ' de ' + pillEls.length);
        btn.addEventListener('click', function () {
            pill.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
        });
        indicator.appendChild(btn);
        dots.push(btn);
    });
    // Track active dot based on scroll position
    function updateActive() {
        if (!needsDots()) { indicator.style.display = 'none'; return; }
        indicator.style.display = '';
        var scrollLeft = pills.scrollLeft;
        var closestIdx = 0;
        var closestDist = Infinity;
        pillEls.forEach(function (pill, i) {
            var d = Math.abs(pill.offsetLeft - scrollLeft);
            if (d < closestDist) { closestDist = d; closestIdx = i; }
        });
        dots.forEach(function (d, i) {
            d.classList.toggle('category-pills-indicator__dot--active', i === closestIdx);
        });
    }
    updateActive();
    pills.addEventListener('scroll', updateActive, { passive: true });
    window.addEventListener('resize', updateActive, { passive: true });
})();

// A — Collapse / expand del intro copy SEO
(function () {
    var wrap = document.querySelector('.category-intro-wrap');
    if (!wrap) return;
    var btn = wrap.querySelector('.category-intro__toggle');
    if (!btn) return;
    var intro = wrap.querySelector('.category-intro');
    var label = btn.querySelector('.category-intro__toggle-label');
    var icon  = btn.querySelector('.category-intro__toggle-icon');
    // Si el contenido no desborda (texto corto), ocultar botón y no colapsar
    if (intro && intro.scrollHeight <= intro.clientHeight + 2) {
        btn.hidden = true;
        wrap.classList.remove('is-collapsed');
        return;
    }
    btn.addEventListener('click', function () {
        var collapsed = wrap.classList.toggle('is-collapsed');
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (label) label.textContent = collapsed ? 'Leer más' : 'Ver menos';
        if (icon)  icon.textContent  = collapsed ? '\u25BC' : '\u25B2';
    });
})();
</script>

<?php get_footer(); ?>
