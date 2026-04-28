<?php
/**
 * Akibara Theme Functions
 *
 * @package Akibara
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

if (!defined('AKIBARA_THEME_VERSION')) {
    define('AKIBARA_THEME_VERSION', '4.7.3');
}
define('AKIBARA_THEME_DIR', get_template_directory());
define('AKIBARA_THEME_URI', get_template_directory_uri());

// Base ?ver= para wp_enqueue_*. Espejo semántico de AKIBARA_THEME_VERSION.
// Migración gradual desde 11 sites con ver= ad-hoc (inc/enqueue.php,
// checkout-accordion.php, pack-serie.php, series-hub.php, checkout-pudo.php).
if (!defined('AKIBARA_ASSET_VER')) {
    define('AKIBARA_ASSET_VER', AKIBARA_THEME_VERSION);
}

if (!function_exists('akb_asset_ver')) {
    function akb_asset_ver($local_bump = '') {
        $base = defined('AKIBARA_ASSET_VER') ? AKIBARA_ASSET_VER : '1.0.0';
        if ($local_bump === '' || $local_bump === null) {
            return $base;
        }
        $suffix = ltrim((string) $local_bump, '.');
        return $base . '.' . $suffix;
    }
}

// Core includes
require_once AKIBARA_THEME_DIR . '/inc/setup.php';
require_once AKIBARA_THEME_DIR . '/inc/enqueue.php';
require_once AKIBARA_THEME_DIR . '/inc/series-hub.php';
require_once AKIBARA_THEME_DIR . '/inc/performance.php';

// WooCommerce integration
if (class_exists('WooCommerce')) {
    require_once AKIBARA_THEME_DIR . '/inc/woocommerce.php';
    require_once AKIBARA_THEME_DIR . '/inc/filters.php';
    require_once AKIBARA_THEME_DIR . '/inc/google-auth.php';
    require_once AKIBARA_THEME_DIR . '/inc/magic-link.php';
    require_once AKIBARA_THEME_DIR . '/inc/gallery-dedupe.php';
    require_once AKIBARA_THEME_DIR . '/inc/gallery-cleanup.php';
    require_once AKIBARA_THEME_DIR . '/inc/image-auto-trim.php';
}

// Microsoft Clarity — session recordings + heatmaps (requires AKIBARA_CLARITY_ID in wp-config).
require_once AKIBARA_THEME_DIR . '/inc/clarity.php';

// Preload hero image en la home para mejorar LCP mobile (~4.2s → ~2.2s).
require_once AKIBARA_THEME_DIR . '/inc/hero-preload.php';

// Preload featured image en single blog posts (mismo approach que hero-preload).
require_once AKIBARA_THEME_DIR . '/inc/blog-preload.php';

/**
 * AJAX: Get wishlist products by IDs
 */
add_action('wp_ajax_akibara_get_wishlist_products', 'akibara_ajax_get_wishlist_products');
add_action('wp_ajax_nopriv_akibara_get_wishlist_products', 'akibara_ajax_get_wishlist_products');

function akibara_ajax_get_wishlist_products() {
    check_ajax_referer('akibara-wishlist-nonce', 'nonce');

    $raw = sanitize_text_field( wp_unslash( $_POST['product_ids'] ?? '' ) );
    $ids = array_slice(array_filter(array_map('absint', explode(',', $raw))), 0, 50);

    if (empty($ids)) {
        wp_send_json_success(['products' => []]);
    }

    $products = [];
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if (!$product || $product->get_status() !== 'publish') continue;

        $cats = get_the_terms($id, 'product_cat');
        $category = ($cats && !is_wp_error($cats)) ? $cats[0]->name : '';

        $discount = akibara_get_discount_pct($product);

        $img_id = $product->get_image_id();
        $products[] = [
            'id'         => $id,
            'title'      => $product->get_name(),
            'url'        => get_permalink($id),
            'image'      => $img_id ? wp_get_attachment_image_url($img_id, 'product-card') : wc_placeholder_img_src('product-card'),
            'price_html' => $product->get_price_html(),
            'category'   => $category,
            'on_sale'    => $product->is_on_sale(),
            'discount'   => $discount,
            'in_stock'   => $product->is_in_stock(),
        ];
    }

    wp_send_json_success(['products' => $products]);
}

/**
 * AJAX: Add all wishlist products to cart
 */
add_action('wp_ajax_akibara_add_wishlist_to_cart', 'akibara_ajax_add_wishlist_to_cart');
add_action('wp_ajax_nopriv_akibara_add_wishlist_to_cart', 'akibara_ajax_add_wishlist_to_cart');

