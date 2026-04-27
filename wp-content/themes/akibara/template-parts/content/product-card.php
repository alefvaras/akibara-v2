<?php
/**
 * Product Card Component
 *
 * @package Akibara
 */

defined('ABSPATH') || exit;

global $product;

if (!$product) return;

$product_id = $product->get_id();
$permalink = get_the_permalink();
$title = get_the_title();
$img_id = $product->get_image_id();
$cats = get_the_terms($product_id, 'product_cat');
$primary_cat = ($cats && !is_wp_error($cats)) ? $cats[0] : null;
$card_context = (string) get_query_var('akibara_card_context', 'catalog');

$show_rating = in_array($card_context, ['catalog', 'home-bestsellers'], true);
$show_reserva_countdown = $card_context !== 'home-bestsellers';
$show_notify_cta = $card_context === 'catalog';
$total_sales = (int) $product->get_total_sales();

$is_akb_reserva = get_post_meta( $product_id, '_akb_reserva', true ) === 'yes';
$is_preventa = $is_akb_reserva; // Alias: tras la unificación 2026-04 toda reserva es preventa.
$is_catalog_outofstock = !$is_akb_reserva && !$product->is_in_stock();

$card_class = 'product-card aki-panel';
if ( $is_catalog_outofstock ) {
    $card_class .= ' product-card--outofstock';
}

if ( $is_preventa ) {
    $card_status = 'preventa';
} elseif ( $is_catalog_outofstock ) {
    $card_status = 'agotado';
} else {
    $card_status = 'disponible';
}
?>

