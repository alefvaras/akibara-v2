<?php
/**
 * Hero Section — Akibara Homepage
 * Imágenes Gemini con texto integrado + animaciones CSS/JS
 * SEO: texto oculto para crawlers (alt + h1 visually-hidden + structured data)
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// HO-8: upload_url configurable via filter (prev hardcode a /2026/04/).
// Cambiar via filter en functions.php o via option `akibara_hero_upload_subdir`.
$hero_subdir = get_option( 'akibara_hero_upload_subdir', '/2026/04/' );
$upload_url  = wp_get_upload_dir()['baseurl'] . apply_filters( 'akibara_hero_upload_url_subdir', $hero_subdir );
$shop_url    = get_permalink( wc_get_page_id( 'shop' ) );

// HO-7 + HO-8 (2026-04-19): filenames SEO-friendly.
// NOTA: Este archivo hero.php standalone NO se usa actualmente.
// El activo es template-parts/front-page/hero.php (llamado desde front-page.php).
// Se mantiene sincronizado por higiene por si alguien lo activa en el futuro.
$hero_files = apply_filters( 'akibara_hero_files', [
	'desk_webp' => 'hero-gemini-desktop.webp',
	'desk_jpg'  => 'akibara-hero-manga-comics-chile-desktop.jpeg',
	'tab_webp'  => 'hero-gemini-tablet.webp',
	'tab_jpg'   => 'akibara-hero-manga-comics-chile-tablet.jpeg',
	'mob_webp'  => 'hero-gemini-mobile.webp',
	'mob_jpg'   => 'akibara-hero-manga-comics-chile-mobile.jpeg',
] );

$desk_webp = $upload_url . $hero_files['desk_webp'];
$desk_jpg  = $upload_url . $hero_files['desk_jpg'];
$tab_webp  = $upload_url . $hero_files['tab_webp'];
$tab_jpg   = $upload_url . $hero_files['tab_jpg'];
$mob_webp  = $upload_url . $hero_files['mob_webp'];
$mob_jpg   = $upload_url . $hero_files['mob_jpg'];
?>

<!-- JSON-LD: Organization + imagen hero para Google -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Store",
  "name": "Akibara",
  "description": "Tu Distrito del Manga y Cómics en Chile. Las mejores series de manga japonés y cómics americanos.",
  "url": "<?php echo esc_url( home_url() ); ?>",
  "image": "<?php echo esc_url( $desk_jpg ); ?>",
  "hasOfferCatalog": {
    "@type": "OfferCatalog",
    "name": "Manga y Cómics",
    "itemListElement": [
      { "@type": "Offer", "itemOffered": { "@type": "Product", "name": "Manga" } },
      { "@type": "Offer", "itemOffered": { "@type": "Product", "name": "Cómics" } }
    ]
  }
}
</script>

<section
    class="aki-hero"
    aria-labelledby="aki-hero-title"
    data-shop-url="<?php echo esc_url( $shop_url ); ?>"
    itemscope itemtype="https://schema.org/WPHeader">

    <!-- SEO: texto para crawlers, invisible para usuarios.
         <p> en vez de <h1> para evitar duplicar H1 con el de front-page.php (homepage-h1). -->
    <p id="aki-hero-title" class="aki-hero__seo-heading">
        Manga y Cómics en Chile — <span>Explora nuestra tienda</span>
    </p>

    <picture class="aki-hero__picture">
        <!-- Mobile portrait (≤600px) -->
        <source
            media="(max-width: 600px)"
            srcset="<?php echo esc_url( $mob_webp ); ?>"
            type="image/webp"
            width="714" height="1280">
        <source
            media="(max-width: 600px)"
            srcset="<?php echo esc_url( $mob_jpg ); ?>"
            type="image/jpeg"
            width="714" height="1280">

        <!-- Tablet (601–900px) -->
        <source
            media="(max-width: 900px) and (min-width: 601px)"
            srcset="<?php echo esc_url( $tab_webp ); ?>"
            type="image/webp"
            width="1200" height="896">
        <source
            media="(max-width: 900px) and (min-width: 601px)"
            srcset="<?php echo esc_url( $tab_jpg ); ?>"
            type="image/jpeg"
            width="1200" height="896">

        <!-- Desktop (>900px) -->
        <source
            srcset="<?php echo esc_url( $desk_webp ); ?>"
            type="image/webp"
            width="1280" height="542">

        <img
            src="<?php echo esc_url( $desk_jpg ); ?>"
            alt="Tienda de manga y cómics en Chile — Descubre MANGA y CÓMICS en Akibara"
            class="aki-hero__img"
            data-no-lazy="1"
            loading="eager"
            fetchpriority="high"
            width="1280" height="542"
            itemprop="image">
    </picture>

    <!-- Atmospheric overlays -->
    <div class="aki-hero__ink" aria-hidden="true"></div>
    <div class="aki-hero__scanlines" aria-hidden="true"></div>
    <div class="aki-hero__vignette" aria-hidden="true"></div>

    <!-- Glow overlay sobre franjas MANGA (izq) y CÓMICS (der) -->
    <div class="aki-hero__glow aki-hero__glow--manga" aria-hidden="true"></div>
    <div class="aki-hero__glow aki-hero__glow--comics" aria-hidden="true"></div>

    <!-- CTA hitbox transparente sobre "EXPLORAR AHORA" — REMOVED per user request -->

</section>
