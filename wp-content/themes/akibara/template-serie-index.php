<?php
/**
 * Template: Serie Index — Lists all series
 * URL: /serie/
 *
 * Curated sections (Popular, Novedades) + full A-Z grid with search & filters.
 * Data from akibara_get_series_index_data() (cached 2h).
 *
 * @package Akibara
 */

get_header();

$data       = akibara_get_series_index_data();
$all_series = $data['series'] ?? [];
$total      = $data['total'] ?? 0;
$editorials = $data['editorials'] ?? [];
$initial_limit = 36;

// Curated: Popular (top 8 by sales, with cover only)
$popular = array_filter( $all_series, fn( $s ) => $s['sales'] > 0 && ! empty( $s['cover'] ) );
usort( $popular, fn( $a, $b ) => $b['sales'] <=> $a['sales'] );
$popular = array_slice( $popular, 0, 8 );

// Curated: Novedades (newest volume in last 90 days, with cover only)
$cutoff   = date( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
$new_series = array_filter( $all_series, fn( $s ) => $s['latest'] >= $cutoff && ! empty( $s['cover'] ) );
usort( $new_series, fn( $a, $b ) => strcmp( $b['latest'], $a['latest'] ) );
$new_series = array_slice( $new_series, 0, 8 );

sort( $editorials );
?>

<main class="site-content" id="main-content">
    <div class="page-header">
        <div class="container">
            <nav class="page-header__breadcrumb">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Inicio</a>
                <span class="separator">/</span>
                <span>Series</span>
            </nav>
            <h1 class="page-header__title">Todas las Series</h1>
            <p class="serie-index__subtitle"><?php echo esc_html( $total ); ?> series de manga y cómics</p>
        </div>
    </div>

    <div class="container serie-container">

        <?php if ( ! empty( $popular ) ) : ?>
        <!-- Popular -->
        <section class="si-section">
            <div class="si-section__header">
                <h2 class="si-section__title aki-manga-title">Populares</h2>
            </div>
            <div class="si-row">
                <?php foreach ( $popular as $s ) :
                    $url = home_url( '/serie/' . $s['slug'] . '/' );
                ?>
                <a href="<?php echo esc_url( $url ); ?>" class="serie-card">
                    <div class="serie-card__cover">
                        <?php if ( $s['cover'] ) : ?>
                            <img src="<?php echo esc_url( $s['cover'] ); ?>" alt="<?php echo esc_attr( $s['name'] ); ?>" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div class="serie-card__info">
                        <h3 class="serie-card__name"><?php echo esc_html( $s['name'] ); ?></h3>
                        <span class="serie-card__count"><?php echo esc_html( $s['count'] ); ?> volúmenes</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ( ! empty( $new_series ) ) : ?>
        <!-- Novedades -->
        <section class="si-section">
            <div class="si-section__header">
                <h2 class="si-section__title aki-manga-title">Novedades</h2>
            </div>
            <div class="si-row">
                <?php foreach ( $new_series as $s ) :
                    $url = home_url( '/serie/' . $s['slug'] . '/' );
                ?>
                <a href="<?php echo esc_url( $url ); ?>" class="serie-card">
                    <div class="serie-card__cover">
                        <?php if ( $s['cover'] ) : ?>
                            <img src="<?php echo esc_url( $s['cover'] ); ?>" alt="<?php echo esc_attr( $s['name'] ); ?>" loading="lazy">
                        <?php endif; ?>
                    </div>
                    <div class="serie-card__info">
                        <h3 class="serie-card__name"><?php echo esc_html( $s['name'] ); ?></h3>
                        <span class="serie-card__count"><?php echo esc_html( $s['count'] ); ?> volúmenes</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Todas A-Z + Filtros -->
        <section class="si-section si-section--grid">
            <div class="si-section__header">
                <h2 class="si-section__title aki-manga-title">Todas las Series</h2>
            </div>

            <div class="si-filters">
                <div class="si-search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="si-search-input" class="si-search__input" placeholder="Buscar serie..." autocomplete="off" aria-label="Buscar serie">
                </div>
                <div class="si-chips" id="si-chips">
                    <button type="button" class="si-chip si-chip--active" data-filter="all">Todas</button>
                    <button type="button" class="si-chip" data-filter="manga">Manga</button>
                    <button type="button" class="si-chip" data-filter="comics">Cómics</button>
                    <?php if ( count( $editorials ) > 1 ) : ?>
                    <select id="si-editorial-filter" class="si-chip si-chip--select">
                        <option value="">Editorial</option>
                        <?php foreach ( $editorials as $ed ) : ?>
                            <option value="<?php echo esc_attr( sanitize_title( $ed ) ); ?>"><?php echo esc_html( $ed ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>

            <p class="si-results-count" id="si-results-count"><?php echo esc_html( $total ); ?> series</p>

            <div class="serie-index-grid" id="si-grid" data-initial-limit="<?php echo esc_attr( $initial_limit ); ?>">
                <?php foreach ( $all_series as $s ) :
                    $url = home_url( '/serie/' . $s['slug'] . '/' );
                    $search_name    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s['name'], 'UTF-8' ) : strtolower( $s['name'] );
                    $editorial_slug = sanitize_title( $s['editorial'] );
                ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="serie-card js-si-card"
                   data-name="<?php echo esc_attr( $search_name ); ?>"
                   data-category="<?php echo esc_attr( $s['category'] ); ?>"
                   data-editorial="<?php echo esc_attr( $editorial_slug ); ?>">
                    <div class="serie-card__cover">
                        <?php if ( $s['cover'] ) : ?>
                            <img src="<?php echo esc_url( $s['cover'] ); ?>" alt="<?php echo esc_attr( $s['name'] ); ?>" loading="lazy">
                        <?php else : ?>
                            <div class="serie-card__placeholder">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="serie-card__info">
                        <h3 class="serie-card__name"><?php echo esc_html( $s['name'] ); ?></h3>
                        <span class="serie-card__count"><?php echo esc_html( $s['count'] ); ?> volúmenes</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <p class="si-no-results" id="si-no-results" hidden>No se encontraron series con ese criterio.</p>

            <?php if ( $total > $initial_limit ) : ?>
            <div class="si-load-more" id="si-load-more" hidden>
                <button type="button" class="btn btn--secondary" id="si-load-more-btn">
                    <span>Ver todas las series</span>
                </button>
            </div>
            <?php endif; ?>
        </section>

    </div>
</main>

<?php get_footer(); ?>
