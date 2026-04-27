<?php
/**
 * Forward widget — render a "Compra los tomos disponibles" carousel at the
 * bottom of a blog post, listing in-stock products for the series the post
 * covers. Counterpart to inc/blog-cta-product.php (which goes the other way).
 *
 * Signal source: post tags. The auto-tagger (see /tmp/auto-tag job) added the
 * series names mentioned in each post as `post_tag` terms — so we can map
 * tags → serie names → products with matching `_akibara_serie` meta.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

const AKIBARA_BLOG_PRODUCT_CTA_LIMIT     = 8;   // max products in the widget
const AKIBARA_BLOG_PRODUCT_CTA_PER_SERIE = 4;   // max per serie (so multi-serie posts stay balanced)

/**
 * Find published, in-stock products for a serie (by `_akibara_serie` meta).
 *
 * @return int[] Product IDs, ordered by `_akibara_numero` ASC (vol 1 first).
 */
function akibara_blog_products_for_serie( string $serie_name, int $limit ): array {
	$serie_name = trim( $serie_name );
	if ( $serie_name === '' || $limit < 1 ) {
		return [];
	}

	$cache_key = 'akb_blogprod_' . md5( mb_strtolower( $serie_name ) . '|' . $limit );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	// Build a LIKE pattern that catches both exact and prefixed serie meta
	// values (mirrors the fuzzy logic in blog-cta-product.php).
	$like = $wpdb->esc_like( $serie_name );

	$ids = $wpdb->get_col( $wpdb->prepare( "
		SELECT pm.post_id
		FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
		LEFT JOIN {$wpdb->postmeta} pm_num ON pm_num.post_id = pm.post_id AND pm_num.meta_key = '_akibara_numero'
		LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.post_id = pm.post_id AND pm_stock.meta_key = '_stock_status'
		WHERE pm.meta_key = '_akibara_serie'
		  AND ( pm.meta_value = %s OR pm.meta_value LIKE %s )
		  AND p.post_type = 'product'
		  AND p.post_status = 'publish'
		  AND ( pm_stock.meta_value IS NULL OR pm_stock.meta_value = 'instock' )
		ORDER BY CAST( pm_num.meta_value AS UNSIGNED ) ASC, p.ID ASC
		LIMIT %d
	",
		$serie_name,
		$like . ' %', // matches "Saga 1", "Saga 2"… without dragging unrelated series
		$limit
	) );

	$ids = array_map( 'intval', $ids ?: [] );
	set_transient( $cache_key, $ids, 6 * HOUR_IN_SECONDS );
	return $ids;
}

/**
 * Map post → distinct serie names. Uses post_tag (auto-tagger output) as the
 * primary signal; falls back to scanning content for pa_serie term names if
 * the post has no tags.
 *
 * @return string[]
 */
function akibara_blog_post_series( int $post_id ): array {
	$tags = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
	if ( $tags ) {
		// Prefer tags that resolve to actual product series (skip noise).
		$valid = [];
		foreach ( $tags as $tag_name ) {
			if ( akibara_blog_products_for_serie( $tag_name, 1 ) ) {
				$valid[] = $tag_name;
			}
		}
		if ( $valid ) {
			return $valid;
		}
	}

	return [];
}

/**
 * Render the widget HTML. Returns empty string when there's nothing to show.
 */
function akibara_blog_render_product_cta( int $post_id ): string {
	$series = akibara_blog_post_series( $post_id );
	if ( ! $series ) {
		return '';
	}

	// Collect products: take up to N per serie until total cap is reached.
	$product_ids = [];
	$by_serie    = [];
	foreach ( $series as $serie_name ) {
		$ids = akibara_blog_products_for_serie( $serie_name, AKIBARA_BLOG_PRODUCT_CTA_PER_SERIE );
		if ( $ids ) {
			$by_serie[ $serie_name ] = $ids;
			foreach ( $ids as $id ) {
				$product_ids[ $id ] = true;
				if ( count( $product_ids ) >= AKIBARA_BLOG_PRODUCT_CTA_LIMIT ) {
					break 2;
				}
			}
		}
	}

	if ( ! $product_ids ) {
		return '';
	}

	// Headline adapts to the serie list — single serie reads more naturally.
	if ( count( $by_serie ) === 1 ) {
		$serie_label = array_key_first( $by_serie );
		$headline    = sprintf( '🛒 Compra %s en Akibara', $serie_label );
		$sub         = 'Tomos disponibles con envío a todo Chile · 3 cuotas sin interés';
	} else {
		$headline = '🛒 Compra estos manga en Akibara';
		$sub      = 'Tomos disponibles · Envío a todo Chile · 3 cuotas sin interés';
	}

	ob_start();
	?>
	<aside class="akb-post-products" aria-label="Productos relacionados al articulo">
		<header class="akb-post-products__head">
			<h2 class="akb-post-products__title"><?php echo esc_html( $headline ); ?></h2>
			<p class="akb-post-products__sub"><?php echo esc_html( $sub ); ?></p>
		</header>
		<div class="akb-post-products__grid">
			<?php foreach ( array_keys( $product_ids ) as $pid ) :
				$product = wc_get_product( $pid );
				if ( ! $product ) { continue; }
				$thumb_id   = $product->get_image_id();
				$alt        = function_exists( 'akibara_blog_image_alt' ) ? akibara_blog_image_alt( $thumb_id, $product->get_name() ) : $product->get_name();
				$price_html = $product->get_price_html();
				$on_sale    = $product->is_on_sale();
				$is_reserva = get_post_meta( $pid, '_akb_reserva', true ) === 'yes';
			?>
				<a class="akb-post-products__card" href="<?php echo esc_url( get_permalink( $pid ) ); ?>" rel="bookmark">
					<div class="akb-post-products__thumb">
						<?php if ( $thumb_id ) {
							echo wp_get_attachment_image(
								$thumb_id,
								'product-card',
								false,
								[
									'class'    => 'akb-post-products__img',
									'loading'  => 'lazy',
									'decoding' => 'async',
									'sizes'    => '(min-width: 700px) 200px, 45vw',
									'alt'      => $alt,
								]
							);
						} ?>
						<?php if ( $is_reserva ) : ?>
							<span class="akb-post-products__badge akb-post-products__badge--reserva">PREVENTA</span>
						<?php elseif ( $on_sale ) : ?>
							<span class="akb-post-products__badge akb-post-products__badge--sale">OFERTA</span>
						<?php endif; ?>
					</div>
					<div class="akb-post-products__body">
						<span class="akb-post-products__name"><?php echo esc_html( $product->get_name() ); ?></span>
						<span class="akb-post-products__price"><?php echo wp_kses_post( $price_html ); ?></span>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	</aside>
	<?php
	return (string) ob_get_clean();
}

