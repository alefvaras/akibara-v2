<?php
defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════
// JSON-LD PRODUCT/BOOK — Enhanced product structured data for manga
// Uses Book type for manga products with author, format, pages, ISBN
// ═══════════════════════════════════════════════════════════════
add_action('wp_head', function () {
    if (!is_product()) return;
    // Skip if Rank Math handles product schema
    if (defined('RANK_MATH_VERSION')) return;

    $product = wc_get_product(get_the_ID());
    if (!$product) return;

    $image = wp_get_attachment_url($product->get_image_id());
    $desc  = wp_strip_all_tags($product->get_short_description() ?: $product->get_description());
    $desc  = mb_substr($desc, 0, 5000);

    // Check if this is a book/manga product (has author or series metadata)
    $authors = get_the_terms(get_the_ID(), 'pa_autor');
    $is_book = $authors && !is_wp_error($authors) && !empty($authors);

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => $is_book ? 'Book' : 'Product',
        'name'        => $product->get_name(),
        'url'         => get_permalink(),
        'description' => $desc,
        'sku'         => $product->get_sku() ?: (string) $product->get_id(),
        'inLanguage'  => 'es-CL',
    ];

    if ($image) {
        $schema['image'] = $image;
    }

    // Brand / Publisher
    $brands = get_the_terms(get_the_ID(), 'product_brand');
    if ($brands && !is_wp_error($brands)) {
        $schema['publisher'] = [
            '@type' => 'Organization',
            'name'  => $brands[0]->name,
        ];
    }

    // Book-specific fields
    if ($is_book) {
        // Author
        $author_names = [];
        foreach (array_slice($authors, 0, 3) as $author) {
            $author_names[] = $author->name;
        }
        if ($author_names) {
            $schema['author'] = $author_names;
        }

        // Book format (pa_encuadernacion)
        $formats = get_the_terms(get_the_ID(), 'pa_encuadernacion');
        if ($formats && !is_wp_error($formats) && !empty($formats)) {
            $format_map = [
                'rústico' => 'Paperback',
                'tapa dura' => 'Hardcover',
                'bolsillo' => 'Paperback',
            ];
            $format = strtolower($formats[0]->name);
            $schema['bookFormat'] = $format_map[$format] ?? 'Paperback';
        }

        // Number of pages (if available in metadata)
        $pages = get_post_meta(get_the_ID(), '_akb_paginas', true);
        if ($pages && is_numeric($pages)) {
            $schema['numberOfPages'] = (int) $pages;
        }

        // ISBN (if available)
        $isbn = get_post_meta(get_the_ID(), '_akb_isbn', true);
        if ($isbn) {
            $schema['isbn'] = sanitize_text_field($isbn);
        }
    }

    // Offers
    $schema['offers'] = [
        '@type'           => 'Offer',
        'url'             => get_permalink(),
        'priceCurrency'   => 'CLP',
        'price'           => $product->get_price(),
        'availability'    => $product->is_in_stock()
            ? 'https://schema.org/InStock'
            : 'https://schema.org/OutOfStock',
        'seller'          => [
            '@type' => 'Organization',
            'name'  => 'Akibara',
        ],
    ];

    // Reviews
    $review_count = $product->get_review_count();
    $avg_rating   = (float) $product->get_average_rating();
    if ($review_count > 0 && $avg_rating > 0) {
        $schema['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => $avg_rating,
            'reviewCount' => $review_count,
        ];
    }

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>' . "\n";
}, 15);

// ═══════════════════════════════════════════════════════════════
// ENHANCED PRODUCT SCHEMA — Brand, Author, Availability, Series
// ═══════════════════════════════════════════════════════════════

