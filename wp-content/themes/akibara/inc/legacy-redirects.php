<?php
/**
 * Legacy URL redirects — recover SEO juice from URLs Google still has indexed
 * but that 404 today.
 *
 * Detected from GSC "No se ha encontrado (404)" report (2026-04-21):
 *   - /editorial/{slug}/         → /marca/{slug}/   (taxonomy rewrite changed)
 *   - /home-N/                   → /                 (WP duplicate-home cleanup)
 *   - /serie/{X}/page/N/ deep    → /serie/{X}/      (out-of-range pagination)
 *   - /category/{deleted-slug}/  → /blog/           (categories that no longer exist)
 *
 * All redirects are 301 (permanent) so PageRank flows through. We resolve the
 * destination at request time so renames/edits don't strand bot crawls.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', function (): void {
	$path = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( ! $path ) {
		return;
	}
	$path = '/' . ltrim( $path, '/' );

	// 0a) Mega-menu legacy: `/preventas/?product_cat=manga|comics` → /manga/ o /comics/.
	//     Antes el header apuntaba a `/preventas/?product_cat=...` y WP devolvía 301
	//     implícito hacia `/manga/` / `/comics/` (chain). El header ya está corregido
	//     a destino canónico, pero usuarios externos con bookmarks/backlinks siguen
	//     llegando vía la URL vieja. Redirigimos explícito para evitar query string
	//     parásito y para que el destino quede registrado en GSC sin cadena.
	if ( strpos( $path, '/preventas/' ) === 0 && isset( $_GET['product_cat'] ) ) {
		$cat = sanitize_text_field( wp_unslash( $_GET['product_cat'] ) );
		if ( 'manga' === $cat ) {
			wp_safe_redirect( home_url( '/manga/' ), 301 );
			exit;
		}
		if ( 'comics' === $cat ) {
			wp_safe_redirect( home_url( '/comics/' ), 301 );
			exit;
		}
	}

	// 0) Per-product RSS feeds — `/product/{slug}/feed/` and `/{slug}/feed/`.
	//    Google indexed 103 of these as "duplicates without canonical". RSS
	//    feeds for individual products are noise and shouldn't compete with
	//    the canonical product URL. We 301 them to the product page.
	//    This handler runs BEFORE the is_404 gate because feeds may return
	//    200/404 depending on context; we want to short-circuit either way.
	if ( preg_match( '#^/(?:product/)?([a-z0-9-]+)/feed/?$#i', $path, $m ) ) {
		$slug = $m[1];
		// Don't catch the global `/feed/` or `/comments/feed/` here.
		if ( ! in_array( $slug, [ 'feed', 'comments' ], true ) ) {
			$product_id = wc_get_product_id_by_sku( $slug );
			if ( ! $product_id ) {
				$post = get_page_by_path( $slug, OBJECT, 'product' );
				$product_id = $post ? $post->ID : 0;
			}
			if ( $product_id ) {
				wp_safe_redirect( get_permalink( $product_id ), 301 );
				exit;
			}
		}
	}

	// Only intervene on 404s we can confidently re-route.
	if ( ! is_404() ) {
		return;
	}

	// 1) /editorial/{slug}/ → /marca/{slug}/ (product_brand rewrite migration)
	if ( preg_match( '#^/editorial/([^/]+)/?(?:page/(\d+)/?)?$#', $path, $m ) ) {
		$slug   = sanitize_title( $m[1] );
		$page   = isset( $m[2] ) ? (int) $m[2] : 0;
		$term   = get_term_by( 'slug', $slug, 'product_brand' );
		if ( $term && ! is_wp_error( $term ) ) {
			$dest = get_term_link( $term );
			if ( $page > 1 ) {
				$dest = trailingslashit( $dest ) . 'page/' . $page . '/';
			}
			wp_safe_redirect( $dest, 301 );
			exit;
		}
		// Fallback: if the term doesn't exist anymore, send to the editorials hub.
		wp_safe_redirect( home_url( '/editoriales/' ), 301 );
		exit;
	}

	// 2) /home-N/ → /  (WP auto-suffixed duplicates of "home" slug)
	if ( preg_match( '#^/home-\d+/?$#', $path ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	// 3) /serie/{slug}/page/N/ where N is out of range → /serie/{slug}/
	if ( preg_match( '#^/serie/([^/]+)/page/\d+/?$#', $path, $m ) ) {
		$slug = sanitize_title( $m[1] );
		// Verify the serie landing exists before redirecting.
		$serie_path = home_url( '/serie/' . $slug . '/' );
		$exists = wp_remote_head( $serie_path, [ 'timeout' => 3, 'redirection' => 0 ] );
		if ( ! is_wp_error( $exists ) && (int) wp_remote_retrieve_response_code( $exists ) === 200 ) {
			wp_safe_redirect( $serie_path, 301 );
			exit;
		}
	}

	// 4) /category/{slug}/ where category was deleted → /blog/
	//    Only redirect if the term truly doesn't exist (live categories must
	//    keep their natural 200/redirect-to-archive behavior).
	if ( preg_match( '#^/category/([^/]+)/?$#', $path, $m ) ) {
		$slug = sanitize_title( $m[1] );
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_safe_redirect( home_url( '/blog/' ), 301 );
			exit;
		}
	}

	// 5) /product/{slug}/ where product was deleted → /serie/{matched}/ if name
	//    pattern lets us infer the serie. Heuristic: "demon-slayer-15-ivrea"
	//    → look up "demon-slayer" / "demon slayer" as pa_serie term.
	if ( preg_match( '#^/product/([a-z0-9-]+)/?$#i', $path, $m ) ) {
		$slug = $m[1];
		// Strip trailing volume + editorial: "demon-slayer-15-ivrea-argentina" → "demon-slayer"
		$base = preg_replace( '/-\d+(-[a-z-]+)?$/i', '', $slug );
		$base = preg_replace( '/-(planeta|ivrea|panini|norma|ecc|kodai|milky-way|arechi)(-.*)?$/i', '', $base );
		if ( $base && $base !== $slug ) {
			$serie_url = home_url( '/serie/' . $base . '/' );
			$exists = wp_remote_head( $serie_url, [ 'timeout' => 3, 'redirection' => 0 ] );
			if ( ! is_wp_error( $exists ) && (int) wp_remote_retrieve_response_code( $exists ) === 200 ) {
				wp_safe_redirect( $serie_url, 301 );
				exit;
			}
		}
	}

	// 6) /product-brand/{slug}/ → /marca/{slug}/ directo (aplana chain).
	//    Antes pasaba por /editorial/{slug}/ que después redirige a /marca/.
	//    Ahora el redirect es directo, evita perder PageRank en chain doble.
	if ( preg_match( '#^/product-brand/([^/]+)/?$#', $path, $m ) ) {
		$slug = sanitize_title( $m[1] );
		$term = get_term_by( 'slug', $slug, 'product_brand' );
		if ( $term && ! is_wp_error( $term ) ) {
			wp_safe_redirect( get_term_link( $term ), 301 );
			exit;
		}
		wp_safe_redirect( home_url( '/editoriales/' ), 301 );
		exit;
	}

	// 7) Paginación rota — cualquier ruta que termine en /page/N/ y dé 404
	//    redirige al base sin paginación. Cubre /genero/X/, /tienda/, /manga/X/,
	//    /comics/X/, /serie/X/, etc. Google indexó números altos que ya no existen.
	if ( preg_match( '#^(/.+?)/page/\d+/?$#', $path, $m ) ) {
		wp_safe_redirect( home_url( $m[1] . '/' ), 301 );
		exit;
	}

	// 8) /product-category/{rest}/ → /{rest}/ (legacy permalink WC, sin /product-category/).
	//    Caso: /product-category/manga/seinen/ → /manga/seinen/
	if ( preg_match( '#^/product-category/(.+)$#', $path, $m ) ) {
		wp_safe_redirect( home_url( '/' . ltrim( $m[1], '/' ) ), 301 );
		exit;
	}
}, 5 );
