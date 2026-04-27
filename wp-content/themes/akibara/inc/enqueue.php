<?php
/**
 * Enqueue Scripts & Styles
 *
 * @package Akibara
 * @version 1.1.0 — Performance optimized
 */

defined('ABSPATH') || exit;

add_action('wp_enqueue_scripts', function () {
    // Bumped .118 — feat(a11y): Sprint 11 #3 search popup focus trap.
    // main.js expone window.akibaraCreateFocusTrap (utility ya existente
    // en el IIFE para drawer/cart/account/lightbox). El plugin akibara
    // (akibara-search.php inline script wp_footer) lo consume para activar
    // focus trap en #akibara-pro-popup + return focus al trigger clickeado
    // al cerrar (Esc, click overlay/X). Antes el popup tenía aria-modal="true"
    // pero Tab podía salir del modal y al cerrar el foco se perdía al body.
    // Bumped .117 — feat(filters-a11y): Sprint 7 D5.
    // D5: filters.php "Ver X más" button suma aria-expanded + aria-controls;
    // filters-ajax.js sincroniza aria-expanded al toggle + focus al primer
    // .filter-check--overflow al expandir. Bug colateral fix: lógica
    // newExpanded estaba invertida ("Ver menos" cuando contraído).
    // Bumped .116 — feat(a11y Round 3): Sprint 6/7 items A6 + A7 + B8.
    // A6: checkout-pudo.js suma Escape handler + role=dialog/aria-modal
    // sobre #aki-pudo-iframe-wrap al abrir el panel PUDO. Sin esto, el
    // selector BlueX (iframe cross-origin) atrapaba al usuario keyboard
    // y screen reader sin vía de cierre.
    // A7: checkout-sticky-mobile.js setea aria-label dinámico per-step
    // ("Continuar a envío" / "Continuar a pago" / "Realizar pedido")
    // sincronizado vía updated_checkout + MutationObserver del
    // .aki-step--active. Antes anunciaba "Continuar" sin contexto en
    // los 3 pasos.
    // B8: header.php agrega aria-label="Akibara — Inicio" al <a> del
    // logo para contextualizar la navegación a home.
    // Bumped .115 — feat(checkout-a11y): Sprint 6 Cola B items A4 + A21.
    // A4: filter PHP woocommerce_form_field inyecta <p class="checkout-field-hint">
    // role="alert" + aria-live="polite" después de cada input/select/textarea
    // del checkout, y agrega aria-describedby="{id}_description" al control.
    // Nuevo checkout-validation.js popule el container al focusout/change/input
    // con mensajes en español neutro. Cierra gap entre WC core (que solo marca
    // aria-invalid sin texto) y screen readers (que necesitan texto).
    // A21: progress bar <div> → <ol> + cada step a <li> con aria-current="step"
    // en el activo. Líneas decorativas son <li aria-hidden="true">. JS sincroniza
    // aria-current al avanzar/retroceder de paso. CSS suma list-style:none.
    // Bumped .114 — fix(theme): Sprint 6 Cola B items A2 + I14 + I15.
    // A2: --aki-muted/--aki-light eran usados en checkout.css pero nunca
    // definidos en :root del design-system. Aliased ambos.
    // I14: .product-card__add-to-cart:hover envuelto en @media (hover: hover)
    // para evitar sticky-hover en mobile.
    // I15: box-shadow del CTA hover reducido (4px 12px → 2px 6px) para no ser
    // clipeado por overflow:hidden del .product-card parent.
    $ver = akb_asset_ver( '118' );

    // Self-hosted fonts — no external render-blocking request
    wp_enqueue_style('akibara-fonts', AKIBARA_THEME_URI . '/assets/css/fonts.css', [], $ver);

    // Critical CSS — sync-loaded, prevents drawer/overlay FOUC before layout.css applies.
    // MUST be excluded from the lazy-load preload filter (see inc/performance.php).
    wp_enqueue_style('akibara-critical', AKIBARA_THEME_URI . '/assets/css/critical.css', [], $ver);

    // Design system
    wp_enqueue_style('akibara-design', AKIBARA_THEME_URI . '/assets/css/design-system.css', ['akibara-critical'], $ver);
    wp_enqueue_style('akibara-header', AKIBARA_THEME_URI . '/assets/css/header-v2.css', ['akibara-design'], $ver);
    wp_enqueue_style('akibara-layout', AKIBARA_THEME_URI . '/assets/css/layout-v2.css', ['akibara-design'], $ver);

    // WooCommerce styles — only on shop/product/category pages
    if (class_exists('WooCommerce') && (is_woocommerce() || is_cart() || is_checkout() || is_account_page() || is_front_page())) {
        wp_enqueue_style('akibara-wc', AKIBARA_THEME_URI . '/assets/css/woocommerce.css', ['akibara-layout'], $ver);
    }

    // Pages (internal content pages + blog)
    if (!is_front_page() && !is_woocommerce() && !is_product()) {
        wp_enqueue_style("akibara-pages-v2", AKIBARA_THEME_URI . "/assets/css/pages.css", ["akibara-layout"], $ver);
    }

    // Search dark theme override — only on search pages
    if (is_search()) {
        wp_enqueue_style("akibara-search-dark", AKIBARA_THEME_URI . "/assets/css/search-dark.css", ["akibara-design"], $ver);
    }

    // Custom pages (tracking + encargos)
    if (is_page(array("rastrear", "encargos", "tracking", "pedidos-especiales"))) {
        wp_enqueue_style("akibara-custom-pages", AKIBARA_THEME_URI . "/assets/css/pages-custom.css", ["akibara-layout"], $ver);
    }

    // Series templates (custom rewrite pages — is_page_template() won't match)
    if ( get_query_var( 'akibara_serie' ) || get_query_var( 'akibara_serie_index' ) ) {
        wp_enqueue_style('akibara-series', AKIBARA_THEME_URI . '/assets/css/series.css', ['akibara-layout'], $ver);
    }

    // Responsive
    wp_enqueue_style('akibara-responsive', AKIBARA_THEME_URI . '/assets/css/responsive-v2.css', ['akibara-layout'], $ver);

    // Branding v1 — trust section + campaign banner countdown
    wp_enqueue_style('akibara-branding-v1', AKIBARA_THEME_URI . '/assets/css/branding-v1.css', ['akibara-design'], $ver);

    // Homepage specific
    if (is_front_page()) {
        wp_enqueue_style('akibara-home', AKIBARA_THEME_URI . '/assets/css/homepage.css', ['akibara-layout'], $ver);
        wp_enqueue_style('akibara-hero', AKIBARA_THEME_URI . '/assets/css/hero-section.css', ['akibara-layout'], $ver);
        wp_enqueue_script('akibara-hero', AKIBARA_THEME_URI . '/assets/js/hero-section.js', [], $ver, true);
    }

    // Checkout CSS — ONLY on checkout page (was loading on all WC pages)
    if (class_exists('WooCommerce') && (is_checkout() || is_cart())) {
        // filemtime() para cache-bust inmediato durante iteración visual del checkout.
        $co_path = AKIBARA_THEME_DIR . '/assets/css/checkout.css';
        $co_ver  = file_exists($co_path) ? (string) filemtime($co_path) : $ver;
        wp_enqueue_style('akibara-checkout', AKIBARA_THEME_URI . '/assets/css/checkout.css', ['akibara-wc'], $co_ver);
    }

    // Main JS — deferred
    wp_enqueue_script('akibara-main', AKIBARA_THEME_URI . '/assets/js/main.js', [], $ver, true);

    // Cart page enhancements (sticky sync, save-later state)
    if ( is_cart() ) {
        wp_enqueue_script('akibara-cart-page', AKIBARA_THEME_URI . '/assets/js/cart-page.js', ['akibara-main', 'akibara-cart'], $ver, true);
    }

    // Account / Auth CSS+JS (login, register)
    if (is_account_page() || is_wc_endpoint_url()) {
        wp_enqueue_style('akibara-account', AKIBARA_THEME_URI . '/assets/css/account.css', ['akibara-design'], $ver);
        wp_enqueue_script('akibara-account', AKIBARA_THEME_URI . '/assets/js/account.js', ['akibara-main'], $ver, true);
        wp_localize_script('akibara-account', 'akibaraMagic', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('akibara-magic-link'),
        ]);
    }

    // Cart JS — only when needed
    if (class_exists('WooCommerce')) {
        wp_enqueue_script('akibara-cart', AKIBARA_THEME_URI . '/assets/js/cart.js', ['akibara-main'], $ver, true);
        wp_localize_script('akibara-cart', 'akibaraCart', [
            'ajaxUrl'  => WC_AJAX::get_endpoint('%%endpoint%%'),
            'nonce'    => wp_create_nonce('akibara-cart-nonce'),
            'restUrl'  => esc_url_raw( rest_url( 'akibara/v1/cart/' ) ),
            'restNonce' => wp_create_nonce('wp_rest'),
            'cartUrl'  => wc_get_cart_url(),
            'checkUrl'    => wc_get_checkout_url(),
            'notifyNonce' => wp_create_nonce('akibara-notify-stock'),
        ]);
    }

    // Single product
    if (is_product()) {
        wp_enqueue_script('akibara-product', AKIBARA_THEME_URI . '/assets/js/product.js', ['akibara-main'], $ver, true);
        wp_enqueue_style('akibara-product-info', AKIBARA_THEME_URI . '/assets/css/product-info.css', ['akibara-wc'], $ver);
    }

    // AJAX Filters v3
    if (is_shop() || is_product_category() || is_product_tag() || is_tax('product_brand') || is_search()) {
        wp_enqueue_script("akibara-filters", AKIBARA_THEME_URI . "/assets/js/filters-ajax.js", [], $ver, true);
        wp_localize_script("akibara-filters", "akibaraFilters", [
            "ajaxUrl" => admin_url("admin-ajax.php"),
        ]);
        wp_enqueue_style("akibara-filters-v3", AKIBARA_THEME_URI . "/assets/css/filters-v3.css", [], $ver);
    }

    // Wishlist (localStorage-based favorites)
    wp_enqueue_script('akibara-wishlist', AKIBARA_THEME_URI . '/assets/js/wishlist.js', ['akibara-main'], $ver, true);
    wp_localize_script('akibara-wishlist', 'akibaraWishlist', [
        'ajaxUrl'   => WC_AJAX::get_endpoint('%%endpoint%%'),
        'nonce'     => wp_create_nonce('akibara-wishlist-nonce'),
        'cartNonce' => wp_create_nonce('akibara-cart-nonce'),
    ]);

    // Campaign countdown — solo si hay campañas activas con banner_text.
    // Usa el check lightweight (no renderiza el HTML, solo consulta las reglas).
    if ( function_exists( 'akibara_descuento_banner_is_active' ) && akibara_descuento_banner_is_active() ) {
        wp_enqueue_script(
            'akibara-campaign-countdown',
            AKIBARA_THEME_URI . '/assets/js/campaign-countdown.js',
            [],
            $ver,
            true
        );
    }
});

