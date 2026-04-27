<?php
/**
 * Akibara Blog SEO — JSON-LD Article/BlogPosting + BreadcrumbList.
 *
 * Rank Math genera un @graph para singles, pero:
 *  - Omite `image` cuando no hay featured image (nosotros hacemos fallback al logo).
 *  - Omite `articleSection` (categorias) y `keywords` (tags).
 *  - Emite BreadcrumbList pobre sin enlazar el archivo /blog/.
 * Este modulo enriquece via rank_math/json_ld (cuando Rank Math esta activo)
 * o emite un script propio (cuando no).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve canonical featured image for a post, with fallbacks:
 *  1) post thumbnail
 *  2) first <img> in post_content
 *  3) theme logo
 *  4) empty (caller decides)
 *
 * @return array{url:string,width:int,height:int}|null
 */
function akibara_blog_resolve_image( int $post_id ): ?array {
	$thumb_id = get_post_thumbnail_id( $post_id );
	if ( $thumb_id ) {
		$src = wp_get_attachment_image_src( $thumb_id, 'full' );
		if ( $src ) {
			return [ 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] ];
		}
	}

	$content = get_post_field( 'post_content', $post_id );
	if ( $content && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
		$url = $m[1];
		$abs_url = str_starts_with( $url, '//' ) ? 'https:' . $url : $url;
		return [ 'url' => $abs_url, 'width' => 1200, 'height' => 630 ];
	}

	$logo_id = get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$src = wp_get_attachment_image_src( $logo_id, 'full' );
		if ( $src ) {
			return [ 'url' => $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] ];
		}
	}

	return null;
}

/**
 * Build the shared Article node used by both JSON-LD output paths.
 */
function akibara_blog_build_article_node(): ?array {
	if ( ! is_singular( 'post' ) ) {
		return null;
	}
	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return null;
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return null;
	}

	// Description priority: manual excerpt > Rank Math desc > auto-excerpt.
	$desc = '';
	if ( has_excerpt( $post_id ) ) {
		$desc = wp_strip_all_tags( get_the_excerpt( $post_id ) );
	} else {
		$desc = wp_strip_all_tags( wp_trim_words( $post->post_content, 30, '' ) );
	}
	$desc = mb_substr( trim( $desc ), 0, 200 );

	$cats  = get_the_category( $post_id );
	$tags  = get_the_tags( $post_id );
	$image = akibara_blog_resolve_image( $post_id );

	$author_id   = (int) $post->post_author;
	$author_name = get_the_author_meta( 'display_name', $author_id ) ?: 'Akibara';

	$node = [
		'@type'            => 'BlogPosting',
		'@id'              => get_permalink( $post_id ) . '#article',
		'headline'         => mb_substr( get_the_title( $post_id ), 0, 110 ),
		'description'      => $desc,
		'datePublished'    => get_the_date( 'c', $post_id ),
		'dateModified'     => get_the_modified_date( 'c', $post_id ),
		'url'              => get_permalink( $post_id ),
		'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => get_permalink( $post_id ) ],
		'author'           => [
			'@type' => 'Person',
			'name'  => $author_name,
			'url'   => get_author_posts_url( $author_id ),
		],
		'publisher'        => [
			'@type' => 'Organization',
			'name'  => 'Akibara',
			'url'   => home_url( '/' ),
			'logo'  => [
				'@type' => 'ImageObject',
				'url'   => wp_get_attachment_url( (int) get_theme_mod( 'custom_logo' ) ) ?: home_url( '/wp-content/themes/akibara/assets/img/logo-akibara.webp' ),
			],
		],
		'inLanguage'       => 'es-CL',
		'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
	];

	if ( $image ) {
		$node['image'] = [
			'@type'  => 'ImageObject',
			'url'    => $image['url'],
			'width'  => $image['width'],
			'height' => $image['height'],
		];
	}

	if ( $cats && ! is_wp_error( $cats ) ) {
		$section_names = array_values( array_filter(
			wp_list_pluck( $cats, 'name' ),
			static fn( $n ) => strcasecmp( $n, 'Uncategorized' ) !== 0
		) );
		if ( $section_names ) {
			$node['articleSection'] = count( $section_names ) === 1 ? $section_names[0] : $section_names;
		}
	}

	if ( $tags && ! is_wp_error( $tags ) ) {
		$node['keywords'] = implode( ', ', wp_list_pluck( $tags, 'name' ) );
	}

	return $node;
}

