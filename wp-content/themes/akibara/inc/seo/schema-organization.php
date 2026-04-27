<?php
defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════
// ORGANIZATION SCHEMA — Site-wide structured data
// ═══════════════════════════════════════════════════════════════
add_action('wp_head', function () {
    if (!is_front_page()) return;
    if (defined('RANK_MATH_VERSION')) return;

    $logo = get_theme_mod('custom_logo');
    $logo_url = $logo ? wp_get_attachment_url($logo) : '';

    $org = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => 'Akibara',
        'url'      => home_url('/'),
        'sameAs'   => [
            'https://www.instagram.com/akibara.cl/',
            'https://www.tiktok.com/@akibara.cl',
            'https://www.facebook.com/akibara.cl/',
        ],
    ];

    if ($logo_url) {
        $org['logo'] = $logo_url;
    }

    echo '<script type="application/ld+json">' . wp_json_encode($org, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>' . "\n";
}, 20);

// ═══════════════════════════════════════════════════════════════
// RANK MATH SCHEMA FIX — Correct inconsistencies in cached schema
// ═══════════════════════════════════════════════════════════════
add_filter('rank_math/json_ld', function($data, $jsonld) {
    if (!is_array($data)) return $data;

    $json = wp_json_encode($data);
    $changed = false;

    // Fix: $70.000 → $55.000 in any schema text
    if (strpos($json, '70.000') !== false) {
        $json = str_replace('70.000', '55.000', $json);
        $changed = true;
    }

    // Fix: Hardcoded editorials → dynamic from product_brand taxonomy
    $hardcoded = 'Panini, Ivrea, Norma, ECC, Kodai, Planeta y m';
    if (strpos($json, $hardcoded) !== false || strpos($json, 'Norma') !== false || strpos($json, 'ECC') !== false || strpos($json, 'Kodai') !== false) {
        $brands = get_terms([
            'taxonomy'   => 'product_brand',
            'hide_empty' => true,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ]);
        if (!is_wp_error($brands) && !empty($brands)) {
            $names = wp_list_pluck($brands, 'name');
            $dynamic = implode(', ', array_slice($names, 0, -1)) . ' y ' . end($names);
            // Replace the full hardcoded phrase
            $json = preg_replace(
                '/editoriales autorizadas como Panini, Ivrea, Norma, ECC, Kodai, Planeta y m[^"]*/u',
                'editoriales autorizadas como ' . $dynamic,
                $json
            );
            // Also replace any remaining individual references
            $json = str_replace(['Norma, ECC, Kodai, Planeta', 'Norma', 'ECC', 'Kodai'], '', $json);
            $changed = true;
        }
    }

    if ($changed) {
        $data = json_decode($json, true);
    }

    // Remove Article schema from homepage — homepage is Organization/WebSite, not Article
    if ( is_front_page() ) {
        foreach ( array_keys( $data ) as $key ) {
            $node = $data[ $key ] ?? null;
            if ( is_array( $node ) && ( $node['@type'] ?? '' ) === 'Article' ) {
                unset( $data[ $key ] );
            }
        }
        unset( $data['Article'] );
    }

    return $data;
}, 99, 2);
