<?php
/**
 * Homepage — Akibara
 * Optimized: uses transient cache for product queries + shared category data.
 *
 * @package Akibara
 * @version 1.1.0
 */

get_header();

// Reuse shared data from header (no duplicate queries)
$cats = akibara_get_shared_cats();
$manga_cat   = $cats['manga_cat'];
$comics_cat  = $cats['comics_cat'];
$manga_demos = $cats['manga_demos'];
$comics_subs = $cats['comics_subs'];
// home_latest_urgency_badge A/B test sunset 2026-04-19: Variant B ganó (sin badge urgencia
// en home) por razones de branding editorial + tracking de conversión insuficiente para medir.
// Fichas de producto SÍ muestran stock-urgency cuando aplica (stock bajo real).
?>

<main class="site-content" id="main-content">


<?php get_template_part( "template-parts/front-page/hero" ); ?>

<?php get_template_part( "template-parts/front-page/trust-badges" ); ?>

<!-- TAGLINE -->
    <div id="homepage-content"></div>
    <div class="homepage-tagline">
        <?php // Branding canónico 2026-04-25 (owner): "Akibara | Tu Distrito de Cómics y Manga"
              // Guiño Akihabara (秋葉原) — barrio otaku Tokio. Akibara = Akiba(hara) + Chile vibe. ?>
        <h1 class="homepage-h1">
            <span class="homepage-h1__brand">Akibara</span>
            <span class="homepage-h1__separator">|</span>
            <span class="homepage-h1__text">Tu Distrito de Cómics y Manga</span>
        </h1>
        <p class="homepage-h1__sub">El Akihabara chileno · envío a todo Chile</p>
    </div>

    <!-- ULTIMAS LLEGADAS (cached) -->
    <section class="home-products home-products--new aki-reveal">
        <div class="container">
            <div class="section-header">
                <h2 class="section-header__title">Últimas llegadas</h2>
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop')) . '?orderby=date'); ?>" class="section-header__link">Ver todo <?php echo akibara_icon('arrow', 16); ?></a>
            </div>
            <?php
            $latest_ids = akibara_get_homepage_section('latest');
            if ($latest_ids) :
                $latest = new WP_Query([
                    'post_type' => 'product',
                    'post__in' => $latest_ids,
                    'orderby' => 'post__in',
                    'posts_per_page' => count($latest_ids),
                    'no_found_rows' => true,
                ]);
                if ($latest->have_posts()) : ?>
                    <div class="product-grid product-grid--large">
                        <?php
                        set_query_var('akibara_card_context', 'home-latest');
                        while ($latest->have_posts()) : $latest->the_post();
                            get_template_part('template-parts/content/product-card');
                        endwhile;
                        set_query_var('akibara_card_context', '');
                        wp_reset_postdata(); ?>
                    </div>
                <?php endif;
            endif; ?>
        </div>
    </section>

    <?php do_action( "akibara_homepage_after_latest" ); ?>

    <!-- PREVENTAS (cached) -->
    <section class="home-products home-products--preorder aki-reveal aki-halftone">
        <div class="container">
            <div class="section-header">
                <h2 class="section-header__title">Preventas</h2>
                <p class="section-header__desc" style="color:var(--aki-gray-400);font-size:var(--text-sm);margin-top:var(--space-1)">Reserva tu manga antes del lanzamiento — asegura tu ejemplar</p>
                <a href="<?php echo esc_url(home_url("/preventas/")); ?>" class="section-header__link">Ver todo <?php echo akibara_icon('arrow', 16); ?></a>
            </div>
            <?php
            $preorder_ids = akibara_get_homepage_section('preorders');
            if ($preorder_ids) :
                $preorders = new WP_Query([
                    'post_type' => 'product',
                    'post__in' => $preorder_ids,
                    'orderby' => 'post__in',
                    'posts_per_page' => count($preorder_ids),
                    'no_found_rows' => true,
                ]);
                if ($preorders->have_posts()) : ?>
                    <div class="product-grid product-grid--large">
                        <?php
                        set_query_var('akibara_card_context', 'home-preorders');
                        while ($preorders->have_posts()) : $preorders->the_post();
                            get_template_part('template-parts/content/product-card');
                        endwhile;
                        set_query_var('akibara_card_context', '');
                        wp_reset_postdata(); ?>
                    </div>
                <?php endif;
            endif; ?>
        </div>
    </section>

    <!-- MAS VENDIDOS (cached) -->
    <section class="home-products home-products--best aki-reveal">
        <div class="container">
            <div class="section-header">
                <h2 class="section-header__title">Mas Vendidos</h2>
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop')) . '?orderby=popularity'); ?>" class="section-header__link">Ver todo <?php echo akibara_icon('arrow', 16); ?></a>
            </div>
            <?php
            $best_ids = akibara_get_homepage_section('bestsellers');
            if ($best_ids) :
                $bestsellers = new WP_Query([
                    'post_type' => 'product',
                    'post__in' => $best_ids,
                    'orderby' => 'post__in',
                    'posts_per_page' => count($best_ids),
                    'no_found_rows' => true,
                ]);
                if ($bestsellers->have_posts()) : ?>
                    <div class="product-grid product-grid--large">
                        <?php
                        set_query_var('akibara_card_context', 'home-bestsellers');
                        while ($bestsellers->have_posts()) : $bestsellers->the_post();
                            get_template_part('template-parts/content/product-card');
                        endwhile;
                        set_query_var('akibara_card_context', '');
                        wp_reset_postdata(); ?>
                    </div>
                <?php endif;
            endif; ?>
        </div>
    </section>

    <?php do_action( 'akibara_homepage_after_bestsellers' ); ?>

    <?php
    $brand_items = akibara_get_homepage_editorial_brands();
    ?>

    <!-- EDITORIALES GRID (early-funnel trust) -->
    <?php if ($brand_items) :
        $country_name_map = [ 'ES' => 'España', 'AR' => 'Argentina' ];

        // Cap a 8 cells: 7 logos + tile "Ver todas" si hay >8 marcas.
        $total_brands  = count($brand_items);
        $show_more     = $total_brands > 8;
        $visible_items = $show_more ? array_slice($brand_items, 0, 7) : $brand_items;
        $more_url      = home_url('/editoriales/');
    ?>
    <section class="home-editoriales aki-reveal">
        <div class="container">
            <div class="section-header">
                <div class="section-header__group">
                    <h2 class="section-header__title">De dónde viene tu manga</h2>
                    <p class="section-header__desc">Importamos ediciones oficiales en español desde España y Argentina</p>
                </div>
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop')) . '?orderby=popularity'); ?>" class="section-header__link">Ver catálogo <?php echo akibara_icon('arrow', 16); ?></a>
            </div>
            <ul class="editoriales-grid" role="list">
                <?php foreach ($visible_items as $bi) :
                    $country = $bi['country'] ?? '';
                    ?>
                <li role="listitem" class="editoriales-grid__cell">
                    <a href="<?php echo esc_url($bi['url']); ?>" class="editoriales-grid__item" data-brand="<?php echo esc_attr($bi['slug']); ?>" aria-label="Ver catálogo de <?php echo esc_attr($bi['name']); ?> (<?php echo (int) $bi['count']; ?> títulos)">
                        <?php if ($country) : ?>
                        <span class="editoriales-grid__country" aria-label="<?php echo esc_attr($country_name_map[$country] ?? $country); ?>"><?php echo esc_html($country); ?></span>
                        <?php endif; ?>
                        <span class="editoriales-grid__logo">
                            <img src="<?php echo esc_url($bi['img']); ?>" alt="<?php echo esc_attr($bi['name']); ?>" width="200" height="100" loading="lazy" decoding="async">
                        </span>
                        <span class="editoriales-grid__label"><?php echo esc_html($bi['name']); ?></span>
                        <span class="editoriales-grid__count"><?php echo (int) $bi['count']; ?> títulos</span>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if ($show_more) : ?>
                <li role="listitem" class="editoriales-grid__cell editoriales-grid__cell--more">
                    <a href="<?php echo esc_url($more_url); ?>" class="editoriales-grid__item" aria-label="Ver todas las editoriales (<?php echo (int) $total_brands; ?>)">
                        <span class="editoriales-grid__logo editoriales-grid__logo--more">
                            <span class="editoriales-grid__more-count">+<?php echo (int) ($total_brands - 7); ?></span>
                        </span>
                        <span class="editoriales-grid__label">Ver todas →</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

</main>

<?php get_footer(); ?>
