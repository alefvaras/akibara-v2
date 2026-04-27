<?php
defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════
// META DESCRIPTION — For pages Rank Math doesn't handle
// ═══════════════════════════════════════════════════════════════
add_action('wp_head', function () {
    // Skip if Rank Math handles it
    if (defined('RANK_MATH_VERSION')) return;

    $desc = '';

    if (is_front_page() || is_home()) {
        // Dynamic editorial names from product_brand
        $brands = get_terms(['taxonomy'=>'product_brand','hide_empty'=>true,'orderby'=>'count','order'=>'DESC','number'=>5]);
        $brand_str = '';
        if (!is_wp_error($brands) && !empty($brands)) {
            $names = wp_list_pluck($brands, 'name');
            $brand_str = ' de ' . implode(', ', array_slice($names, 0, -1)) . ' y ' . end($names);
        }
        $desc = 'Akibara — Tu Distrito del Manga en Chile. Compra manga y cómics originales' . $brand_str . ' con envío a todo Chile, 3 cuotas sin interés.';
    } elseif (is_shop()) {
        $desc = 'Tienda de manga y cómics online. Encuentra tus series favoritas con envío a todo Chile. Novedades, preventas y ofertas exclusivas.';
    } elseif (is_product_category()) {
        $term = get_queried_object();
        if ($term) {
            $desc = $term->description ?: 'Compra ' . $term->name . ' online en Akibara. Catálogo completo con envío a todo Chile.';
            $desc = wp_strip_all_tags($desc);
            $desc = mb_substr($desc, 0, 160);
        }
    } elseif (is_tax('product_brand')) {
        $term = get_queried_object();
        if ($term) {
            $desc = 'Compra manga y cómics de ' . $term->name . ' en Chile. Envío a todo el país. ' . $term->count . ' títulos disponibles en Akibara.';
        }
    } elseif (is_product()) {
        $product = wc_get_product(get_the_ID());
        if ($product) {
            $desc = wp_strip_all_tags($product->get_short_description() ?: $product->get_description());
            $desc = mb_substr($desc, 0, 160);
        }
    } elseif (is_singular('post')) {
        $post_id = get_the_ID();
        if (has_excerpt($post_id)) {
            $desc = wp_strip_all_tags(get_the_excerpt($post_id));
        } else {
            $desc = wp_strip_all_tags(get_post_field('post_content', $post_id));
        }
        $desc = trim(preg_replace('/\s+/', ' ', $desc));
        $desc = mb_substr($desc, 0, 160);
    }

    if ($desc) {
        echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
    }
}, 2);

// ═══════════════════════════════════════════════════════════════
// REL NEXT / PREV — Pagination SEO hints
// ═══════════════════════════════════════════════════════════════
add_action('wp_head', function () {
    // Skip if Rank Math handles rel next/prev
    if (defined('RANK_MATH_VERSION')) return;

    if (!is_archive() && !is_home() && !is_shop()) return;

    global $wp_query;
    $total  = (int) $wp_query->max_num_pages;
    $paged  = max(1, (int) get_query_var('paged'));

    if ($total <= 1) return;

    if ($paged > 1) {
        printf('<link rel="prev" href="%s" />' . "\n", esc_url(get_pagenum_link($paged - 1)));
    }
    if ($paged < $total) {
        printf('<link rel="next" href="%s" />' . "\n", esc_url(get_pagenum_link($paged + 1)));
    }
}, 5);