function akibara_ajax_add_wishlist_to_cart() {
    check_ajax_referer('akibara-wishlist-nonce', 'nonce');

    $raw = sanitize_text_field( wp_unslash( $_POST['product_ids'] ?? '' ) );
    $ids = array_slice(array_filter(array_map('absint', explode(',', $raw))), 0, 50);

    $added = 0;
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if (!$product || !$product->is_in_stock() || !$product->is_purchasable()) continue;
        if (WC()->cart->add_to_cart($id, 1)) $added++;
    }

    wp_send_json_success([
        'added'   => $added,
        'count'   => WC()->cart->get_cart_contents_count(),
        'total'   => WC()->cart->get_cart_total(),
        'message' => $added . ' producto' . ($added !== 1 ? 's' : '') . ' agregado' . ($added !== 1 ? 's' : '') . ' al carrito',
    ]);
}

// Public order tracking
require_once AKIBARA_THEME_DIR . '/inc/tracking.php';

// Encargos form handler
require_once AKIBARA_THEME_DIR . '/inc/encargos.php';

// Redirect old pedidos-especiales to encargos
add_action('template_redirect', function() {
    if (is_page('pedidos-especiales') || (isset($_SERVER['REQUEST_URI']) && strpos(sanitize_text_field($_SERVER['REQUEST_URI']), '/pedidos-especiales') !== false)) {
        wp_redirect(home_url('/encargos/'), 301);
        exit;
    }
});

// Smart features (complete series, search, 404)
require_once AKIBARA_THEME_DIR . '/inc/smart-features.php';
// Smart recommendations engine
require_once AKIBARA_THEME_DIR . "/inc/recommendations.php";

// Serie landing pages
require_once AKIBARA_THEME_DIR . '/inc/serie-landing.php';

// Cart UX Enhancements (progress, ship bar, cross-sell, sticky)
require_once AKIBARA_THEME_DIR . '/inc/cart-enhancements.php';

// Accordion checkout v2
require_once AKIBARA_THEME_DIR . '/inc/checkout-accordion.php';
// BlueX PUDO selector (retiro en punto) — reemplaza el widget feo del plugin
require_once AKIBARA_THEME_DIR . '/inc/checkout-pudo.php';
// Metro San Miguel — aviso post-pago (email + thank-you page).
require_once AKIBARA_THEME_DIR . '/inc/metro-pickup-notice.php';
require_once AKIBARA_THEME_DIR . '/inc/cloudflare-purge.php';
require_once AKIBARA_THEME_DIR . '/inc/google-business-schema.php';

// Enhanced filters (chips, badges, styling)
require_once AKIBARA_THEME_DIR . '/inc/filters-enhanced.php';

require_once AKIBARA_THEME_DIR . '/inc/seo.php';

// Blog enhancements (reading time, share buttons, TOC)
require_once AKIBARA_THEME_DIR . "/inc/blog.php";

// Blog WebP: genera .webp paralelo para sizes del blog y sirve en src/srcset
require_once AKIBARA_THEME_DIR . "/inc/blog-webp.php";

// BACS bank transfer details (RUT, tipo cuenta, email)
require_once AKIBARA_THEME_DIR . '/inc/bacs-details.php';

// Footer newsletter (Brevo)
require_once AKIBARA_THEME_DIR . '/inc/newsletter.php';

// Shortcode: [akibara_editoriales_grid] for /editoriales/ page
require_once AKIBARA_THEME_DIR . '/inc/shortcode-editoriales.php';

// Pack add-to-cart endpoint (dedicated, separate from wishlist)
add_action( 'wp_ajax_akibara_add_pack_to_cart', 'akibara_ajax_add_pack_to_cart' );
add_action( 'wp_ajax_nopriv_akibara_add_pack_to_cart', 'akibara_ajax_add_pack_to_cart' );