// Preload self-hosted fonts (critical for LCP)
add_action('wp_head', function () {
    $uri = AKIBARA_THEME_URI . '/assets/fonts';
    echo '<link rel="preload" href="' . $uri . '/bebas-neue-v14-latin-regular.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
    echo '<link rel="preload" href="' . $uri . '/inter-v18-latin-regular.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
}, 1);

// TAP TARGETS + FILTER DRAWER — Inline CSS fuera del pipeline de LiteSpeed.
// LiteSpeed optimizer stripea transform:translateX(0), display:inline-flex y min-height de archivos externos.
add_action( 'wp_head', function () {
    if ( ! ( is_shop() || is_product_category() || is_product_tag() || is_tax( 'product_brand' ) || is_search() ) ) return;
    // Drawer: posición y tamaño via !important en inline style.
    // left/transition los maneja filters-v3.css (normal, sin !important) para que el cascade funcione.
    echo '<style id="akb-tap-targets">'
        . '.filter-toggle{min-height:44px}'
        . '.category-pill{display:inline-flex;align-items:center;min-height:44px}'
        . '@media(max-width:768px){'
        // Drawer layout: fixed fullscreen, off-screen por default.
        // Specificity: ID selector + !important — tiene que ganarle a cualquier
        // cosa que LiteSpeed CSS combine pueda reordenar.
        .   '#shop-sidebar{position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100dvh!important;max-height:none!important;z-index:9999!important;transform:translateX(100%)!important;transition:transform .28s cubic-bezier(.32,.72,0,1)!important;background:#141414!important}'
        // Estado abierto: slide-in. Mismo selector ID evita que .shop-sidebar.open
        // (specificity 0,0,2,0) pierda contra #shop-sidebar (0,1,0,0).
        .   '#shop-sidebar.open{transform:translateX(0)!important}'
        // Overlay: justo debajo del drawer, encima del resto.
        .   '.filter-sheet-overlay{z-index:9998!important}'
        // FAB WhatsApp: display:none es más fuerte que opacity y overrides
        // cualquier opacity:1 que .akibara-wa__btn.is-visible pueda tener.
        .   'body.filter-drawer-open .akibara-wa,body.filter-drawer-open .akibara-wa__btn{display:none!important;opacity:0!important;pointer-events:none!important;visibility:hidden!important}'
        // Site header + bottom nav: ocultar cuando drawer abierto. iOS Safari crea
        // stacking contexts inesperados cuando <html> tiene overflow:clip visible
        // y un ancestor del header tiene position:relative+z-index — ambos aparecen
        // VISUALMENTE encima del drawer pese a que drawer z-index 9999 > 200.
        // Fix: removerlos con display:none cuando filter-drawer-open.
        .   'body.filter-drawer-open .site-header,body.filter-drawer-open #site-header,body.filter-drawer-open .bottom-nav,body.filter-drawer-open #bottom-nav{display:none!important;visibility:hidden!important}'
        // Contraste toggles (Solo en stock / Pre-venta): el user reporta low-contrast.
        // Labels #E5E5E5 sobre #141414 = 13.2:1 (WCAG AAA). Switch bg visible aun en off.
        .   '#shop-sidebar .filter-toggle-link,#shop-sidebar .filter-toggle-link *{color:#E5E5E5!important}'
        .   '#shop-sidebar .filter-toggle-switch{background:#3A3A3A!important;border:1px solid #6A6A6A!important}'
        .   '#shop-sidebar .filter-toggle-switch--on{background:#D90010!important;border-color:#D90010!important}'
        . '}'
        . '</style>' . "\n";
}, 5 );

