<?php
/**
 * Akibara: URLs limpias para categorías de productos
 *
 * Registra rewrite rules de WP para que /comics/, /manga/, etc.
 * funcionen directamente sin prefijo /product-category/ ni /categoria-producto/.
 * También filtra term_link para que los enlaces generados por WC usen la URL corta.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registra las rewrite rules de WP para cada slug de product_cat.
 * Se ejecuta en init > 5 (después de que WC registre la taxonomía).
 */
add_action( 'init', 'akb_register_clean_category_rules', 15 );
function akb_register_clean_category_rules(): void {
	$slugs = get_transient( 'akb_product_cat_slugs' );
	if ( false === $slugs ) {
		$slugs = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'slugs',
			)
		);
		if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
			return;
		}
		set_transient( 'akb_product_cat_slugs', $slugs, HOUR_IN_SECONDS );
	}
	if ( empty( $slugs ) ) {
		return;
	}
	foreach ( $slugs as $slug ) {
		$re = preg_quote( $slug, '/' );
		add_rewrite_rule( "^{$re}/feed/(feed|rdf|rss|rss2|atom)/?$", "index.php?product_cat={$slug}&feed=\$matches[1]", 'top' );
		add_rewrite_rule( "^{$re}/page/?([0-9]{1,})/?$", "index.php?product_cat={$slug}&paged=\$matches[1]", 'top' );
		add_rewrite_rule( "^{$re}/?$", "index.php?product_cat={$slug}", 'top' );
	}
}

/**
 * Filtra term_link para devolver la URL corta /{slug}/.
 */
add_filter( 'term_link', 'akb_clean_category_url', 10, 3 );
function akb_clean_category_url( string $url, \WP_Term $term, string $taxonomy ): string {
	if ( 'product_cat' !== $taxonomy ) {
		return $url;
	}
	return trailingslashit( home_url( '/' . $term->slug ) );
}

/**
 * Regenera rewrite rules cuando cambia alguna categoría de producto.
 */
foreach ( array( 'created_product_cat', 'edited_product_cat', 'deleted_product_cat' ) as $_akb_hook ) {
	add_action(
		$_akb_hook,
		static function (): void {
			delete_transient( 'akb_product_cat_slugs' );
			flush_rewrite_rules( false );
		}
	);
}
unset( $_akb_hook );

/**
 * Las páginas 404 NUNCA se deben cachear.
 * Avisa a LiteSpeed y envía no-store para que Cloudflare tampoco cachee.
 */
add_action(
	'template_redirect',
	static function (): void {
		if ( ! is_404() ) {
			return;
		}
		if ( has_action( 'litespeed_control_set_nocache' ) ) {
			do_action( 'litespeed_control_set_nocache', 'akibara-404-never-cache' );
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'Pragma: no-cache', true );
		}
	},
	0
);

/**
 * Purgar LiteSpeed automáticamente cuando WP actualiza las rewrite rules.
 * Cubre flush_rewrite_rules(), cambios de permalinks y activación de plugins.
 */
add_action(
	'update_option_rewrite_rules',
	static function (): void {
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
	},
	10,
	0
);