// ═══════════════════════════════════════════════════════════════
// OPEN GRAPH + TWITTER CARD — Social sharing meta
// ═══════════════════════════════════════════════════════════════
add_action('wp_head', function () {
    if (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION')) return;

    if (is_product()) {
        $product = wc_get_product(get_the_ID());
        if (!$product) return;

        $image = wp_get_attachment_url($product->get_image_id());
        $desc  = wp_strip_all_tags($product->get_short_description() ?: $product->get_description());
        $desc  = mb_substr($desc, 0, 160);

        echo '<meta property="og:type" content="product" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($product->get_name()) . ' — Akibara" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink()) . '" />' . "\n";
        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '" />' . "\n";
        }
        echo '<meta property="product:price:amount" content="' . esc_attr($product->get_price()) . '" />' . "\n";
        echo '<meta property="product:price:currency" content="CLP" />' . "\n";

        // Twitter Card
        if ($image) {
            echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
            echo '<meta name="twitter:title" content="' . esc_attr($product->get_name()) . ' — Akibara" />' . "\n";
            echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
            echo '<meta name="twitter:image" content="' . esc_url($image) . '" />' . "\n";
        }

    } elseif (is_shop() || is_product_category() || is_product_taxonomy()) {
        $title = is_shop() ? 'Tienda' : single_term_title('', false);
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . ' — Akibara" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_pagenum_link(1)) . '" />' . "\n";
        $logo = get_theme_mod('custom_logo');
        if ($logo) {
            echo '<meta property="og:image" content="' . esc_url(wp_get_attachment_url($logo)) . '" />' . "\n";
        }

    } elseif (is_front_page()) {
        echo '<meta property="og:type" content="website" />' . "\n";
        echo '<meta property="og:title" content="Akibara — Tu Distrito del Manga" />' . "\n";
        echo '<meta property="og:description" content="Compra manga, cómics y novelas gráficas originales con envío a todo Chile." />' . "\n";
        echo '<meta property="og:url" content="' . esc_url(home_url('/')) . '" />' . "\n";
        $logo = get_theme_mod('custom_logo');
        if ($logo) {
            echo '<meta property="og:image" content="' . esc_url(wp_get_attachment_url($logo)) . '" />' . "\n";
        }

    } elseif (is_singular('post')) {
        $post_id  = get_the_ID();
        $thumb_id = get_post_thumbnail_id($post_id);
        $img_url  = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'full') : '';
        if (!$img_url) {
            $logo_id = get_theme_mod('custom_logo');
            $img_url = $logo_id ? wp_get_attachment_url($logo_id) : '';
        }

        if (has_excerpt($post_id)) {
            $post_desc = wp_strip_all_tags(get_the_excerpt($post_id));
        } else {
            $post_desc = wp_strip_all_tags(get_post_field('post_content', $post_id));
        }
        $post_desc = mb_substr(trim(preg_replace('/\s+/', ' ', $post_desc)), 0, 160);

        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr(get_the_title($post_id)) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($post_desc) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink($post_id)) . '" />' . "\n";
        if ($img_url) {
            echo '<meta property="og:image" content="' . esc_url($img_url) . '" />' . "\n";
        }
        echo '<meta property="article:published_time" content="' . esc_attr(get_the_date('c', $post_id)) . '" />' . "\n";
        echo '<meta property="article:modified_time" content="' . esc_attr(get_the_modified_date('c', $post_id)) . '" />' . "\n";
        $cats_og = get_the_category($post_id);
        if ($cats_og) {
            foreach ($cats_og as $c) {
                if ((int) $c->term_id !== 1) {
                    echo '<meta property="article:section" content="' . esc_attr($c->name) . '" />' . "\n";
                    break;
                }
            }
        }
        $tags_og = get_the_tags($post_id);
        if ($tags_og) {
            foreach ($tags_og as $t) {
                echo '<meta property="article:tag" content="' . esc_attr($t->name) . '" />' . "\n";
            }
        }

        if ($img_url) {
            echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
            echo '<meta name="twitter:title" content="' . esc_attr(get_the_title($post_id)) . '" />' . "\n";
            echo '<meta name="twitter:description" content="' . esc_attr($post_desc) . '" />' . "\n";
            echo '<meta name="twitter:image" content="' . esc_url($img_url) . '" />' . "\n";
        }
    }

    // Site name for all (og:locale removed — Rank Math emits it; see filter below)
    if (is_product() || is_shop() || is_product_category() || is_product_taxonomy() || is_front_page() || is_singular('post')) {
        echo '<meta property="og:site_name" content="Akibara — Manga y Cómics en Chile" />' . "\n";
    }
}, 5);

