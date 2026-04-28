<?php
/**
 * Single Product — Gallery partial
 *
 * Imagen principal + thumbnails clickables.
 * Dedupe perceptual (pHash) vía akibara_product_unique_gallery_ids().
 * Aspect-ratio del contenedor principal = ratio real de la imagen (si disponible)
 * para evitar letterboxing/bandas de fondo.
 *
 * @package Akibara
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

$main_img_id = $product->get_image_id();
$gallery_ids = function_exists( 'akibara_product_unique_gallery_ids' )
    ? akibara_product_unique_gallery_ids( $product )
    : $product->get_gallery_image_ids();

// Aspect-ratio dinámico basado en la imagen real; fallback 11/15 (~manga cover).
$main_ratio_css = '11 / 15';
if ( $main_img_id ) {
    $meta = wp_get_attachment_metadata( $main_img_id );
    if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
        $main_ratio_css = (int) $meta['width'] . ' / ' . (int) $meta['height'];
    }
}
?>
<div class="product-gallery">
    <div class="product-gallery__main" id="product-main-image" style="aspect-ratio: <?php echo esc_attr( $main_ratio_css ); ?>;">
        <?php if ( $main_img_id ) :
            echo wp_get_attachment_image( $main_img_id, 'large', false, [
                'id'            => 'main-product-img',
                'loading'       => 'eager',
                'fetchpriority' => 'high',
                'data-fullsize' => wp_get_attachment_image_url( $main_img_id, 'full' ),
            ] );
        else : ?>
            <img src="<?php echo esc_url( wc_placeholder_img_src( 'large' ) ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>">
        <?php endif; ?>
        <button class="product-gallery__zoom" id="gallery-zoom-btn" aria-label="Ver imagen ampliada" title="Zoom">
            <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
        </button>
    </div>

    <?php if ( ! empty( $gallery_ids ) ) : ?>
        <div class="product-gallery__thumbs">
            <?php if ( $main_img_id ) : ?>
                <button class="product-gallery__thumb product-gallery__thumb--active"
                        data-full="<?php echo esc_url( wp_get_attachment_image_url( $main_img_id, 'large' ) ); ?>">
                    <?php echo wp_get_attachment_image( $main_img_id, 'thumbnail' ); ?>
                </button>
            <?php endif; ?>
            <?php foreach ( $gallery_ids as $gid ) :
                // P0-01 fix A: skip orphan attachment IDs whose image cannot be resolved.
                // Prevents rendering empty <button> wrappers (visible as black boxes).
                $thumb_full_url = wp_get_attachment_image_url( $gid, 'large' );
                if ( ! $thumb_full_url ) {
                    continue;
                }
                ?>
                <button class="product-gallery__thumb"
                        data-full="<?php echo esc_url( $thumb_full_url ); ?>">
                    <?php echo wp_get_attachment_image( $gid, 'thumbnail' ); ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