/**
 * Inject the widget at the end of the post content. Filter priority 25 keeps
 * it after TOC (5) and auto-product-links (20), but before wpautop (10 → no,
 * wpautop is at 10 but we need to be careful).
 *
 * Actually wpautop runs at priority 10 and converts our HTML newlines. We run
 * at 25 so wpautop has already wrapped paragraphs. The aside HTML is block
 * level and won't be wrapped by wpautop again.
 */
add_filter( 'the_content', function ( string $content ): string {
	if ( ! is_singular( 'post' ) ) {
		return $content;
	}
	if ( ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$widget = akibara_blog_render_product_cta( (int) get_the_ID() );
	if ( $widget === '' ) {
		return $content;
	}
	return $content . $widget;
}, 25 );

/**
 * Inline CSS — shipped only on single posts.
 */
add_action( 'wp_enqueue_scripts', function (): void {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$css = <<<CSS
.akb-post-products{margin:2.5rem 0 1rem;padding:1.5rem;border-radius:16px;background:linear-gradient(135deg,rgba(231,76,60,.08),rgba(231,76,60,.02));border:1px solid rgba(231,76,60,.2)}
.akb-post-products__head{margin-bottom:1.25rem;text-align:center}
.akb-post-products__title{font-size:1.35rem;font-weight:700;margin:0 0 .35rem;color:inherit}
.akb-post-products__sub{font-size:.85rem;opacity:.7;margin:0}
.akb-post-products__grid{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(160px,1fr))}
.akb-post-products__card{display:flex;flex-direction:column;gap:.55rem;text-decoration:none;color:inherit;background:rgba(0,0,0,.04);border-radius:12px;overflow:hidden;transition:transform .15s ease,box-shadow .15s ease;border:1px solid rgba(255,255,255,.04)}
.akb-post-products__card:hover{transform:translateY(-2px);box-shadow:0 12px 24px -16px rgba(231,76,60,.45);border-color:rgba(231,76,60,.3)}
.akb-post-products__thumb{position:relative;aspect-ratio:2/3;overflow:hidden;background:#0001}
.akb-post-products__img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s ease}
.akb-post-products__card:hover .akb-post-products__img{transform:scale(1.04)}
.akb-post-products__badge{position:absolute;top:.5rem;left:.5rem;padding:.18rem .5rem;border-radius:6px;font-size:.65rem;font-weight:700;letter-spacing:.04em;color:#fff}
.akb-post-products__badge--sale{background:var(--aki-red,#D90010)}
.akb-post-products__badge--reserva{background:#1A6CFF}
.akb-post-products__body{display:flex;flex-direction:column;gap:.25rem;padding:.55rem .75rem .85rem;flex:1;justify-content:space-between}
.akb-post-products__name{font-size:.85rem;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.akb-post-products__price{font-size:.95rem;font-weight:700;color:var(--aki-red,#D90010)}
.akb-post-products__price del{opacity:.45;font-weight:400;font-size:.78rem;margin-right:.3rem}
@media (max-width:600px){.akb-post-products{padding:1rem;border-radius:12px}.akb-post-products__grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.65rem}.akb-post-products__title{font-size:1.1rem}}
CSS;
	wp_register_style( 'akb-post-products', false );
	wp_enqueue_style( 'akb-post-products' );
	wp_add_inline_style( 'akb-post-products', $css );
}, 30 );

/**
 * Cache invalidation — forget cached product lists when a product changes
 * status, price, stock or its serie meta.
 */
function akibara_blog_product_cta_flush(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_akb_blogprod_%' OR option_name LIKE '_transient_timeout_akb_blogprod_%'" );
}
add_action( 'save_post_product', 'akibara_blog_product_cta_flush' );
add_action( 'woocommerce_product_set_stock', 'akibara_blog_product_cta_flush' );
