<?php
/**
 * Single Product Page — Orquestador.
 *
 * Divide el template en partials independientes bajo template-parts/single-product/:
 *  - gallery.php         → imagen principal + thumbnails
 *  - info.php            → bloque completo de info (título, precio, ATC, trust, share)
 *  - ficha-tecnica.php   → specs del manga/cómic
 *  - tabs.php            → tabs WC (description, additional, reviews)
 *  - related.php         → smart recommendations + genre-based popular
 *  - lightbox.php        → overlay para zoom de imagen
 *  - sticky-atc.php      → sticky add-to-cart mobile
 *
 * Refactor 2026-04-19: split de archivo monolítico de 606 líneas.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) : the_post();
    global $product;
    $product_id = $product->get_id();
?>

<main class="site-content" id="main-content">
    <?php woocommerce_breadcrumb(); ?>

    <?php
    /**
     * Hook: woocommerce_before_single_product.
     * Required for plugins that hook before the product display.
     */
    do_action( 'woocommerce_before_single_product' );
    ?>

    <!-- Product Layout -->
    <div class="single-product-layout">
        <?php get_template_part( 'template-parts/single-product/gallery' ); ?>

        <?php get_template_part( 'template-parts/single-product/info' ); ?>
    </div>

    <?php
    // Series Hub Injection (deferred/AJAX)
    if ( function_exists( 'akibara_render_series_hub' ) ) {
        akibara_render_series_hub( $product_id );
    }
    ?>


    <!-- ═══ PACK SERIE CTA ═══ -->
    <?php
    if ( function_exists( 'akibara_render_pack_cta' ) ) {
        akibara_render_pack_cta( $product_id );
    }
    ?>

    <!-- ═══ BLOG GUIDE CTA (reverse interlink) ═══ -->
    <?php
    if ( function_exists( 'akibara_render_blog_cta_for_product' ) ) {
        akibara_render_blog_cta_for_product( $product_id );
    }
    ?>
    <?php get_template_part( 'template-parts/single-product/ficha-tecnica' ); ?>

    <?php get_template_part( 'template-parts/single-product/tabs' ); ?>

    <?php
    /**
     * Hook: woocommerce_after_single_product_summary.
     */
    // do_action( 'woocommerce_after_single_product_summary' ); // Disabled: template renders tabs + related manually
    ?>

    <?php get_template_part( 'template-parts/single-product/related' ); ?>

    <?php
    /**
     * Hook: woocommerce_after_single_product.
     */
    do_action( 'woocommerce_after_single_product' );
    ?>

    <?php get_template_part( 'template-parts/single-product/lightbox' ); ?>

</main>

<?php get_template_part( 'template-parts/single-product/sticky-atc' ); ?>

<?php endwhile; ?>

<?php get_footer(); ?>
