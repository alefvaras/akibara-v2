<?php
/**
 * Single Product — Info partial
 *
 * Bloque principal de info: categoría, título, badges, rating, precio,
 * stock/reserva, short desc, add-to-cart form (o fallback OOS con notify),
 * trust signals, WhatsApp, wishlist, share, meta (SKU/cats/tags).
 *
 * Inherited from single-product.php: $product.
 *
 * @package Akibara
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

$product_id = $product->get_id();
$cats       = get_the_terms( $product_id, 'product_cat' );
$tags       = get_the_terms( $product_id, 'product_tag' );
?>
<div class="product-info">
    <?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
        <nav class="product-info__breadcrumbs" aria-label="Categorías del producto">
            <ol class="product-info__breadcrumbs-list">
                <?php foreach ( $cats as $cat ) : ?>
                    <?php
                    $cat_link = get_term_link( $cat );
                    if ( is_wp_error( $cat_link ) ) {
                        continue;
                    }
                    ?>
                    <li class="product-info__breadcrumbs-item">
                        <a href="<?php echo esc_url( $cat_link ); ?>"><?php echo esc_html( $cat->name ); ?></a>
                    </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    <?php endif; ?>

    <?php if ( function_exists( 'akb_reserva_esta_activa' ) && akb_reserva_esta_activa( $product ) ) : ?>
        <span class="badge badge--preorder badge--placed"><span>Preventa</span></span>
    <?php endif; ?>
    <h1 class="product-info__title aki-manga-title"><?php the_title(); ?></h1>

    <?php
    // ── Badge de edición / país ──────────────────────────
    $pais_terms = get_the_terms( $product_id, 'pa_pais' );
    if ( $pais_terms && ! is_wp_error( $pais_terms ) ) :
        $badge = akibara_get_country_badge_data( $pais_terms[0]->name );
    ?>
    <div class="product-edition-badge">
        <?php if ( $badge['show_flag'] ) : ?>
            <span class="product-edition-badge__flag" aria-hidden="true"><?php echo esc_html( $badge['flag'] ); ?></span>
        <?php else : ?>
            <span class="product-edition-badge__flag product-edition-badge__flag--abbr" aria-hidden="true"><?php echo esc_html( $badge['fallback'] ); ?></span>
        <?php endif; ?>
        <span class="product-edition-badge__text">Edición <?php echo esc_html( $badge['country'] ); ?></span>
    </div>
    <?php endif; ?>

    <?php
    $avg_rating   = $product->get_average_rating();
    $rating_count = $product->get_rating_count();
    if ( $rating_count > 0 ) : ?>
    <div class="product-info__rating">
        <?php echo wp_kses_post( akibara_render_stars( (float) $avg_rating ) ); ?>
        <a href="#tab-reviews" class="product-info__rating-link">
            <?php echo absint( $rating_count ); ?> reseña<?php echo $rating_count !== 1 ? 's' : ''; ?>
        </a>
    </div>
    <?php elseif ( 'yes' === get_option( 'woocommerce_enable_reviews' ) && $product->get_reviews_allowed() ) : ?>
    <div class="product-info__first-review">
        <a href="#tab-reviews" class="product-info__first-review-link">Sé el primero en reseñar</a>
    </div>
    <?php endif; ?>

    <div class="product-info__price">
        <?php echo wp_kses_post( $product->get_price_html() ); ?>
    </div>

    <?php
    // Render direct y remover el hook antes de do_action('woocommerce_single_product_summary')
    // para evitar que el plugin lo registre dos veces (direct call + hook priority 11).
    remove_action( 'woocommerce_single_product_summary', 'akibara_installments_single', 11 );
    if ( function_exists( 'akibara_installments_single' ) ) akibara_installments_single();
    ?>

    <!-- Stock / Reserva status -->
    <?php if ( function_exists( 'akb_reserva_esta_activa' ) && akb_reserva_esta_activa( $product ) ) :
        $r_disp = Akibara_Reserva_Product::get_disponibilidad_text( $product );
    ?>
        <span class="product-info__stock product-info__stock--preorder">
            <span class="dot"></span>
            <?php echo esc_html( $r_disp ); ?>
        </span>
        <?php
        // Countdown timer para preventas con fecha fija
        $r_modo  = Akibara_Reserva_Product::get_fecha_modo( $product );
        $r_fecha = Akibara_Reserva_Product::get_fecha( $product );
        if ( 'fija' === $r_modo && $r_fecha > 0 && $r_fecha > time() ) :
            $diff  = $r_fecha - time();
            $days  = (int) floor( $diff / 86400 );
            $hours = (int) floor( ( $diff % 86400 ) / 3600 );
            $mins  = (int) floor( ( $diff % 3600 ) / 60 );
            if ( $days <= 90 ) :
        ?>
        <div class="akb-countdown aki-preventa-highlight" data-timestamp="<?php echo esc_attr( $r_fecha ); ?>">
            <div class="akb-countdown-label">Disponible en:</div>
            <div class="akb-countdown-timer">
                <div class="akb-cd-unit"><span class="akb-cd-num" data-unit="days"><?php echo $days; ?></span><span class="akb-cd-txt">días</span></div>
                <div class="akb-cd-sep">:</div>
                <div class="akb-cd-unit"><span class="akb-cd-num" data-unit="hours"><?php echo str_pad( $hours, 2, '0', STR_PAD_LEFT ); ?></span><span class="akb-cd-txt">hrs</span></div>
                <div class="akb-cd-sep">:</div>
                <div class="akb-cd-unit"><span class="akb-cd-num" data-unit="minutes"><?php echo str_pad( $mins, 2, '0', STR_PAD_LEFT ); ?></span><span class="akb-cd-txt">min</span></div>
            </div>
        </div>
        <?php endif; endif; ?>
        <?php
        // Social proof
        $total_sales = (int) $product->get_total_sales();
        if ( $total_sales > 0 ) : ?>
        <div class="akb-social-proof">
            <span class="akb-sp-icon">&#128293;</span>
            <span class="akb-sp-text"><?php echo absint( $total_sales ); ?> personas ya lo reservaron</span>
        </div>
        <?php endif; ?>
    <?php elseif ( $product->is_in_stock() ) : ?>
        <?php
        $stock_qty   = $product->get_stock_quantity();
        $stock_class = 'product-info__stock--in';
        $stock_text  = 'En stock';
        // Sprint 11 a11y fix #6: aria-live=assertive solo para urgencia "última unidad"
        // (interrumpe SR para alertar). polite para resto (no interrumpe lectura).
        $stock_aria_live = 'polite';
        if ( $stock_qty !== null ) {
            if ( $stock_qty === 1 ) {
                $stock_class = 'product-info__stock--urgent';
                $stock_text  = '¡Última unidad disponible!';
                $stock_aria_live = 'assertive';
            } elseif ( $stock_qty <= 5 ) {
                $stock_class = 'product-info__stock--low';
                $stock_text  = 'Solo quedan ' . $stock_qty . ' unidades';
            } elseif ( $stock_qty <= 10 ) {
                $stock_class = 'product-info__stock--scarce';
                $stock_text  = 'Solo quedan ' . $stock_qty . ' unidades';
            }
        }
        ?>
        <span class="product-info__stock <?php echo $stock_class; ?>" role="status" aria-live="<?php echo esc_attr( $stock_aria_live ); ?>">
            <span class="dot" aria-hidden="true"></span>
            <?php echo esc_html( $stock_text ); ?>
        </span>
    <?php else : ?>
        <span class="product-info__stock product-info__stock--out" role="status" aria-live="polite">
            <span class="dot" aria-hidden="true"></span>
            Por encargo &mdash; llega en <?php echo esc_html( akibara_encargo_estimate_weeks( $product ) ); ?>
        </span>
    <?php endif; ?>

    <!-- Short description -->
    <?php if ( $product->get_short_description() ) : ?>
        <div class="product-info__short-desc">
            <?php echo wp_kses_post( $product->get_short_description() ); ?>
        </div>
    <?php endif; ?>

    <!-- Add to cart -->
    <?php if ( $product->is_in_stock() && $product->is_purchasable() ) : ?>
        <form class="product-add-to-cart" method="post" enctype="multipart/form-data">
            <div class="product-quantity">
                <button type="button" class="product-quantity__btn js-qty-minus" aria-label="Reducir">
                    <?php echo akibara_icon( 'minus', 16 ); ?>
                </button>
                <input type="number" name="quantity" value="1" min="1" max="<?php echo esc_attr( $product->get_stock_quantity() ?: 99 ); ?>" step="1" class="js-qty-input" aria-label="Cantidad">
                <button type="button" class="product-quantity__btn js-qty-plus" aria-label="Aumentar">
                    <?php echo akibara_icon( 'plus', 16 ); ?>
                </button>
            </div>
            <button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product_id ); ?>" class="btn btn--primary">
                <span><?php echo esc_html( $product->single_add_to_cart_text() ); ?></span>
            </button>
            <?php // Sprint 11 a11y fix #10 (audit 2026-04-26): &bull; separador → <ul> semántica.
            // SR antes leía "Mismo día RM punto 3 cuotas..." (separador como punto repetido).
            // Ahora <ul> + role="list" (workaround CSS reset list-style:none que rompe SR
            // semantics en Safari/VO). aria-label como group title.
            ?>
            <ul class="product-atc-trust" role="list" aria-label="Beneficios">
                <li>Mismo día RM</li>
                <li>3 cuotas sin interés</li>
                <li>Manga original</li>
            </ul>
        </form>
    <?php else : ?>
        <!-- CTA para producto agotado -->
        <div class="product-oos-cta">
            <a href="<?php echo esc_url( home_url( '/encargos/?titulo=' . urlencode( get_the_title() ) ) ); ?>"
               class="btn btn--secondary product-oos-btn">
                <span>Encargar este título</span>
            </a>
            <p class="product-oos-note">
                Tiempo estimado: <?php echo esc_html( akibara_encargo_estimate_weeks( $product ) ); ?> &bull; Sin costo extra
            </p>

            <a href="<?php echo esc_url( akibara_get_product_whatsapp_url( $product_id ) ); ?>"
               target="_blank" rel="noopener" class="product-oos-whatsapp">
                <?php echo akibara_icon( 'whatsapp', 15 ); ?>
                <span>Consultar disponibilidad por WhatsApp</span>
            </a>

            <!-- Avísame cuando llegue -->
            <div class="aki-notify-single" id="aki-notify-single">
                <?php if ( ! get_post_meta( get_the_ID(), '_akibara_notify_closed', true ) ) : ?>
                <p class="aki-notify-single__label">¿Prefieres esperar? Te avisamos cuando llegue:</p>
                <form class="aki-notify-single__form" id="aki-notify-sp-form">
                    <label for="aki-notify-sp-email" class="sr-only">Tu correo electrónico</label>
                    <input type="email" class="aki-notify-single__input" id="aki-notify-sp-email"
                           placeholder="correo@ejemplo.com" required autocomplete="email" inputmode="email">
                    <button type="submit" class="btn btn--primary aki-notify-single__btn"
                            data-product="<?php echo esc_attr( get_the_ID() ); ?>"
                            aria-label="<?php echo esc_attr( 'Avísame cuando ' . get_the_title() . ' vuelva al stock' ); ?>">
                        <span>Avísame</span>
                    </button>
                </form>
                <p class="aki-notify-single__ok" id="aki-notify-sp-ok" hidden role="status" aria-live="polite">
                    &#10003; Listo. Te enviamos un correo cuando llegue al stock.
                </p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Trust signals -->
    <div class="product-trust-signals">
        <?php foreach ( akibara_get_product_trust_signals( $product_id ) as $signal ) : ?>
        <div class="product-trust-signals__item">
            <?php echo akibara_icon( esc_attr( $signal['icon'] ), 13 ); ?>
            <span><?php echo esc_html( $signal['text'] ); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- WhatsApp contextual -->
    <a href="<?php echo esc_url( akibara_get_product_whatsapp_url( $product_id ) ); ?>" target="_blank" rel="noopener" class="product-info__whatsapp">
        <?php echo akibara_icon( 'whatsapp', 15 ); ?>
        <span>¿Dudas sobre este título? <strong>Escríbenos</strong></span>
    </a>

    <!-- Wishlist -->
    <button class="product-info__wishlist js-wishlist" data-product-id="<?php echo esc_attr( $product_id ); ?>" aria-label="Guardar en favoritos">
        <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
        <span class="wishlist-label-add">Guardar en favoritos</span>
        <span class="wishlist-label-added">Guardado en favoritos</span>
    </button>

    <!-- Share -->
    <div class="product-share">
        <button type="button" class="product-share__btn product-share__btn--native js-share-native" data-title="<?php echo esc_attr( get_the_title() ); ?>" data-url="<?php echo esc_url( get_permalink() ); ?>">
            <svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
            <span>Compartir</span>
        </button>
        <a href="https://wa.me/?text=<?php echo esc_attr( urlencode( get_the_title() . ' - ' . get_permalink() ) ); ?>" target="_blank" rel="noopener" class="product-share__btn product-share__btn--wa" title="Compartir en WhatsApp" aria-label="Compartir en WhatsApp">
            <svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492l4.624-1.47A11.94 11.94 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.75c-2.115 0-4.109-.652-5.762-1.785l-.413-.248-2.714.863.879-2.645-.274-.43A9.72 9.72 0 012.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75z"/></svg>
        </a>
        <button type="button" class="product-share__btn js-copy-url" data-url="<?php echo esc_url( get_permalink() ); ?>" aria-label="Copiar enlace" title="Copiar enlace">
            <svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
        </button>
    </div>

    <?php
    /**
     * Hook: woocommerce_single_product_summary.
     * - title (5)
     * - rating (10)
     * - price (10)
     * - excerpt (20)
     * - add_to_cart (30)
     * - meta (40)
     * - sharing (50)
     */
    do_action( 'woocommerce_single_product_summary' );
    ?>

    <!-- Meta -->
    <div class="product-meta">
        <?php if ( $product->get_sku() ) : ?>
            <div class="product-meta__item">
                <span class="product-meta__label">SKU:</span>
                <span class="product-meta__value"><?php echo esc_html( $product->get_sku() ); ?></span>
            </div>
        <?php endif; ?>

        <?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
            <div class="product-meta__item">
                <span class="product-meta__label">Categoría:</span>
                <span class="product-meta__value">
                    <?php
                    $cat_links = [];
                    foreach ( $cats as $cat ) {
                        $cat_links[] = '<a href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
                    }
                    echo implode( ', ', $cat_links );
                    ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ( $tags && ! is_wp_error( $tags ) ) : ?>
            <div class="product-meta__item">
                <span class="product-meta__label">Etiquetas:</span>
                <span class="product-meta__value product-meta__value--tags">
                    <?php foreach ( $tags as $tag ) : ?>
                        <a href="<?php echo esc_url( get_term_link( $tag ) ); ?>" class="tag"><?php echo esc_html( $tag->name ); ?></a>
                    <?php endforeach; ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>
