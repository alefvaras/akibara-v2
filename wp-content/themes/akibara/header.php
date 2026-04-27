<!DOCTYPE html>
<html class="no-js" <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0A0A0A">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="aki-scroll-progress" aria-hidden="true" role="presentation"></div>

<a href="#main-content" class="skip-link">Saltar al contenido</a>

<?php
// Shared data — single query set, reused by front-page.php too
$cats = akibara_get_shared_cats();
$manga_cat   = $cats['manga_cat'];
$comics_cat  = $cats['comics_cat'];
$manga_demos = $cats['manga_demos'];
$comics_subs = $cats['comics_subs'];

// Editorial menu — cached in transient
$editorial_menu = akibara_get_editorial_menu();

// Series hub state (/serie/ and /serie/{slug}/)
$is_series_page = ! empty( get_query_var( 'akibara_serie' ) ) || ! empty( get_query_var( 'akibara_serie_index' ) );

// Checkout funnel: excluye order-received (thank-you) y endpoints de cuenta.
// Dentro del funnel suprimimos topbar promocional y cart-drawer para reducir
// fricciones y salidas (Baymard #2.3 "Minimize distractions during checkout").
$akb_in_checkout_funnel = function_exists( 'is_checkout' )
    && is_checkout()
    && ! ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) );
?>

