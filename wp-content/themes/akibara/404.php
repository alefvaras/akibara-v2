<?php
/**
 * 404 Page — Enhanced with search, popular products, and encargos link
 *
 * @package Akibara
 */

get_header();
?>

<main class="site-content" id="main-content">
    <div class="error-404">

        <!-- Error visual -->
        <div class="error-404__hero">
            <div class="error-404__code">404</div>
            <h1 class="error-404__title">Página no encontrada</h1>
            <p class="error-404__desc">
                El manga que buscas no está en este estante. Quizás fue movido o el enlace ya no existe.
            </p>

            <!-- Search -->
            <div class="error-404__search">
                <form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                    <div class="error-404__search-row">
                        <input type="search" name="s" placeholder="Buscar productos..." value=""
                               class="error-404__search-input"
                               aria-label="Buscar">
                        <input type="hidden" name="post_type" value="product">
                        <button type="submit" class="btn btn--primary btn--sm"><span>Buscar</span></button>
                    </div>
                </form>
            </div>

            <!-- CTAs -->
            <div class="error-404__ctas">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn btn--primary aki-speed-lines"><span>Ir al Inicio</span></a>
                <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="btn btn--secondary"><span>Ver Catálogo</span></a>
            </div>
        </div>

        <!-- Trending searches -->
        <?php
        $trending = get_option( 'akibara_trending_searches', [] );
        arsort( $trending );
        $top_searches = array_slice( array_keys( $trending ), 0, 6 );
        if ( empty( $top_searches ) ) {
            $top_searches = [ 'chainsaw man', 'jujutsu kaisen', 'one piece', 'berserk', 'dan da dan', 'spy x family' ];
        }
        ?>
        <div class="error-404__trending">
            <p class="error-404__trending-label">Búsquedas populares:</p>
            <div class="error-404__trending-tags">
                <?php foreach ( $top_searches as $search ) : ?>
                    <a href="<?php echo esc_url( home_url( '/?s=' . urlencode( $search ) . '&post_type=product' ) ); ?>"
                       class="error-404__tag">
                        <?php echo esc_html( ucwords( $search ) ); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Popular products -->
        <?php
        $popular_ids = function_exists( 'akibara_get_404_suggestions' ) ? akibara_get_404_suggestions() : [];
        if ( ! empty( $popular_ids ) ) :
            $popular = new WP_Query( [
                'post_type'      => 'product',
                'post__in'       => $popular_ids,
                'orderby'        => 'post__in',
                'posts_per_page' => 6,
                'no_found_rows'  => true,
            ] );
            if ( $popular->have_posts() ) :
        ?>
        <div class="error-404__products">
            <div class="section-header">
                <h2 class="section-header__title">Quizás te interese</h2>
                <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="section-header__link">Ver todo <?php echo akibara_icon( 'arrow', 16 ); ?></a>
            </div>
            <div class="product-grid product-grid--large">
                <?php while ( $popular->have_posts() ) : $popular->the_post();
                    get_template_part( 'template-parts/content/product-card' );
                endwhile; wp_reset_postdata(); ?>
            </div>
        </div>
        <?php endif; endif; ?>

        <!-- Encargos CTA -->
        <div class="error-404__encargos">
            <p class="error-404__encargos-title">¿No encuentras lo que buscas?</p>
            <p class="error-404__encargos-desc">Podemos encargar cualquier manga o comic directamente desde la editorial.</p>
            <a href="<?php echo esc_url( home_url( '/encargos/' ) ); ?>" class="btn btn--primary"><span>Solicitar Encargo</span></a>
        </div>

    </div>
</main>

<?php get_footer(); ?>
