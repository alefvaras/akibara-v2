<?php
/**
 * Theme Setup
 *
 * @package Akibara
 */

defined('ABSPATH') || exit;

add_action('after_setup_theme', function () {
    // Text domain
    load_theme_textdomain('akibara', AKIBARA_THEME_DIR . '/languages');

    // Theme support
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo', [
        'height'      => 80,
        'width'       => 300,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
    add_theme_support('woocommerce');

    // Image sizes.
    // product-card: 400x600 con crop=false (WP hace fit, no hard-crop).
    // El pipeline del módulo image-normalize fue removido 2026-04-20; WP ahora
    // genera estos subsizes con su algoritmo default al subir nuevas imágenes.
    add_image_size('product-card', 400, 600, false);
    add_image_size('hero-banner', 1600, 600, true);
    // Blog: sizes dedicados para evitar que WP sirva medium_large/large sobredimensionados.
    // crop=false para respetar aspect ratio original de la foto editorial.
    add_image_size('blog-card', 420, 0, false);      // home.php grid (render ~380px)
    add_image_size('blog-featured', 960, 0, false);  // single.php featured (render ~860px)
    add_image_size('blog-related', 260, 0, false);   // single.php related (render ~220px)

    // Menus
    register_nav_menus([
        'primary'    => __('Navegación Principal', 'akibara'),
        'categories' => __('Categorías', 'akibara'),
        'footer'     => __('Footer', 'akibara'),
    ]);
});

/**
 * Custom walker for main navigation
 */
class Akibara_Nav_Walker extends Walker_Nav_Menu {
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
        $classes = ['nav-main__item'];
        $li_class = implode(' ', $classes);

        $link_classes = ['nav-main__link'];
        if (in_array('current-menu-item', $item->classes)) {
            $link_classes[] = 'nav-main__link--active';
        }

        $output .= '<li class="' . esc_attr($li_class) . '">';
        $output .= '<a class="' . esc_attr(implode(' ', $link_classes)) . '" href="' . esc_url($item->url) . '">';
        $output .= esc_html($item->title);
        $output .= '</a>';
    }
}

/**
 * Widget areas
 */
add_action('widgets_init', function () {
    register_sidebar([
        'name'          => __('Shop Sidebar', 'akibara'),
        'id'            => 'shop-sidebar',
        'before_widget' => '<div id="%1$s" class="sidebar-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="sidebar-widget__title">',
        'after_title'   => '</h3>',
    ]);
});

/**
 * Customizer: Akibara Homepage settings
 */
add_action('customize_register', function ($wp_customize) {
    $wp_customize->add_section('akibara_homepage', [
        'title'    => 'Akibara Homepage',
        'priority' => 30,
    ]);

    $wp_customize->add_setting('akibara_hero_image', [
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ]);

    $wp_customize->add_control(new WP_Customize_Image_Control($wp_customize, 'akibara_hero_image', [
        'label'   => 'Imagen Hero Homepage',
        'section' => 'akibara_homepage',
    ]));
});

/**
 * Get SVG icon inline
 */
function akibara_icon($name, $size = 20) {
    $size = absint($size);
    $icons = [
        'search'   => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>',
        'cart'     => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>',
        'user'     => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'menu'     => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12h18M3 6h18M3 18h18"/></svg>',
        'close'    => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'arrow'    => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>',
        'plus'     => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
        'minus'    => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14"/></svg>',
        'whatsapp' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>',
        'instagram' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
        'facebook' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>',

        'tiktok'   => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.88-2.88 2.89 2.89 0 012.88-2.88c.28 0 .56.04.81.12v-3.5a6.37 6.37 0 00-.81-.05A6.34 6.34 0 003.15 15.3a6.34 6.34 0 0010.86 4.48v-7.1a8.16 8.16 0 005.58 2.2v-3.45a4.85 4.85 0 01-3.77-1.51z"/></svg>',
        'filter'   => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>',
        'grid'     => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
        'fire'     => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>',
        'star'     => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'truck'    => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'shield'   => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'tag'      => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        'calendar' => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'package'  => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    ];

    return $icons[$name] ?? '';
}

/**
 * Get cart count
 */
function akibara_cart_count() {
    if (function_exists('WC') && WC()->cart) {
        return WC()->cart->get_cart_contents_count();
    }
    return 0;
}

/**
 * Get cart total
 */
function akibara_cart_total() {
    if (function_exists('WC') && WC()->cart) {
        return WC()->cart->get_cart_total();
    }
    return '$0';
}

/**
 * Get product discount percentage string (e.g., '-15%').
 *
 * Wrapper defensivo — la implementación vive en el plugin Akibara
 * (modules/product-badges/module.php). Si el plugin está desactivado,
 * devolvemos string vacío en lugar de fatal error.
 */
function akibara_get_discount_pct($product) {
    if ( function_exists( 'akb_plugin_get_discount_pct' ) ) {
        return akb_plugin_get_discount_pct( $product );
    }
    return '';
}

/**
 * Render product badges HTML.
 *
 * Wrapper defensivo — la implementación vive en el plugin Akibara
 * (modules/product-badges/module.php). Si el plugin está desactivado,
 * no se renderizan badges (degradación silenciosa) en lugar de fatal.
 */
function akibara_render_badges( $product ) {
    if ( function_exists( 'akb_plugin_render_badges' ) ) {
        return akb_plugin_render_badges( $product );
    }
    // Fallback degradado: si el plugin está desactivado, no render badges en vez de fatal.
    return;
}

// Hero link Customizer setting
add_action('customize_register', function ($wp_customize) {
    $wp_customize->add_setting('akibara_hero_link', [
        'default'           => '',
        'sanitize_callback' => 'esc_url_raw',
    ]);
    $wp_customize->add_control('akibara_hero_link', [
        'label'   => 'Link del Hero Banner',
        'section' => 'akibara_homepage',
        'type'    => 'url',
        'description' => 'URL a donde lleva el banner hero. Dejar vacio para ir a la tienda.',
    ]);
});


// Add no-js class removal (for noscript fallback)
add_action( 'wp_head', function() {
    echo '<script>document.documentElement.classList.remove("no-js");document.documentElement.classList.add("js");</script>' . "\n";
}, 1 );
