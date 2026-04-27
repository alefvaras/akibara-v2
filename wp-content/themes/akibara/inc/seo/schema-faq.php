<?php
defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════
// FAQ SCHEMA — For preguntas-frecuentes page
// ═══════════════════════════════════════════════════════════════
add_action("wp_head", function () {
    if (!is_page("preguntas-frecuentes")) return;
    
    // Build from page content — content-hash keyed cache auto-invalidates on edits
    $page = get_page_by_path("preguntas-frecuentes");
    if (!$page) return;
    
    $cache_key = "akibara_faq_schema_" . substr(md5($page->post_content), 0, 10);
    $schema = get_transient($cache_key);
    if (!$schema) {
        // Delete old cache entries
        delete_transient("akibara_faq_schema");
        
        preg_match_all("/<summary>([^<]+)<\/summary><div>(.*?)<\/div>/s", $page->post_content, $matches);
        if (empty($matches[1])) return;
        
        $items = [];
        for ($i = 0; $i < count($matches[1]); $i++) {
            $items[] = [
                "@type" => "Question",
                "name" => strip_tags($matches[1][$i]),
                "acceptedAnswer" => [
                    "@type" => "Answer",
                    "text" => strip_tags($matches[2][$i]),
                ],
            ];
        }
        
        $schema = [
            "@context" => "https://schema.org",
            "@type" => "FAQPage",
            "mainEntity" => $items,
        ];
        
        set_transient($cache_key, $schema, DAY_IN_SECONDS);
    }
    
    echo "<script type=\"application/ld+json\">" . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . "</script>\n";
}, 25);

// ═══════════════════════════════════════════════════════════════
// PRODUCT FAQ — Shared data helper + schema + visual render
// ═══════════════════════════════════════════════════════════════

/**
 * Gather FAQ data for a product. Cached per request via static var.
 * Used by both JSON-LD schema and visual accordion.
 *
 * @return array[] Each item: ['q' => string, 'a' => string]
 */
