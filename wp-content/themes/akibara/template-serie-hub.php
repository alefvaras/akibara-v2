<?php
/**
 * Template: Serie Hub Landing Page
 * Renderizado cuando el slug pedido (e.g. 'rezero') agrupa varias sub-series
 * (e.g. rezero-chapter-one, rezero-chapter-two, ...).
 *
 * @package Akibara
 */

get_header();

$hub_slug = sanitize_title( get_query_var( 'akibara_serie' ) );
$hub      = akibara_get_serie_hub_data( $hub_slug );

if ( ! $hub || empty( $hub['variants'] ) ) {
    get_template_part( '404' );
    return;
}

$name      = $hub['hub_name'];
$variants  = $hub['variants'];
$arc_count = count( $variants );
$total     = $hub['total_volumes'];
$in_stock  = $hub['in_stock'];
$preorder  = $hub['preorder'];
?>

<main class="site-content" id="main-content">
    <div class="page-header">
        <div class="container">
            <nav class="page-header__breadcrumb">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Inicio</a>
                <span class="separator">/</span>
                <a href="<?php echo esc_url( home_url( '/serie/' ) ); ?>">Series</a>
                <span class="separator">/</span>
                <span><?php echo esc_html( $name ); ?></span>
            </nav>
            <h1 class="page-header__title"><?php echo esc_html( $name ); ?></h1>
            <p class="serie-index__subtitle">
                <?php echo esc_html( $arc_count ); ?> arcos · <?php echo esc_html( $total ); ?> volúmenes
                <?php if ( $preorder > 0 ) : ?>
                    · <?php echo esc_html( $preorder ); ?> en preventa
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="container serie-container">
        <div class="serie-desc">
            <p>Descubre todos los arcos de <strong><?php echo esc_html( $name ); ?></strong> disponibles en Akibara. Selecciona una saga para ver sus volúmenes, precios y disponibilidad.</p>
        </div>

        <section class="si-section">
            <div class="si-row">
                <?php foreach ( $variants as $v ) : ?>
                    <a href="<?php echo esc_url( $v['url'] ); ?>" class="serie-card">
                        <div class="serie-card__cover">
                            <?php if ( ! empty( $v['cover'] ) ) : ?>
                                <img src="<?php echo esc_url( $v['cover'] ); ?>" alt="<?php echo esc_attr( $v['name'] ); ?>" loading="lazy">
                            <?php endif; ?>
                        </div>
                        <div class="serie-card__info">
                            <h3 class="serie-card__name"><?php echo esc_html( $v['name'] ); ?></h3>
                            <span class="serie-card__count">
                                <?php echo esc_html( $v['count'] ); ?> volúmenes
                                <?php if ( $v['preorder'] > 0 ) : ?>
                                    · <?php echo esc_html( $v['preorder'] ); ?> en preventa
                                <?php endif; ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="serie-explore-cta">
            <h2 class="serie-explore-cta__title">Explora más series</h2>
            <p class="serie-explore-cta__text">Encuentra otras sagas y novedades en nuestro catálogo completo.</p>
            <a href="<?php echo esc_url( home_url( '/serie/' ) ); ?>" class="btn btn--primary">
                <span>Ver todas las series</span>
            </a>
        </div>
    </div>
</main>

<?php get_footer(); ?>
