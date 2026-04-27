<?php
get_header();
while (have_posts()) : the_post();
    $post_id        = get_the_ID();
    $related_by_cat = function_exists('akibara_blog_related_posts') ? akibara_blog_related_posts($post_id, 4) : [];
    $featured_id    = get_post_thumbnail_id($post_id);
    $featured_alt   = function_exists('akibara_blog_image_alt') ? akibara_blog_image_alt($featured_id, get_the_title()) : get_the_title();
?>
<main class="site-content" id="main-content">
    <article class="blog-post" itemscope itemtype="https://schema.org/BlogPosting">
        <div class="page-hero aki-reveal">
            <div class="page-hero__inner">
                <nav class="page-hero__breadcrumb" aria-label="Breadcrumb">
                    <a href="<?php echo esc_url(home_url("/")); ?>">Inicio</a>
                    <span>/</span>
                    <a href="<?php echo esc_url(home_url("/blog/")); ?>">Blog</a>
                    <span>/</span>
                    <span><?php the_title(); ?></span>
                </nav>
                <div class="page-hero__meta">
                    <time datetime="<?php echo get_the_date("c"); ?>" itemprop="datePublished"><?php echo get_the_date(); ?></time>
                    <span class="page-hero__reading-time">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php echo akibara_reading_time(); ?>
                    </span>
                    <?php
                    $cats = get_the_category();
                    $primary_cat = null;
                    if ($cats) {
                        foreach ($cats as $c) { if ((int) $c->term_id !== 1) { $primary_cat = $c; break; } }
                        $primary_cat = $primary_cat ?: $cats[0];
                    }
                    if ($primary_cat) :
                        ?>
                        <a class="page-hero__cat" href="<?php echo esc_url(get_category_link($primary_cat)); ?>" rel="category tag"><?php echo esc_html($primary_cat->name); ?></a>
                    <?php endif; ?>
                </div>
                <h1 class="page-hero__title" itemprop="headline"><?php the_title(); ?></h1>
            </div>
        </div>

        <div class="page-body aki-reveal">
            <?php if ($featured_id) : ?>
                <figure class="page-body__featured" itemprop="image" itemscope itemtype="https://schema.org/ImageObject">
                    <?php
                    echo wp_get_attachment_image(
                        $featured_id,
                        'blog-featured',
                        false,
                        [
                            'class'         => 'page-body__featured-img',
                            'loading'       => 'eager',
                            'fetchpriority' => 'high',
                            'decoding'      => 'async',
                            'sizes'         => '(min-width: 900px) 860px, 100vw',
                            'alt'           => $featured_alt,
                            'itemprop'      => 'contentUrl',
                        ]
                    );
                    $featured_caption = wp_get_attachment_caption($featured_id);
                    if ($featured_caption) : ?>
                        <figcaption class="page-body__featured-caption"><?php echo esc_html($featured_caption); ?></figcaption>
                    <?php endif; ?>
                </figure>
            <?php endif; ?>

            <div class="page-body__content" itemprop="articleBody">
                <?php the_content(); ?>
            </div>

            <?php akibara_share_buttons(); ?>

            <?php $tags = get_the_tags(); if ($tags) : ?>
                <div class="page-body__tags" aria-label="Etiquetas">
                    <?php foreach ($tags as $tag) : ?>
                        <a href="<?php echo esc_url(get_tag_link($tag)); ?>" class="tag" rel="tag"><?php echo esc_html($tag->name); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($related_by_cat) : ?>
            <aside class="page-body__related" aria-label="Articulos relacionados">
                <h2>Articulos que te pueden interesar</h2>
                <div class="page-body__related-grid">
                    <?php foreach ($related_by_cat as $rp) :
                        $rp_thumb_id = get_post_thumbnail_id($rp->ID);
                        $rp_alt      = function_exists('akibara_blog_image_alt') ? akibara_blog_image_alt($rp_thumb_id, $rp->post_title) : $rp->post_title;
                    ?>
                        <a href="<?php echo esc_url(get_permalink($rp)); ?>" class="related-card">
                            <?php if ($rp_thumb_id) : ?>
                                <?php echo wp_get_attachment_image(
                                    $rp_thumb_id,
                                    'blog-related',
                                    false,
                                    [
                                        'class'    => 'related-card__img',
                                        'loading'  => 'lazy',
                                        'decoding' => 'async',
                                        'sizes'    => '(min-width: 900px) 220px, 45vw',
                                        'alt'      => $rp_alt,
                                    ]
                                ); ?>
                            <?php endif; ?>
                            <h3 class="related-card__title"><?php echo esc_html($rp->post_title); ?></h3>
                            <div class="related-card__meta">
                                <time datetime="<?php echo esc_attr(get_the_date('c', $rp)); ?>"><?php echo get_the_date('', $rp); ?></time>
                                <span><?php echo esc_html(akibara_reading_time($rp->ID)); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>
            <?php endif; ?>
        </div>
    </article>
</main>
<?php endwhile; ?>
<?php get_footer(); ?>
