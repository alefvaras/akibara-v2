<?php
/**
 * Single Product — Related Products partial
 *
 * Dos grids:
 *  1. Smart recommendations (akibara_get_smart_recommendations, 6 items)
 *  2. Genre-based popular (akibara_get_genre_popular, 4 items)
 *
 * Nota crítica: después del loop, restaura $product global al producto
 * principal para que los partials siguientes (tabs hooks, etc.) funcionen.
 *
 * Inherited from single-product.php: $product.
 *
 * @package Akibara
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

global $product;
$product_id = $product->get_id();

// Smart recommendations
$related_ids = function_exists( 'akibara_get_smart_recommendations' )
    ? akibara_get_smart_recommendations( $product_id, 6 )
    : [];

if ( $related_ids ) :
    $related_products = new WP_Query( [
        'post_type'      => 'product',
        'post__in'       => $related_ids,
        'orderby'        => 'post__in',
        'posts_per_page' => count( $related_ids ),
        'no_found_rows'  => true,
    ] );

    if ( $related_products->have_posts() ) : ?>
        <div class="related-products">
            <div class="section-header">
                <h2 class="section-header__title">También te puede gustar</h2>
            </div>
            <div class="product-grid product-grid--large">
                <?php while ( $related_products->have_posts() ) :
                    $related_products->the_post();
                    global $product;
                    get_template_part( 'template-parts/content/product-card' );
                endwhile;
                wp_reset_postdata(); ?>
            </div>
        </div>
    <?php endif;
endif;

// Genre-based popular section
if ( function_exists( 'akibara_get_primary_genre_name' ) && function_exists( 'akibara_get_genre_popular' ) ) :
    $genre_name    = akibara_get_primary_genre_name( $product_id );
    $genre_exclude = array_merge( [ $product_id ], $related_ids ?: [] );
    $genre_ids     = akibara_get_genre_popular( $product_id, $genre_exclude, 4 );

    if ( $genre_name && count( $genre_ids ) >= 4 ) :
        $genre_products = new WP_Query( [
            'post_type'      => 'product',
            'post__in'       => $genre_ids,
            'orderby'        => 'post__in',
            'posts_per_page' => count( $genre_ids ),
            'no_found_rows'  => true,
        ] );
        if ( $genre_products->have_posts() ) : ?>
            <div class="related-products related-products--genre">
                <div class="section-header">
                    <h2 class="section-header__title">Otros <?php echo esc_html( $genre_name ); ?> populares</h2>
                </div>
                <div class="product-grid product-grid--large">
                    <?php while ( $genre_products->have_posts() ) :
                        $genre_products->the_post();
                        global $product;
                        get_template_part( 'template-parts/content/product-card' );
                    endwhile;
                    wp_reset_postdata(); ?>
                </div>
            </div>
        <?php endif;
    endif;
endif;

// Restore main product after related products loop
$product = wc_get_product( $product_id );
global $post;
$post = get_post( $product_id );
setup_postdata( $post );
