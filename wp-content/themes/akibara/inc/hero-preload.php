<?php
/**
 * Preload del hero de la home para mejorar LCP.
 *
 * Problema detectado (Lighthouse mobile PSI): LCP = 4.2s en mobile.
 * El elemento LCP es la hero image; aunque el <img> ya tiene `fetchpriority="high"`
 * + `loading="eager"`, el browser no puede descargarla hasta parsear el `<picture>`
 * en medio del `<body>`. Con `<link rel="preload" imagesrcset>` en `<head>`, la
 * descarga arranca ~800ms antes — suficiente para bajar LCP al umbral "good" (<2.5s).
 *
 * Solo aplica en front-page (home). En otras URLs no hace nada.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'akibara_hero_preload', 1 );
function akibara_hero_preload(): void {
    if ( ! is_front_page() ) return;

    // Las rutas deben coincidir con `template-parts/front-page/hero.php`.
    $hero_subdir = get_option( 'akibara_hero_upload_subdir', '/2026/04/' );
    $upload_url  = wp_get_upload_dir()['baseurl'] . apply_filters( 'akibara_hero_upload_url_subdir', $hero_subdir );

    $hero_files = apply_filters( 'akibara_hero_files', [
        'desk_webp' => 'hero-gemini-desktop.webp',
        'tab_webp'  => 'hero-gemini-tablet.webp',
        'mob_webp'  => 'hero-gemini-mobile.webp',
    ] );

    $mob  = esc_url( $upload_url . $hero_files['mob_webp'] );
    $tab  = esc_url( $upload_url . $hero_files['tab_webp'] );
    $desk = esc_url( $upload_url . $hero_files['desk_webp'] );

    // 3 preloads con `media` en vez de 1 con imagesrcset: cada browser solo
    // descarga la variante que matchea su viewport. El `imagesrcset` tenía
    // ambigüedad en Lighthouse mobile → descargaba desktop (1280w) en lugar
    // de mobile (714w), inflando LCP a 12s+ en audits mobile.
    //
    // Breakpoints sincronizados con los `<source media="">` del <picture> en
    // template-parts/front-page/hero.php para que preload y render coincidan.
    $variants = [
        [ 'url' => $mob,  'media' => '(max-width: 600px)' ],
        [ 'url' => $tab,  'media' => '(min-width: 601px) and (max-width: 900px)' ],
        [ 'url' => $desk, 'media' => '(min-width: 901px)' ],
    ];

    foreach ( $variants as $v ) {
        printf(
            "<link rel=\"preload\" as=\"image\" type=\"image/webp\" href=\"%s\" media=\"%s\" fetchpriority=\"high\">\n",
            esc_attr( $v['url'] ),
            esc_attr( $v['media'] )
        );
    }
}