<header class="site-header" id="site-header">
    <?php if ( ! $akb_in_checkout_funnel ) : ?>
    <!-- ===== TOP BAR (campaign banner > rotative banner fallback) ===== -->
    <div class="topbar">
        <div class="topbar__inner akibara-topbar-inner">
            <?php
            // Si hay campaña activa con banner_text, mostrar ese (máx prioridad visual).
            // Si no, fallback al mensaje rotativo del módulo banner existente.
            $akb_campaign_html = function_exists( 'akibara_descuento_banner_html' ) ? akibara_descuento_banner_html() : '';
            if ( $akb_campaign_html !== '' ) {
                // Ya escapeado en el helper (esc_html + esc_attr en el HTML generado).
                echo $akb_campaign_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                ?>
                <span class="aki-topbar-text"><?php echo esc_html( function_exists('akibara_banner_get_primer_mensaje') ? akibara_banner_get_primer_mensaje() : 'Envío gratis sobre $' . number_format(akibara_get_free_shipping_threshold(), 0, ',', '.') . ' CLP · Mismo día en RM' ); ?></span>
                <?php
            }
            ?>
        </div>
    </div>
    <?php endif; // ! $akb_in_checkout_funnel ?>

    <!-- ===== ROW 1: Logo + Editoriales + Search + Icons ===== -->
    <div class="header-main">
        <div class="header-main__inner">
            <!-- Mobile: hamburger -->
            <button class="hamburger" id="menu-toggle" aria-label="Menú" aria-expanded="false" aria-controls="mobile-drawer">
                <span></span><span></span><span></span>
            </button>

            <!-- Logo -->
            <?php // B8 (a11y Round 3): aria-label contextualiza el link como "ir a home" para SR. ?>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="logo" aria-label="Akibara — Inicio">
                <?php

                $logo_id = get_theme_mod('custom_logo');
                if (file_exists(get_template_directory() . '/assets/img/logo-akibara.webp')) {
                    echo '<img src="' . esc_url(AKIBARA_THEME_URI . '/assets/img/logo-akibara.webp') . '?v=5" alt="Akibara" class="logo__img" width="181" height="72" fetchpriority="high" loading="eager" decoding="async" data-no-lazy="1">';
                } else if ($logo_id) {

                    $mime = get_post_mime_type($logo_id);
                    if ($mime === 'image/svg+xml') {
                        echo '<img src="' . esc_url(wp_get_attachment_url($logo_id)) . '" alt="' . esc_attr(get_bloginfo('name')) . '" class="logo__img" width="250"  fetchpriority="high" loading="eager" decoding="async" data-no-lazy="1">';
                    } else {
                        echo wp_get_attachment_image($logo_id, 'full', false, [
                            'class' => 'logo__img',
                            'sizes' => '250px',
                            'fetchpriority' => 'high',
                            'loading' => 'eager',
                            'decoding' => 'async',
                        ]);
                    }
                } else {
                    echo '<img src="' . esc_url(AKIBARA_THEME_URI . '/assets/img/akibara-logo.svg') . '" alt="Akibara" class="logo__img">';
                }
                ?>
            </a>

            <!-- Editoriales dropdown button -->
            <?php if ($editorial_menu) : ?>
            <div class="editoriales-dropdown" id="editoriales-dropdown">
                <button class="editoriales-btn" id="editoriales-btn" type="button">
                    <?php echo akibara_icon('grid', 16); ?>
                    <span>Editoriales</span>
                </button>
                <div class="editoriales-panel" id="editoriales-panel">
                    <?php
                    $country_labels = ['AR' => 'Edición Argentina', 'ES' => 'Edición Española'];
                    $groups = [];
                    foreach ($editorial_menu as $item) {
                        $key = $item->country ?: '__other';
                        $groups[$key][] = $item;
                    }
                    ?>
                    <!-- Editoriales styles moved to header-v2.css -->
                    <div class=editoriales-panel__grouped>
                        <?php foreach ($country_labels as $code => $label) :
                            if (empty($groups[$code])) continue;
                        ?>
                        <div class="editoriales-panel__country">
                            <span class="editoriales-panel__country-label"><?php echo esc_html($label); ?></span>
                            <div class="editoriales-panel__country-items">
                                <?php foreach ($groups[$code] as $item) : ?>
                                    <a href="<?php echo esc_url($item->url); ?>" class="editoriales-panel__item">
                                        <?php echo esc_html($item->title); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!empty($groups['__other'])) : ?>
                        <div class="editoriales-panel__country">
                            <div class="editoriales-panel__country-items">
                                <?php foreach ($groups['__other'] as $item) : ?>
                                    <a href="<?php echo esc_url($item->url); ?>" class="editoriales-panel__item">
                                        <?php echo esc_html($item->title); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Search bar — triggers Akibara plugin popup -->
            <div class="searchbar">
                <button class="searchbar__input button-search-popup" type="button" aria-label="Buscar productos" aria-haspopup="dialog" aria-expanded="false" aria-controls="akibara-pro-popup">
                    <span class="searchbar__text">Buscar productos...</span>
                    <?php echo akibara_icon('search', 20); ?>
                </button>
            </div>

            <!-- Icons -->
            <div class="header-icons">
                <button class="header-icon header-icon--search button-search-popup" type="button" aria-label="Buscar productos" aria-haspopup="dialog" aria-expanded="false" aria-controls="akibara-pro-popup">
                    <?php echo akibara_icon('search', 22); ?>
                </button>
                <div class="account-dropdown" id="account-dropdown">
                    <?php
                    $is_logged  = is_user_logged_in();
                    $cur_user   = $is_logged ? wp_get_current_user() : null;
                    $first_name = $cur_user ? ($cur_user->first_name ?: explode(' ', $cur_user->display_name)[0]) : '';
                    $g_avatar   = $cur_user ? get_user_meta($cur_user->ID, 'akibara_google_avatar', true) : '';
                    $initial    = $first_name ? mb_strtoupper(mb_substr($first_name, 0, 1)) : '?';
                    ?>
                    <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="header-icon header-icon--account" aria-label="Mi cuenta" id="account-btn"
                            aria-expanded="false" aria-controls="account-panel">
                        <?php if ($is_logged && $g_avatar) : ?>
                            <span class="account-panel__avatar"><img src="<?php echo esc_url($g_avatar); ?>" alt="<?php echo esc_attr($first_name); ?>" loading="lazy" referrerpolicy="no-referrer"></span>
                        <?php elseif ($is_logged) : ?>
                            <span class="account-panel__avatar"><?php echo esc_html($initial); ?></span>
                        <?php else : ?>
                            <?php echo akibara_icon('user', 22); ?>
                        <?php endif; ?>
                    </a>

                    <div class="account-panel" id="account-panel">
                        <?php if ($is_logged) : ?>
                            <div class="account-panel__greeting">
                                <strong>Hola, <?php echo esc_html($first_name ?: 'por aquí'); ?></strong>
                                <?php echo esc_html($cur_user->user_email); ?>
                            </div>
                            <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="account-panel__item">
                                <?php echo akibara_icon('tag', 16); ?> Mis Pedidos
                            </a>
                            <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="account-panel__item">
                                <?php echo akibara_icon('user', 16); ?> Mi Cuenta
                            </a>
                            <a href="<?php echo esc_url(home_url('/encargos/')); ?>" class="account-panel__item">
                                <?php echo akibara_icon('cart', 16); ?> Encargos
                            </a>
                            <a href="<?php echo esc_url(home_url('/rastrear/')); ?>" class="account-panel__item">
                                <?php echo akibara_icon('truck', 16); ?> Rastrear Pedido
                            </a>
                            <a href="<?php echo esc_url(wc_logout_url()); ?>" class="account-panel__item account-panel__item--logout">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                Cerrar sesión
                            </a>
                        <?php else : ?>
                            <div class="account-panel__cta">
                                <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="account-panel__cta-primary">
                                    Ingresar a mi cuenta
                                </a>
                                <a href="<?php echo esc_url(add_query_arg('tab','register', wc_get_page_permalink('myaccount'))); ?>" class="account-panel__cta-secondary">
                                    ¿Sin cuenta? Créala gratis →
                                </a>
                            </div>
                            <a href="<?php echo esc_url(home_url('/encargos/')); ?>" class="account-panel__item">
                                <?php echo akibara_icon('cart', 16); ?> Encargos
                            </a>
                            <a href="<?php echo esc_url(home_url('/rastrear/')); ?>" class="account-panel__item">
                                <?php echo akibara_icon('truck', 16); ?> Rastrear Pedido
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?php echo esc_url(home_url('/wishlist/')); ?>" class="header-icon header-icon--wishlist" aria-label="Favoritos">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                    <span class="header-icon__badge header-icon__badge--hidden js-wishlist-count">0</span>
                </a>
                <?php $aki_cart_count = akibara_cart_count(); ?>
                <button class="header-icon header-icon--cart" id="cart-toggle" aria-label="<?php echo $aki_cart_count > 0 ? sprintf( esc_attr__( 'Carrito, %d artículos', 'akibara' ), $aki_cart_count ) : esc_attr__( 'Carrito vacío', 'akibara' ); ?>">
                    <?php echo akibara_icon('cart', 22); ?>
                    <span class="header-icon__badge<?php echo $aki_cart_count > 0 ? '' : ' header-icon__badge--hidden'; ?>" id="cart-count" aria-hidden="true"><?php echo (int) $aki_cart_count; ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ===== ROW 2: Navigation ===== -->
    <nav class="mainnav" id="mainnav">
        <div class="mainnav__inner">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="mainnav__link <?php echo is_front_page() ? 'mainnav__link--active' : ''; ?>"<?php echo is_front_page() ? ' aria-current="page"' : ''; ?>>Inicio</a>

            <!-- Manga with dropdown -->
            <div class="mainnav__item mainnav__item--has-sub">
                <?php $aki_manga_active = is_product_category('manga') || is_product_category(['shonen','seinen','shojo','manhwa','josei','kodomo']); ?>
                <a href="<?php echo $manga_cat ? esc_url(get_term_link($manga_cat)) : '#'; ?>" class="mainnav__link <?php echo $aki_manga_active ? 'mainnav__link--active' : ''; ?>"<?php echo $aki_manga_active ? ' aria-current="page"' : ''; ?>>Manga</a>
                <div class="mainnav__sub">
                    <?php foreach ($manga_demos as $demo) : ?>
                        <a href="<?php echo esc_url(get_term_link($demo)); ?>" class="mainnav__sublink"><?php echo esc_html($demo->name); ?></a>
                    <?php endforeach; ?>
                    <a href="<?php echo esc_url(home_url('/serie/')); ?>" class="mainnav__sublink">Explorar por series</a>
                    <a href="<?php echo $manga_cat ? esc_url(get_term_link($manga_cat)) : '#'; ?>" class="mainnav__sublink mainnav__sublink--all">Ver todo</a>
                </div>
            </div>

            <!-- Cómics with dropdown -->
            <div class="mainnav__item mainnav__item--has-sub">
                <?php $aki_comics_active = is_product_category('comics') || is_product_category(['vertigo','independiente','dc','marvel']); ?>
                <a href="<?php echo $comics_cat ? esc_url(get_term_link($comics_cat)) : '#'; ?>" class="mainnav__link <?php echo $aki_comics_active ? 'mainnav__link--active' : ''; ?>"<?php echo $aki_comics_active ? ' aria-current="page"' : ''; ?>>Cómics</a>
                <div class="mainnav__sub">
                    <?php foreach ($comics_subs as $csub) : ?>
                        <a href="<?php echo esc_url(get_term_link($csub)); ?>" class="mainnav__sublink"><?php echo esc_html($csub->name); ?></a>
                    <?php endforeach; ?>
                    <a href="<?php echo esc_url(home_url('/serie/')); ?>" class="mainnav__sublink">Explorar por series</a>
                    <a href="<?php echo $comics_cat ? esc_url(get_term_link($comics_cat)) : '#'; ?>" class="mainnav__sublink mainnav__sublink--all">Ver todo</a>
                </div>
            </div>

            <a href="<?php echo esc_url(home_url('/serie/')); ?>" class="mainnav__link <?php echo $is_series_page ? 'mainnav__link--active' : ''; ?>"<?php echo $is_series_page ? ' aria-current="page"' : ''; ?>>Series</a>

            <!-- Preventas with dropdown -->
            <div class="mainnav__item mainnav__item--has-sub">
                <?php $aki_preventas_active = is_page('preventas') || is_product_category('preventas'); ?>
                <a href="<?php echo esc_url(home_url('/preventas/')); ?>" class="mainnav__link <?php echo $aki_preventas_active ? 'mainnav__link--active' : ''; ?>"<?php echo $aki_preventas_active ? ' aria-current="page"' : ''; ?>>Preventas</a>
                <div class="mainnav__sub">
                    <a href="<?php echo esc_url(home_url('/manga/')); ?>" class="mainnav__sublink">Manga</a>
                    <a href="<?php echo esc_url(home_url('/comics/')); ?>" class="mainnav__sublink">Cómics</a>
                    <a href="<?php echo esc_url(home_url('/encargos/')); ?>" class="mainnav__sublink">Encargar un título</a>
                    <a href="<?php echo esc_url(home_url('/preventas/')); ?>" class="mainnav__sublink mainnav__sublink--all">Ver todas</a>
                </div>
            </div>

            <?php $aki_blog_active = is_home() || ( is_single() && get_post_type() === 'post' ); ?>
            <a href="<?php echo esc_url(home_url('/blog/')); ?>" class="mainnav__link <?php echo $aki_blog_active ? 'mainnav__link--active' : ''; ?>"<?php echo $aki_blog_active ? ' aria-current="page"' : ''; ?>>Blog</a>

        </div>
    </nav>