// =================================================================
// SEO — product_brand (Editorial) archive pages
// =================================================================

// Custom page titles for editorial archive pages
add_filter('document_title_parts', function($title) {
    if (is_tax('product_brand')) {
        $term = get_queried_object();
        if ($term) {
            $title['title'] = $term->name . ' — Manga y Cómics en Chile';
            $title['tagline'] = '';
        }
    }
    return $title;
});

// ═══════════════════════════════════════════════════════════════
// OG:LOCALE — Akibara es tienda 100% Chile (memoria project_google_online).
// Forzamos es_CL para reflejar el mercado real. Aunque históricamente
// Facebook documentaba sólo es_LA / es_ES / es_MX, hoy las plataformas
// modernas (FB, X, LinkedIn, Pinterest) aceptan locales BCP47 estándar.
// El SEO audit 2026-04-24 exige es_CL (F-02) para señales país-específicas.
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/opengraph/facebook/og_locale', static function (): string {
    return 'es_CL';
}, 999 );

// Cubrimos también el filter genérico por si Rank Math cambia el hook.
add_filter( 'rank_math/opengraph/locale', static function (): string {
    return 'es_CL';
}, 999 );

// ═══════════════════════════════════════════════════════════════
// OG:TYPE FIX — Rank Math emits "article" for pages/taxonomies.
// OG spec: pages + taxonomies + archives should be "website".
// Products stay "product" (handled natively by Rank Math WC module).
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/opengraph/type', function( string $type ): string {
    if ( function_exists( 'is_product' ) && is_product() ) {
        return 'product';
    }
    if ( is_singular( 'post' ) ) {
        return 'article';
    }
    // Pages, archives, taxonomies, home, 404, search → website
    return 'website';
}, 20 );

// ═══════════════════════════════════════════════════════════════
// CANONICAL FIX for page templates — Rank Math sometimes leaks
// trailing slash inconsistencies on page templates (encargos, rastrear).
// Force canonical = get_permalink() + trailing slash for pages.
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/frontend/canonical', function( string $canonical ): string {
    if ( is_page() && ! is_front_page() ) {
        $id = get_queried_object_id();
        if ( $id ) {
            return trailingslashit( get_permalink( $id ) );
        }
    }
    return $canonical;
}, 25 );

// ═══════════════════════════════════════════════════════════════
// META DESCRIPTION FALLBACK for pages without Rank Math meta.
// Rank Math sometimes doesn't emit meta desc on page templates
// with custom content (encargos, rastrear, mi-cuenta).
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/frontend/description', function( string $desc ): string {
    if ( ! empty( $desc ) ) return $desc;
    if ( ! is_page() ) return $desc;

    // Manual fallbacks for known pages
    $slug = get_post_field( 'post_name', get_queried_object_id() );
    $fallbacks = [
        'encargos'  => 'Solicita manga y cómics que no tenemos en stock. Cotización en 48h. Akibara, envíos a todo Chile.',
        'rastrear'  => 'Rastrea el estado de tu pedido Akibara con BlueX. Seguimiento en tiempo real.',
        'mi-cuenta' => 'Accede a tu cuenta Akibara para ver pedidos, favoritos y preferencias.',
        'contacto'  => 'Contáctanos por WhatsApp, email o el formulario. Akibara, manga y cómics en Chile.',
        'bienvenida' => 'Bienvenido a Akibara, tu distrito del manga y cómics en Chile.',
    ];
    return $fallbacks[ $slug ] ?? $desc;
}, 25 );

// ═══════════════════════════════════════════════════════════════
// META DESCRIPTION BLOG POSTS — Rank Math no siempre emite una
// description para posts sin configuracion manual por entrada.
// Fallback: excerpt o primer parrafo limpio, recortado a 160 chars.
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/frontend/description', function( string $desc ): string {
    if ( ! empty( $desc ) ) return $desc;
    if ( ! is_singular( 'post' ) ) return $desc;

    $post_id = get_the_ID();
    if ( has_excerpt( $post_id ) ) {
        $src = get_the_excerpt( $post_id );
    } else {
        $src = get_post_field( 'post_content', $post_id );
    }

    $clean = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $src ) ) );
    if ( mb_strlen( $clean ) > 160 ) {
        $clean = mb_substr( $clean, 0, 157 ) . '...';
    }
    return $clean;
}, 25 );

