<?php
/**
 * Search Results Template — Akibara
 *
 * @package Akibara
 */

get_header(); ?>

<main class="site-content" id="main-content">
    <div class="page-hero">
        <div class="page-hero__inner">
            <nav class="page-hero__breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Inicio</a>
                <span>/</span>
                <span>Búsqueda</span>
            </nav>
            <h1 class="page-hero__title">Resultados para: "<?php echo esc_html(get_search_query()); ?>"</h1>
        </div>
    </div>

    <div class="container section--compact">
        <?php global $wp_query; if (have_posts()) : ?>
            <p class="search-meta"><?php printf(esc_html__('Se encontraron %d resultados', 'akibara'), $wp_query->found_posts); ?></p>
            <div class="product-grid product-grid--large">
                <?php while (have_posts()) : the_post();
                    if (get_post_type() === 'product') :
                        global $product;
                        $product = wc_get_product(get_the_ID());
                        if ($product) :
                            get_template_part('template-parts/content/product-card');
                        endif;
                    endif;
                endwhile; ?>
            </div>
            <?php the_posts_navigation(); ?>
        <?php else : ?>
            <div class="search-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <h2>Sin resultados</h2>
                <p>No encontramos productos que coincidan con tu búsqueda. Intenta con otros términos.</p>
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="btn btn--primary"><span>Ver Catálogo</span></a>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php get_footer(); ?>
