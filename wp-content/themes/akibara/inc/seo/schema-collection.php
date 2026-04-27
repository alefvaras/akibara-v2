<?php
defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════
// JSON-LD BREADCRUMBS — Structured data for Google
// ═══════════════════════════════════════════════════════════════
add_action('wp_head', function () {
    // Skip if Rank Math outputs breadcrumbs
    if (defined('RANK_MATH_VERSION')) return;

    if (!is_product() && !is_product_category() && !is_product_taxonomy()) return;

    $items = [];
    $pos = 1;

    $items[] = [
        '@type'    => 'ListItem',
        'position' => $pos++,
        'name'     => 'Inicio',
        'item'     => home_url('/'),
    ];

    if (is_product()) {
        $product = wc_get_product(get_the_ID());
        if (!$product) return;

        $terms = get_the_terms(get_the_ID(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            $term = array_reduce($terms, function ($carry, $t) {
                if (!$carry || $t->parent > 0) return $t;
                return $carry;
            });

            $chain = [];
            $current = $term;
            while ($current) {
                array_unshift($chain, $current);
                $current = $current->parent ? get_term($current->parent, 'product_cat') : null;
            }

            foreach ($chain as $cat) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => $cat->name,
                    'item'     => get_term_link($cat),
                ];
            }
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => $product->get_name(),
        ];

    } elseif (is_product_category() || is_product_taxonomy()) {
        $term = get_queried_object();
        if (!$term) return;

        if ($term->parent) {
            $chain = [];
            $current = get_term($term->parent, $term->taxonomy);
            while ($current && !is_wp_error($current)) {
                array_unshift($chain, $current);
                $current = $current->parent ? get_term($current->parent, $term->taxonomy) : null;
            }
            foreach ($chain as $parent) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => $parent->name,
                    'item'     => get_term_link($parent),
                ];
            }
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => $term->name,
        ];
    }

    if (count($items) < 2) return;

    $ld = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];

    echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}, 10);

// JSON-LD CollectionPage schema for product category archives (/comics/, /manga/, etc.)
add_action('wp_head', function () {
    if (!is_product_category()) return;

    $term = get_queried_object();
    if (!$term) return;

    $paged     = max(1, (int) get_query_var('paged'));
    $term_url  = trailingslashit(get_term_link($term));
    $canonical = $paged > 1 ? $term_url . 'page/' . $paged . '/' : $term_url;

    $desc = $term->description
        ? wp_strip_all_tags($term->description)
        : 'Compra ' . $term->name . ' originales en Akibara. Catálogo completo con envío a todo Chile, stock disponible y preventas.';

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => $term->name . ' — Comprar en Akibara Chile',
        'description' => mb_substr($desc, 0, 200),
        'url'         => $canonical,
        'inLanguage'  => 'es-CL',
        'publisher'   => [
            '@type' => 'Organization',
            'name'  => 'Akibara',
            'url'   => home_url('/'),
        ],
    ];

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>' . "\n";
}, 12);

// JSON-LD CollectionPage schema for editorial archives
add_action('wp_head', function () {
    if (!is_tax('product_brand')) return;
    if (defined('RANK_MATH_VERSION')) return;

    $term = get_queried_object();
    if (!$term) return;

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => $term->name . ' — Manga y Cómics en Chile',
        'description' => 'Catálogo de manga y cómics de ' . $term->name . ' disponibles en Akibara. ' . $term->count . ' títulos con envío a todo Chile.',
        'url'         => get_term_link($term),
        'publisher'   => [
            '@type' => 'Organization',
            'name'  => 'Akibara',
            'url'   => home_url('/'),
        ],
    ];

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>' . "\n";
}, 12);

