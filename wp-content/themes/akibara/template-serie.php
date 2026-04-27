<?php
/**
 * Template: Serie Landing Page
 * Auto-generated for each manga/comic series.
 *
 * @package Akibara
 */

get_header();

$serie_slug = sanitize_title( get_query_var( 'akibara_serie' ) );
$data = akibara_get_serie_data( $serie_slug );

if ( ! $data ) {
    get_template_part( '404' );
    return;
}

$name       = $data['serie_name'];
$total      = $data['total'];
$in_stock   = $data['in_stock'];
$preorder   = $data['preorder'];
$oos        = $data['out_of_stock'];
$editorial  = $data['editorial'];
$category   = $data['category'];
$cat_link   = $data['category_link'];
$min_price  = $data['min_price'];
$max_price  = $data['max_price'];
?>

<main class="site-content" id="main-content">
    <!-- Breadcrumb -->
    <div class="page-header">
        <div class="container">
            <nav class="page-header__breadcrumb">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Inicio</a>
                <span class="separator">/</span>
                <?php if ( $category && $cat_link ) : ?>
                    <a href="<?php echo esc_url( $cat_link ); ?>"><?php echo esc_html( $category ); ?></a>
                    <span class="separator">/</span>
                <?php endif; ?>
                <a href="<?php echo esc_url( home_url( '/serie/' ) ); ?>">Series</a>
                <span class="separator">/</span>
                <span><?php echo esc_html( $name ); ?></span>
            </nav>
            <h1 class="page-header__title"><?php echo esc_html( $name ); ?></h1>
        </div>
    </div>

    <div class="container serie-container">

        <!-- Serie Stats / Filter Chips -->
        <div class="serie-stats" role="group" aria-label="Filtrar volúmenes por disponibilidad">
            <div class="serie-stats__grid js-serie-filters">
                <button type="button" class="serie-stats__item serie-stats__item--active" data-filter="all" aria-pressed="true">
                    <span class="serie-stats__num"><?php echo esc_html( $total ); ?></span>
                    <span class="serie-stats__label">Volumenes</span>
                </button>
                <?php if ( $in_stock > 0 ) : ?>
                <button type="button" class="serie-stats__item serie-stats__item--green" data-filter="disponible" aria-pressed="false">
                    <span class="serie-stats__num"><?php echo esc_html( $in_stock ); ?></span>
                    <span class="serie-stats__label">Disponibles</span>
                </button>
                <?php endif; ?>
                <?php if ( $preorder > 0 ) : ?>
                <button type="button" class="serie-stats__item serie-stats__item--yellow" data-filter="preventa" aria-pressed="false">
                    <span class="serie-stats__num"><?php echo esc_html( $preorder ); ?></span>
                    <span class="serie-stats__label">En preventa</span>
                </button>
                <?php endif; ?>
                <?php if ( $oos > 0 ) : ?>
                <button type="button" class="serie-stats__item serie-stats__item--red" data-filter="agotado" aria-pressed="false">
                    <span class="serie-stats__num"><?php echo esc_html( $oos ); ?></span>
                    <span class="serie-stats__label">Agotados</span>
                </button>
                <?php endif; ?>
            </div>

            <?php if ( $editorial ) : ?>
                <p class="serie-stats__meta">
                    Editorial: <strong><?php echo esc_html( $editorial ); ?></strong>
                    <?php if ( $min_price > 0 ) : ?>
                     | Desde <strong>$<?php echo esc_html( number_format( $min_price, 0, ',', '.' ) ); ?></strong>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Description -->
        <div class="serie-desc">
            <p>Encuentra todos los volumenes de <strong><?php echo esc_html( $name ); ?></strong><?php echo $editorial ? ' de ' . esc_html( $editorial ) : ''; ?> en Akibara. <?php echo esc_html( $in_stock ); ?> tomos disponibles con stock inmediato<?php echo $preorder > 0 ? " y {$preorder} en preventa" : ''; ?>. Envio a todo Chile y retiro gratis en San Miguel.</p>
        </div>

        <!-- Product Grid -->
        <div class="section-header serie-header">
            <h2 class="section-header__title js-serie-grid-title">Todos los volumenes</h2>
            <span class="serie-header__count js-serie-grid-count" data-label-singular="tomo" data-label-plural="tomos"><?php echo esc_html( $total ); ?> tomos</span>
        </div>

        <?php $serie_grid_limit = 24; ?>

        <div class="product-grid product-grid--large js-serie-grid" data-load-limit="<?php echo esc_attr( $serie_grid_limit ); ?>" data-total="<?php echo esc_attr( $total ); ?>">
            <?php
            $serie_query = new WP_Query( [
                'post_type'      => 'product',
                'post__in'       => $data['products'],
                'orderby'        => 'post__in',
                'posts_per_page' => $total,
                'no_found_rows'  => true,
            ] );

            while ( $serie_query->have_posts() ) :
                $serie_query->the_post();
                get_template_part( 'template-parts/content/product-card' );
            endwhile;
            wp_reset_postdata();
            ?>
        </div>

        <div class="serie-empty js-serie-empty" hidden>
            <p class="serie-empty__text">No hay volúmenes que coincidan con este filtro.</p>
            <button type="button" class="btn btn--secondary js-serie-filter-reset">
                <span>Ver todos los tomos</span>
            </button>
        </div>

        <?php if ( $total > $serie_grid_limit ) : ?>
        <div class="serie-load-more" id="serie-load-more">
            <button type="button" class="btn btn--secondary" id="serie-load-more-btn">
                <span>Cargar más tomos</span>
            </button>
        </div>
        <?php endif; ?>

        <!-- Encargos CTA (if out of stock exists) -->
        <?php if ( $oos > 0 ) : ?>
        <div class="serie-encargo-cta">
            <p class="serie-encargo-cta__text">¿Falta un tomo de <?php echo esc_html( $name ); ?>? Podemos encargarlo para ti.</p>
            <a href="<?php echo esc_url( home_url( '/encargos/' ) ); ?>" class="btn btn--secondary btn--sm"><span>Solicitar Encargo</span></a>
        </div>
        <?php endif; ?>

        <!-- Explore more series CTA — shown when the series is long enough that the user may want to return to the global index -->
        <?php if ( $total >= 15 ) : ?>
        <div class="serie-explore-cta">
            <h2 class="serie-explore-cta__title aki-manga-title">Descubre más series</h2>
            <p class="serie-explore-cta__text">Explora nuestro catálogo completo de manga y cómics en Akibara.</p>
            <a href="<?php echo esc_url( home_url( '/serie/' ) ); ?>" class="btn btn--primary aki-speed-lines">
                <span>Ver todas las series</span>
            </a>
        </div>
        <?php endif; ?>
    </div>
</main>


<?php get_footer(); ?>
