<?php
/**
 * Hero Section — Akibara Homepage
 * Imágenes Gemini con texto integrado + animaciones CSS/JS
 * SEO: texto oculto para crawlers (alt + h1 visually-hidden + structured data)
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// HO-7 + HO-8 (2026-04-19): filenames SEO-friendly + subdir configurable via filter.
// JPG fallbacks renombrados de "Gemini_Generated_Image_*.jpeg" a "akibara-hero-manga-comics-chile-*.jpeg"
// para mejorar señal SEO débil que Google obtiene del filename en <img src>.
// Los archivos originales con nombre Gemini siguen existiendo en server (WP attachments + thumbnails)
// para preservar cualquier URL indexada en Google Images.
$hero_subdir = get_option( 'akibara_hero_upload_subdir', '/2026/04/' );
$upload_url  = wp_get_upload_dir()['baseurl'] . apply_filters( 'akibara_hero_upload_url_subdir', $hero_subdir );
$shop_url    = get_permalink( wc_get_page_id( 'shop' ) );

$hero_files = apply_filters( 'akibara_hero_files', [
	'desk_webp' => 'hero-gemini-desktop.webp',
	'desk_jpg'  => 'akibara-hero-manga-comics-chile-desktop.jpeg',
	'tab_webp'  => 'hero-gemini-tablet-v3.webp',
	'tab_jpg'   => 'akibara-hero-manga-comics-chile-tablet-v3.jpeg',
	'mob_webp'  => 'hero-gemini-mobile-v3.webp',
	'mob_jpg'   => 'akibara-hero-manga-comics-chile-mobile-v3.jpeg',
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

    <!-- SEO: texto para crawlers, invisible para usuarios -->
    <p id="aki-hero-title" class="aki-hero__seo-heading">
        Manga y Cómics en Chile — <span>Explora nuestra tienda</span>
    </p>

    <picture class="aki-hero__picture">
        <!-- Mobile portrait (≤600px) -->
        <source
            media="(max-width: 600px)"
            srcset="<?php echo esc_url( $mob_webp ); ?>"
            type="image/webp"
            width="714" height="1122">
        <source
            media="(max-width: 600px)"
            srcset="<?php echo esc_url( $mob_jpg ); ?>"
            type="image/jpeg"
            width="714" height="1122">

        <!-- Tablet (601–900px) -->
        <source
            media="(max-width: 900px) and (min-width: 601px)"
            srcset="<?php echo esc_url( $tab_webp ); ?>"
            type="image/webp"
            width="1200" height="838">
        <source
            media="(max-width: 900px) and (min-width: 601px)"
            srcset="<?php echo esc_url( $tab_jpg ); ?>"
            type="image/jpeg"
            width="1200" height="838">

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
    <div class="aki-hero__scanlines" aria-hidden="true"></div>
    <div class="aki-hero__vignette" aria-hidden="true"></div>

    <!-- Glow overlay sobre franjas MANGA (izq) y CÓMICS (der) -->
    <div class="aki-hero__glow aki-hero__glow--manga" aria-hidden="true"></div>
    <div class="aki-hero__glow aki-hero__glow--comics" aria-hidden="true"></div>

    <?php
    $_mt = get_term_by( 'slug', 'manga', 'product_cat' );
    $_ct = get_term_by( 'slug', 'comics', 'product_cat' );
    $manga_url  = ( $_mt && ! is_wp_error( $_mt ) )  ? get_term_link( $_mt )  : home_url( '/categoria-producto/manga/' );
    $comics_url = ( $_ct && ! is_wp_error( $_ct ) ) ? get_term_link( $_ct ) : home_url( '/categoria-producto/comics/' );
    if ( is_wp_error( $manga_url ) )  $manga_url  = home_url( '/categoria-producto/manga/' );
    if ( is_wp_error( $comics_url ) ) $comics_url = home_url( '/categoria-producto/comics/' );
    ?>

    <!-- Clickable hotspots: MANGA, CÓMICS, EXPLORAR AHORA.
         Posiciones en % por breakpoint (hero-section.css). Las imágenes se renderizan
         con aspect-ratio matching a la natural, por eso los % son estables.

         Sprint 11 a11y fix #1 (audit 2026-04-26): hotspots invisibles + aria-label
         duplican tab order con la nav band visible (líneas 139-155 abajo, mismos 3
         destinos). Keyboard users veían focus-ring sobre rectángulos invisibles.
         Solución: aria-hidden + tabindex=-1 deja la región clickeable con mouse
         (preserva discovery visual del UX original) pero excluye de assistive tech
         + tab order. Los keyboard/SR users navegan por la nav band (line 139+).
         WCAG 2.4.4 Link Purpose, 1.3.1 Info and Relationships. -->
    <a href="<?php echo esc_url( $manga_url ); ?>" class="aki-hero__hit aki-hero__hit--manga" aria-hidden="true" tabindex="-1"></a>
    <a href="<?php echo esc_url( $comics_url ); ?>" class="aki-hero__hit aki-hero__hit--comics" aria-hidden="true" tabindex="-1"></a>
    <a href="<?php echo esc_url( $shop_url ); ?>" class="aki-hero__hit aki-hero__hit--explorar" aria-hidden="true" tabindex="-1"></a>

</section>

<!-- S1-14: CTA band mobile-only — asiste discovery de hotspots invisibles.
     3 links visibles (Manga / Cómics / Ver todo) debajo del hero solo en ≤600px. -->
<nav class="aki-hero-cta-band" aria-label="Categorías destacadas">
    <a href="<?php echo esc_url( $manga_url ); ?>"
       class="aki-hero-cta-btn aki-hero-cta-btn--manga"
       data-hero-cta="manga">
        <span class="aki-hero-cta-btn__label">Manga</span>
    </a>
    <a href="<?php echo esc_url( $comics_url ); ?>"
       class="aki-hero-cta-btn aki-hero-cta-btn--comics"
       data-hero-cta="comics">
        <span class="aki-hero-cta-btn__label">Cómics</span>
    </a>
    <a href="<?php echo esc_url( $shop_url ); ?>"
       class="aki-hero-cta-btn aki-hero-cta-btn--explorar"
       data-hero-cta="explorar">
        <span class="aki-hero-cta-btn__label">Ver todo</span>
    </a>
</nav>