</header>

<!-- ===== MOBILE NAV DRAWER ===== -->
<div class="mobile-overlay" id="mobile-overlay"></div>
<nav class="mobile-drawer" aria-label="Menú principal" id="mobile-drawer">
    <div class="mobile-drawer__head">
        <span class="mobile-drawer__title">Menú</span>
        <button class="mobile-drawer__close" id="mobile-close" aria-label="Cerrar menú"><?php echo akibara_icon('close', 24); ?></button>
    </div>
    <div class="mobile-drawer__search">
        <button class="searchbar__input button-search-popup" type="button" aria-label="Buscar productos" aria-haspopup="dialog" aria-expanded="false" aria-controls="akibara-pro-popup">
            <?php echo akibara_icon('search', 18); ?>
            <span class="searchbar__text">Buscar productos...</span>
        </button>
    </div>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="mobile-drawer__link">Inicio</a>
    <a href="<?php echo esc_url(home_url('/serie/')); ?>" class="mobile-drawer__link">Series</a>

    <div class="mobile-drawer__group">
        <button class="mobile-drawer__link mobile-drawer__toggle" data-target="mob-manga" aria-expanded="false" aria-controls="mob-manga">
            Manga
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div class="mobile-drawer__sub" id="mob-manga">
            <?php foreach ($manga_demos as $d) : ?>
                <a href="<?php echo esc_url(get_term_link($d)); ?>" class="mobile-drawer__sublink"><?php echo esc_html($d->name); ?></a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(home_url('/serie/')); ?>" class="mobile-drawer__sublink">Explorar por series</a>
            <a href="<?php echo $manga_cat ? esc_url(get_term_link($manga_cat)) : '#'; ?>" class="mobile-drawer__sublink mobile-drawer__sublink--accent">Ver todo Manga</a>
        </div>
    </div>

    <div class="mobile-drawer__group">
        <button class="mobile-drawer__link mobile-drawer__toggle" data-target="mob-comics" aria-expanded="false" aria-controls="mob-comics">
            Cómics
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div class="mobile-drawer__sub" id="mob-comics">
            <?php foreach ($comics_subs as $cs) : ?>
                <a href="<?php echo esc_url(get_term_link($cs)); ?>" class="mobile-drawer__sublink"><?php echo esc_html($cs->name); ?></a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(home_url('/serie/')); ?>" class="mobile-drawer__sublink">Explorar por series</a>
            <a href="<?php echo $comics_cat ? esc_url(get_term_link($comics_cat)) : '#'; ?>" class="mobile-drawer__sublink mobile-drawer__sublink--accent">Ver todo Cómics</a>
        </div>
    </div>

    <?php if ($editorial_menu) : ?>
    <div class="mobile-drawer__group">
        <button class="mobile-drawer__link mobile-drawer__toggle" data-target="mob-edit" aria-expanded="false" aria-controls="mob-edit">
            Editoriales
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div class="mobile-drawer__sub" id="mob-edit">
            <?php
            $mob_groups = [];
            foreach ($editorial_menu as $ei) {
                $mk = $ei->country ?: '__other';
                $mob_groups[$mk][] = $ei;
            }
            $mob_labels = ['AR' => 'Argentina', 'ES' => 'España'];
            foreach ($mob_labels as $mc => $ml) :
                if (empty($mob_groups[$mc])) continue;
            ?>
                <span class="mobile-drawer__sublabel"><?php echo esc_html($ml); ?></span>
                <?php foreach ($mob_groups[$mc] as $ei) : ?>
                    <a href="<?php echo esc_url($ei->url); ?>" class="mobile-drawer__sublink"><?php echo esc_html($ei->title); ?></a>
                <?php endforeach;
            endforeach;
            if (!empty($mob_groups['__other'])) :
                foreach ($mob_groups['__other'] as $ei) : ?>
                    <a href="<?php echo esc_url($ei->url); ?>" class="mobile-drawer__sublink"><?php echo esc_html($ei->title); ?></a>
                <?php endforeach;
            endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="mobile-drawer__group">
        <button class="mobile-drawer__link mobile-drawer__toggle" data-target="mob-preventas" aria-expanded="false" aria-controls="mob-preventas">
            Preventas
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div class="mobile-drawer__sub" id="mob-preventas">
            <a href="<?php echo esc_url(home_url('/manga/')); ?>" class="mobile-drawer__sublink">Manga</a>
            <a href="<?php echo esc_url(home_url('/comics/')); ?>" class="mobile-drawer__sublink">Cómics</a>
            <a href="<?php echo esc_url(home_url('/encargos/')); ?>" class="mobile-drawer__sublink">Encargar un título</a>
            <a href="<?php echo esc_url(home_url('/preventas/')); ?>" class="mobile-drawer__sublink mobile-drawer__sublink--accent">Ver todas las Preventas</a>
        </div>
    </div>

    <a href="<?php echo esc_url(home_url('/blog/')); ?>" class="mobile-drawer__link">Blog</a>
    <a href="<?php echo esc_url(home_url('/preguntas-frecuentes/')); ?>" class="mobile-drawer__link">Preguntas Frecuentes</a>

    <div class="mobile-drawer__foot">
        <a href="<?php echo esc_url(home_url('/encargos/')); ?>" class="mobile-drawer__link">
            <?php echo akibara_icon('search', 16); ?> Encargar Manga
        </a>
        <a href="<?php echo esc_url(home_url('/rastrear/')); ?>" class="mobile-drawer__link">
            <?php echo akibara_icon('truck', 16); ?> Rastrear Pedido
        </a>
        <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="mobile-drawer__link">
            <?php echo akibara_icon('user', 16); ?> Mi Cuenta
        </a>
    </div>