// ═══════════════════════════════════════════════════════════════
// ITEMLIST SCHEMA — Lista de productos para archives de categoría/brand
// ═══════════════════════════════════════════════════════════════
add_action( 'wp_head', function () {
    if ( ! is_product_category() && ! is_tax( 'product_brand' ) && ! is_shop() ) return;

    global $wp_query;
    if ( empty( $wp_query->posts ) ) return;

    $items = [];
    $pos   = 1;
    foreach ( $wp_query->posts as $post ) {
        $p = wc_get_product( $post->ID );
        if ( ! $p ) continue;
        $entry = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'url'      => get_permalink( $post->ID ),
            'name'     => $p->get_name(),
        ];
        $img_id = $p->get_image_id();
        if ( $img_id ) {
            $img_url = wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' );
            if ( $img_url ) $entry['image'] = $img_url;
        }
        $items[] = $entry;
    }

    if ( empty( $items ) ) return;

    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'ItemList',
        'itemListElement' => $items,
    ];

    echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>' . "\n";
}, 13 );

// ═══════════════════════════════════════════════════════════════
// BREADCRUMB SCHEMA — Add subcategory + serie to product breadcrumbs
// ═══════════════════════════════════════════════════════════════

add_filter( 'rank_math/json_ld', 'akibara_enrich_breadcrumb_schema', 22, 2 );

function akibara_enrich_breadcrumb_schema( array $data, $jsonld ): array {
    if ( ! is_singular( 'product' ) ) return $data;

    global $product;
    $product = wc_get_product( get_the_ID() );
    if ( ! $product ) return $data;

    $product_id = $product->get_id();

    // Find BreadcrumbList in JSON-LD
    $bc_key = null;
    foreach ( $data as $key => $entity ) {
        if ( isset( $entity['@type'] ) && $entity['@type'] === 'BreadcrumbList' ) {
            $bc_key = $key;
            break;
        }
    }

    if ( $bc_key === null ) return $data;

    $items = $data[ $bc_key ]['itemListElement'] ?? [];
    if ( count( $items ) < 2 ) return $data;

    // Get subcategory (shonen, seinen, etc.)
    $cats = get_the_terms( $product_id, 'product_cat' );
    $subcat = null;
    if ( $cats && ! is_wp_error( $cats ) ) {
        foreach ( $cats as $cat ) {
            if ( $cat->parent > 0 ) {
                $subcat = $cat;
                break;
            }
        }
    }

    // Get serie
    $serie      = get_post_meta( $product_id, '_akibara_serie', true );
    $serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
    // If display name is empty, derive from slug or product title
    if ( empty( $serie ) && ! empty( $serie_norm ) ) {
        // Try pa_serie taxonomy term name
        $serie_terms = get_the_terms( $product_id, 'pa_serie' );
        if ( $serie_terms && ! is_wp_error( $serie_terms ) ) {
            $serie = $serie_terms[0]->name;
        } else {
            // Derive from product title: "Dan Da Dan 5 – Ivrea Argentina" -> "Dan Da Dan"
            $title = get_the_title( $product_id );
            if ( preg_match( '/^(.+?)\s*\d+\s*[–—-]/u', $title, $m ) ) {
                $serie = trim( $m[1] );
            }
        }
    }

    // Insert subcategory after the first category
    $new_items = [];
    $pos = 1;

    foreach ( $items as $item ) {
        $item['position'] = $pos;
        $new_items[] = $item;
        $pos++;

        // After the category item (position 2), insert subcat and serie
        if ( count( $new_items ) === 2 ) {
            if ( $subcat ) {
                $new_items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos,
                    'name'     => $subcat->name,
                    'item'     => get_term_link( $subcat ),
                ];
                $pos++;
            }

            if ( $serie && $serie_norm ) {
                $new_items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos,
                    'name'     => $serie,
                    'item'     => home_url( '/serie/' . $serie_norm . '/' ),
                ];
                $pos++;
            }
        }
    }

    // Fix last item position
    if ( ! empty( $new_items ) ) {
        $last_idx = count( $new_items ) - 1;
        $new_items[ $last_idx ]['position'] = $pos - 1;
    }

    $data[ $bc_key ]['itemListElement'] = $new_items;

    return $data;
}