/**
 * Build BreadcrumbList for blog posts (Inicio > Blog > [Categoria] > Titulo).
 */
function akibara_blog_build_breadcrumb_node( int $post_id ): array {
	$items = [];
	$pos   = 1;

	$items[] = [
		'@type'    => 'ListItem',
		'position' => $pos++,
		'name'     => 'Inicio',
		'item'     => home_url( '/' ),
	];

	$items[] = [
		'@type'    => 'ListItem',
		'position' => $pos++,
		'name'     => 'Blog',
		'item'     => home_url( '/blog/' ),
	];

	$cats = get_the_category( $post_id );
	if ( $cats && ! is_wp_error( $cats ) ) {
		// Use first non-Uncategorized category.
		foreach ( $cats as $cat ) {
			if ( (int) $cat->term_id !== 1 ) {
				$items[] = [
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => $cat->name,
					'item'     => get_term_link( $cat ),
				];
				break;
			}
		}
	}

	$items[] = [
		'@type'    => 'ListItem',
		'position' => $pos,
		'name'     => get_the_title( $post_id ),
	];

	return [
		'@type'           => 'BreadcrumbList',
		'@id'             => get_permalink( $post_id ) . '#breadcrumb',
		'itemListElement' => $items,
	];
}

/**
 * Is this entity the blog's article node? Detects via several signals so we
 * merge with Rank Math's node regardless of which @type it used (or if empty).
 */
