<?php
/**
 * Page Template — Akibara
 * Professional layout for internal pages
 *
 * @package Akibara
 */

get_header();

while (have_posts()) : the_post();
    $page_title = get_the_title();
    $page_slug = get_post_field('post_name', get_the_ID());

    // Don't style WC pages (cart, checkout, my-account) — they have their own templates
    $wc_pages = ['carrito', 'checkout', 'mi-cuenta', 'mi-cuenta-2', 'shop-2', 'tienda', 'wishlist'];
    $is_wc_page = in_array($page_slug, $wc_pages);
?>

<main class="site-content" id="main-content">
    <?php
    if (!$is_wc_page) :
        // Detect if content has custom aki-page-hero
        $raw = get_post_field('post_content', get_the_ID());
        $has_custom_hero = (strpos($raw, 'aki-page-hero') !== false);
    ?>

    <?php if (!$has_custom_hero) : ?>
    <!-- Page Header (generic) -->
    <div class="page-hero">
        <div class="page-hero__inner">
            <nav class="page-hero__breadcrumb">
                <a href="<?php echo esc_url(home_url('/')); ?>">Inicio</a>
                <span>/</span>
                <span><?php echo esc_html($page_title); ?></span>
            </nav>
            <h1 class="page-hero__title"><?php echo esc_html($page_title); ?></h1>
        </div>
    </div>
    <?php endif; ?>

    <!-- Page Content -->
    <div class="page-body <?php echo $has_custom_hero ? 'page-body--custom' : ''; ?>">
        <div class="page-body__content">
            <?php the_content(); ?>
        </div>
    </div>

    <?php elseif ($page_slug === 'wishlist') : ?>
    <!-- ===== WISHLIST PAGE ===== -->
    <div class="container section">
        <div class="wishlist-page">
            <div class="wishlist-page__header">
                <div>
                    <h1 class="page-header__title">Mis Favoritos</h1>
                    <span id="wishlist-page-count" class="wishlist-page__count"></span>
                </div>
                <div id="wishlist-actions" class="wishlist-page__actions" style="display:none">
                    <button id="wishlist-add-all" class="btn btn--primary btn--sm"><span>Agregar todo al carrito</span></button>
                    <button id="wishlist-clear" class="wishlist-page__clear">Vaciar favoritos</button>
                </div>
            </div>

            <!-- Loading skeleton -->
            <div id="wishlist-loading">
                <div class="wishlist-grid">
                    <?php for ($i = 0; $i < 8; $i++) : ?>
                    <div class="wishlist-skeleton-card">
                        <div class="skeleton skeleton--image"></div>
                        <div class="skeleton skeleton--text"></div>
                        <div class="skeleton skeleton--text-sm"></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Products grid (filled by JS) -->
            <div id="wishlist-products" class="wishlist-grid"></div>

            <!-- Empty state -->
            <div id="wishlist-empty" class="wishlist-empty" style="display:none">
                <div class="wishlist-empty__icon">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                </div>
                <h2>Aun no tienes favoritos</h2>
                <p>Explora nuestro catálogo y guarda los productos que mas te gusten.</p>
                <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="btn btn--primary"><span>Explorar catálogo</span></a>
            </div>
        </div>
    </div>

    <?php else : ?>
    <!-- WC Page: minimal wrapper -->
    <?php if ($page_slug === 'mi-cuenta' && !is_user_logged_in()) : ?>
    <div class="aki-auth-page">
        <?php the_content(); ?>
    </div>
    <?php else : ?>
    <div class="container section">
        <?php if ($page_slug !== 'mi-cuenta') : ?>
        <h1 class="page-header__title"><?php echo esc_html($page_title); ?></h1>
        <?php endif; ?>
        <div class="page-content">
            <?php the_content(); ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</main>

<?php endwhile; ?>
<?php get_footer(); ?>
