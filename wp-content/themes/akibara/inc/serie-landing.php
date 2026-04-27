<?php
/**
 * Akibara — Serie Landing Pages
 *
 * Auto-generates SEO landing pages for each manga/comic series.
 * URL: /serie/{slug}/
 * Data source: _akibara_serie_norm post meta
 *
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// REWRITE RULES — /serie/{slug}/
// ══════════════════════════════════════════════════════════════════

add_action( 'init', 'akibara_serie_rewrite_rules' );

function akibara_serie_rewrite_rules(): void {
    add_rewrite_rule(
        '^serie/([^/]+)/?$',
        'index.php?akibara_serie=$matches[1]',
        'top'
    );
    add_rewrite_tag( '%akibara_serie%', '([^/]+)' );
}

// Flush on activation (run once)
add_action( 'after_switch_theme', function () {
    akibara_serie_rewrite_rules();
    flush_rewrite_rules();
} );

// ══════════════════════════════════════════════════════════════════
// TEMPLATE LOADER
// ══════════════════════════════════════════════════════════════════

add_filter( 'template_include', 'akibara_serie_template' );
add_filter( 'template_include', 'akibara_serie_index_template' );

function akibara_serie_index_template( string $template ): string {
    if ( ! get_query_var( 'akibara_serie_index' ) ) return $template;
    return locate_template( 'template-serie-index.php' ) ?: $template;
}

function akibara_serie_template( string $template ): string {
    $serie_slug = get_query_var( 'akibara_serie' );
    if ( empty( $serie_slug ) ) return $template;

    $slug = sanitize_title( $serie_slug );

    // 1) Exact match → render single serie page
    $data = akibara_get_serie_data( $slug );
    if ( $data && ! empty( $data['products'] ) ) {
        return locate_template( 'template-serie.php' ) ?: $template;
    }

    // 2) Hub fallback: slug is a parent of multiple variants
    //    (e.g. /serie/rezero/ → rezero-chapter-one, rezero-chapter-two, ...).
    //    1 variant → 301 redirect; 2+ variants → render hub page.
    $hub = akibara_get_serie_hub_data( $slug );
    if ( $hub && ! empty( $hub['variants'] ) ) {
        if ( count( $hub['variants'] ) === 1 ) {
            wp_safe_redirect( $hub['variants'][0]['url'], 301 );
            exit;
        }
        return locate_template( 'template-serie-hub.php' ) ?: $template;
    }

    // 2.5) Compact form fallback — /serie/tokyorevengers/ → /serie/tokyo-revengers/
    //      Si el slug compact no tiene data pero está en name_map, derivar el
    //      slug-form vía sanitize_title del display name y redirect 301.
    //      Resuelve soft-404s reportados en GSC (ej. pos #1 + 0 clics por title vacío).
    $name_map = akibara_serie_name_map();
    $compact  = preg_replace( '/[^a-z0-9]/', '', $slug );
    if ( isset( $name_map[ $compact ] ) && $compact === $slug ) {
        $derived_slug = sanitize_title( $name_map[ $compact ] );
        if ( $derived_slug && $derived_slug !== $slug ) {
            $derived_data = akibara_get_serie_data( $derived_slug );
            if ( $derived_data && ! empty( $derived_data['products'] ) ) {
                wp_safe_redirect( home_url( '/serie/' . $derived_slug . '/' ), 301 );
                exit;
            }
        }
    }

    // 3) No match → 404
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    return get_404_template();
}

/**
 * Determine render mode for a serie URL: 'serie' | 'hub' | 'none'.
 * Statically cached per request — used by SEO filters to dispatch correctly.
 */
function akibara_serie_render_mode( string $slug ): string {
    static $cache = [];
    $slug = sanitize_title( $slug );
    if ( isset( $cache[ $slug ] ) ) return $cache[ $slug ];
    if ( akibara_get_serie_data( $slug ) )      return $cache[ $slug ] = 'serie';
    if ( akibara_get_serie_hub_data( $slug ) )  return $cache[ $slug ] = 'hub';
    return $cache[ $slug ] = 'none';
}

// ══════════════════════════════════════════════════════════════════
// TRAILING SLASH ENFORCER
// WP's redirect_canonical() doesn't handle custom rewrite tags, so
// we enforce trailing slash ourselves on /serie/{slug} and /serie.
// This prevents duplicate content indexing (/serie/xxx vs /serie/xxx/).
// Runs BEFORE template_include so the redirect short-circuits.
// ══════════════════════════════════════════════════════════════════

add_action( 'template_redirect', 'akibara_serie_force_trailing_slash', 5 );

function akibara_serie_force_trailing_slash(): void {
    if ( ! get_query_var( 'akibara_serie' ) && ! get_query_var( 'akibara_serie_index' ) ) {
        return;
    }

    $req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ( '' === $req_uri ) return;

    $parts = explode( '?', $req_uri, 2 );
    $path  = $parts[0];
    $qs    = isset( $parts[1] ) && '' !== $parts[1] ? '?' . $parts[1] : '';

    if ( '/' === substr( $path, -1 ) ) return;

    $target = $path . '/' . $qs;

    // Guard: never redirect to same URL (defense against future bugs).
    if ( $target === $req_uri ) return;

    wp_safe_redirect( $target, 301 );
    exit;
}

// ══════════════════════════════════════════════════════════════════
// SHARED — Display name overrides for series with tricky normalization
// ══════════════════════════════════════════════════════════════════

