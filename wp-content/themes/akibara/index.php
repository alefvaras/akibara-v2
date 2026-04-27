<?php
/**
 * Main index fallback
 *
 * @package Akibara
 */

get_header(); ?>

<main class="site-content" id="main-content">
    <div class="container section">
        <?php if (have_posts()) : ?>
            <?php while (have_posts()) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                    <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                    <div><?php the_excerpt(); ?></div>
                </article>
            <?php endwhile; ?>
            <?php the_posts_navigation(); ?>
        <?php else : ?>
            <p><?php esc_html_e('No se encontraron resultados.', 'akibara'); ?></p>
        <?php endif; ?>
    </div>
</main>

<?php get_footer(); ?>