add_filter( 'rank_math/json_ld', function( array $data, $jsonld ): array {
    if ( ! is_singular( 'product' ) ) return $data;

    global $product;
    $product = wc_get_product( get_the_ID() );
    if ( ! $product ) return $data;

    $product_id = $product->get_id();

    // Find the Product entity in Rank Math's JSON-LD
    $product_key = null;
    foreach ( $data as $key => $entity ) {
        if ( isset( $entity['@type'] ) ) {
            $type = $entity['@type'];
            if ( $type === 'Product' || ( is_array( $type ) && in_array( 'Product', $type ) ) ) {
                $product_key = $key;
                break;
            }
        }
    }

    if ( $product_key === null ) return $data;

    // Brand (editorial real, no 'Akibara')
    $brands = get_the_terms( $product_id, 'product_brand' );
    if ( $brands && ! is_wp_error( $brands ) ) {
        $data[ $product_key ]['brand'] = [
            '@type' => 'Brand',
            'name'  => $brands[0]->name,
        ];
    } else {
        // Remove wrong 'Akibara' brand that Rank Math adds by default
        if ( isset( $data[ $product_key ]['brand']['name'] ) && $data[ $product_key ]['brand']['name'] === 'Akibara' ) {
            unset( $data[ $product_key ]['brand'] );
        }
    }

    // Author
    $authors = get_the_terms( $product_id, 'pa_autor' );
    if ( $authors && ! is_wp_error( $authors ) ) {
        $data[ $product_key ]['author'] = [
            '@type' => 'Person',
            'name'  => $authors[0]->name,
        ];
    }

    // SKU/ISBN
    $sku = $product->get_sku();
    if ( ! empty( $sku ) ) {
        $data[ $product_key ]['sku'] = $sku;
        if ( preg_match( '/^978\d{10}$/', $sku ) ) {
            $data[ $product_key ]['isbn'] = $sku;
        }
    }

    // Fix availability for pre-orders
    if ( isset( $data[ $product_key ]['offers'] ) ) {
        $is_preorder = get_post_meta( $product_id, '_akb_reserva', true ) === 'yes';
        $offers =& $data[ $product_key ]['offers'];

        if ( isset( $offers['@type'] ) ) {
            if ( $is_preorder ) {
                $offers['availability'] = 'https://schema.org/PreOrder';
            } elseif ( $product->is_in_stock() ) {
                $offers['availability'] = 'https://schema.org/InStock';
            } else {
                $offers['availability'] = 'https://schema.org/OutOfStock';
            }
        }
    }

    // Additional properties
    $additional = [];

    $genres = get_the_terms( $product_id, 'pa_genero' );
    if ( $genres && ! is_wp_error( $genres ) ) {
        $additional[] = [
            '@type' => 'PropertyValue',
            'name'  => 'Genero',
            'value' => implode( ', ', wp_list_pluck( $genres, 'name' ) ),
        ];
    }

    $vol = get_post_meta( $product_id, '_akibara_numero', true );
    if ( $vol ) {
        $additional[] = [
            '@type' => 'PropertyValue',
            'name'  => 'Volumen',
            'value' => $vol,
        ];
    }

    $serie = get_post_meta( $product_id, '_akibara_serie', true );
    if ( $serie ) {
        $additional[] = [
            '@type' => 'PropertyValue',
            'name'  => 'Serie',
            'value' => $serie,
        ];
    }

    if ( ! empty( $additional ) ) {
        $data[ $product_key ]['additionalProperty'] = $additional;
    }

    return $data;
}, 20, 2 );


// ═════════════════════════════════════════════════════════════════════
// ENHANCED PRODUCT SCHEMA — Book/ComicStory data merged into Rank Math
// Instead of a separate ld+json script (which competed with Rank Math's
// Product schema), we enrich the existing Product entity with Book fields.
// ═════════════════════════════════════════════════════════════════════
// Priority PHP_INT_MAX: Rank Math runs an internal normalization step that
// replaces `publisher: { name: "Milky Way" }` with a reference to #organization
// (the store) *after* filters at typical priorities (1–99). Running last
// guarantees our editorial publisher wins in the final @graph.
add_filter( 'rank_math/json_ld', 'akibara_enrich_product_book_schema', PHP_INT_MAX, 2 );

// og:locale fix movido a inc/seo/meta.php (output-buffer approach).
// rank_math/opengraph/facebook/locale no existe en RM — el método
// locale() llama get_locale() directamente sin apply_filters().
add_filter( 'language_attributes', function( $output ) {
    return preg_replace( '/lang="[^"]*"/', 'lang="es-CL"', $output );
}, 99 );