function akibara_serie_name_map(): array {
    return [
        // Clean slugs (most series)
        'onepiece'                  => 'One Piece',
        'myheroacademia'            => 'My Hero Academia',
        'jujutsukaisen'             => 'Jujutsu Kaisen',
        'chainsawman'               => 'Chainsaw Man',
        'dandadan'                  => 'Dan Da Dan',
        'blackclover'               => 'Black Clover',
        'bluelock'                  => 'Blue Lock',
        'onepunchman'               => 'One Punch Man',
        'fireforce'                 => 'Fire Force',
        'goldenkamuy'               => 'Golden Kamuy',
        'tokyorevengers'            => 'Tokyo Revengers',
        'vinlandsaga'               => 'Vinland Saga',
        'hunterxhunter'             => 'Hunter x Hunter',
        'spyxfamily'                => 'Spy x Family',
        'maximumberserk'            => 'Berserk Maximum',
        'kaguyasamaloveiswar'       => 'Kaguya-sama: Love is War',
        'haikyuu'                   => 'Haikyu!!',
        'noragami'                  => 'Noragami',
        'kingdom'                   => 'Kingdom',
        'vagabond'                  => 'Vagabond',
        'bungoustraydogs'           => 'Bungo Stray Dogs',
        'bungostraydogs'            => 'Bungo Stray Dogs',
        // Series whose norm includes edition/subtitle info
        'saintseiyaedkanzenban'     => 'Saint Seiya',
        'bleachremix'               => 'Bleach',
        'demonslayerkimetsunoyaiba' => 'Demon Slayer',
        'attackontitanediciondeluxe'=> 'Attack on Titan',
        'codegeassleloucheladelarebelion' => 'Code Geass',
        'jigokurakuhellsparadise'   => 'Jigokuraku: Hell\'s Paradise',
        'hellsingedicioninmortal'   => 'Hellsing',
        'gantzdeluxeedition'        => 'Gantz',
        'slamdunkdeluxe'            => 'Slam Dunk',
        'yuyuhakushokanzenban'      => 'Yu Yu Hakusho',
        'elpecadooriginaldetakopi'  => 'El Pecado Original de Takopi',
        'kaijuno8'                  => 'Kaiju No. 8',
        'mobpsycho100'              => 'Mob Psycho 100',
        'mononogatari'              => 'Mononogatari',
        'rezero'                    => 'Re:Zero',
    ];
}

// ══════════════════════════════════════════════════════════════════
// ALIAS MAP — Common/short slugs → canonical _akibara_serie_norm
// Redirects /serie/{alias}/ → /serie/{canonical}/ with 301
// ══════════════════════════════════════════════════════════════════

function akibara_serie_alias_map(): array {
    return [
        // Edition/subtitle stripped
        'saint-seiya'           => 'saint-seiya-ed-kanzenban',
        'bleach'                => 'bleach-remix',
        'demon-slayer'          => 'demon-slayer-kimetsu-no-yaiba',
        'kimetsu-no-yaiba'      => 'demon-slayer-kimetsu-no-yaiba',
        'attack-on-titan'       => 'attack-on-titan-edicion-deluxe',
        'code-geass'            => 'code-geass-lelouch-el-de-la-rebelion',
        'jigokuraku'            => 'jigokuraku-hells-paradise',
        'hell-paradise'         => 'jigokuraku-hells-paradise',
        'hellsing'              => 'hellsing-edicion-inmortal',
        'gantz'                 => 'gantz-deluxe-edition',
        'slam-dunk'             => 'slam-dunk-deluxe',
        'yu-yu-hakusho'         => 'yu-yu-hakusho-kanzenban',
        'takopi'                => 'el-pecado-original-de-takopi',
        // Common alternate slugs
        'kaiju-8'               => 'kaiju-no-8',
        'mob-psycho'            => 'mob-psycho-100',
        'monogatari'            => 'mononogatari',
        'jujutsu-kaisen-novela' => 'jujutsu-kaisen-0-movie-edition-la-novela',
        'hellblazer'            => 'biblioteca-vertigo-john-constantine-hellblazer',
        'hunter-x-hunter'       => 'hunter-x-hunter',
    ];
}

add_action( 'template_redirect', 'akibara_serie_alias_redirect', 6 );

function akibara_serie_alias_redirect(): void {
    $slug = get_query_var( 'akibara_serie' );
    if ( empty( $slug ) ) return;

    $alias_map = akibara_serie_alias_map();
    $canonical = $alias_map[ $slug ] ?? null;
    if ( ! $canonical || $canonical === $slug ) return;

    wp_safe_redirect( home_url( '/serie/' . $canonical . '/' ), 301 );
    exit;
}

// ══════════════════════════════════════════════════════════════════
// DATA LOADER — Get all products for a serie
// ══════════════════════════════════════════════════════════════════

