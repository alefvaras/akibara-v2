<?php
/**
 * Empty cart page — with product suggestions + tracking link
 *
 * @package Akibara
 * @version 7.0.2
 */

defined('ABSPATH') || exit;

do_action('woocommerce_cart_is_empty');

?>

<div class="empty-cart">
    <div class="empty-cart__icon">
        <?php echo akibara_icon('cart', 48); ?>
    </div>
    <h2 class="empty-cart__title">Tu carrito está vacío</h2>
    <p class="empty-cart__desc">Explora nuestro catálogo y encuentra tu próximo manga favorito.</p>
    <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="btn btn--primary">
        <span>Explorar Catálogo</span>
    </a>
</div>

<!-- Suggestions: Best sellers -->
<?php
$suggestions = new WP_Query([
    'post_type'      => 'product',
    'posts_per_page' => 6,
    'post_status'    => 'publish',
    'meta_key'       => 'total_sales',
    'orderby'        => 'meta_value_num',
    'order'          => 'DESC',
    'no_found_rows'  => true,
    'tax_query'      => [['taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => 'exclude-from-catalog', 'operator' => 'NOT IN']],
]);

if ($suggestions->have_posts()) : ?>
    <div class="empty-cart__suggestions">
        <div class="section-header">
            <h2 class="section-header__title">Te puede interesar</h2>
        </div>
        <div class="product-grid product-grid--large">
            <?php while ($suggestions->have_posts()) : $suggestions->the_post();
                get_template_part('template-parts/content/product-card');
            endwhile; wp_reset_postdata(); ?>
        </div>
    </div>
<?php endif; ?>
