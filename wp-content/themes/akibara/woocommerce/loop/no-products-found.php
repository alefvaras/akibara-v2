<?php
/**
 * Empty state: no products found
 * Custom template with Encargar CTA instead of default WC message.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// Try to get current search/filter context for the Encargar link
$titulo_hint = '';
if ( isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) {
    $titulo_hint = sanitize_text_field( $_GET['s'] );
} elseif ( is_product_category() ) {
    $titulo_hint = get_queried_object()->name ?? '';
}
$encargos_url = home_url( '/encargos/' . ( $titulo_hint ? '?titulo=' . rawurlencode( $titulo_hint ) : '' ) );
$shop_url     = get_permalink( wc_get_page_id( 'shop' ) );
?>

<div class="aki-empty-state">
    <svg class="aki-empty-state__icon" width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
        <line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/>
    </svg>

    <h2 class="aki-empty-state__title">No encontramos resultados</h2>
    <p class="aki-empty-state__desc">
        <?php if ( $titulo_hint ) : ?>
            No hay productos que coincidan con <strong>&ldquo;<?php echo esc_html( $titulo_hint ); ?>&rdquo;</strong>.<br>
        <?php else : ?>
            No hay productos que coincidan con tu búsqueda o filtros.<br>
        <?php endif; ?>
        Si buscas un título que no tenemos, podemos encargarlo por ti.
    </p>

    <div class="aki-empty-state__actions">
        <a href="<?php echo esc_url( $encargos_url ); ?>" class="btn btn--primary">
            <span>Encargar este título &rarr;</span>
        </a>
        <a href="<?php echo esc_url( $shop_url ); ?>" class="btn btn--secondary">
            <span>Ver todo el catálogo</span>
        </a>
    </div>

    <p class="aki-empty-state__hint">
        También puedes <a href="<?php echo esc_url( $shop_url ); ?>">limpiar los filtros</a> o probar con otro término de búsqueda.
    </p>
</div>
