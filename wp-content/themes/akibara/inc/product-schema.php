<?php
/**
 * Product Schema Markup — legacy fallback.
 *
 * El schema Product + Book/ComicStory se emite desde
 * `inc/seo.php::akibara_enrich_product_book_schema` a través del filtro
 * `rank_math/json_ld`. Ese enfoque es más robusto porque:
 *   - Evita emitir un <script ld+json> duplicado que confunda a Google.
 *   - Aprovecha el grafo consolidado de Rank Math (brand, author, offers).
 *   - Garantiza que `@id` y referencias cruzadas estén consistentes.
 *
 * Si Rank Math NO está activo, mantenemos un mínimo Product schema para
 * no dejar las páginas sin structured data.
 *
 * @package Akibara
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function akibara_product_schema(): void {
	// Evitar duplicar schema cuando Rank Math (o Yoast) ya lo emite.
	if ( defined( 'RANK_MATH_VERSION' ) || defined( 'WPSEO_VERSION' ) ) {
		return;
	}
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	global $product;
	if ( ! is_a( $product, 'WC_Product' ) ) {
		return;
	}

	$sku   = $product->get_sku();
	$price = $product->get_price();

	$schema = [
		'@context'    => 'https://schema.org/',
		'@type'       => 'Product',
		'name'        => wp_kses_post( $product->get_name() ),
		'image'       => wp_get_attachment_url( $product->get_image_id() ),
		'description' => wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ),
		'sku'         => $sku,
		'brand'       => [
			'@type' => 'Brand',
			'name'  => get_bloginfo( 'name' ),
		],
		'offers'      => [
			'@type'         => 'Offer',
			'url'           => get_permalink( $product->get_id() ),
			'priceCurrency' => get_woocommerce_currency(),
			'price'         => $price,
			'priceValidUntil' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
			'itemCondition'  => 'https://schema.org/NewCondition',
			'availability'   => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		],
	];

	// Reviews.
	$rating_count  = $product->get_rating_count();
	$average_rating = (float) $product->get_average_rating();
	if ( $rating_count > 0 ) {
		$schema['aggregateRating'] = [
			'@type'       => 'AggregateRating',
			'ratingValue' => $average_rating,
			'ratingCount' => $rating_count,
		];
	}

	// Book cuando hay ISBN válido.
	$isbn = get_post_meta( $product->get_id(), '_isbn', true );
	if ( ! $isbn && $sku && preg_match( '/^97[89]\d{10}$/', (string) $sku ) ) {
		$isbn = $sku;
	}
	if ( $isbn ) {
		$schema['@type']      = [ 'Product', 'Book' ];
		$schema['isbn']       = $isbn;
		$schema['bookFormat'] = 'Paperback';
		$schema['inLanguage'] = 'es';
	}

	echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>';
}
add_action( 'wp_head', 'akibara_product_schema' );