function akibara_is_article_entity( $entity ): bool {
	if ( ! is_array( $entity ) ) {
		return false;
	}

	$type = isset( $entity['@type'] ) ? (array) $entity['@type'] : [];
	foreach ( $type as $t ) {
		if ( in_array( $t, [ 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle' ], true ) ) {
			return true;
		}
	}

	// Rank Math rich snippet convention.
	if ( isset( $entity['@id'] ) && str_contains( (string) $entity['@id'], '#richSnippet' ) ) {
		return true;
	}

	// Heuristic: an article-shaped node even with missing/empty @type.
	if ( isset( $entity['headline'] ) && isset( $entity['datePublished'] ) ) {
		return true;
	}

	return false;
}

/**
 * Path 1 — Rank Math present: enrich its @graph in-place.
 * We extend the existing Article/BlogPosting (or richSnippet) node and replace
 * the BreadcrumbList so one canonical graph survives. Any duplicate article
 * entities are pruned.
 */
add_filter( 'rank_math/json_ld', function ( array $data, $jsonld ): array {
	if ( ! is_singular( 'post' ) ) {
		return $data;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return $data;
	}

	$enriched = akibara_blog_build_article_node();
	if ( ! $enriched ) {
		return $data;
	}

	$canonical_id = get_permalink( $post_id ) . '#richSnippet';
	$enriched['@id'] = $canonical_id; // align with Rank Math convention

	$article_keys   = [];
	$breadcrumb_key = null;

	foreach ( $data as $key => $entity ) {
		if ( akibara_is_article_entity( $entity ) ) {
			$article_keys[] = $key;
		}
		if ( is_array( $entity ) && isset( $entity['@type'] ) && in_array( 'BreadcrumbList', (array) $entity['@type'], true ) ) {
			$breadcrumb_key = $key;
		}
	}

	if ( $article_keys ) {
		$primary = array_shift( $article_keys );
		$base    = is_array( $data[ $primary ] ) ? $data[ $primary ] : [];
		$data[ $primary ] = array_merge( $base, $enriched );
		$data[ $primary ]['@type'] = 'BlogPosting';
		// Drop duplicate article entities.
		foreach ( $article_keys as $dupe_key ) {
			unset( $data[ $dupe_key ] );
		}
	} else {
		$data['akb_article'] = $enriched;
	}

	if ( $breadcrumb_key !== null ) {
		$data[ $breadcrumb_key ] = akibara_blog_build_breadcrumb_node( $post_id );
	} else {
		$data['akb_breadcrumb'] = akibara_blog_build_breadcrumb_node( $post_id );
	}

	return $data;
}, 25, 2 );

/**
 * Path 2 — Rank Math absent: emit our own JSON-LD graph.
 */
add_action( 'wp_head', function () {
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		return;
	}
	if ( ! is_singular( 'post' ) ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$article = akibara_blog_build_article_node();
	if ( ! $article ) {
		return;
	}

	$graph = [
		'@context' => 'https://schema.org',
		'@graph'   => [
			$article,
			akibara_blog_build_breadcrumb_node( $post_id ),
		],
	];

	echo '<script type="application/ld+json">'
		. wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG )
		. '</script>' . "\n";
}, 12 );

/**
 * Scan post content for H2 questions followed by answer paragraphs and build
 * a FAQPage schema. Only emits when >=2 Q&A pairs are found, otherwise returns
 * null (no schema — quality over quantity).
 *
 * Heuristic: a heading is a "question" when it ends with "?" or starts with a
 * Spanish interrogative word. The "answer" is the text of the block elements
 * (paragraphs, lists) between this heading and the next H2/H3.
 */
function akibara_blog_build_faq_node(): ?array {
	if ( ! is_singular( 'post' ) ) {
		return null;
	}
	$post_id = get_the_ID() ?: get_queried_object_id();
	if ( ! $post_id ) {
		return null;
	}

	$content = (string) get_post_field( 'post_content', $post_id );
	if ( $content === '' ) {
		return null;
	}

	// Split by H2 openings, keeping the delimiter in results.
	$parts = preg_split( '/(<h2[^>]*>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
		return null;
	}

	$interrogatives = [ 'qué', 'cómo', 'cuándo', 'dónde', 'por qué', 'cuál', 'cuánto' ];
	$qa = [];

	// $parts layout: [ preamble, <h2>, section1, <h2>, section2, ... ]
	for ( $i = 1; $i < count( $parts ); $i += 2 ) {
		$section = $parts[ $i + 1 ] ?? '';
		if ( ! preg_match( '/^(.*?)<\/h2>(.*)$/is', $section, $m ) ) {
			continue;
		}
		$heading_raw = $m[1];
		$body_raw    = $m[2];

		// Body ends at the next h2/h3 if present (we already split on h2; cap on h3).
		if ( preg_match( '/(.*?)<h[23][\s>]/is', $body_raw, $bm ) ) {
			$body_raw = $bm[1];
		}

		$heading = trim( wp_strip_all_tags( $heading_raw ) );
		if ( $heading === '' ) {
			continue;
		}

		$is_question = str_ends_with( $heading, '?' );
		if ( ! $is_question ) {
			$heading_lc = mb_strtolower( $heading );
			foreach ( $interrogatives as $iw ) {
				if ( str_starts_with( $heading_lc, $iw . ' ' ) ) {
					$is_question = true;
					break;
				}
			}
		}
		if ( ! $is_question ) {
			continue;
		}

		$answer = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body_raw ) ) );
		if ( mb_strlen( $answer ) < 40 ) {
			continue;
		}
		if ( mb_strlen( $answer ) > 1000 ) {
			$answer = mb_substr( $answer, 0, 997 ) . '...';
		}

		// Normalize the question (drop trailing punctuation, keep leading ¿).
		$qa[] = [
			'@type'          => 'Question',
			'name'           => $heading,
			'acceptedAnswer' => [
				'@type' => 'Answer',
				'text'  => $answer,
			],
		];
	}

	if ( count( $qa ) < 2 ) {
		return null;
	}

	return [
		'@type'      => 'FAQPage',
		'@id'        => get_permalink( $post_id ) . '#faq',
		'mainEntity' => $qa,
	];
}

/**
 * FAQ schema — inject into Rank Math graph when available.
 */
/**
 * FAQ schema — emit as standalone JSON-LD script.
 *
 * Rank Math re-absorbs any FAQPage entity injected into its @graph (moves it
 * into the WebPage @type array), so we bypass that pipeline and emit our own
 * block. Google accepts multiple JSON-LD scripts on the same page.
 */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$faq = akibara_blog_build_faq_node();
	if ( ! $faq ) {
		return;
	}
	$graph = [ '@context' => 'https://schema.org' ] + $faq;
	echo '<script type="application/ld+json">'
		. wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG )
		. '</script>' . "\n";
}, 13 );

/**
 * Detect a listicle post and extract its enumerated items into an ItemList
 * schema. Eligible posts:
 *  - Title matches "Los N mejores…", "Top N…" or generic "Las N…" patterns.
 *  - Body has H3 (or H2) headings prefixed with "N. " (e.g. "1. Demon Slayer").
 *
 * Returns null when not a listicle, or when fewer than 3 items are found
 * (Google requires at least 3 for ItemList rich results).
 *
 * If the item name matches a known series, the URL points to that
 * /serie/{slug}/ landing page — turning the listicle into a clickable
 * itinerary in the SERP carousel.
 */
