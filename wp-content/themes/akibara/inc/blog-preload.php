<?php
/**
 * Preload del featured image en single blog posts para mejorar LCP.
 *
 * Problema: el <img> ya usa `fetchpriority="high"` + `loading="eager"`, pero
 * el navegador no puede descargarlo hasta parsear el <img> en medio del <body>.
 * Con <link rel="preload" imagesrcset> en <head>, la descarga arranca cientos
 * de milisegundos antes → LCP mobile baja significativamente.
 *
 * Mirror de `hero-preload.php` (que ataca el hero de la home), pero para blog
 * posts. Solo emite el preload cuando:
 *   - Estamos en singular 'post'
 *   - El post tiene featured image
 *   - Los subsizes blog-card/blog-featured existen (evita 404s en preload)
 *
 * Usa el size 'blog-featured' (960w) como principal y blog-card (420w) como
 * fallback mobile — matching al markup de single.php.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'akibara_blog_preload_featured', 1 );
function akibara_blog_preload_featured(): void {
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$attachment_id = get_post_thumbnail_id();
	if ( ! $attachment_id ) {
		return;
	}

	// Resolve candidates in decreasing visual quality, then build srcset.
	$candidates = [
		'blog-card'     => 420,
		'blog-featured' => 960,
	];

	$parts = [];
	foreach ( $candidates as $size => $width ) {
		$src = wp_get_attachment_image_src( $attachment_id, $size );
		if ( $src && ! empty( $src[0] ) ) {
			$parts[] = esc_url( $src[0] ) . ' ' . (int) $width . 'w';
		}
	}

	// Fallback: if the custom sizes aren't on disk (e.g. older attachments),
	// emit a plain preload for the 'large' size rather than emitting nothing.
	if ( ! $parts ) {
		$large = wp_get_attachment_image_src( $attachment_id, 'large' );
		if ( ! $large ) {
			return;
		}
		$mime = 'image/jpeg';
		if ( preg_match( '/\.webp(\?|$)/i', $large[0] ) ) {
			$mime = 'image/webp';
		} elseif ( preg_match( '/\.png(\?|$)/i', $large[0] ) ) {
			$mime = 'image/png';
		}
		printf(
			'<link rel="preload" as="image" type="%s" href="%s" fetchpriority="high">' . "\n",
			esc_attr( $mime ),
			esc_url( $large[0] )
		);
		return;
	}

	$srcset = implode( ', ', $parts );
	$sizes  = '(min-width: 900px) 860px, 100vw';

	// Prefer image/webp type hint when the largest available source is .webp —
	// the browser skips the preload if its content-type disagrees with the
	// Accept header negotiation. Leaving type off is safer for mixed formats.
	$largest_url = end( $parts );
	$type_attr   = '';
	if ( strpos( $largest_url, '.webp' ) !== false ) {
		$type_attr = ' type="image/webp"';
	}

	printf(
		'<link rel="preload" as="image"%s imagesrcset="%s" imagesizes="%s" fetchpriority="high">' . "\n",
		$type_attr,
		esc_attr( $srcset ),
		esc_attr( $sizes )
	);
}
