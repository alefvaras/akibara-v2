<?php
get_header();
$current_cat = isset($_GET["cat"]) ? absint($_GET["cat"]) : 0;
?>
<main class="site-content" id="main-content">
    <div class="page-hero">
        <div class="page-hero__inner">
            <nav class="page-hero__breadcrumb">
                <a href="<?php echo esc_url(home_url("/")); ?>">Inicio</a>
                <span>/</span>
                <span>Blog</span>
            </nav>
            <h1 class="page-hero__title">Blog</h1>
            <p class="page-hero__desc">Guías, reseñas y novedades del mundo manga y cómics</p>
        </div>
    </div>

    <div class="blog-grid-wrap">
        <?php
        $blog_cats = get_terms(["taxonomy" => "category", "hide_empty" => true, "exclude" => [1]]);
        if ($blog_cats && !is_wp_error($blog_cats) && count($blog_cats) > 1) : ?>
        <div class="blog-cat-pills">
            <a href="<?php echo esc_url(home_url("/blog")); ?>"
               class="blog-cat-pill<?php echo !$current_cat ? ' blog-cat-pill--active' : ''; ?>">
                Todos
            </a>
            <?php foreach ($blog_cats as $bc) : ?>
            <a href="<?php echo esc_url(add_query_arg("cat", $bc->term_id, home_url("/blog"))); ?>"
               class="blog-cat-pill<?php echo $current_cat === $bc->term_id ? ' blog-cat-pill--active' : ''; ?>">
                <?php echo esc_html($bc->name); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="blog-layout">
            <div class="blog-main">
                <?php
                if ($current_cat) {
                    $custom_query = new WP_Query(["cat" => $current_cat, "posts_per_page" => 12, "no_found_rows" => true]);
                } else {
                    $custom_query = null;
                }
                $use_query = $custom_query ?: $GLOBALS["wp_query"];
                ?>

                <?php if ($use_query->have_posts()) : ?>
                    <div class="blog-grid aki-reveal">
                        <?php
                        $blog_card_index = 0;
                        while ($use_query->have_posts()) : $use_query->the_post();
                            $card_thumb_id = get_post_thumbnail_id();
                            $card_alt      = function_exists('akibara_blog_image_alt') ? akibara_blog_image_alt($card_thumb_id, get_the_title()) : get_the_title();
                            // Eager + high priority for the first 2 cards to win LCP on archive.
                            $card_loading  = $blog_card_index < 2 ? 'eager' : 'lazy';
                            $card_priority = $blog_card_index === 0 ? 'high' : 'auto';
                        ?>
                            <article class="blog-card">
                                <?php if ($card_thumb_id) : ?>
                                    <a href="<?php the_permalink(); ?>" class="blog-card__image" aria-label="<?php echo esc_attr(get_the_title()); ?>">
                                        <?php echo wp_get_attachment_image(
                                            $card_thumb_id,
                                            'blog-card',
                                            false,
                                            [
                                                'loading'       => $card_loading,
                                                'fetchpriority' => $card_priority,
                                                'decoding'      => 'async',
                                                'sizes'         => '(min-width: 900px) 380px, (min-width: 600px) 45vw, 100vw',
                                                'alt'           => $card_alt,
                                            ]
                                        ); ?>
                                    </a>
                                <?php endif; ?>
                                <div class="blog-card__body">
                                    <div class="blog-card__meta">
                                        <time datetime="<?php echo get_the_date("c"); ?>"><?php echo get_the_date(); ?></time>
                                        <span class="blog-card__reading-time">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                            <?php echo akibara_reading_time(); ?>
                                        </span>
                                        <?php
                                        $cats = get_the_category();
                                        if ($cats) {
                                            foreach ($cats as $cat) {
                                                if ($cat->term_id !== 1) {
                                                    echo "<span>" . esc_html($cat->name) . "</span>";
                                                    break;
                                                }
                                            }
                                        }
                                        ?>
                                    </div>
                                    <h2 class="blog-card__title">
                                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                                    </h2>
                                    <p class="blog-card__excerpt"><?php echo wp_trim_words(get_the_excerpt(), 20); ?></p>
                                    <a href="<?php the_permalink(); ?>" class="blog-card__link">
                                        Leer más <?php echo akibara_icon("arrow", 14); ?>
                                    </a>
                                </div>
                            </article>
                        <?php $blog_card_index++; endwhile; ?>
                    </div>
                    <?php if (!$custom_query) the_posts_navigation(["prev_text" => "Más antiguas", "next_text" => "Más recientes"]); ?>
                    <?php if ($custom_query) wp_reset_postdata(); ?>
                <?php else : ?>
                    <p style="color: var(--aki-gray-400); text-align: center; padding: var(--space-12) 0;">No hay entradas en esta categoría.</p>
                <?php endif; ?>
            </div>

            <aside class="blog-sidebar">
                <div class="blog-sidebar__section">
                    <h3 class="blog-sidebar__title">Categorías</h3>
                    <ul class="blog-sidebar__cats">
                        <?php
                        $sidebar_cats = get_categories(['hide_empty' => true, 'exclude' => [1]]);
                        foreach ($sidebar_cats as $sc) : ?>
                            <li>
                                <a href="<?php echo esc_url(add_query_arg("cat", $sc->term_id, home_url("/blog"))); ?>">
                                    <?php echo esc_html($sc->name); ?>
                                    <span class="blog-sidebar__count"><?php echo $sc->count; ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="blog-sidebar__section">
                    <h3 class="blog-sidebar__title">Posts Recientes</h3>
                    <?php
                    $recent = get_posts(['numberposts' => 5, 'post_type' => 'post']);
                    foreach ($recent as $rp) : ?>
                        <a href="<?php echo get_permalink($rp); ?>" class="blog-sidebar__recent">
                            <span class="blog-sidebar__recent-title"><?php echo esc_html($rp->post_title); ?></span>
                            <span class="blog-sidebar__recent-date"><?php echo get_the_date('d/m/Y', $rp); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="blog-sidebar__section blog-sidebar__cta">
                    <p>Encuentra tu próximo manga favorito</p>
                    <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="btn btn--primary">Explorar catálogo</a>
                </div>
            </aside>
        </div>
    </div>
</main>
<?php get_footer(); ?>