// ═══════════════════════════════════════════════════════════════
// OG:IMAGE CATEGORY / BRAND ARCHIVES — Portada del producto más popular
// Rank Math no emite og:image para archives sin imagen configurada en el
// term, así que lo inyectamos directamente vía wp_head.
// ═══════════════════════════════════════════════════════════════
add_action( 'wp_head', function () {
    if ( ! is_product_category() && ! is_tax( 'product_brand' ) ) return;

    $term = get_queried_object();
    if ( ! $term instanceof WP_Term ) return;

    $cache_key = 'akibara_og_img_' . $term->taxonomy . '_' . $term->term_id;
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        if ( $cached ) {
            echo '<meta property="og:image" content="' . esc_url( $cached ) . '" />' . "\n";
        }
        return;
    }

    $query_args = [
        'limit'   => 1,
        'orderby' => 'popularity',
        'order'   => 'DESC',
        'status'  => 'publish',
    ];
    if ( is_product_category() ) {
        $query_args['category'] = [ $term->slug ];
    } else {
        $query_args['tax_query'] = [ [
            'taxonomy' => 'product_brand',
            'field'    => 'term_id',
            'terms'    => [ $term->term_id ],
        ] ];
    }
    $products = wc_get_products( $query_args );

    $result = '';
    if ( ! empty( $products ) ) {
        $img_id = $products[0]->get_image_id();
        if ( $img_id ) {
            $url = wp_get_attachment_image_url( $img_id, 'large' );
            if ( $url ) $result = $url;
        }
    }
    if ( ! $result ) {
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) $result = (string) wp_get_attachment_image_url( $logo_id, 'full' );
    }

    set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
    if ( $result ) {
        echo '<meta property="og:image" content="' . esc_url( $result ) . '" />' . "\n";
    }
}, 26 );

// ═══════════════════════════════════════════════════════════════
// OG:IMAGE BLOG POSTS — Rank Math respeta la imagen destacada solo
// cuando esta configurada. Forzamos el fallback al thumbnail y, si
// falta, al logo del sitio (para que el post nunca comparta sin imagen).
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/opengraph/facebook/og_image', function( $image ) {
    if ( ! is_singular( 'post' ) ) return $image;
    if ( ! empty( $image ) ) return $image;

    $thumb_id = get_post_thumbnail_id( get_the_ID() );
    if ( $thumb_id ) {
        return wp_get_attachment_image_url( $thumb_id, 'full' );
    }
    $logo_id = get_theme_mod( 'custom_logo' );
    if ( $logo_id ) {
        return wp_get_attachment_image_url( $logo_id, 'full' );
    }
    return $image;
}, 20 );

add_filter( 'rank_math/opengraph/twitter/twitter_image', function( $image ) {
    if ( ! is_singular( 'post' ) ) return $image;
    if ( ! empty( $image ) ) return $image;

    $thumb_id = get_post_thumbnail_id( get_the_ID() );
    if ( $thumb_id ) {
        return wp_get_attachment_image_url( $thumb_id, 'full' );
    }
    return $image;
}, 20 );

// ═══════════════════════════════════════════════════════════════
// META DESCRIPTION HOMEPAGE — Recorte a 155 chars para SERP
// (Rank Math emite la meta pero la versión actual es 207 chars — Google
// trunca en ~160. Preservamos keywords: manga, cómics, original, Chile,
// +1.300 títulos, envío, 3 cuotas.)
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/frontend/description', function( string $desc ): string {
    if ( ! is_front_page() ) return $desc;
    return 'Compra manga y cómics originales en Chile. +1.300 títulos. Envío a todo Chile, 3 cuotas sin interés. Tienda online de Akibara.';
}, 30 );