</nav>

<!-- ===== CART DRAWER ===== -->
<?php
// Suprimimos el drawer dentro del checkout funnel: duplica el resumen del
// pedido y expone un CTA "Pagar" alternativo que salta la validación del
// paso actual. Fuera del checkout (home, catálogo, carrito) se mantiene.
if ( class_exists( 'WooCommerce' ) && ! $akb_in_checkout_funnel ) :
?>
<div class="cart-drawer__overlay" id="cart-overlay"></div>
<aside class="cart-drawer" id="cart-drawer">
    <div class="cart-drawer__header">
        <span class="cart-drawer__title">Tu Carrito</span>
        <button class="cart-drawer__close" id="cart-close" aria-label="Cerrar carrito"><?php echo akibara_icon('close', 24); ?></button>
    </div>
    <div class="cart-drawer__items" id="cart-items">
        <?php get_template_part('template-parts/content/mini-cart'); ?>
    </div>
    <div class="cart-drawer__footer">
        <?php
        // Badge de retiro San Miguel eliminado del cart drawer (2026-04-21).
        // Motivos:
        //   - Redundante con el topbar rotativo y con el checkout paso 2.
        //   - Para usuarios fuera de RM (~60% del mercado) era excluyente.
        //   - Para usuarios RM era ruido: la progress bar ya cubre el espacio
        //     con un mensaje universal ("Te faltan $X para envío gratis").
        // El descubrimiento del retiro vive ahora solo en topbar + checkout.
        ?>
        <div class="cart-drawer__total"><span>Total</span><span id="cart-total"><?php echo akibara_cart_total(); ?></span></div>
        <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="btn btn--secondary cart-drawer__action"><span>Ver Carrito</span></a>
        <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="btn btn--primary cart-drawer__action"><span>Pagar</span></a>
    </div>
</aside>
<?php endif; ?>

<script>
/* Sprint 11 a11y fix #2 (audit 2026-04-26): aria-expanded del #menu-toggle,
 * #mobile-close y #mobile-overlay ahora lo maneja main.js openDrawer/closeDrawer
 * (con focus trap createFocusTrap + Escape + return focus a hamburger).
 * Aquí solo quedan los toggles de accordions DENTRO del drawer (disclosure
 * pattern, no abren modal — no requieren focus trap). */
(function(){
    document.querySelectorAll('.mobile-drawer__toggle').forEach(function(b){
        b.addEventListener('click',function(){
            this.setAttribute('aria-expanded',this.getAttribute('aria-expanded')==='true'?'false':'true');
        });
    });
})();
</script>
