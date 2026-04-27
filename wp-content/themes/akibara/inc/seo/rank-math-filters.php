<?php
/**
 * Akibara — Rank Math filters específicos del Blog Index (home.php)
 *
 * Resuelve findings del SEO Audit 2026-04-24:
 *   F-03 — Blog index sin <meta name="description">.
 *   F-04 — Blog index sin og:image.
 *   F-08 — Blog title genérico ("Blog - Akibara", 14 chars, sin keyword).
 *
 * NO incluye F-02 (og:locale) — vive en inc/seo/meta.php (filter consolidado).
 * NO incluye F-01 (canonical staging) — declarado INFO, no es bug real:
 *   Rank Math suprime <link rel="canonical"> intencionalmente cuando la
 *   página es noindex (staging). En producción el canonical sí se emite.
 *
 * Detección de "blog index": is_home() && ! is_front_page(). En este theme
 * el template es home.php (no page-blog.php).
 *
 * @package Akibara
 * @since   1.0.0  2026-04-24
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'akibara_is_blog_index' ) ) {
	/**
	 * El blog index de Akibara responde a is_home() (home.php). No hay
	 * page-blog.php; la front-page es la home de WooCommerce, NO el blog.
	 */
	function akibara_is_blog_index(): bool {
		return is_home() && ! is_front_page();
	}
}

if ( ! function_exists( 'akibara_blog_index_default_image' ) ) {
	/**
	 * Imagen default para og:image del blog index. Prioridad:
	 *   1. Logo configurado en Customizer (get_theme_mod('custom_logo')).
	 *   2. Logo PNG del tema (logo-akibara.png).
	 */
	function akibara_blog_index_default_image(): string {
		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$url = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		return trailingslashit( get_template_directory_uri() ) . 'assets/img/logo-akibara.png';
	}
}

// ═══════════════════════════════════════════════════════════════
// F-08 — TITLE BLOG INDEX
// Antes: "Blog - Akibara" (14 chars, sin keyword, low CTR potential).
// Después: "Blog Akibara — Guías de manga, reviews y novedades en Chile".
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/frontend/title', function ( string $title ): string {
	if ( ! akibara_is_blog_index() ) {
		return $title;
	}
	return 'Blog Akibara — Guías de manga, reviews y novedades en Chile';
}, 25 );

// Algunas plataformas (Twitter Cards / OG) toman el título por separado.
// Mantener consistencia entre <title>, og:title y twitter:title.
add_filter( 'rank_math/opengraph/title', function ( string $title ): string {
	if ( ! akibara_is_blog_index() ) {
		return $title;
	}
	return 'Blog Akibara — Guías de manga, reviews y novedades en Chile';
}, 25 );

// ═══════════════════════════════════════════════════════════════
// F-03 — META DESCRIPTION BLOG INDEX
// Sin description → Google genera snippet auto (pobre).
// Mantenemos copy en tuteo chileno neutro (memoria feedback_voz_chilena).
// 155-160 chars máx. (Google trunca alrededor de 160).
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/frontend/description', function ( string $desc ): string {
	if ( ! akibara_is_blog_index() ) {
		return $desc;
	}
	return 'Guías, reseñas y novedades del mundo manga y cómics. Recomendaciones para empezar tu colección y noticias de editoriales en Chile.';
}, 25 );

// ═══════════════════════════════════════════════════════════════
// F-04 — OG:IMAGE BLOG INDEX
// Sin imagen → preview vacío en WhatsApp / FB / Twitter / LinkedIn.
// Fallback al logo (Customizer → PNG del tema).
// ═══════════════════════════════════════════════════════════════
add_filter( 'rank_math/opengraph/facebook/og_image', function ( $image ) {
	if ( ! akibara_is_blog_index() ) {
		return $image;
	}
	if ( ! empty( $image ) ) {
		return $image;
	}
	return akibara_blog_index_default_image();
}, 25 );

add_filter( 'rank_math/opengraph/twitter/twitter_image', function ( $image ) {
	if ( ! akibara_is_blog_index() ) {
		return $image;
	}
	if ( ! empty( $image ) ) {
		return $image;
	}
	return akibara_blog_index_default_image();
}, 25 );

// Filter genérico (Rank Math lo dispara para todas las plataformas OG).
add_filter( 'rank_math/opengraph/image', function ( $image ) {
	if ( ! akibara_is_blog_index() ) {
		return $image;
	}
	if ( ! empty( $image ) ) {
		return $image;
	}
	return akibara_blog_index_default_image();
}, 25 );