function akibara_enrich_product_book_schema( array $data, $jsonld ): array {
    if ( ! is_singular( 'product' ) ) return $data;

    global $product;
    $product = wc_get_product( get_the_ID() );
    if ( ! $product ) return $data;

    $product_id = $product->get_id();

    // Find the Product entity
    $product_key = null;
    foreach ( $data as $key => $entity ) {
        if ( isset( $entity['@type'] ) ) {
            $type = $entity['@type'];
            if ( $type === 'Product' || ( is_array( $type ) && in_array( 'Product', $type ) ) ) {
                $product_key = $key;
                break;
            }
        }
    }
    if ( $product_key === null ) return $data;

    // Detect manga/comic
    $cats = get_the_terms( $product_id, 'product_cat' );
    $is_manga = false;
    $is_comic = false;
    if ( $cats && ! is_wp_error( $cats ) ) {
        foreach ( $cats as $cat ) {
            if ( in_array( $cat->slug, [ 'manga', 'shonen', 'seinen', 'shojo', 'josei', 'kodomo', 'isekai' ], true ) ) $is_manga = true;
            if ( in_array( $cat->slug, [ 'comics', 'comic', 'comic-americano', 'graphic-novel' ], true ) ) $is_comic = true;
        }
    }
    if ( ! $is_manga && ! $is_comic ) return $data;

    // Add Book-specific @type alongside Product
    $existing_type = $data[ $product_key ]['@type'];
    $book_type = $is_manga ? 'Book' : 'ComicStory';
    if ( is_array( $existing_type ) ) {
        $data[ $product_key ]['@type'] = array_unique( array_merge( $existing_type, [ $book_type ] ) );
    } else {
        $data[ $product_key ]['@type'] = [ $existing_type, $book_type ];
    }

    // Book fields
    $data[ $product_key ]['bookFormat'] = 'Paperback';
    $data[ $product_key ]['inLanguage']  = 'es';

    // Serie → isPartOf
    $serie      = get_post_meta( $product_id, '_akibara_serie', true );
    $serie_norm = get_post_meta( $product_id, '_akibara_serie_norm', true );
    if ( $serie ) {
        $isPartOf = [ '@type' => 'BookSeries', 'name' => $serie ];
        if ( $serie_norm ) $isPartOf['url'] = home_url( '/serie/' . $serie_norm . '/' );
        $data[ $product_key ]['isPartOf'] = $isPartOf;
    }

    // Volume number → position
    $numero = get_post_meta( $product_id, '_akibara_numero', true );
    if ( $numero ) $data[ $product_key ]['position'] = (int) $numero;

    // Publisher (editorial)
    $brands = get_the_terms( $product_id, 'product_brand' );
    if ( $brands && ! is_wp_error( $brands ) ) {
        $data[ $product_key ]['publisher'] = [ '@type' => 'Organization', 'name' => $brands[0]->name ];
    }

    // ISBN / GTIN13 — fix Rank Math que emite gtin8 con valor de 13 dígitos (inválido).
    $sku = $product->get_sku();
    if ( $sku && preg_match( '/^97[89]\d{10}$/', $sku ) ) {
        $data[ $product_key ]['isbn']   = $sku;
        $data[ $product_key ]['gtin13'] = $sku;
        unset( $data[ $product_key ]['gtin8'] );
    }

    // priceValidUntil dinámico (Rank Math lo hardcodea a un año fijo).
    // Regenera cada año calendario → evita que Google deshabilite el rich snippet de precio.
    if ( isset( $data[ $product_key ]['offers'] ) ) {
        $offers =& $data[ $product_key ]['offers'];
        $valid_until = gmdate( 'Y-12-31', strtotime( '+1 year' ) );
        if ( isset( $offers['@type'] ) ) {
            $offers['priceValidUntil'] = $valid_until;
        } elseif ( is_array( $offers ) ) {
            foreach ( $offers as &$offer ) {
                if ( is_array( $offer ) && isset( $offer['@type'] ) ) {
                    $offer['priceValidUntil'] = $valid_until;
                }
            }
            unset( $offer );
        }
    }

    // Illustrator (si existe como taxonomía pa_ilustrador o meta _akb_ilustrador)
    $illustrators = get_the_terms( $product_id, 'pa_ilustrador' );
    if ( $illustrators && ! is_wp_error( $illustrators ) ) {
        $data[ $product_key ]['illustrator'] = [
            '@type' => 'Person',
            'name'  => $illustrators[0]->name,
        ];
    } else {
        $ilu_meta = get_post_meta( $product_id, '_akb_ilustrador', true );
        if ( $ilu_meta ) {
            $data[ $product_key ]['illustrator'] = [
                '@type' => 'Person',
                'name'  => sanitize_text_field( (string) $ilu_meta ),
            ];
        }
    }

    // numberOfPages (meta _akb_paginas o _akibara_paginas)
    $pages = (int) get_post_meta( $product_id, '_akb_paginas', true );
    if ( ! $pages ) {
        $pages = (int) get_post_meta( $product_id, '_akibara_paginas', true );
    }
    if ( $pages > 0 ) {
        $data[ $product_key ]['numberOfPages'] = $pages;
    }

    // datePublished (meta _akb_fecha_publicacion en formato YYYY o YYYY-MM-DD)
    $published = get_post_meta( $product_id, '_akb_fecha_publicacion', true );
    if ( $published ) {
        $published = trim( (string) $published );
        if ( preg_match( '/^\d{4}(-\d{2}(-\d{2})?)?$/', $published ) ) {
            $data[ $product_key ]['datePublished'] = $published;
        }
    }

    // Genre
    $genre_map = [
        'shonen' => 'Shōnen manga', 'seinen' => 'Seinen manga',
        'shojo'  => 'Shōjo manga',  'josei'  => 'Josei manga',
        'kodomo' => 'Kodomo manga', 'isekai' => 'Isekai',
    ];
    if ( $cats && ! is_wp_error( $cats ) ) {
        foreach ( $cats as $cat ) {
            if ( isset( $genre_map[ $cat->slug ] ) ) {
                $data[ $product_key ]['genre'] = $genre_map[ $cat->slug ];
                break;
            }
        }
    }

    // bookEdition — derivado de la editorial (brand)
    if ( $brands && ! is_wp_error( $brands ) ) {
        $brand_name = $brands[0]->name;
        $data[ $product_key ]['bookEdition'] = 'Edición ' . $brand_name;
    }

    // aggregateRating — desde reviews de WooCommerce
    $rating_count   = $product->get_rating_count();
    $average_rating = (float) $product->get_average_rating();
    if ( $rating_count > 0 && $average_rating > 0 ) {
        $data[ $product_key ]['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => round( $average_rating, 1 ),
            'ratingCount' => $rating_count,
            'bestRating'  => 5,
            'worstRating' => 1,
        ];
    }

    // NewCondition + shippingDetails + hasMerchantReturnPolicy
    // ──────────────────────────────────────────────────────────────
    // Google requiere desde mid-2023 shippingDetails y hasMerchantReturnPolicy
    // en offers de productos físicos para mostrar Rich Snippets.
    // Sin esto, el schema es válido pero Google oculta el snippet de precio/stock.
    if ( isset( $data[ $product_key ]['offers'] ) ) {
        $data[ $product_key ]['offers']['itemCondition'] = 'https://schema.org/NewCondition';

        // Keep seller reference aligned with the Organization node in @graph.
        if ( isset( $data[ $product_key ]['offers']['seller'] ) && is_array( $data[ $product_key ]['offers']['seller'] ) ) {
            $data[ $product_key ]['offers']['seller']['@id'] = home_url( '/#organization' );
            $data[ $product_key ]['offers']['seller']['url'] = home_url( '/' );
        }

        // priceValidUntil for sale items
        if ( $product->is_on_sale() && $product->get_regular_price() ) {
            $sale_end = get_post_meta( $product_id, '_sale_price_dates_to', true );
            $data[ $product_key ]['offers']['priceValidUntil'] = $sale_end ? date( 'Y-m-d', (int) $sale_end ) : date( 'Y-12-31' );
        }

        // shippingDetails: array con 2 variantes (gratis ≥ threshold, pagado <)
        $free_threshold = function_exists( 'akibara_get_free_shipping_threshold' )
            ? (int) akibara_get_free_shipping_threshold()
            : 55000;

        $delivery_time = [
            '@type'        => 'ShippingDeliveryTime',
            'handlingTime' => [
                '@type'    => 'QuantitativeValue',
                'minValue' => 1,
                'maxValue' => 2,
                'unitCode' => 'DAY',
            ],
            'transitTime'  => [
                '@type'    => 'QuantitativeValue',
                'minValue' => 2,
                'maxValue' => 5,
                'unitCode' => 'DAY',
            ],
        ];

        $destination_cl = [
            '@type'          => 'DefinedRegion',
            'addressCountry' => 'CL',
        ];

        $data[ $product_key ]['offers']['shippingDetails'] = [
            // Variante 1 — envío gratis para pedidos ≥ threshold (ej: $55.000)
            [
                '@type'                     => 'OfferShippingDetails',
                'shippingRate'              => [
                    '@type'    => 'MonetaryAmount',
                    'value'    => '0',
                    'currency' => 'CLP',
                ],
                'shippingDestination'       => $destination_cl,
                'eligibleTransactionVolume' => [
                    '@type'         => 'PriceSpecification',
                    'minPrice'      => (string) $free_threshold,
                    'priceCurrency' => 'CLP',
                ],
                'deliveryTime'              => $delivery_time,
            ],
            // Variante 2 — envío pagado (Blue Express nacional)
            [
                '@type'               => 'OfferShippingDetails',
                'shippingRate'        => [
                    '@type'    => 'MonetaryAmount',
                    'value'    => '3990',
                    'currency' => 'CLP',
                ],
                'shippingDestination' => $destination_cl,
                'deliveryTime'        => $delivery_time,
            ],
        ];

        // hasMerchantReturnPolicy — política estándar Chile: 10 días, defecto de fábrica
        $data[ $product_key ]['offers']['hasMerchantReturnPolicy'] = [
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => 'CL',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => 10,
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => 'https://schema.org/ReturnFeesCustomerResponsibility',
        ];
    }

    return $data;
}
