<?php
/**
 * Reverse interlinking — surface a blog post on the product page when there's
 * an article that covers the same series.
 *
 * The signal we use is the `post_tag` taxonomy: posts were auto-tagged with
 * the series names mentioned in their content. So a product whose serie name
 * matches a post tag → render a CTA linking to that post.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Find the most recent published post tagged with this series name.
 *
 * Cached per-serie for 12h (auto-invalidated when posts/products change via
 * the `clean_post_cache` and `set_object_terms` hooks below). Returns null
 * when nothing matches so callers can decide whether to render.
 */
function akibara_blog_post_for_serie( string $serie_name ): ?WP_Post {
	$serie_name = trim( $serie_name );
	if ( $serie_name === '' ) {
		return null;
	}

	$cache_key = 'akb_blogcta_' . md5( mb_strtolower( $serie_name ) );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached === 'none' ? null : get_post( (int) $cached );
	}

	// 1) Exact match (fast path).
	$tag = get_term_by( 'name', $serie_name, 'post_tag' );

	// 2) Fuzzy match — product `_akibara_serie` meta sometimes includes the
	//    title suffix (e.g. "One Piece nº" instead of "One Piece"). Find the
	//    longest tag whose name is a prefix or substring of the serie name,
	//    or vice versa.
	if ( ! $tag || is_wp_error( $tag ) ) {
		$serie_lc = mb_strtolower( $serie_name );
		$candidates = get_terms( [
			'taxonomy'   => 'post_tag',
			'hide_empty' => true,
		] );
		if ( ! is_wp_error( $candidates ) ) {
			$best = null;
			$best_len = 0;
			foreach ( $candidates as $candidate ) {
				$cand_lc = mb_strtolower( $candidate->name );
				if ( mb_strlen( $cand_lc ) < 4 ) {
					continue; // skip noise like "saga"
				}
				$matches = str_starts_with( $serie_lc, $cand_lc )
					|| str_starts_with( $cand_lc, $serie_lc )
					|| str_contains( $serie_lc, ' ' . $cand_lc )
					|| str_contains( $serie_lc, $cand_lc . ' ' );
				if ( $matches && mb_strlen( $cand_lc ) > $best_len ) {
					$best = $candidate;
					$best_len = mb_strlen( $cand_lc );
				}
			}
			$tag = $best;
		}
	}

	if ( ! $tag ) {
		set_transient( $cache_key, 'none', 12 * HOUR_IN_SECONDS );
		return null;
	}

	// Pull all posts with this tag (rare to have many — bounded by series count).
	$posts = get_posts( [
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 20,
		'tax_query'           => [
			[
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $tag->term_id,
			],
		],
		'orderby'             => 'date',
		'order'               => 'DESC',
		'no_found_rows'       => true,
		'ignore_sticky_posts' => 1,
	] );

	if ( ! $posts ) {
		set_transient( $cache_key, 'none', 12 * HOUR_IN_SECONDS );
		return null;
	}

	// Relevance ranking: a post titled "Chainsaw Man: guía…" beats a generic
	// listicle that merely mentions Chainsaw Man among others.
	// Use the resolved tag name (canonical), not the raw product meta which
	// may include suffixes like "nº" that no post title contains.
	$serie_lc = mb_strtolower( $tag->name );
	$best     = null;
	$best_score = -1;

	foreach ( $posts as $candidate ) {
		$title_lc = mb_strtolower( $candidate->post_title );
		$score    = 0;

		// Exact serie name appears in title → strong relevance signal.
		if ( str_contains( $title_lc, $serie_lc ) ) {
			$score += 100;
			// Bonus when title starts with the serie (dedicated guide).
			if ( str_starts_with( $title_lc, $serie_lc ) ) {
				$score += 50;
			}
		}

		// Recency tie-breaker — newer posts inch ahead among equal-relevance peers.
		$age_days = max( 0, ( time() - get_post_time( 'U', true, $candidate ) ) / DAY_IN_SECONDS );
		$score   -= min( 30, $age_days / 30 ); // ~1 point per month, capped

		if ( $score > $best_score ) {
			$best_score = $score;
			$best       = $candidate;
		}
	}

	$best = $best ?: $posts[0];
	set_transient( $cache_key, $best->ID, 12 * HOUR_IN_SECONDS );
	return $best;
}

/**
 * Render the CTA box on the product page. Silent no-op when the product has
 * no serie meta or no matching blog post.
 */