function akibara_get_serie_data( string $serie_norm ): ?array {
    if ( empty( $serie_norm ) ) return null;

    // Accept BOTH storage formats simultaneously:
    //   - slug_form: hyphenated slug matching pa_serie term (e.g. 'jujutsu-kaisen'). Used in URLs.
    //   - norm_form: compact form without non-alnum chars (e.g. 'jujutsukaisen'). Used as name_map key.
    // In DB ~72 of 95 series use slug_form, ~23 use norm_form; match both so all resolve.
    $slug_form = strtolower( $serie_norm );
    $norm_form = preg_replace( '/[^a-z0-9]/', '', $slug_form );

    // Cache keyed by slug_form (URL shape).
    $cache_key = 'akb_serie_' . md5( $slug_form );
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) return $cached;

    global $wpdb;

    // Get all product IDs for this serie, ordered by volume number.
    // Match either storage format (IN clause); if slug_form == norm_form, same placeholder twice is harmless.
    $product_ids = $wpdb->get_col( $wpdb->prepare( "
        SELECT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_serie ON p.ID = pm_serie.post_id
            AND pm_serie.meta_key = '_akibara_serie_norm'
            AND pm_serie.meta_value IN (%s, %s)
        LEFT JOIN {$wpdb->postmeta} pm_num ON p.ID = pm_num.post_id
            AND pm_num.meta_key = '_akibara_numero'
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        ORDER BY CAST(pm_num.meta_value AS UNSIGNED) ASC
    ", $slug_form, $norm_form ) );

    if ( empty( $product_ids ) ) return null;

    // Get serie display name — name_map takes priority (try norm_form then slug_form), then product meta, then fallback.
    $first_id = $product_ids[0];
    $name_map = akibara_serie_name_map();

    if ( isset( $name_map[ $norm_form ] ) ) {
        $serie_name = $name_map[ $norm_form ];
    } elseif ( isset( $name_map[ $slug_form ] ) ) {
        $serie_name = $name_map[ $slug_form ];
    } else {
        $serie_name = get_post_meta( $first_id, '_akibara_serie', true );
        if ( empty( $serie_name ) ) {
            $serie_name = ucwords( str_replace( [ '_', '-' ], ' ', $slug_form ) );
        }
    }

    // Get category from first product
    $cats = get_the_terms( $first_id, 'product_cat' );
    $category = '';
    $category_link = '';
    if ( $cats && ! is_wp_error( $cats ) ) {
        foreach ( $cats as $cat ) {
            if ( in_array( $cat->slug, [ 'manga', 'comics' ] ) ) {
                $category = $cat->name;
                $category_link = get_term_link( $cat );
                break;
            }
            // Use first non-default category
            if ( $cat->term_id != get_option( 'default_product_cat' ) ) {
                $category = $cat->name;
                $category_link = get_term_link( $cat );
            }
        }
    }

    // Get brand/editorial from first product
    $brands = get_the_terms( $first_id, 'product_brand' );
    $editorial = ( $brands && ! is_wp_error( $brands ) ) ? $brands[0]->name : '';

    // Stats
    $in_stock = 0;
    $preorder = 0;
    $out_of_stock = 0;
    $min_price = PHP_FLOAT_MAX;
    $max_price = 0;

    foreach ( $product_ids as $pid ) {
        $product = wc_get_product( $pid );
        if ( ! $product ) continue;

        $price = (float) $product->get_price();
        if ( $price > 0 ) {
            $min_price = min( $min_price, $price );
            $max_price = max( $max_price, $price );
        }

        $is_reserva = get_post_meta( $pid, '_akb_reserva', true ) === 'yes';
        if ( $is_reserva ) {
            $preorder++;
        } elseif ( $product->is_in_stock() ) {
            $in_stock++;
        } else {
            $out_of_stock++;
        }
    }

    if ( $min_price === PHP_FLOAT_MAX ) $min_price = 0;

    $data = [
        'serie_norm'    => $slug_form,
        'serie_name'    => $serie_name,
        'slug'          => $slug_form,
        'products'      => $product_ids,
        'total'         => count( $product_ids ),
        'in_stock'      => $in_stock,
        'preorder'      => $preorder,
        'out_of_stock'  => $out_of_stock,
        'min_price'     => $min_price,
        'max_price'     => $max_price,
        'category'      => $category,
        'category_link' => $category_link,
        'editorial'     => $editorial,
    ];

    set_transient( $cache_key, $data, 30 * MINUTE_IN_SECONDS );
    return $data;
}

// ══════════════════════════════════════════════════════════════════
// HUB DATA LOADER — Aggregate variants of a parent slug
// e.g. 'rezero' → ['rezero-chapter-one', 'rezero-chapter-two', ...]
// ══════════════════════════════════════════════════════════════════

function akibara_get_serie_hub_data( string $hub_slug ): ?array {
    if ( empty( $hub_slug ) || strlen( $hub_slug ) < 3 ) return null;

    $cache_key = 'akb_serie_hub_' . md5( $hub_slug );
    $cached    = get_transient( $cache_key );
    // Only trust a non-empty cached array. Empty/falsy cached values (from prior bugs
    // or DB hiccups) are treated as a miss and recomputed — 30 min of bad UX is too long.
    if ( is_array( $cached ) && ! empty( $cached['variants'] ) ) return $cached;

    global $wpdb;

    // Find all distinct serie_norm values starting with "{hub_slug}-".
    // The required hyphen prevents short/ambiguous prefixes from matching unrelated series.
    $like = $wpdb->esc_like( $hub_slug ) . '-%';
    $serie_norms = $wpdb->get_col( $wpdb->prepare( "
        SELECT DISTINCT pm.meta_value
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            AND p.post_type = 'product' AND p.post_status = 'publish'
        WHERE pm.meta_key = '_akibara_serie_norm'
        AND pm.meta_value LIKE %s
        AND pm.meta_value != ''
        ORDER BY pm.meta_value ASC
    ", $like ) );

    if ( empty( $serie_norms ) ) return null; // Don't cache negatives — see above.

    $variants = [];
    foreach ( $serie_norms as $norm ) {
        $data = akibara_get_serie_data( $norm );
        if ( ! $data ) continue;

        $first_id = $data['products'][0] ?? 0;
        $cover    = '';
        if ( $first_id ) {
            $product = wc_get_product( $first_id );
            if ( $product && ( $img_id = $product->get_image_id() ) ) {
                $cover = wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' );
            }
        }

        $variants[] = [
            'norm'     => $norm,
            'slug'     => $norm,
            'name'     => $data['serie_name'],
            'count'    => $data['total'],
            'in_stock' => $data['in_stock'],
            'preorder' => $data['preorder'],
            'cover'    => $cover,
            'url'      => home_url( '/serie/' . $norm . '/' ),
        ];
    }

    if ( empty( $variants ) ) return null;

    // Sort variants in narrative order: trailing arabic/roman numeral or ordinal word wins
    // over alphabetical ("One, Two, Three, Four" not "Four, One, Three, Two").
    usort( $variants, function ( $a, $b ) {
        $ka = akibara_serie_variant_sort_key( $a['name'] );
        $kb = akibara_serie_variant_sort_key( $b['name'] );
        return $ka === $kb ? strcasecmp( $a['name'], $b['name'] ) : $ka <=> $kb;
    } );

    // Hub display name: prefer name_map (norm_form), then derive from longest common prefix
    // of the variants' display names, then humanize the slug.
    $name_map  = akibara_serie_name_map();
    $norm_form = preg_replace( '/[^a-z0-9]/', '', $hub_slug );
    if ( isset( $name_map[ $norm_form ] ) ) {
        $hub_name = $name_map[ $norm_form ];
    } elseif ( isset( $name_map[ $hub_slug ] ) ) {
        $hub_name = $name_map[ $hub_slug ];
    } else {
        $hub_name = akibara_serie_hub_common_name( array_column( $variants, 'name' ), $hub_slug );
    }

    $result = [
        'hub_slug'      => $hub_slug,
        'hub_name'      => $hub_name,
        'variants'      => $variants,
        'total_volumes' => array_sum( array_column( $variants, 'count' ) ),
        'in_stock'      => array_sum( array_column( $variants, 'in_stock' ) ),
        'preorder'      => array_sum( array_column( $variants, 'preorder' ) ),
    ];

    set_transient( $cache_key, $result, 30 * MINUTE_IN_SECONDS );
    return $result;
}

/**
 * Extract a numeric sort key from a serie variant display name.
 * Prefers (in order): trailing arabic digits ("Chapter 2", "Vol. 3"),
 * trailing roman numerals ("Part III"), trailing ordinal words in English
 * or Spanish ("Chapter One", "Parte Segunda"). Returns 999 when no cue.
 */
function akibara_serie_variant_sort_key( string $name ): int {
    $clean = trim( $name );
    if ( $clean === '' ) return 999;

    if ( preg_match( '/(\d+)\s*$/', $clean, $m ) ) return (int) $m[1];

    static $ordinals = null;
    if ( $ordinals === null ) {
        $ordinals = [
            // English cardinals
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
            'eleven' => 11, 'twelve' => 12,
            // English ordinals
            'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
            'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
            // Spanish cardinals
            'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5,
            'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10,
            // Spanish ordinals
            'primero' => 1, 'primera' => 1, 'segundo' => 2, 'segunda' => 2,
            'tercero' => 3, 'tercera' => 3, 'cuarto' => 4, 'cuarta' => 4,
            'quinto' => 5, 'quinta' => 5, 'sexto' => 6, 'sexta' => 6,
            'septimo' => 7, 'septima' => 7, 'octavo' => 8, 'octava' => 8,
            // Roman numerals (trailing only — guard against false positives like "I" as "yo")
            'i' => 1, 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5,
            'vi' => 6, 'vii' => 7, 'viii' => 8, 'ix' => 9, 'x' => 10,
            'xi' => 11, 'xii' => 12,
        ];
    }

    $words = preg_split( '/\s+/', mb_strtolower( $clean, 'UTF-8' ) );
    $last  = end( $words );
    // Strip trailing punctuation from the last token (e.g. "Three.")
    $last  = rtrim( $last, ".,;:-–—" );
    if ( isset( $ordinals[ $last ] ) ) return $ordinals[ $last ];

    return 999;
}

/**
 * Derive a hub name from the longest common prefix of variant names.
 * Falls back to humanized slug when prefix is too short.
 */
function akibara_serie_hub_common_name( array $names, string $fallback_slug ): string {
    if ( empty( $names ) ) return ucwords( str_replace( '-', ' ', $fallback_slug ) );
    $first  = trim( reset( $names ) );
    $prefix = $first;
    foreach ( $names as $n ) {
        $n   = trim( $n );
        $max = min( strlen( $prefix ), strlen( $n ) );
        $i   = 0;
        while ( $i < $max && $prefix[ $i ] === $n[ $i ] ) $i++;
        $prefix = substr( $prefix, 0, $i );
        if ( $prefix === '' ) break;
    }
    $prefix = trim( $prefix, " \t-:–—" );
    if ( strlen( $prefix ) < 3 ) {
        return ucwords( str_replace( '-', ' ', $fallback_slug ) );
    }
    return $prefix;
}

// Invalidate cache when products change
add_action( 'save_post_product', function ( int $post_id ) {
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

    // Always invalidate index cache (product may be reassigned/removed from a series)
    delete_transient( 'akb_series_index_v1' );

    $serie_norm = get_post_meta( $post_id, '_akibara_serie_norm', true );
    if ( ! empty( $serie_norm ) ) {
        // Invalidate both possible cache keys (slug_form used today, norm_form for legacy callers).
        $slug_form = strtolower( $serie_norm );
        $norm_form = preg_replace( '/[^a-z0-9]/', '', $slug_form );
        delete_transient( 'akb_serie_' . md5( $slug_form ) );
        if ( $slug_form !== $norm_form ) {
            delete_transient( 'akb_serie_' . md5( $norm_form ) );
        }
    }

    // Invalidate ALL hub transients — variants depend on per-serie data which we just bumped.
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_akb_serie_hub_%', '_transient_timeout_akb_serie_hub_%'
    ) );
} );

// ══════════════════════════════════════════════════════════════════
// INDEX DATA — Batch query for all series (used by template-serie-index.php)
// ══════════════════════════════════════════════════════════════════

function akibara_get_series_index_data(): array {
    $cache_key = 'akb_series_index_v1';
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) return $cached;

    global $wpdb;

    // Single query: all series with counts, total sales, latest volume date
    $rows = $wpdb->get_results( "
        SELECT
            pm_norm.meta_value AS serie_norm,
            MIN(pm_name.meta_value) AS serie_name,
            COUNT(DISTINCT p.ID) AS volume_count,
            MAX(p.post_date) AS latest_volume,
            COALESCE(SUM(CAST(pm_sales.meta_value AS UNSIGNED)), 0) AS total_sales,
            CAST(
                SUBSTRING_INDEX(
                    GROUP_CONCAT(
                        DISTINCT p.ID
                        ORDER BY CAST(pm_num.meta_value AS UNSIGNED) ASC, p.ID ASC
                    ),
                    ',',
                    1
                )
            AS UNSIGNED) AS first_product_id
        FROM {$wpdb->postmeta} pm_norm
        INNER JOIN {$wpdb->posts} p ON pm_norm.post_id = p.ID
            AND p.post_type = 'product' AND p.post_status = 'publish'
        LEFT JOIN {$wpdb->postmeta} pm_name
            ON p.ID = pm_name.post_id AND pm_name.meta_key = '_akibara_serie'
        LEFT JOIN {$wpdb->postmeta} pm_num
            ON p.ID = pm_num.post_id AND pm_num.meta_key = '_akibara_numero'
        LEFT JOIN {$wpdb->postmeta} pm_sales
            ON p.ID = pm_sales.post_id AND pm_sales.meta_key = 'total_sales'
        WHERE pm_norm.meta_key = '_akibara_serie_norm'
            AND pm_norm.meta_value != ''
        GROUP BY pm_norm.meta_value
        HAVING volume_count >= 2
    " );

    if ( empty( $rows ) ) {
        set_transient( $cache_key, [ 'series' => [], 'total' => 0, 'editorials' => [] ], 2 * HOUR_IN_SECONDS );
        return [ 'series' => [], 'total' => 0, 'editorials' => [] ];
    }

    // Batch-prime WP caches for all first-product IDs (avoids N+1)
    $first_ids = array_map( 'intval', array_column( $rows, 'first_product_id' ) );
    _prime_post_caches( $first_ids );
    update_meta_cache( 'post', $first_ids );
    update_object_term_cache( $first_ids, 'product' );

    $name_map   = akibara_serie_name_map();
    $series     = [];
    $editorials = [];
    $manga_cats = [ 'manga', 'shonen', 'seinen', 'shojo', 'josei', 'kodomo', 'isekai' ];
    $comic_cats = [ 'comics', 'comic', 'comic-americano', 'graphic-novel' ];

    foreach ( $rows as $row ) {
        $norm = $row->serie_norm;

        // Resolve display name
        if ( isset( $name_map[ $norm ] ) ) {
            $name = $name_map[ $norm ];
        } else {
            $name = $row->serie_name;
            if ( empty( $name ) ) {
                $name = ucwords( str_replace( [ '_', '-' ], ' ', $norm ) );
            }
        }

        // First product metadata (covers, categories, editorial)
        $pid       = (int) $row->first_product_id;
        $cover     = '';
        $category  = '';
        $editorial = '';

        if ( $pid ) {
            $product = wc_get_product( $pid );
            if ( $product ) {
                $img_id = $product->get_image_id();
                $cover  = $img_id ? wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : '';

                $cats = get_the_terms( $pid, 'product_cat' );
                if ( $cats && ! is_wp_error( $cats ) ) {
                    foreach ( $cats as $cat ) {
                        if ( in_array( $cat->slug, $manga_cats, true ) ) {
                            $category = 'manga';
                            break;
                        }
                        if ( in_array( $cat->slug, $comic_cats, true ) ) {
                            $category = 'comics';
                            break;
                        }
                    }
                }

                $brands    = get_the_terms( $pid, 'product_brand' );
                $editorial = ( $brands && ! is_wp_error( $brands ) ) ? $brands[0]->name : '';
                if ( $editorial ) $editorials[ $editorial ] = true;
            }
        }

        $series[] = [
            'norm'      => $norm,
            'slug'      => sanitize_title( $norm ),
            'name'      => $name,
            'count'     => (int) $row->volume_count,
            'sales'     => (int) $row->total_sales,
            'latest'    => $row->latest_volume,
            'cover'     => $cover,
            'category'  => $category ?: 'other',
            'editorial' => $editorial,
        ];
    }

    // Sort alphabetically by name
    usort( $series, fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

    $data = [
        'series'     => $series,
        'total'      => count( $series ),
        'editorials' => array_keys( $editorials ),
    ];

    set_transient( $cache_key, $data, 2 * HOUR_IN_SECONDS );
    return $data;
}

// ══════════════════════════════════════════════════════════════════
// SEO — Title, Description, Canonical, OG Tags, Schema
// ══════════════════════════════════════════════════════════════════

/**
 * Helper: detect active serie or index
 */
function akibara_is_serie_page(): bool {
    return ! empty( get_query_var( 'akibara_serie' ) ) || ! empty( get_query_var( 'akibara_serie_index' ) );
}

// Prevent WP from treating serie pages as blog archive — must run early
add_action( 'pre_get_posts', function ( WP_Query $query ) {
    if ( ! $query->is_main_query() || is_admin() ) return;
    if ( ! empty( get_query_var( 'akibara_serie' ) ) || ! empty( get_query_var( 'akibara_serie_index' ) ) ) {
        $query->is_home    = false;
        $query->is_archive = false;
        $query->is_singular = true; // treat as a singular page for Rank Math
    }
}, 1 ); // priority 1 = before Rank Math reads query flags

// ── Title ──────────────────────────────────────────────────────────

// Resolve title for serie/hub/index (single source for all title filters).
function akibara_serie_resolve_title( string $fallback ): string {
    $slug = get_query_var( 'akibara_serie' );
    if ( ! empty( $slug ) ) {
        $clean = sanitize_title( $slug );
        $mode  = akibara_serie_render_mode( $clean );
        if ( 'serie' === $mode ) {
            $data = akibara_get_serie_data( $clean );
            return $data['serie_name'] . ' - Comprar manga en Akibara Chile';
        }
        if ( 'hub' === $mode ) {
            $hub = akibara_get_serie_hub_data( $clean );
            return $hub['hub_name'] . ' - Todos los arcos en Akibara Chile';
        }
    }
    if ( get_query_var( 'akibara_serie_index' ) ) {
        return 'Todas las Series de Manga y Cómics | Akibara Chile';
    }
    return $fallback;
}

// pre_get_document_title runs before WP adds site name, good for <title>
add_filter( 'pre_get_document_title', 'akibara_serie_resolve_title', 99 );

// Rank Math title override (for og:title and their SEO title output)
add_filter( 'rank_math/frontend/title', 'akibara_serie_resolve_title', 99 );

// ── Meta Description ───────────────────────────────────────────────

add_filter( 'rank_math/frontend/description', function ( string $desc ): string {
    $slug = get_query_var( 'akibara_serie' );
    if ( empty( $slug ) ) {
        if ( get_query_var( 'akibara_serie_index' ) ) {
            $index_data = akibara_get_series_index_data();
            $total      = isset( $index_data['total'] ) ? (int) $index_data['total'] : 0;

            if ( $total > 0 ) {
                return "Explora {$total} series completas de manga y cómics en Akibara Chile. Descubre colecciones populares, novedades y tomos con envío a todo Chile.";
            }

            return 'Explora las series completas de manga y cómics en Akibara Chile. Descubre colecciones populares, novedades y tomos con envío a todo Chile.';
        }
        return $desc;
    }

    $clean = sanitize_title( $slug );
    $mode  = akibara_serie_render_mode( $clean );

    if ( 'hub' === $mode ) {
        $hub      = akibara_get_serie_hub_data( $clean );
        $name     = $hub['hub_name'];
        $count    = count( $hub['variants'] );
        $vols     = $hub['total_volumes'];
        $preorder = $hub['preorder'] > 0 ? " {$hub['preorder']} en preventa." : '';
        return "Descubre todos los arcos de {$name} en Akibara Chile: {$count} sagas, {$vols} volúmenes con envío a todo Chile.{$preorder} Stock completo y colección garantizada.";
    }

    if ( 'serie' === $mode ) {
        $data      = akibara_get_serie_data( $clean );
        $name      = $data['serie_name'];
        $total     = $data['total'];
        $editorial = $data['editorial'] ? ' de ' . $data['editorial'] : '';
        $preorder  = $data['preorder'] > 0 ? " {$data['preorder']} en preventa." : '';
        return "Compra {$name}{$editorial} en Akibara Chile. {$total} volúmenes disponibles con envío a todo Chile.{$preorder} Stock completo y colección garantizada.";
    }

    return $desc;
}, 99 );

// ── Canonical ─────────────────────────────────────────────────────

// Override Rank Math canonical — single source of truth, no duplicates
add_filter( 'rank_math/frontend/canonical', function ( string $canonical ): string {
    $slug = get_query_var( 'akibara_serie' );
    if ( ! empty( $slug ) ) return home_url( '/serie/' . sanitize_title( $slug ) . '/' );
    if ( get_query_var( 'akibara_serie_index' ) ) return home_url( '/serie/' );
    return $canonical;
}, 99 );

// ── Open Graph ────────────────────────────────────────────────────

add_filter( 'rank_math/opengraph/url', function ( string $url ): string {
    $slug = get_query_var( 'akibara_serie' );
    if ( ! empty( $slug ) ) return home_url( '/serie/' . sanitize_title( $slug ) . '/' );
    if ( get_query_var( 'akibara_serie_index' ) ) return home_url( '/serie/' );
    return $url;
}, 99 );

add_filter( 'rank_math/opengraph/title', function ( string $title ): string {
    $slug = get_query_var( 'akibara_serie' );
    if ( ! empty( $slug ) ) {
        $clean = sanitize_title( $slug );
        $mode  = akibara_serie_render_mode( $clean );
        if ( 'serie' === $mode ) {
            $data = akibara_get_serie_data( $clean );
            return $data['serie_name'] . ' | Akibara Chile';
        }
        if ( 'hub' === $mode ) {
            $hub = akibara_get_serie_hub_data( $clean );
            return $hub['hub_name'] . ' | Akibara Chile';
        }
    }
    if ( get_query_var( 'akibara_serie_index' ) ) return 'Todas las Series | Akibara Chile';
    return $title;
}, 99 );

add_filter( 'rank_math/opengraph/description', function ( string $desc ): string {
    $slug = get_query_var( 'akibara_serie' );
    if ( empty( $slug ) && ! get_query_var( 'akibara_serie_index' ) ) return $desc;

    // Reuse the meta description filter result
    return apply_filters( 'rank_math/frontend/description', $desc );
}, 99 );

add_filter( 'rank_math/opengraph/type', function ( string $type ): string {
    if ( akibara_is_serie_page() ) return 'website';
    return $type;
}, 99 );

// Dynamic OG image: use volume 1 cover as social sharing image
add_filter( 'rank_math/opengraph/facebook/image', function ( $image_url ) {
    $slug = get_query_var( 'akibara_serie' );
    if ( empty( $slug ) ) return $image_url;

    $clean = sanitize_title( $slug );
    $mode  = akibara_serie_render_mode( $clean );

    $first_id = 0;
    if ( 'serie' === $mode ) {
        $data = akibara_get_serie_data( $clean );
        $first_id = $data && ! empty( $data['products'] ) ? (int) $data['products'][0] : 0;
    } elseif ( 'hub' === $mode ) {
        $hub = akibara_get_serie_hub_data( $clean );
        if ( $hub && ! empty( $hub['variants'] ) ) {
            $first_variant = $hub['variants'][0];
            $first_data    = akibara_get_serie_data( $first_variant['norm'] );
            $first_id      = $first_data && ! empty( $first_data['products'] ) ? (int) $first_data['products'][0] : 0;
        }
    }

    if ( ! $first_id ) return $image_url;

    $first_product = wc_get_product( $first_id );
    if ( $first_product ) {
        $img_id = $first_product->get_image_id();
        if ( $img_id ) {
            $img_url = wp_get_attachment_image_url( $img_id, 'large' );
            if ( $img_url ) return $img_url;
        }
    }
    return $image_url;
} );

// Clean up Rank Math residual meta for serie pages
add_filter( 'rank_math/opengraph/facebook/article:published_time', function ( $time ) {
    if ( get_query_var( 'akibara_serie' ) ) return false;
    return $time;
} );
add_filter( 'rank_math/opengraph/twitter/twitter:label1', function ( $label ) {
    if ( get_query_var( 'akibara_serie' ) ) return false;
    return $label;
} );
add_filter( 'rank_math/opengraph/twitter/twitter:data1', function ( $data ) {
    if ( get_query_var( 'akibara_serie' ) ) return false;
    return $data;
} );

// ── Schema: CollectionPage + ItemList + FAQPage ────────────────────

// Disable Rank Math's default schema on serie pages (outputs wrong blog schema)
add_filter( 'rank_math/schema/output', function ( array $schemas ): array {
    if ( ! akibara_is_serie_page() ) return $schemas;
    // Remove all Rank Math schemas — we output our own below
    return [];
}, 99 );

add_action( 'wp_head', 'akibara_serie_full_schema', 20 );

function akibara_serie_full_schema(): void {
    $slug = get_query_var( 'akibara_serie' );

    // Serie index page schema
    if ( ! empty( get_query_var( 'akibara_serie_index' ) ) ) {
        $index_data   = akibara_get_series_index_data();
        $total_series = isset( $index_data['total'] ) ? (int) $index_data['total'] : 0;

        $item_list = [];
        $position  = 1;
        foreach ( array_slice( $index_data['series'] ?? [], 0, 40 ) as $serie ) {
            $item_list[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'url'      => home_url( '/serie/' . $serie['slug'] . '/' ),
                'name'     => $serie['name'],
            ];
        }

        $description = $total_series > 0
            ? "Explora {$total_series} series completas de manga y cómics en Akibara Chile."
            : 'Explora las series completas de manga y cómics en Akibara Chile.';

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'CollectionPage',
            '@id'      => home_url( '/serie/' ),
            'url'      => home_url( '/serie/' ),
            'name'     => 'Todas las Series de Manga y Cómics | Akibara Chile',
            'description' => $description,
            'isPartOf' => [ '@id' => home_url( '/#website' ) ],
            'breadcrumb' => [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [ '@type' => 'ListItem', 'position' => 1, 'item' => [ '@id' => home_url( '/' ), 'name' => 'Inicio' ] ],
                    [ '@type' => 'ListItem', 'position' => 2, 'item' => [ '@id' => home_url( '/serie/' ), 'name' => 'Series' ] ],
                ],
            ],
        ];

        if ( ! empty( $item_list ) ) {
            $schema['mainEntity'] = [
                '@type'           => 'ItemList',
                'name'            => 'Listado de series de manga y cómics',
                'numberOfItems'   => $total_series,
                'itemListElement' => $item_list,
            ];
        }

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
        return;
    }

    if ( empty( $slug ) ) return;

    $clean = sanitize_title( $slug );
    $mode  = akibara_serie_render_mode( $clean );

    // Hub schema: CollectionPage + ItemList of arc pages
    if ( 'hub' === $mode ) {
        $hub      = akibara_get_serie_hub_data( $clean );
        $hub_url  = home_url( '/serie/' . $clean . '/' );
        $hub_name = $hub['hub_name'];
        $items    = [];
        $position = 1;
        foreach ( $hub['variants'] as $v ) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'url'      => $v['url'],
                'name'     => $v['name'],
            ];
        }
        $hub_schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            '@id'         => $hub_url,
            'url'         => $hub_url,
            'name'        => $hub_name . ' - Todos los arcos en Akibara Chile',
            'description' => "Descubre todos los arcos de {$hub_name}: {$hub['total_volumes']} volúmenes en " . count( $hub['variants'] ) . " sagas.",
            'isPartOf'    => [ '@id' => home_url( '/#website' ) ],
            'breadcrumb'  => [
                '@type'           => 'BreadcrumbList',
                'itemListElement' => [
                    [ '@type' => 'ListItem', 'position' => 1, 'item' => [ '@id' => home_url( '/' ),       'name' => 'Inicio' ] ],
                    [ '@type' => 'ListItem', 'position' => 2, 'item' => [ '@id' => home_url( '/serie/' ), 'name' => 'Series' ] ],
                    [ '@type' => 'ListItem', 'position' => 3, 'item' => [ '@id' => $hub_url,             'name' => $hub_name ] ],
                ],
            ],
            'mainEntity'  => [
                '@type'           => 'ItemList',
                'name'            => $hub_name . ' — Arcos disponibles',
                'numberOfItems'   => count( $hub['variants'] ),
                'itemListElement' => $items,
            ],
        ];
        echo '<script type="application/ld+json">' . wp_json_encode( $hub_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
        return;
    }

    $data = akibara_get_serie_data( $clean );
    if ( ! $data ) return;

    $serie_url  = home_url( '/serie/' . sanitize_title( $slug ) . '/' );
    $name       = $data['serie_name'];
    $editorial  = $data['editorial'] ?: '';
    $total      = $data['total'];

    // Build ItemList (max 50 products)
    $items    = [];
    $position = 1;
    foreach ( array_slice( $data['products'], 0, 50 ) as $pid ) {
        $product = wc_get_product( $pid );
        if ( ! $product ) continue;

        $img_id   = $product->get_image_id();
        $img_url  = $img_id ? wp_get_attachment_image_url( $img_id, 'product-card' ) : '';
        $price    = $product->get_price();
        $in_stock = $product->is_in_stock();

        $item = [
            '@type' => 'Product',
            'name'  => $product->get_name(),
            'url'   => get_permalink( $pid ),
        ];
        if ( $img_url ) $item['image'] = $img_url;
        if ( $editorial ) $item['brand'] = [ '@type' => 'Brand', 'name' => $editorial ];
        if ( $price ) {
            $item['offers'] = [
                '@type'           => 'Offer',
                'price'           => $price,
                'priceCurrency'   => 'CLP',
                'availability'    => $in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'seller'          => [ '@type' => 'Organization', 'name' => 'Akibara' ],
            ];
        }

        $items[] = [
            '@type'    => 'ListItem',
            'position' => $position++,
            'item'     => $item,
        ];
    }

    // CollectionPage schema
    $page_schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        '@id'         => $serie_url,
        'url'         => $serie_url,
        'name'        => $name . ' - Comprar manga en Akibara Chile',
        'description' => "Compra {$name} en Akibara Chile. {$total} volúmenes disponibles con envío a todo Chile.",
        'isPartOf'    => [ '@id' => home_url( '/#website' ) ],
        'breadcrumb'  => [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                [ '@type' => 'ListItem', 'position' => 1, 'item' => [ '@id' => home_url( '/' ),       'name' => 'Inicio' ] ],
                [ '@type' => 'ListItem', 'position' => 2, 'item' => [ '@id' => home_url( '/tienda/' ), 'name' => 'Tienda' ] ],
                [ '@type' => 'ListItem', 'position' => 3, 'item' => [ '@id' => $serie_url,            'name' => $name ] ],
            ],
        ],
        'mainEntity' => [
            '@type'           => 'ItemList',
            'name'            => $name . ' — Colección completa',
            'numberOfItems'   => $total,
            'itemListElement' => $items,
        ],
    ];

    // FAQ schema for rich snippets
    $min_fmt   = number_format( $data['min_price'], 0, ',', '.' );
    $max_fmt   = number_format( $data['max_price'], 0, ',', '.' );
    $price_txt = ( $data['min_price'] === $data['max_price'] )
        ? "a \${$min_fmt} CLP"
        : "desde \${$min_fmt} hasta \${$max_fmt} CLP";

    $in_stock_count  = $data['in_stock'];
    $preorder_count  = $data['preorder'];
    $available_total = $in_stock_count + $preorder_count;

    $faq_schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => [
            [
                '@type'          => 'Question',
                'name'           => "¿Dónde puedo comprar {$name} en Chile?",
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => "Puedes comprar {$name} en Akibara (akibara.cl), la tienda online de manga y cómics en Chile. Ofrecemos {$total} volúmenes de la colección completa con envío a todo Chile.",
                ],
            ],
            [
                '@type'          => 'Question',
                'name'           => "¿Cuánto cuesta {$name} en Chile?",
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => "Los volúmenes de {$name} en Akibara Chile están disponibles {$price_txt}. Aceptamos pago en cuotas sin interés.",
                ],
            ],
            [
                '@type'          => 'Question',
                'name'           => "¿{$name} está disponible en stock?",
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => "Sí, {$available_total} de {$total} volúmenes de {$name} están disponibles en Akibara Chile ({$in_stock_count} en stock inmediato" . ( $preorder_count > 0 ? " y {$preorder_count} en preventa" : '' ) . ").",
                ],
            ],
        ],
    ];

    echo '<script type="application/ld+json">' . wp_json_encode( $page_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
    echo '<script type="application/ld+json">' . wp_json_encode( $faq_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
}

// ── Intercept Rank Math head output and fix canonical + OG URL ────
// Rank Math fires its entire <head> output at wp_head priority 1.
// We wrap it with ob_start (priority 0) → capture & fix (priority 2).

add_action( 'wp_head', function (): void {
    if ( ! akibara_is_serie_page() ) return;
    ob_start();
}, 0 );

add_action( 'wp_head', function (): void {
    if ( ! akibara_is_serie_page() ) return;

    $slug  = get_query_var( 'akibara_serie' );


    if ( ! empty( $slug ) ) {
        $correct_url   = home_url( '/serie/' . sanitize_title( $slug ) . '/' );
        $correct_title = akibara_serie_resolve_title( 'Serie | Akibara' );
    } else {
        $correct_url   = home_url( '/serie/' );
        $correct_title = 'Todas las Series de Manga y Cómics | Akibara Chile';
    }

    $output = ob_get_clean();
    if ( false === $output ) return; // Buffer wasn't started

    // Fix canonical — replace whatever canonical Rank Math output with ours
    $output = preg_replace(
        '/<link\s+rel=["\']canonical["\'][^>]+>\s*\n?/',
        '<link rel="canonical" href="' . esc_url( $correct_url ) . '" />' . "\n",
        $output
    );

    // Fix og:url — Rank Math outputs it as the blog URL
    $output = preg_replace(
        '/(<meta\s+property=["\']og:url["\']\s+content=["\'])[^"\']*(["\'][^>]*>)/',
        '${1}' . esc_url( $correct_url ) . '${2}',
        $output
    );

    // Fix og:title
    $output = preg_replace(
        '/(<meta\s+property=["\']og:title["\']\s+content=["\'])[^"\']*(["\'][^>]*>)/',
        '${1}' . esc_attr( $correct_title ) . '${2}',
        $output
    );

    // Fix twitter:title
    $output = preg_replace(
        '/(<meta\s+name=["\']twitter:title["\']\s+content=["\'])[^"\']*(["\'][^>]*>)/',
        '${1}' . esc_attr( $correct_title ) . '${2}',
        $output
    );

    // Fix og:type — series should be 'website' not 'article'
    $output = preg_replace(
        '/(<meta\s+property=["\']og:type["\']\s+content=["\'])[^"\']*(["\'][^>]*>)/',
        '${1}website${2}',
        $output
    );

    // Fix <title> tag
    $output = preg_replace(
        '/<title>[^<]*<\/title>/',
        '<title>' . esc_html( $correct_title ) . '</title>',
        $output
    );

    // Remove Rank Math's schema block on serie pages (we output our own below)
    $output = preg_replace(
        '/<script[^>]+class=["\']rank-math-schema-pro["\'][^>]*>.*?<\/script>\s*\n?/s',
        '',
        $output
    );

    echo $output; // phpcs:ignore WordPress.Security.EscapeOutput
}, 2 );

// ── Force correct <title> tag via JS fallback ─────────────────────
add_action( 'wp_head', function (): void {
    $slug  = get_query_var( 'akibara_serie' );
    $index = get_query_var( 'akibara_serie_index' );

    if ( empty( $slug ) && empty( $index ) ) return;

    if ( ! empty( $slug ) ) {
        $title = esc_js( akibara_serie_resolve_title( 'Serie | Akibara' ) );
    } else {
        $title = esc_js( 'Todas las Series de Manga y Cómics | Akibara Chile' );
    }
    echo "<script>if(document.title!=='" . $title . "')document.title='" . $title . "';</script>\n";
}, 999 );

// ══════════════════════════════════════════════════════════════════
// SITEMAP — Custom sitemap for all serie pages
// ══════════════════════════════════════════════════════════════════

// Register /sitemap-series.xml via WP rewrite
add_action( 'init', function () {
    add_rewrite_rule( '^sitemap-series\.xml$', 'index.php?akibara_sitemap_series=1', 'top' );
    add_rewrite_tag( '%akibara_sitemap_series%', '([0-9]+)' );

    // Ensure rewrite rule is flushed once (WP-CLI runs with skip-themes, so manual flush is unreliable).
    if ( ! get_option( 'akb_series_sitemap_rewrite_v1' ) ) {
        flush_rewrite_rules( false );
        update_option( 'akb_series_sitemap_rewrite_v1', 1 );
    }
}, 0 );

// Serve the sitemap XML
add_action( 'template_redirect', function () {
    $query_flag = get_query_var( 'akibara_sitemap_series' );
    $uri        = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

    $is_direct_request = ( 0 === strpos( $uri, '/sitemap-series.xml' ) );
    if ( ! $query_flag && ! $is_direct_request ) {
        return;
    }

    global $wpdb;

    header( 'X-Akb-Series-Sitemap: 1' );
    // Get all unique serie_norm values
    $series = $wpdb->get_col( "
        SELECT DISTINCT meta_value
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_akibara_serie_norm'
        AND meta_value != ''
        ORDER BY meta_value ASC
    " );

    if ( empty( $series ) ) {
        wp_die( 'No series found', '', 404 );
    }

    header( 'Content-Type: application/xml; charset=UTF-8' );
    header( 'X-Robots-Tag: noindex' );
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ( $series as $serie_norm ) {
        $url      = home_url( '/serie/' . sanitize_title( $serie_norm ) . '/' );
        $lastmod  = gmdate( 'Y-m-d' );
        echo "  <url>\n";
        echo '    <loc>' . esc_url( $url ) . "</loc>\n";
        echo "    <lastmod>{$lastmod}</lastmod>\n";
        echo "    <changefreq>weekly</changefreq>\n";
        echo "    <priority>0.7</priority>\n";
        echo "  </url>\n";
    }

    // Also add the serie index
    echo "  <url>\n";
    echo '    <loc>' . esc_url( home_url( '/serie/' ) ) . "</loc>\n";
    echo '    <lastmod>' . gmdate( 'Y-m-d' ) . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";

    echo '</urlset>';
    exit;
} );

// Inject serie sitemap into Rank Math sitemap index
// rank_math/sitemap/index appends raw XML string to sitemapindex
add_filter( 'rank_math/sitemap/index', function ( string $index ): string {
    $url     = home_url( '/sitemap-series.xml' );
    $lastmod = gmdate( 'Y-m-d\TH:i:s+00:00' );
    $index  .= "\t<sitemap>\n\t\t<loc>" . esc_url( $url ) . "</loc>\n\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n\t</sitemap>\n";
    return $index;
}, 10 );

// ══════════════════════════════════════════════════════════════════
// INDEX PAGE — /serie/ lists all series
// ══════════════════════════════════════════════════════════════════

add_action( 'init', function () {
    add_rewrite_rule( '^serie/?$', 'index.php?akibara_serie_index=1', 'top' );
    add_rewrite_tag( '%akibara_serie_index%', '([0-9]+)' );
} );