function akibara_blog_build_itemlist_node(): ?array {
	if ( ! is_singular( 'post' ) ) {
		return null;
	}
	$post_id = get_the_ID() ?: get_queried_object_id();
	if ( ! $post_id ) {
		return null;
	}

	$title = get_the_title( $post_id );
	$listicle_pattern = '/\b(los|las|top|the)\s+(\d+|diez|veinte|cinco)\s/iu';
	if ( ! preg_match( $listicle_pattern, $title ) ) {
		return null;
	}

	$content = (string) get_post_field( 'post_content', $post_id );
	if ( $content === '' ) {
		return null;
	}

	// Extract H3 (preferred) and H2 headings prefixed with a number+dot.
	if ( ! preg_match_all( '/<h[23][^>]*>\s*(\d+)\s*[.)\-:]\s*(.*?)<\/h[23]>/is', $content, $matches, PREG_SET_ORDER ) ) {
		return null;
	}

	if ( count( $matches ) < 3 ) {
		return null;
	}

	// Resolve series-name → /serie/{slug}/ map (cached, reused from auto-linker).
	$series_map = get_transient( 'akb_blog_series_map' );
	if ( ! is_array( $series_map ) ) {
		$series_map = [];
		$terms = get_terms( [ 'taxonomy' => 'pa_serie', 'hide_empty' => true, 'fields' => 'all' ] );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$series_map[ $t->name ] = home_url( '/serie/' . $t->slug . '/' );
			}
		}
		set_transient( 'akb_blog_series_map', $series_map, DAY_IN_SECONDS );
	}

	$items = [];
	$seen_pos = [];
	foreach ( $matches as $m ) {
		$pos  = (int) $m[1];
		$name = trim( wp_strip_all_tags( $m[2] ) );
		if ( $name === '' || isset( $seen_pos[ $pos ] ) ) {
			continue;
		}
		$seen_pos[ $pos ] = true;

		$item = [
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => $name,
		];

		// Match item name against a known serie (longest-prefix wins to avoid
		// "One Piece" matching "One Piece nº" etc).
		$best = null;
		$best_len = 0;
		$name_lc = mb_strtolower( $name );
		foreach ( $series_map as $sname => $url ) {
			$s_lc = mb_strtolower( $sname );
			if ( str_starts_with( $name_lc, $s_lc ) || str_contains( $name_lc, $s_lc ) ) {
				if ( mb_strlen( $s_lc ) > $best_len ) {
					$best = $url;
					$best_len = mb_strlen( $s_lc );
				}
			}
		}
		if ( $best ) {
			$item['url'] = $best;
		}

		$items[] = $item;
	}

	if ( count( $items ) < 3 ) {
		return null;
	}

	// Sort by position ascending (defensive — content authors sometimes write
	// out of order during edits).
	usort( $items, fn( $a, $b ) => $a['position'] <=> $b['position'] );

	return [
		'@type'           => 'ItemList',
		'@id'             => get_permalink( $post_id ) . '#itemlist',
		'name'            => $title,
		'numberOfItems'   => count( $items ),
		'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
		'itemListElement' => $items,
	];
}

/**
 * ItemList schema — standalone JSON-LD block (same reasoning as FAQ: keeps
 * it independent of Rank Math's graph absorption).
 */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$list = akibara_blog_build_itemlist_node();
	if ( ! $list ) {
		return;
	}
	$graph = [ '@context' => 'https://schema.org' ] + $list;
	echo '<script type="application/ld+json">'
		. wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG )
		. '</script>' . "\n";
}, 14 );

/**
 * Blog archive (home.php) — emit Blog schema.
 */
add_action( 'wp_head', function () {
	if ( ! is_home() ) {
		return;
	}

	$schema = [
		'@context'    => 'https://schema.org',
		'@type'       => 'Blog',
		'@id'         => home_url( '/blog/' ) . '#blog',
		'name'        => 'Blog Akibara',
		'description' => 'Guias, resenas y novedades del mundo manga y comics desde Chile.',
		'url'         => home_url( '/blog/' ),
		'inLanguage'  => 'es-CL',
		'publisher'   => [
			'@type' => 'Organization',
			'name'  => 'Akibara',
			'url'   => home_url( '/' ),
		],
	];

	echo '<script type="application/ld+json">'
		. wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG )
		. '</script>' . "\n";
}, 12 );