// Preload hero images — consolidado en `inc/hero-preload.php` usando
// `imagesrcset` moderno (cubre mobile/tablet/desktop en un solo link en vez
// de 2 con `media=`, y evita duplicación de descargas).

// Optimize asset loading for SEO and page speed
add_filter( 'script_loader_tag', 'akibara_optimize_scripts', 10, 3 );
function akibara_optimize_scripts( $tag, $handle, $src ) {
    // Defer non-critical scripts
    $defer_scripts = array( 'akibara-main', 'akibara-cart' );
    if ( in_array( $handle, $defer_scripts ) && strpos( $tag, 'defer' ) === false ) {
        $tag = str_replace( '<script ', '<script defer ', $tag );
    }
    return $tag;
}

// DISABLED 2026-04-25 (incident CSS roto prod): el patrón
// `<link rel="preload" as="style" onload="this.rel='stylesheet'">` requiere JS
// para promover el preload a stylesheet. Si Cloudflare cachea HTML cuando los
// assets combined no existen aún, los CSS quedan en estado "preload" sin aplicar
// estilos → página renderea sin estilos críticos (fonts, design-system, header,
// layout, responsive, search-dark). Causa raíz: drift entre HTML cacheado y
// CSS combined regenerado por LSCWP.
//
// Tradeoff: perdemos optimización LCP marginal (~50-100ms) en el primer load,
// pero ganamos resilencia ante cualquier caching layer (CF, LSWS, browser).
// El navegador moderno ya hace render-blocking optimizado para `<link rel="stylesheet">`
// con HTTP/2 multiplexing — la diferencia real es casi imperceptible.
//
// Si en el futuro queremos re-habilitar: usar `media="print" onload="this.media='all'"`
// pattern (más robusto, no requiere JS para fallback), o fontDisplay=swap solo para fonts.
//
// add_filter( 'style_loader_tag', 'akibara_optimize_styles', 10, 4 );
function akibara_optimize_styles( $html, $handle, $href, $media ) {
    // Preserved for reference — no longer registered.
    $preload_styles = array( 'akibara-fonts', 'akibara-design', 'akibara-header', 'akibara-layout', 'akibara-responsive', 'akibara-search-dark' );
    if ( in_array( $handle, $preload_styles ) ) {
        $html = str_replace( "rel='stylesheet'", "rel='preload' as='style' onload=\"this.rel='stylesheet'\"", $html );
    }
    return $html;
}

// ─── Agregar defer a scripts propios no críticos ─────────────────
add_filter( 'script_loader_tag', function ( string $tag, string $handle ): string {
    // Solo defer a scripts propios de Akibara (allowlist)
    $defer_handles = [
        'akibara-hero',
        'akibara-product',
        'akibara-wishlist',
        'akibara-filters',
        'akibara-account',
        'akibara-cart-page',
        'akb-gtag',
    ];
    if ( ! in_array( $handle, $defer_handles, true ) ) return $tag;

    // No duplicar defer
    if ( strpos( $tag, 'defer' ) !== false ) return $tag;

    return str_replace( ' src=', ' defer src=', $tag );
}, 10, 2 );