<div class="<?php echo esc_attr( $card_class ); ?>" data-product-id="<?php echo esc_attr($product_id); ?>" data-card-context="<?php echo esc_attr($card_context); ?>" data-status="<?php echo esc_attr( $card_status ); ?>">
    <!-- Image -->
    <a href="<?php echo esc_url($permalink); ?>" class="product-card__image">
        <?php akibara_render_badges($product); ?>
        <?php
        $brands = get_the_terms($product_id, 'product_brand');
        if ($brands && !is_wp_error($brands)) :
            $brand = $brands[0];
        ?>
            <span class="product-card__editorial" data-brand="<?php echo esc_attr($brand->slug); ?>"><?php echo esc_html($brand->name); ?></span>
        <?php endif; ?>
        <?php if ($img_id) :
            echo wp_get_attachment_image($img_id, 'product-card', false, [
                'loading' => 'lazy',
                'alt'     => esc_attr($title),
                'sizes'   => '(max-width: 480px) 45vw, (max-width: 768px) 30vw, 220px',
            ]);
        else : ?>
            <img src="<?php echo esc_url(wc_placeholder_img_src('product-card')); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
        <?php endif; ?>
    </a>

    <!-- Body -->
    <div class="product-card__body">
        <div class="product-card__meta">
            <?php if ($primary_cat) : ?>
                <span class="product-card__category"><?php echo esc_html($primary_cat->name); ?></span>
            <?php endif; ?>
            <?php
            $estado_serie = get_post_meta( $product_id, '_akibara_estado_serie', true );
            if ( $estado_serie === 'completa' ) : ?>
                <span class="product-card__serie-badge product-card__serie-badge--completa">&#10003; Completa</span>
            <?php elseif ( $estado_serie === 'suspendida' ) : ?>
                <span class="product-card__serie-badge product-card__serie-badge--suspendida">&#9888; Suspendida</span>
            <?php endif; ?>
            <button class="product-card__wishlist js-wishlist" data-product-id="<?php echo esc_attr($product_id); ?>" title="Guardar" aria-label="Guardar en favoritos">
                <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
            </button>
        </div>

        <h3 class="product-card__title">
            <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($title); ?></a>
        </h3>

        <?php if ( $show_rating ) : ?>
            <?php
            $avg_rating   = $product->get_average_rating();
            $rating_count = $product->get_rating_count();
            if ( $rating_count > 0 ) :
                $full  = (int) floor( (float) $avg_rating );
                $half  = ( (float) $avg_rating - $full ) >= 0.5 ? 1 : 0;
                $empty = 5 - $full - $half;
                $stars = str_repeat( '★', $full ) . ( $half ? '★' : '' ) . str_repeat( '☆', $empty );
                $show_bestseller_hint = $card_context === 'home-bestsellers' && $total_sales >= 3;
                $rating_count_class = 'product-card__rating-count' . ( $show_bestseller_hint ? ' product-card__rating-count--hint' : '' );
                $rating_count_title = $show_bestseller_hint ? '🔥 ' . number_format( $total_sales, 0, ',', '.' ) . ' compradores' : '';
            ?>
            <div class="product-card__rating">
                <span class="product-card__rating-stars"><?php echo $stars; ?></span>
                <span class="<?php echo esc_attr( $rating_count_class ); ?>"<?php echo $rating_count_title ? ' title="' . esc_attr( $rating_count_title ) . '"' : ''; ?>>(<?php echo $rating_count; ?>)</span>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Footer: precio + acciones siempre pegados al fondo -->
        <div class="product-card__footer">
            <div class="product-card__price">
                <?php echo wp_kses_post( $product->get_price_html() ); ?>
            </div>

            <?php
            // Badge de fecha estimada para preventas
            if ( $is_preventa && class_exists( 'Akibara_Reserva_Product' ) ) :
                $estado_prov = Akibara_Reserva_Product::get_estado_proveedor( $product );
                $fecha_badge = '';

                if ( 'recibido' === $estado_prov ) {
                    $fecha_badge = 'Listo para enviar';
                } elseif ( 'en_transito' === $estado_prov ) {
                    $fecha_badge = 'En camino';
                } elseif ( 'pedido' === $estado_prov ) {
                    $llegada = Akibara_Reserva_Product::get_fecha_estimada_llegada( $product );
                    if ( $llegada > 0 ) {
                        $remaining = (int) ceil( ( $llegada - time() ) / DAY_IN_SECONDS );
                        if ( $remaining > 0 ) {
                            $fecha_badge = '~' . $remaining . ' días';
                        } else {
                            $fecha_badge = 'Llegada inminente';
                        }
                    }
                } elseif ( 'sin_pedir' === $estado_prov ) {
                    $brand_days = Akibara_Reserva_Product::get_brand_shipping_days( $product );
                    if ( $brand_days > 0 ) {
                        $fecha_badge = '~' . $brand_days . ' días est.';
                    }
                }

                if ( ! empty( $fecha_badge ) ) : ?>
                    <div class="product-card__fecha-badge product-card__fecha-badge--<?php echo esc_attr( $estado_prov ); ?>">
                        <?php echo esc_html( $fecha_badge ); ?>
                    </div>
                <?php endif;
            endif;
            ?>

            <?php
            // ── Fecha real de preventa ──
            if ( $show_reserva_countdown && $is_akb_reserva ) :
                $akb_fecha = (int) get_post_meta( $product_id, '_akb_reserva_fecha', true );
                $akb_fecha_modo = get_post_meta( $product_id, '_akb_reserva_fecha_modo', true );
                if ( $akb_fecha > 0 && $akb_fecha > time() ) :
                    $dias_restantes = (int) ceil( ( $akb_fecha - time() ) / DAY_IN_SECONDS );
                    $fecha_display = date_i18n( 'M Y', $akb_fecha );
            ?>
                <div class="product-card__reserva-countdown" data-fecha="<?php echo esc_attr( $akb_fecha ); ?>">
                    <span class="product-card__reserva-icon">📅</span>
                    <span class="product-card__reserva-text">
                        <?php if ( $dias_restantes <= 7 ) : ?>
                            ¡<?php echo $dias_restantes; ?>d para llegada!
                        <?php elseif ( $dias_restantes <= 30 ) : ?>
                            ~<?php echo $dias_restantes; ?> días
                        <?php else : ?>
                            Llega <?php echo esc_html( $fecha_display ); ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif ( $akb_fecha_modo === 'sin_fecha' ) : ?>
                <div class="product-card__reserva-countdown product-card__reserva-countdown--nofecha">
                    <span class="product-card__reserva-icon">📦</span>
                    <span class="product-card__reserva-text">Fecha por confirmar</span>
                </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ( $product->is_in_stock() ) :
                // Helper centralizado garantiza consistencia archive ↔ single.
                $cta_text = function_exists( 'akibara_get_atc_text' )
                    ? akibara_get_atc_text( $product )
                    : ( $is_akb_reserva ? 'Reservar ahora' : 'Agregar al carrito' );
                $cta_icon = $is_akb_reserva ? 'calendar' : 'cart';
            ?>
                <button class="product-card__add-to-cart js-quick-add<?php echo $is_akb_reserva ? ' product-card__add-to-cart--reserva' : ''; ?>" data-product-id="<?php echo esc_attr($product_id); ?>" aria-label="<?php echo esc_attr($cta_text . ': ' . $product->get_name()); ?>">
                    <?php echo akibara_icon($cta_icon, 14); ?>
                    <span><?php echo esc_html($cta_text); ?></span>
                </button>
            <?php else : ?>
                <a href="<?php echo esc_url(home_url('/encargos/?titulo='.urlencode($title))); ?>"
                   class="product-card__add-to-cart product-card__add-to-cart--encargar product-card__add-to-cart--link"
                   title="Solicitar encargo de este título">Solicitar encargo</a>
                <?php if ( $show_notify_cta ) : ?>
                    <button class="product-card__notify-link js-notify-open"
                            data-product="<?php echo esc_attr($product_id); ?>"
                            data-title="<?php echo esc_attr($title); ?>"
                            type="button"
                            aria-label="<?php echo esc_attr( 'Avísame cuando ' . $title . ' vuelva al stock' ); ?>">&#9993; Avísame cuando vuelva</button>
                <?php endif; ?>
            <?php endif; ?>
            <?php
            // Inyectar el bottom-sheet una sola vez (primer card agotado en la página).
            if ( $show_notify_cta && ! did_action( 'akibara_notify_sheet_queued' ) ) {
                do_action( 'akibara_notify_sheet_queued' );
                add_action( 'wp_footer', static function (): void { ?>
<div class="aki-notify-sheet" id="aki-notify-sheet" role="dialog" aria-modal="true" aria-labelledby="aki-notify-sheet-heading" hidden>
    <div class="aki-notify-sheet__backdrop" id="aki-notify-sheet-bd"></div>
    <div class="aki-notify-sheet__panel">
        <button class="aki-notify-sheet__close" id="aki-notify-sheet-close" type="button" aria-label="Cerrar">
            <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <p id="aki-notify-sheet-heading" class="aki-notify-sheet__title">&#9993; Avísame cuando vuelva</p>
        <p class="aki-notify-sheet__hint">Te escribimos cuando vuelva al stock.</p>
        <div class="aki-notify-sheet__row">
            <label for="aki-notify-sheet-email" class="sr-only">Tu correo electrónico</label>
            <input type="email" class="aki-notify-sheet__input" id="aki-notify-sheet-email"
                   placeholder="correo@ejemplo.com" inputmode="email" autocomplete="email">
            <button type="button" class="aki-notify-sheet__btn" id="aki-notify-sheet-submit">Avisar</button>
        </div>
        <p class="aki-notify-sheet__ok" id="aki-notify-sheet-ok" hidden role="status" aria-live="polite">&#10003; Listo, te avisamos cuando vuelva</p>
        <p class="aki-notify-sheet__err" id="aki-notify-sheet-err" hidden role="alert"></p>
    </div>
</div>
                <?php }, 100 );
            }
            ?>
        </div><!-- /.product-card__footer -->
    </div><!-- /.product-card__body -->
</div>