function akibara_ajax_add_pack_to_cart() {
    check_ajax_referer( 'akibara-pack-nonce', 'nonce' );

    $max_items = 50;

    $raw = sanitize_text_field( wp_unslash( $_POST['product_ids'] ?? '' ) );
    if ( is_array( $_POST['product_ids'] ?? null ) ) {
        $ids = array_slice( array_filter( array_map( 'absint', wp_unslash( $_POST['product_ids'] ) ) ), 0, $max_items );
    } else {
        $ids = array_slice( array_filter( array_map( 'absint', explode( ',', $raw ) ) ), 0, $max_items );
    }

    if ( empty( $ids ) ) {
        wp_send_json_error( [ 'message' => 'No se proporcionaron productos.' ] );
    }

    $added   = 0;
    $skipped = 0;
    $cart_item_keys = array_column( WC()->cart->get_cart(), 'product_id' );

    foreach ( $ids as $id ) {
        $product = wc_get_product( $id );
        if ( ! $product || ! $product->is_in_stock() || ! $product->is_purchasable() ) {
            continue;
        }
        if ( in_array( $id, $cart_item_keys, true ) ) {
            $skipped++;
            continue;
        }
        if ( WC()->cart->add_to_cart( $id, 1 ) ) {
            $added++;
        }
    }

    wp_send_json_success( [
        'added'   => $added,
        'skipped' => $skipped,
        'count'   => WC()->cart->get_cart_contents_count(),
        'total'   => WC()->cart->get_cart_total(),
        'message' => $added > 0
            ? $added . ' producto' . ( $added !== 1 ? 's' : '' ) . ' agregado' . ( $added !== 1 ? 's' : '' ) . ' al carrito'
            : ( $skipped > 0 ? 'Todos los productos ya estan en el carrito' : 'No se pudo agregar productos' ),
    ] );
}

// Pack Serie CTA (volume bundle in single product)
require_once AKIBARA_THEME_DIR . "/inc/pack-serie.php";

// Reverse interlinking: surface blog post on product page when serie matches
require_once AKIBARA_THEME_DIR . "/inc/blog-cta-product.php";

// Legacy URL 301 redirects (recover SEO juice from GSC 404 patterns)
require_once AKIBARA_THEME_DIR . "/inc/legacy-redirects.php";

// Forward widget: list in-stock products at end of blog posts about a series
require_once AKIBARA_THEME_DIR . "/inc/blog-product-cta.php";

// IndexNow vive en wp-content/mu-plugins/akibara-indexnow.php
// (mu-plugin garantiza que se cargue tambien en cron/CLI/REST, contextos
// donde el theme no se inicializa).

// Include Product Schema for SEO
require_once get_template_directory() . '/inc/product-schema.php';

// Include Sitemap and Indexing Enhancements for SEO
require_once get_template_directory() . '/inc/sitemap-indexing.php';

// Safety net: suppress any residual plugin newsletter render (consolidated into theme inc/newsletter.php)
add_action("init", function() {
    remove_action("akibara_footer_brand_after", "akibara_footer_signup_render");
}, 20);

require_once get_template_directory() . '/inc/health.php';

require_once get_template_directory() . '/inc/bluex-webhook.php';

// Set --card-index for stagger animation (calc-based)
add_action( 'wp_footer', function (): void {
    ?>
    <script>
    document.querySelectorAll('.product-grid').forEach(function(grid) {
        grid.querySelectorAll('.product-card').forEach(function(card, i) {
            card.style.setProperty('--card-index', i);
        });
    });
    document.querySelectorAll('.blog-grid,.posts-grid').forEach(function(grid) {
        grid.querySelectorAll('.blog-card').forEach(function(card, i) {
            card.style.setProperty('--card-index', i);
        });
    });
    </script>
    <?php
}, 99 );

require_once get_template_directory() . '/inc/rest-cart.php';

// Admin branding: login screen + admin footer.
// Los hooks internos solo disparan en wp-login.php o wp-admin/*, no en el frontend.
require_once AKIBARA_THEME_DIR . '/inc/admin.php';

/* ── SVG Upload Support (admin only, with sanitization) ── */
add_filter( 'upload_mimes', function( $mimes ) {
    if ( current_user_can( 'manage_options' ) ) {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';
    }
    return $mimes;
} );

add_filter( 'wp_check_filetype_and_ext', function( $data, $file, $filename, $mimes ) {
    $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    if ( 'svg' === $ext && current_user_can( 'manage_options' ) ) {
        $data['ext']             = 'svg';
        $data['type']            = 'image/svg+xml';
        $data['proper_filename'] = $filename;
    }
    return $data;
}, 10, 4 );

/* ── Twitter Card: large image for better social sharing ── */
add_filter( 'rank_math/opengraph/twitter/twitter:card', function() {
    return 'summary_large_image';
} );

/* ── Web App Manifest for PWA installability ── */
add_action( 'wp_head', function() {
    echo '<link rel="manifest" href="/manifest.json" crossorigin="use-credentials">' . "\n";
}, 1 );

/* ── Google Search Console verification (alejandro.fvaras@gmail.com property) ── */
add_action( 'wp_head', function() {
    echo '<meta name="google-site-verification" content="hQCZgQLnCwcQr1GIfs6J-vEfqDd6Pr4VXpYE5aGUuJo" />' . "\n";
}, 1 );

/* ── hreflang: emitido desde inc/seo/canonical.php (single source of truth) ── */