function akibara_render_blog_cta_for_product( int $product_id ): void {
	if ( ! $product_id ) {
		return;
	}

	$serie_name = trim( (string) get_post_meta( $product_id, '_akibara_serie', true ) );
	if ( $serie_name === '' ) {
		// Try fallback: derive from product title pattern "Series N – Editorial".
		$title = get_the_title( $product_id );
		if ( $title && preg_match( '/^(.+?)\s*\d+\s*[–—-]/u', $title, $m ) ) {
			$serie_name = trim( $m[1] );
		}
	}

	$post = akibara_blog_post_for_serie( $serie_name );
	if ( ! $post ) {
		return;
	}

	$url        = get_permalink( $post );
	$title      = get_the_title( $post );
	$reading    = function_exists( 'akibara_reading_time' ) ? akibara_reading_time( $post->ID ) : '';
	$thumb_id   = get_post_thumbnail_id( $post->ID );
	$alt        = function_exists( 'akibara_blog_image_alt' ) ? akibara_blog_image_alt( $thumb_id, $title ) : $title;

	?>
	<aside class="akb-blog-cta" aria-label="Articulo relacionado">
		<a class="akb-blog-cta__link" href="<?php echo esc_url( $url ); ?>" rel="bookmark">
			<?php if ( $thumb_id ) : ?>
				<div class="akb-blog-cta__thumb">
					<?php echo wp_get_attachment_image(
						$thumb_id,
						'blog-related',
						false,
						[
							'class'    => 'akb-blog-cta__img',
							'loading'  => 'lazy',
							'decoding' => 'async',
							'sizes'    => '(min-width: 700px) 180px, 100px',
							'alt'      => $alt,
						]
					); ?>
				</div>
			<?php endif; ?>
			<div class="akb-blog-cta__body">
				<span class="akb-blog-cta__eyebrow">📖 Lee nuestra guía</span>
				<span class="akb-blog-cta__title"><?php echo esc_html( $title ); ?></span>
				<?php if ( $reading ) : ?>
					<span class="akb-blog-cta__meta"><?php echo esc_html( $reading ); ?></span>
				<?php endif; ?>
			</div>
			<span class="akb-blog-cta__arrow" aria-hidden="true">→</span>
		</a>
	</aside>
	<?php
}

/**
 * Inline CSS — registered once on product pages that render the CTA. Kept
 * inline (small, single use) to avoid an extra request.
 */
add_action( 'wp_enqueue_scripts', function (): void {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$css = <<<CSS
.akb-blog-cta{margin:1.25rem 0;border-radius:14px;overflow:hidden;background:linear-gradient(135deg,rgba(231,76,60,.06),rgba(231,76,60,.02));border:1px solid rgba(231,76,60,.18);transition:transform .15s ease,box-shadow .15s ease}
.akb-blog-cta:hover{transform:translateY(-1px);box-shadow:0 10px 24px -12px rgba(231,76,60,.35)}
.akb-blog-cta__link{display:flex;align-items:center;gap:1rem;padding:.85rem 1rem;text-decoration:none;color:inherit}
.akb-blog-cta__thumb{flex-shrink:0;width:96px;height:64px;border-radius:8px;overflow:hidden;background:#0001}
.akb-blog-cta__img{width:100%;height:100%;object-fit:cover;display:block}
.akb-blog-cta__body{flex:1;min-width:0;display:flex;flex-direction:column;gap:.15rem}
.akb-blog-cta__eyebrow{font-size:.72rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--aki-red,#D90010);opacity:.9}
.akb-blog-cta__title{font-size:1rem;font-weight:600;line-height:1.3;color:inherit;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.akb-blog-cta__meta{font-size:.78rem;opacity:.65}
.akb-blog-cta__arrow{flex-shrink:0;font-size:1.4rem;color:var(--aki-red,#D90010);opacity:.7;transition:transform .15s ease,opacity .15s ease}
.akb-blog-cta:hover .akb-blog-cta__arrow{transform:translateX(3px);opacity:1}
@media (max-width:600px){.akb-blog-cta__thumb{width:72px;height:54px}.akb-blog-cta__title{font-size:.92rem}}
CSS;
	wp_register_style( 'akb-blog-cta', false );
	wp_enqueue_style( 'akb-blog-cta' );
	wp_add_inline_style( 'akb-blog-cta', $css );
}, 30 );

/**
 * Cache invalidation — when a post is saved, deleted, or its tags change,
 * forget any cached blog→product mappings that referenced it. Cheap to clear
 * the entire blog-cta cache namespace; the dataset is tiny (≤30 series).
 */
function akibara_blog_cta_flush_cache(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_akb_blogcta_%' OR option_name LIKE '_transient_timeout_akb_blogcta_%'" );
}

add_action( 'save_post_post', 'akibara_blog_cta_flush_cache' );
add_action( 'deleted_post', function ( $post_id ) {
	if ( get_post_type( $post_id ) === 'post' ) {
		akibara_blog_cta_flush_cache();
	}
} );
add_action( 'set_object_terms', function ( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( $taxonomy === 'post_tag' && get_post_type( $object_id ) === 'post' ) {
		akibara_blog_cta_flush_cache();
	}
}, 10, 4 );