// ═══════════════════════════════════════════════════════════════
// TWITTER CARD — Use summary_large_image for products
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/opengraph/twitter/card_type', function( $card ) {
    if ( function_exists('is_product') && is_product() ) {
        return 'summary_large_image';
    }
    return 'summary_large_image';
}, 20 );

// OG:IMAGE FOR HOMEPAGE — Rank Math does not add one by default
// ═══════════════════════════════════════════════════════════════
add_action("wp_head", function () {
    if (!is_front_page()) return;
    $logo_id = get_theme_mod("custom_logo");
    if (!$logo_id) return;
    $url = wp_get_attachment_url($logo_id);
    if (!$url) return;
    $meta = wp_get_attachment_metadata($logo_id);
    echo '<meta property="og:image" content="' . esc_url($url) . '" />' . "
";
    if ($meta) {
        echo '<meta property="og:image:width" content="' . esc_attr($meta['width'] ?? '') . '" />' . "
";
        echo '<meta property="og:image:height" content="' . esc_attr($meta['height'] ?? '') . '" />' . "
";
    }
}, 25);

// ═══════════════════════════════════════════════════════════════
// PRODUCT SEO TITLE — Prepend 'Comprar' for purchase-intent keywords
// ═══════════════════════════════════════════════════════════════

add_filter( 'rank_math/frontend/title', function( string $title ): string {
    if ( ! is_singular( 'product' ) ) return $title;
    if ( str_starts_with( $title, 'Comprar' ) ) return $title;

    $product_title = get_the_title();
    return 'Comprar ' . $product_title . ' en Chile | Akibara';
}, 20 );

// ═══════════════════════════════════════════════════════════════
// PRODUCT META DESCRIPTION — Auto-generate if empty
// ═══════════════════════════════════════════════════════════════

add_filter( 'rank_math/frontend/description', function( string $desc ): string {
    if ( ! is_singular( 'product' ) ) return $desc;

    global $product;
    $product = wc_get_product( get_the_ID() );
    if ( ! $product || ! is_object( $product ) ) return $desc;

    $product_id = $product->get_id();
    $name       = $product->get_name();

    // Price clean
    $price_raw = $product->get_price();
    $price     = $price_raw ? '$' . number_format( (float) $price_raw, 0, ',', '.' ) : '';

    // Sale percentage
    $regular = $product->get_regular_price();
    $sale_text = '';
    if ( $regular && $price_raw && (float) $price_raw < (float) $regular ) {
        $pct = round( ( 1 - (float) $price_raw / (float) $regular ) * 100 );
        if ( $pct >= 3 ) $sale_text = " (-{$pct}%)";
    }

    // Brand
    $brands = get_the_terms( $product_id, 'product_brand' );
    $brand  = ( $brands && ! is_wp_error( $brands ) ) ? $brands[0]->name : '';

    // Genre
    $genres    = get_the_terms( $product_id, 'pa_genero' );
    $genre_str = ( $genres && ! is_wp_error( $genres ) )
        ? implode( ', ', wp_list_pluck( array_slice( $genres, 0, 2 ), 'name' ) )
        : '';

    // Stock
    $is_reserva = get_post_meta( $product_id, '_akb_reserva', true ) === 'yes';
    if ( $is_reserva ) { $stock = 'en preventa'; }
    elseif ( $product->is_in_stock() ) { $stock = 'en stock'; }
    else { $stock = 'agotado'; }

    // Build description
    $parts = [];
    $parts[] = "Comprar {$name}";
    if ( $brand ) $parts[0] .= " ({$brand})";
    if ( $price ) $parts[] = "por {$price}{$sale_text}";
    if ( $genre_str ) $parts[] = $genre_str;
    $parts[] = ucfirst( $stock ) . ' con envío a todo Chile, 3 cuotas sin interés';
    $result = implode( '. ', $parts ) . '.';

    if ( mb_strlen( $result ) > 160 ) $result = mb_substr( $result, 0, 157 ) . '...';

    return $result;
}, 20 );