function akibara_get_product_faq_data( ?WC_Product $product = null ): array {
    if ( ! $product ) {
        $product = wc_get_product( get_the_ID() );
    }
    if ( ! $product ) return [];

    // Per-request cache keyed by product ID
    static $cache = [];
    $pid = $product->get_id();
    if ( isset( $cache[ $pid ] ) ) return $cache[ $pid ];

    $name  = $product->get_name();
    // Decodificar entidades HTML (wc_price emite &#036; que queda literal en JSON-LD).
    $price = html_entity_decode( strip_tags( wc_price( $product->get_price() ) ), ENT_QUOTES, 'UTF-8' );

    $serie      = get_post_meta( $pid, '_akibara_serie', true );
    $serie_norm = get_post_meta( $pid, '_akibara_serie_norm', true );
    $vol_num    = get_post_meta( $pid, '_akibara_numero', true );

    $brands = get_the_terms( $pid, 'product_brand' );
    $brand  = ( $brands && ! is_wp_error( $brands ) ) ? $brands[0]->name : '';

    $authors = get_the_terms( $pid, 'pa_autor' );
    $author  = ( $authors && ! is_wp_error( $authors ) ) ? $authors[0]->name : '';

    $genres = get_the_terms( $pid, 'pa_genero' );
    $genre_str = ( $genres && ! is_wp_error( $genres ) )
        ? implode( ', ', wp_list_pluck( $genres, 'name' ) )
        : '';

    $total_vols = 0;
    if ( ! empty( $serie_norm ) ) {
        $total_vols = (int) wp_cache_get( 'akb_serie_count_' . $serie_norm );
        if ( ! $total_vols ) {
            global $wpdb;
            $total_vols = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID AND p.post_type = 'product' AND p.post_status = 'publish'
                 WHERE pm.meta_key = '_akibara_serie_norm' AND pm.meta_value = %s",
                $serie_norm
            ) );
            wp_cache_set( 'akb_serie_count_' . $serie_norm, $total_vols, '', 3600 );
        }
    }

    $in_stock    = $product->is_in_stock();
    $is_preorder = get_post_meta( $pid, '_akb_reserva', true ) === 'yes';
    $stock_text  = $is_preorder ? 'disponible en preventa' : ( $in_stock ? 'disponible en stock' : 'actualmente agotado' );
    $serie_or_name = $serie ?: $name;

    $faqs = [];

    // 1. Price
    $faqs[] = [
        'q' => '¿Cuánto cuesta ' . $name . '?',
        'a' => $name . ' tiene un precio de ' . $price . ' y está ' . $stock_text . ' en Akibara. Envío a todo Chile con hasta 3 cuotas sin interés.',
    ];

    // 2. Volume info
    if ( ! empty( $serie ) && $vol_num && $total_vols > 1 ) {
        $faqs[] = [
            'q' => '¿Qué tomo es ' . $name . '?',
            'a' => 'Es el volumen ' . $vol_num . ' de ' . $total_vols . ' de la serie ' . $serie . '. Todos los tomos están disponibles en Akibara Chile.',
        ];
    }

    // 3. Editorial
    if ( $brand ) {
        $faqs[] = [
            'q' => '¿Qué editorial publica ' . $serie_or_name . '?',
            'a' => $serie_or_name . ' es publicado por ' . $brand . '. Todos los títulos de ' . $brand . ' están disponibles en Akibara con envío a todo Chile.',
        ];
    }

    // 4. Author
    if ( $author ) {
        $faqs[] = [
            'q' => '¿Quién es el autor de ' . $serie_or_name . '?',
            'a' => $serie_or_name . ' fue creado por ' . $author . '. Encuentra más obras de ' . $author . ' en Akibara Chile.',
        ];
    }

    // 5. Genre
    if ( $genre_str ) {
        $faqs[] = [
            'q' => '¿De qué género es ' . $serie_or_name . '?',
            'a' => $serie_or_name . ' es un manga de ' . $genre_str . '. Explora más títulos de estos géneros en Akibara.',
        ];
    }

    // 6. Original?
    if ( $brand ) {
        $faqs[] = [
            'q' => '¿' . $name . ' es original?',
            'a' => 'Sí, es 100% original publicado por ' . $brand . '. Todos los mangas en Akibara son originales con funda protectora incluida.',
        ];
    }

    $cache[ $pid ] = $faqs;
    return $faqs;
}

add_filter( 'rank_math/json_ld', 'akibara_product_faq_schema', 25, 2 );

function akibara_product_faq_schema( array $data, $jsonld ): array {
    if ( ! is_singular( 'product' ) ) return $data;

    $faqs = akibara_get_product_faq_data();
    if ( empty( $faqs ) ) return $data;

    $faq_items = [];
    foreach ( $faqs as $faq ) {
        $faq_items[] = [
            '@type'          => 'Question',
            'name'           => $faq['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => $faq['a'],
            ],
        ];
    }

    $data['ProductFAQ'] = [
        '@type'      => 'FAQPage',
        'mainEntity' => $faq_items,
    ];

    return $data;
}

// ═══════════════════════════════════════════════════════════════
// PRODUCT FAQ VISUAL — Render FAQ accordion on product page
// ═══════════════════════════════════════════════════════════════

add_action( 'woocommerce_after_single_product', 'akibara_render_product_faqs', 5 );

function akibara_render_product_faqs(): void {
    global $product;
    $faqs = akibara_get_product_faq_data( $product );
    if ( empty( $faqs ) ) return;

    echo '<div class="product-faqs">';
    echo '<div class="section-header"><h2 class="section-header__title">Preguntas Frecuentes</h2></div>';
    foreach ( $faqs as $faq ) {
        echo '<details class="product-faq">';
        echo '<summary class="product-faq__q">' . esc_html( $faq['q'] ) . '</summary>';
        echo '<div class="product-faq__a"><p>' . esc_html( $faq['a'] ) . '</p></div>';
        echo '</details>';
    }
    echo '</div>';
}
