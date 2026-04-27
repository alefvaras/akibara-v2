<?php
/**
 * Akibara – Búsqueda v10
 * Motor principal: indexador, queries FULLTEXT, REST API, panel admin.
 *
 * ESTE ARCHIVO se carga desde akibara.php via require_once.
 * NO contiene bootstrap SHORTINIT — eso es solo para search.php (endpoint directo).
 *
 * @package Akibara
 * @version 10.0.0
 */

defined( 'ABSPATH' ) || exit;
// Guard: cargar SOLO si plugin akibara legacy (V10) o akibara-core están active.
// Sprint 2 Cell Core Phase 1 — file relocated desde plugins/akibara/ a plugins/akibara-core/.
if ( ! defined( 'AKIBARA_V10_LOADED' ) && ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	return;
}
if ( defined( 'AKB_SEARCH_LOADED' ) ) {
	return;
}
define( 'AKB_SEARCH_LOADED', '10.0.0' );

// F-pivot defensive guard (mesa-01 vote 2026-04-27): if akb_sinonimos already
// declared via another load path (legacy plugin akibara/, double-include, etc),
// skip this module to prevent fatal redeclare. AKB_SEARCH_LOADED above covers
// file-level dedup; this covers symbol-level dedup. Belt-and-suspenders for the
// public functions akb_sinonimos + akb_create_index_table that originally caused
// the Sprint 2 deploy fatal. Visible warning makes load-order issues debuggable.
if ( function_exists( 'akb_sinonimos' ) || function_exists( 'akb_create_index_table' ) ) {
	error_log( '[akibara-core] akb_sinonimos/akb_create_index_table already declared — F-pivot guard tripped, possible load order issue. Skipping akibara-core/includes/akibara-search.php.' );
	return;
}

if ( ! defined( 'AKB_TABLE' ) ) {
	define( 'AKB_TABLE', $GLOBALS['wpdb']->prefix . 'akibara_index' );
}
if ( ! defined( 'AKB_MIN_CHARS' ) ) {
	define( 'AKB_MIN_CHARS', 2 );
}
if ( ! defined( 'AKB_LIMIT' ) ) {
	define( 'AKB_LIMIT', 10 );
}
if ( ! defined( 'AKB_CACHE_TTL' ) ) {
	define( 'AKB_CACHE_TTL', 600 );
}
if ( ! defined( 'AKB_CDN_TTL' ) ) {
	define( 'AKB_CDN_TTL', 60 );
}
if ( ! defined( 'AKB_CACHE_GROUP' ) ) {
	define( 'AKB_CACHE_GROUP', 'akibara_search' );
}

// ══════════════════════════════════════════════════════════════════
// 0. SINÓNIMOS — fuente única en data/sinonimos.php
// ══════════════════════════════════════════════════════════════════

function akb_sinonimos(): array {
	static $m = null;
	if ( $m !== null ) {
		return $m;
	}
	$m = require AKIBARA_CORE_DIR . 'data/sinonimos.php';
	return $m;
}

// ══════════════════════════════════════════════════════════════════
// 1. SCHEMA v10
// ══════════════════════════════════════════════════════════════════

function akb_create_index_table(): void {
	global $wpdb;
	$table   = AKB_TABLE;
	$charset = $wpdb->get_charset_collate();

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
            product_id    BIGINT(20) UNSIGNED NOT NULL,
            post_name     VARCHAR(200)         NOT NULL DEFAULT '',
            title_orig    VARCHAR(500)         NOT NULL DEFAULT '',
            title_norm    VARCHAR(500)         NOT NULL DEFAULT '',
            title_compact VARCHAR(500)         NOT NULL DEFAULT '',
            sku_norm      VARCHAR(100)         NOT NULL DEFAULT '',
            cats_norm     VARCHAR(1000)        NOT NULL DEFAULT '',
            tags_norm     VARCHAR(1000)        NOT NULL DEFAULT '',
            searchable    TEXT                 NOT NULL,
            in_stock      TINYINT(1)           NOT NULL DEFAULT 1,
            total_sales   BIGINT(20)           NOT NULL DEFAULT 0,
            display_data  TEXT                 NOT NULL,
            PRIMARY KEY   (product_id),
            FULLTEXT KEY  ft_searchable (searchable),
            FULLTEXT KEY  ft_norm       (title_norm),
            KEY           ix_compact    (title_compact(100))
        ) ENGINE=InnoDB {$charset};"
		);
		return;
	}
	akb_migrate_v10( $table );
}

function akb_migrate_v10( string $table ): void {
	global $wpdb;
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table es AKB_TABLE = $wpdb->prefix . 'akibara_index'; $ddl viene de array literal hardcoded. Sin user input. Migration DDL.
	$cols = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ), 'Field' );

	$adds = array(
		'sku_norm'    => "ADD COLUMN sku_norm VARCHAR(100) NOT NULL DEFAULT '' AFTER title_compact",
		'cats_norm'   => "ADD COLUMN cats_norm VARCHAR(1000) NOT NULL DEFAULT '' AFTER sku_norm",
		'tags_norm'   => "ADD COLUMN tags_norm VARCHAR(1000) NOT NULL DEFAULT '' AFTER cats_norm",
		'searchable'  => 'ADD COLUMN searchable TEXT NOT NULL AFTER tags_norm',
		'in_stock'    => 'ADD COLUMN in_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER searchable',
		'total_sales' => 'ADD COLUMN total_sales BIGINT(20) NOT NULL DEFAULT 0 AFTER in_stock',
	);
	foreach ( $adds as $col => $ddl ) {
		if ( ! in_array( $col, $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} {$ddl}" );
		}
	}
	if ( ! $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name='ft_searchable'" ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT KEY ft_searchable (searchable)" );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	update_option( 'akibara_needs_rebuild', 1, false );
}

// ══════════════════════════════════════════════════════════════════
// 2. INDEXADOR v10
// title_orig = título REAL del producto (con ñ, tildes, mayúsculas)
// title_norm = versión normalizada SOLO para búsqueda interna
// ══════════════════════════════════════════════════════════════════

function akb_index_product( int $id ): void {
	global $wpdb;
	$title = get_the_title( $id );
	if ( ! $title ) {
		$wpdb->delete( AKB_TABLE, array( 'product_id' => $id ) );
		return; }

	$norm    = akb_normalize( $title );
	$compact = akb_normalize( $title, true );

	$sku_norm    = akb_normalize( (string) get_post_meta( $id, '_sku', true ) );
	$cats        = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
	$cats_norm   = ( ! is_wp_error( $cats ) && $cats )
		? implode( ' ', array_map( 'akb_normalize', $cats ) ) : '';
	$tags        = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'names' ) );
	$tags_norm   = ( ! is_wp_error( $tags ) && $tags )
		? implode( ' ', array_map( 'akb_normalize', $tags ) ) : '';
	$searchable  = trim( implode( ' ', array_filter( array( $norm, $sku_norm, $cats_norm, $tags_norm ) ) ) );
	$in_stock    = (int) ( get_post_meta( $id, '_stock_status', true ) !== 'outofstock' );
	$total_sales = (int) get_post_meta( $id, 'total_sales', true );

	// Usar el objeto WC para obtener precios con descuentos aplicados
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
	if ( $product ) {
		$price   = (string) $product->get_price();
		$regular = (string) $product->get_regular_price();
	} else {
		$price   = (string) get_post_meta( $id, '_price', true );
		$regular = (string) get_post_meta( $id, '_regular_price', true );
	}
	$thumb = '';
	if ( $tid = get_post_thumbnail_id( $id ) ) {
		$src   = wp_get_attachment_image_src( $tid, 'shop_catalog' ) ?: wp_get_attachment_image_src( $tid, 'full' );
		$thumb = $src[0] ?? '';
	}
	if ( ! $thumb && function_exists( 'wc_placeholder_img_src' ) ) {
		$thumb = wc_placeholder_img_src();
	}

	$post_name = (string) get_post_field( 'post_name', $id );
	// Serie + editorial + stock para autocomplete enriquecido
	$serie_terms     = wp_get_post_terms( $id, 'pa_serie', array( 'fields' => 'names' ) );
	$serie_name      = ( ! is_wp_error( $serie_terms ) && ! empty( $serie_terms ) ) ? str_replace( '|', '', $serie_terms[0] ) : '';
	$editorial_terms = wp_get_post_terms( $id, 'product_brand', array( 'fields' => 'names' ) );
	$editorial_name  = ( ! is_wp_error( $editorial_terms ) && ! empty( $editorial_terms ) )
		? str_replace( '|', '', $editorial_terms[0] )
		: '';
	$stock_raw       = get_post_meta( $id, '_stock_status', true ) ?: 'instock';
	$stock_label     = has_term( 'preventas', 'product_cat', $id ) ? 'preorder' : $stock_raw;

	$display = $price . '|' . $regular . '|' . esc_url_raw( $thumb )
			. '|' . $serie_name . '|' . $editorial_name . '|' . $stock_label;

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- AKB_TABLE = $wpdb->prefix . 'akibara_index' (sin user input).
	$wpdb->query(
		$wpdb->prepare(
			'INSERT INTO ' . AKB_TABLE . '
            (product_id,post_name,title_orig,title_norm,title_compact,
             sku_norm,cats_norm,tags_norm,searchable,in_stock,total_sales,display_data)
         VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%s,%d,%d,%s)
         ON DUPLICATE KEY UPDATE
            post_name=VALUES(post_name),title_orig=VALUES(title_orig),
            title_norm=VALUES(title_norm),title_compact=VALUES(title_compact),
            sku_norm=VALUES(sku_norm),cats_norm=VALUES(cats_norm),
            tags_norm=VALUES(tags_norm),searchable=VALUES(searchable),
            in_stock=VALUES(in_stock),total_sales=VALUES(total_sales),
            display_data=VALUES(display_data)',
			$id,
			$post_name,
			$title,
			$norm,
			$compact,
			$sku_norm,
			$cats_norm,
			$tags_norm,
			$searchable,
			$in_stock,
			$total_sales,
			$display
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	akb_bump_cache_version();
}

add_action( 'save_post_product', static fn( $id ) => ! wp_is_post_revision( $id ) && ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ? akb_index_product( (int) $id ) : null );
add_action( 'woocommerce_update_product', static fn( $id ) => akb_index_product( (int) $id ) );
add_action(
	'before_delete_post',
	function ( $id ) {
		if ( get_post_type( $id ) !== 'product' ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( AKB_TABLE, array( 'product_id' => (int) $id ) );
		akb_bump_cache_version();
	}
);
add_action(
	'updated_post_meta',
	function ( $meta_id, $post_id, $meta_key ) {
		$keys = array( '_thumbnail_id', '_stock_status', 'total_sales', '_sku' );
		if ( in_array( $meta_key, $keys, true ) && get_post_type( $post_id ) === 'product' ) {
			akb_index_product( (int) $post_id );
		}
	},
	10,
	4
);

// ══════════════════════════════════════════════════════════════════
// 3. REBUILD MASIVO
// ══════════════════════════════════════════════════════════════════

function akb_rebuild_full_index( ?callable $cb = null ): array {
	global $wpdb;
	akb_create_index_table();
	$t     = microtime( true );
	$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", 'product', 'publish' ) );
	$total = count( $ids );
	$done  = 0;

	// Collect all product IDs that we will reindex
	$indexed_ids = array();

	foreach ( array_chunk( $ids, 50 ) as $chunk ) {
		foreach ( $chunk as $id ) {
			akb_index_product( (int) $id ); // INSERT ... ON DUPLICATE KEY UPDATE
			$indexed_ids[] = (int) $id;
			++$done;
		}
		if ( $cb ) {
			$cb( $done, $total );
		}
	}

	// Remove stale entries (products that no longer exist or are unpublished)
	// instead of TRUNCATE before rebuild — this way search works during the rebuild
	if ( ! empty( $indexed_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $indexed_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- AKB_TABLE = $wpdb->prefix . 'akibara_index'; $placeholders es lista controlada de '%d'.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . AKB_TABLE . " WHERE product_id NOT IN ({$placeholders})",
				...$indexed_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	akb_bump_cache_version();
	delete_option( 'akibara_needs_rebuild' );
	return array(
		'productos' => $done,
		'tiempo'    => round( microtime( true ) - $t, 2 ),
	);
}

// ══════════════════════════════════════════════════════════════════
// 4. CACHÉ VERSIONADA
// ══════════════════════════════════════════════════════════════════

function akb_cache_version(): int {
	static $v = null;
	if ( $v !== null ) {
		return $v;
	}
	$v = (int) wp_cache_get( 'akb_cache_v', AKB_CACHE_GROUP );
	if ( ! $v ) {
		$v = (int) get_option( 'akibara_cache_version', 1 );
		wp_cache_set( 'akb_cache_v', $v, AKB_CACHE_GROUP, 300 );
	}
	return $v;
}
function akb_bump_cache_version(): void {
	$v = (int) get_option( 'akibara_cache_version', 1 ) + 1;
	update_option( 'akibara_cache_version', $v, false );
	wp_cache_delete( 'akb_cache_v', AKB_CACHE_GROUP );
}

// ══════════════════════════════════════════════════════════════════
// 5. COMPAT — Legacy AJAX fallback
// ══════════════════════════════════════════════════════════════════

add_action(
	'after_setup_theme',
	function () {
		remove_action( 'wp_ajax_akibara_ajax_search_products', 'akibara_ajax_search_products' );
		remove_action( 'wp_ajax_nopriv_akibara_ajax_search_products', 'akibara_ajax_search_products' );
		add_action( 'wp_ajax_akibara_ajax_search_products', 'akb_ajax_compat' );
		add_action( 'wp_ajax_nopriv_akibara_ajax_search_products', 'akb_ajax_compat' );
	},
	100
);

function akb_ajax_compat(): void {
	$q   = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';
	$cat = isset( $_REQUEST['product_cat'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['product_cat'] ) ) : '';
	if ( mb_strlen( $q, 'UTF-8' ) < AKB_MIN_CHARS ) {
		wp_send_json( array() ); }
	$key    = 'akb10_v' . akb_cache_version() . '_' . md5( $q . '|' . $cat );
	$cached = wp_cache_get( $key, AKB_CACHE_GROUP );
	if ( $cached !== false ) {
		wp_send_json( $cached ); }
	$rows = akb_query_index( $q, $cat );
	$out  = akb_format_results( $rows, $q );
	wp_cache_set( $key, $out, AKB_CACHE_GROUP, AKB_CACHE_TTL );
	wp_send_json( $out );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'akibara/v1',
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'akb_rest_handler',
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'cat' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
		register_rest_route(
			'akibara/v1',
			'/suggest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'akb_rest_suggest_handler',
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
	}
);

/**
 * Suggestions endpoint — ligero, devuelve series/autores/queries que hacen prefix-match.
 * No bloquea el render de productos.
 */
function akb_rest_suggest_handler( WP_REST_Request $req ): WP_REST_Response {
	$q = (string) $req->get_param( 'q' );
	if ( mb_strlen( $q, 'UTF-8' ) < AKB_MIN_CHARS ) {
		return akb_rest_response( array() );
	}
	$key    = 'akb_sug_v' . akb_cache_version() . '_' . md5( $q );
	$cached = wp_cache_get( $key, AKB_CACHE_GROUP );
	if ( $cached !== false ) {
		return akb_rest_response( $cached, true );
	}
	$out = akb_get_suggestions( $q );
	wp_cache_set( $key, $out, AKB_CACHE_GROUP, AKB_CACHE_TTL );
	return akb_rest_response( $out );
}

/**
 * Calcula sugerencias a partir de:
 *  - pa_serie (hasta 4): nombre de la serie + conteo de productos publicados
 *  - pa_autor (hasta 2): nombre del autor
 *  - akibara_trending_searches (hasta 2 extra): queries populares con prefix-match
 * Devuelve max 6 items.
 */
function akb_get_suggestions( string $q ): array {
	global $wpdb;
	$q    = trim( $q );
	$like = '%' . $wpdb->esc_like( $q ) . '%';
	$out  = array();

	// Series (_akibara_serie_norm): fuente canónica completa — incluye series con landing
	// en /serie/{slug}/ que no están en pa_serie (ej. "Naruto Jump Remix").
	// Match both storage formats (slug-form "naruto-jump-remix" y norm-form "narutojumpremix").
	$q_norm    = preg_replace( '/[^a-z0-9]/i', '', mb_strtolower( $q, 'UTF-8' ) );
	$like_slug = '%' . $wpdb->esc_like( mb_strtolower( $q, 'UTF-8' ) ) . '%';
	$like_norm = '%' . $wpdb->esc_like( $q_norm ) . '%';
	$like_name = '%' . $wpdb->esc_like( $q ) . '%';

	$series = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value AS serie_slug,
                COUNT(DISTINCT p.ID) AS tomos,
                MAX(pm_name.meta_value) AS serie_name,
                CASE WHEN pm.meta_value LIKE %s OR MAX(pm_name.meta_value) LIKE %s THEN 1 ELSE 2 END AS _rank
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         LEFT JOIN {$wpdb->postmeta} pm_name ON p.ID = pm_name.post_id AND pm_name.meta_key = '_akibara_serie'
         WHERE pm.meta_key = '_akibara_serie_norm'
           AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )
           AND p.post_type = 'product'
           AND p.post_status = 'publish'
         GROUP BY pm.meta_value
         HAVING tomos > 0 AND ( _rank = 1 OR serie_name LIKE %s )
         ORDER BY _rank ASC, tomos DESC, LENGTH(pm.meta_value) ASC
         LIMIT 4",
			$wpdb->esc_like( mb_strtolower( $q, 'UTF-8' ) ) . '%',
			$wpdb->esc_like( $q ) . '%',
			$like_slug,
			$like_norm,
			$like_name
		)
	);

	$name_map = function_exists( 'akibara_serie_name_map' ) ? akibara_serie_name_map() : array();
	foreach ( (array) $series as $row ) {
		$slug_form = strtolower( $row->serie_slug );
		$norm_form = preg_replace( '/[^a-z0-9]/', '', $slug_form );

		if ( isset( $name_map[ $norm_form ] ) ) {
			$label = $name_map[ $norm_form ];
		} elseif ( isset( $name_map[ $slug_form ] ) ) {
			$label = $name_map[ $slug_form ];
		} elseif ( ! empty( $row->serie_name ) ) {
			$label = $row->serie_name;
		} else {
			$label = ucwords( str_replace( array( '_', '-' ), ' ', $slug_form ) );
		}

		$count = (int) $row->tomos;
		$out[] = array(
			'type'  => 'serie',
			'label' => $label,
			'meta'  => sprintf( '%d %s', $count, $count === 1 ? 'tomo' : 'tomos' ),
			'url'   => home_url( '/serie/' . $slug_form . '/' ),
		);
	}

	// Autores (pa_autor): solo 2 para no saturar
	$autores = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.term_id, t.name, tt.count
         FROM {$wpdb->terms} t
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
         WHERE tt.taxonomy = 'pa_autor' AND t.name LIKE %s AND tt.count > 0
         ORDER BY tt.count DESC, LENGTH(t.name) ASC
         LIMIT 2",
			$like
		)
	);
	foreach ( (array) $autores as $t ) {
		$link = get_term_link( (int) $t->term_id, 'pa_autor' );
		if ( is_wp_error( $link ) || ! $link ) {
			continue;
		}
		$out[] = array(
			'type'  => 'autor',
			'label' => $t->name,
			'meta'  => 'Autor',
			'url'   => $link,
		);
	}

	// Trending queries que hagan prefix-match (rellena hasta 6)
	if ( count( $out ) < 6 ) {
		$trending = (array) get_option( 'akibara_trending_searches', array() );
		arsort( $trending );
		$ql     = mb_strtolower( $q, 'UTF-8' );
		$picked = 0;
		$home   = rtrim( home_url( '/' ), '/' );
		foreach ( $trending as $tq => $count ) {
			if ( $picked >= 2 || count( $out ) >= 6 ) {
				break;
			}
			if ( mb_strtolower( (string) $tq, 'UTF-8' ) === $ql ) {
				continue;
			}
			if ( mb_stripos( (string) $tq, $q, 0, 'UTF-8' ) !== 0 ) {
				continue;
			}
			$out[] = array(
				'type'  => 'query',
				'label' => (string) $tq,
				'meta'  => 'Búsqueda popular',
				'url'   => $home . '/?s=' . rawurlencode( (string) $tq ) . '&post_type=product',
			);
			++$picked;
		}
	}

	return $out;
}

function akb_rest_handler( WP_REST_Request $req ): WP_REST_Response {
	$q   = (string) $req->get_param( 'q' );
	$cat = (string) $req->get_param( 'cat' );
	if ( mb_strlen( $q, 'UTF-8' ) < AKB_MIN_CHARS ) {
		return akb_rest_response( array() );
	}
	$key    = 'akb10_v' . akb_cache_version() . '_' . md5( $q . '|' . $cat );
	$cached = wp_cache_get( $key, AKB_CACHE_GROUP );
	if ( $cached !== false ) {
		return akb_rest_response( $cached, true );
	}
	$rows = akb_query_index( $q, $cat );
	$out  = akb_format_results( $rows, $q );
	wp_cache_set( $key, $out, AKB_CACHE_GROUP, AKB_CACHE_TTL );
	return akb_rest_response( $out );
}

function akb_rest_response( array $data, bool $hit = false ): WP_REST_Response {
	$r = new WP_REST_Response( $data, 200 );
	$r->header( 'Cache-Control', 'public, max-age=' . AKB_CDN_TTL . ', stale-while-revalidate=300' );
	if ( $hit ) {
		$r->header( 'X-Akibara-Cache', 'HIT' );
	}
	return $r;
}

// ══════════════════════════════════════════════════════════════════
// 6. MOTOR DE QUERY v10
// ══════════════════════════════════════════════════════════════════

/**
 * Verifica si el índice FULLTEXT `ft_searchable` existe en AKB_TABLE.
 * Cacheado por request (rápido) + transient 1h (persistente).
 *
 * Rationale: si se reconstruye tabla sin FULLTEXT (bug conocido en
 * migraciones), las queries MATCH AGAINST explotarían silenciosamente
 * devolviendo "sin resultados" — peor UX que un LIKE fallback.
 *
 * @return bool
 */
function akb_search_has_fulltext(): bool {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}

	$ttl_key = 'akb_search_ft_ok';
	$stored  = get_transient( $ttl_key );
	if ( $stored !== false ) {
		$cached = (bool) $stored;
		return $cached;
	}

	global $wpdb;
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- AKB_TABLE = $wpdb->prefix . 'akibara_index' (sin user input).
	$has = (bool) $wpdb->get_var(
		'SHOW INDEX FROM ' . AKB_TABLE . " WHERE Key_name='ft_searchable'"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	set_transient( $ttl_key, $has ? '1' : '0', HOUR_IN_SECONDS );
	$cached = $has;
	return $cached;
}

function akb_query_index( string $q, string $cat = '' ): array {
	global $wpdb;

	$lower   = mb_strtolower( $q, 'UTF-8' );
	$norm    = akb_normalize( $q );
	$compact = akb_normalize( $q, true );
	$sino    = akb_sinonimos();
	$exp     = $sino[ $lower ] ?? $sino[ $norm ] ?? $sino[ $compact ] ?? null;
	$table   = AKB_TABLE;
	$limit   = AKB_LIMIT;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table = AKB_TABLE = $wpdb->prefix . 'akibara_index'; $limit = AKB_LIMIT (int constant); $case_sql/$where_sql/$where_cat se construyen con $wpdb->prepare() segmentos. Sin user input directo en interpolaciones.

	if ( preg_match( '/^\d{5,13}$/', preg_replace( '/[\s\-]/', '', $q ) ) ) {
		$sku_q = preg_replace( '/[\s\-]/', '', $q );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id,title_orig,display_data,post_name FROM {$table} WHERE sku_norm=%s LIMIT 5",
				$sku_q
			)
		);
		if ( $rows ) {
			return $rows;
		}
	}

	$len = mb_strlen( $norm, 'UTF-8' );

	if ( $len < 3 ) {
		$like = $wpdb->esc_like( $compact ) . '%';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id,title_orig,display_data,post_name FROM {$table}
             WHERE title_compact LIKE %s ORDER BY total_sales DESC,LENGTH(title_norm) ASC LIMIT {$limit}",
				$like
			)
		) ?: array();
	}

	// Fallback LIKE si no hay FULLTEXT (previene búsqueda rota silenciosa
	// tras migraciones o si MariaDB rechazó el índice).
	if ( ! akb_search_has_fulltext() ) {
		if ( function_exists( 'akb_log' ) ) {
			akb_log( 'search', 'warn', 'FULLTEXT missing, LIKE fallback', array( 'q' => $q ) );
		}
		$like_norm    = '%' . $wpdb->esc_like( $norm ) . '%';
		$like_compact = '%' . $wpdb->esc_like( $compact ) . '%';
		$join_cat_lk  = '';
		$where_cat_lk = '';
		if ( ! empty( $cat ) && $cat !== '0' ) {
			$join_cat_lk  = "INNER JOIN {$wpdb->term_relationships} tr ON i.product_id=tr.object_id
                             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id";
			$where_cat_lk = $wpdb->prepare(
				"AND tt.taxonomy='product_cat' AND tt.term_id=(SELECT term_id FROM {$wpdb->terms} WHERE slug=%s LIMIT 1)",
				$cat
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.product_id,i.title_orig,i.display_data,i.post_name,10 AS _score
             FROM {$table} i {$join_cat_lk}
             WHERE (i.title_norm LIKE %s OR i.title_compact LIKE %s) {$where_cat_lk}
             ORDER BY i.total_sales DESC, LENGTH(i.title_norm) ASC
             LIMIT {$limit}",
				$like_norm,
				$like_compact
			)
		) ?: array();
	}

	$ft = akb_build_ft( $norm );

	$case_parts  = array(
		$wpdb->prepare( 'WHEN i.title_norm=%s THEN 100', $norm ),
		$wpdb->prepare( 'WHEN i.title_norm LIKE %s THEN 90', $wpdb->esc_like( $norm ) . '%' ),
		$wpdb->prepare( 'WHEN MATCH(i.title_norm) AGAINST(%s IN BOOLEAN MODE) THEN 80', $ft ),
		$wpdb->prepare( 'WHEN i.sku_norm=%s THEN 70', $norm ),
		$wpdb->prepare( 'WHEN MATCH(i.searchable) AGAINST(%s IN BOOLEAN MODE) THEN 60', $ft ),
		$wpdb->prepare( 'WHEN i.title_compact LIKE %s THEN 40', '%' . $wpdb->esc_like( $compact ) . '%' ),
	);
	$where_parts = array(
		$wpdb->prepare( 'MATCH(i.title_norm) AGAINST(%s IN BOOLEAN MODE)', $ft ),
		$wpdb->prepare( 'MATCH(i.searchable) AGAINST(%s IN BOOLEAN MODE)', $ft ),
		$wpdb->prepare( 'i.title_compact LIKE %s', '%' . $wpdb->esc_like( $compact ) . '%' ),
		$wpdb->prepare( 'i.sku_norm LIKE %s', $wpdb->esc_like( $norm ) . '%' ),
	);

	if ( $exp ) {
		$ft_exp        = akb_build_ft( akb_normalize( $exp ) );
		$case_parts[]  = $wpdb->prepare( 'WHEN MATCH(i.title_norm) AGAINST(%s IN BOOLEAN MODE) THEN 20', $ft_exp );
		$where_parts[] = $wpdb->prepare( 'MATCH(i.title_norm) AGAINST(%s IN BOOLEAN MODE)', $ft_exp );
	}

	$case_sql  = 'CASE ' . implode( ' ', $case_parts ) . ' ELSE 10 END';
	$where_sql = '(' . implode( ' OR ', $where_parts ) . ')';

	$join_cat = $where_cat = '';
	if ( ! empty( $cat ) && $cat !== '0' ) {
		$join_cat  = "INNER JOIN {$wpdb->term_relationships} tr ON i.product_id=tr.object_id
                      INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id";
		$where_cat = $wpdb->prepare(
			"AND tt.taxonomy='product_cat' AND tt.term_id=(SELECT term_id FROM {$wpdb->terms} WHERE slug=%s LIMIT 1)",
			$cat
		);
	}

	$rows = $wpdb->get_results(
		"SELECT i.product_id,i.title_orig,i.display_data,i.post_name,
                ({$case_sql}) AS _score, i.total_sales
         FROM {$table} i {$join_cat}
         WHERE {$where_sql} {$where_cat}
         ORDER BY _score DESC, i.total_sales DESC, LENGTH(i.title_norm) ASC
         LIMIT {$limit}"
	) ?: array();

	if ( empty( $rows ) && $len >= 4 ) {
		$words = array_filter( explode( ' ', $norm ), static fn( $w ) => mb_strlen( $w, 'UTF-8' ) >= 4 );
		if ( $words ) {
			$w1   = reset( $words );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT i.product_id,i.title_orig,i.display_data,i.post_name,10 AS _score
                 FROM {$table} i {$join_cat}
                 WHERE i.title_norm SOUNDS LIKE %s {$where_cat}
                 ORDER BY i.total_sales DESC,LENGTH(i.title_norm) ASC LIMIT {$limit}",
					$w1
				)
			) ?: array();
		}
	}

	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	return $rows;
}

function akb_build_ft( string $normalized ): string {
	$words = array_filter( explode( ' ', $normalized ), static fn( $w ) => mb_strlen( $w, 'UTF-8' ) >= 2 );
	return $words
		? implode( ' ', array_map( static fn( $w ) => '+' . $w . '*', $words ) )
		: '"' . $normalized . '"';
}

// ══════════════════════════════════════════════════════════════════
// 7. FORMATEADOR — usa title_orig (con ñ/tildes reales)
// ══════════════════════════════════════════════════════════════════

function akb_format_results( array $rows, string $q ): array {
	if ( empty( $rows ) ) {
		akb_log_failed_search( $q );
		return array(
			array(
				'id'    => -1,
				'value' => 'Sin resultados',
				'url'   => '',
			),
		);
	}
	static $home = null, $prod_base = null, $placeholder = null;
	if ( $home === null ) {
		$wc          = (array) get_option( 'woocommerce_permalinks', array() );
		$prod_base   = trim( $wc['product_base'] ?? 'product', '/' ) ?: 'product';
		$home        = rtrim( home_url(), '/' );
		$placeholder = function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src() : '';
	}
	$out = array();
	foreach ( $rows as $row ) {
		$parts   = explode( '|', (string) $row->display_data );
		$price   = (float) ( $parts[0] ?? 0 );
		$regular = (float) ( $parts[1] ?? 0 );
		$thumb   = ! empty( $parts[2] ) ? esc_url( $parts[2] ) : $placeholder;

		if ( $regular > $price && $price > 0 ) {
			$price_html = '<del>' . wc_price( $regular ) . '</del><ins>' . wc_price( $price ) . '</ins>';
		} elseif ( $price > 0 ) {
			$price_html = '<span class="amount">' . wc_price( $price ) . '</span>';
		} else {
			$price_html = '<span class="amount">Ver precio</span>';
		}
		$serie_out     = $parts[3] ?? '';
		$editorial_out = $parts[4] ?? '';
		$stock_out     = $parts[5] ?? 'instock';

		$out[] = array(
			'id'        => (int) $row->product_id,
			'value'     => strip_tags( $row->title_orig ?: '' ),
			'url'       => $home . '/' . $prod_base . '/' . $row->post_name . '/',
			'img'       => $thumb,
			'price'     => wp_kses(
				$price_html,
				array(
					'span' => array( 'class' => array() ),
					'del'  => array(),
					'ins'  => array(),
					'bdi'  => array(),
				)
			),
			'serie'     => $serie_out,
			'editorial' => $editorial_out,
			'stock'     => $stock_out,
		);
	}
	return $out ?: array(
		array(
			'id'    => -1,
			'value' => 'Sin resultados',
			'url'   => '',
		),
	);
}

// ══════════════════════════════════════════════════════════════════
// 8. BÚSQUEDA PRINCIPAL WP (/search)
// ══════════════════════════════════════════════════════════════════

add_filter( 'posts_search', 'akb_main_search_where', 150, 2 );
add_filter( 'posts_orderby', 'akb_main_search_orderby', 150, 2 );

function akb_main_search_where( string $search, WP_Query $q ): string {
	global $wpdb;
	if ( ! ( ! is_admin() && $q->is_main_query() && $q->is_search() && $q->get( 'post_type' ) === 'product' ) ) {
		return $search;
	}
	$term = $q->get( 's' );
	if ( ! $term ) {
		return $search;
	}
	$norm    = akb_normalize( $term );
	$compact = akb_normalize( $term, true );
	$sino    = akb_sinonimos();
	$lower   = mb_strtolower( $term, 'UTF-8' );
	$exp     = $sino[ $lower ] ?? $sino[ $norm ] ?? null;
	$table   = AKB_TABLE;
	$ft      = akb_build_ft( $norm );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table = AKB_TABLE = $wpdb->prefix . 'akibara_index' (sin user input). $term/$compact/$exp pasan por $wpdb->esc_like() y $wpdb->prepare() con %s.
	$conds   = array(
		$wpdb->prepare( "EXISTS(SELECT 1 FROM {$table} i WHERE i.product_id={$wpdb->posts}.ID AND MATCH(i.searchable) AGAINST(%s IN BOOLEAN MODE))", $ft ),
		$wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like( $term ) . '%' ),
	);
	// Fuzzy match sin espacios: "danda" → "dandadan4…" vía title_compact.
	// Why: FULLTEXT +danda* no matchea tokens separados (dan da dan), y post_title LIKE %danda% tampoco.
	if ( $compact !== '' ) {
		$conds[] = $wpdb->prepare(
			"EXISTS(SELECT 1 FROM {$table} i WHERE i.product_id={$wpdb->posts}.ID AND i.title_compact LIKE %s)",
			'%' . $wpdb->esc_like( $compact ) . '%'
		);
	}
	if ( $exp ) {
		$conds[] = $wpdb->prepare(
			"EXISTS(SELECT 1 FROM {$table} i WHERE i.product_id={$wpdb->posts}.ID AND MATCH(i.title_norm) AGAINST(%s IN BOOLEAN MODE))",
			akb_build_ft( akb_normalize( $exp ) )
		);
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return ' AND (' . implode( ' OR ', $conds ) . ')';
}

function akb_main_search_orderby( string $orderby, WP_Query $q ): string {
	global $wpdb;
	if ( ! ( ! is_admin() && $q->is_main_query() && $q->is_search() && $q->get( 'post_type' ) === 'product' ) ) {
		return $orderby;
	}
	$term = $q->get( 's' );
	if ( ! $term ) {
		return $orderby;
	}
	return $wpdb->prepare(
		"CASE WHEN {$wpdb->posts}.post_title LIKE %s THEN 1 ELSE 2 END ASC,LENGTH({$wpdb->posts}.post_title) ASC",
		$wpdb->esc_like( $term ) . '%'
	);
}

// ══════════════════════════════════════════════════════════════════
// 9. PANEL ADMIN
// ══════════════════════════════════════════════════════════════════

add_action(
	'admin_menu',
	function () {
		if ( ! defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
			add_submenu_page(
				'woocommerce',
				'Akibara Búsqueda v10',
				'🔍 Akibara Búsqueda',
				'manage_woocommerce',
				'akibara-search',
				'akb_admin_page'
			);
		}
	}
);

function akb_admin_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos.' );
	}
	$msg  = '';
	$tipo = 'info';

	if ( isset( $_POST['akb_action'] ) && check_admin_referer( 'akb_search_nonce' ) ) {
		$action = sanitize_key( $_POST['akb_action'] );
		if ( $action === 'rebuild' ) {
			$r    = akb_rebuild_full_index();
			$tipo = 'success';
			$msg  = "✅ Índice v10 reconstruido: {$r['productos']} productos en {$r['tiempo']}s";
		} elseif ( $action === 'flush' ) {
			akb_bump_cache_version();
			$tipo = 'success';
			$msg  = '✅ Caché invalidada';
		} elseif ( $action === 'recreate' ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- AKB_TABLE constante interna.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . AKB_TABLE );
			akb_create_index_table();
			$tipo = 'success';
			$msg  = '✅ Tabla v10 recreada — ahora Reconstruir índice';
		}
	}

	global $wpdb;
	$table   = AKB_TABLE;
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table = AKB_TABLE constante interna.
	$indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );
	$cols    = $table === $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" )
		? array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ), 'Field' )
		: array();
	$v10_ok  = in_array( 'searchable', $cols, true );
	$ft_ok   = (bool) $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name='ft_searchable'" );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	?>
	<div class="akb-page-header">
		<h2 class="akb-page-header__title">Busqueda v10</h2>
		<p class="akb-page-header__desc">Multi-campo, fuzzy, popularidad. Motor de busqueda personalizado.</p>
	</div>

	<?php
	if ( $msg ) {
		echo '<div class="akb-notice akb-notice--' . esc_attr( $tipo === 'success' ? 'success' : 'info' ) . '">' . esc_html( $msg ) . '</div>';}
	?>

	<div class="akb-stats">
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo $total > $indexed ? 'akb-stat__value--warning' : 'akb-stat__value--success'; ?>"><?php echo number_format( $indexed ); ?></div>
			<div class="akb-stat__label">Indexados</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value"><?php echo number_format( $total ); ?></div>
			<div class="akb-stat__label">Total productos</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo $v10_ok ? 'akb-stat__value--success' : 'akb-stat__value--error'; ?>"><?php echo $v10_ok ? 'OK' : 'No'; ?></div>
			<div class="akb-stat__label">Schema v10</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo $ft_ok ? 'akb-stat__value--success' : 'akb-stat__value--error'; ?>"><?php echo $ft_ok ? 'OK' : 'No'; ?></div>
			<div class="akb-stat__label">FULLTEXT</div>
		</div>
	</div>

	<div class="akb-card akb-card--section">
		<h3 class="akb-section-title">Estado del indice</h3>
		<table class="akb-table">
			<tbody>
				<tr><td>Tabla</td><td><code><?php echo esc_html( $table ); ?></code></td></tr>
				<tr><td>Schema v10 (searchable, sku, stock...)</td><td><?php echo $v10_ok ? '<span class="akb-badge akb-badge--active">OK</span>' : '<span class="akb-badge akb-badge--warning">Pendiente</span>'; ?></td></tr>
				<tr><td>FULLTEXT ft_searchable</td><td><?php echo $ft_ok ? '<span class="akb-badge akb-badge--active">OK</span>' : '<span class="akb-badge akb-badge--error">Recrear tabla</span>'; ?></td></tr>
				<tr><td>Productos indexados</td><td><strong><?php echo number_format( $indexed ); ?> / <?php echo number_format( $total ); ?></strong></td></tr>
				<tr><td>Version cache</td><td><code><?php echo (int) akb_cache_version(); ?></code></td></tr>
				<tr><td>Endpoint SHORTINIT</td><td><code><?php echo esc_html( plugins_url( 'search.php', AKIBARA_CORE_FILE ) ); ?></code></td></tr>
				<tr><td>Fallback REST</td><td><code><?php echo esc_html( rest_url( 'akibara/v1/search' ) ); ?></code></td></tr>
			</tbody>
		</table>
	</div>

	<div class="akb-card__actions">
		<form method="post">
			<?php wp_nonce_field( 'akb_search_nonce' ); ?>
			<button name="akb_action" value="rebuild" type="submit" class="akb-btn akb-btn--primary">Reconstruir indice v10</button>
			<button name="akb_action" value="flush" type="submit" class="akb-btn">Invalidar cache</button>
			<button name="akb_action" value="recreate" type="submit" class="akb-btn akb-btn--danger"
				onclick="return confirm('¿Recrear tabla? Se pierde el indice actual.')">Recrear tabla</button>
		</form>
	</div>
	<?php
}

// ══════════════════════════════════════════════════════════════════
// 11. CSS + JS FRONTEND
// ══════════════════════════════════════════════════════════════════

add_action(
	'wp_head',
	function () {
		?>
<style id="akibara-search-styles">
.ajax-search{position:relative}
.ajax-search-result{position:absolute;top:calc(100% + 8px);left:0;right:0;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.14);max-height:480px;overflow-y:auto;z-index:999999;display:none;border:1px solid #e5e7eb}
.ajax-search-result.show{display:block!important}
.akibara-search-popup{position:fixed;inset:0;z-index:9999999;opacity:0;visibility:hidden;transition:opacity .25s,visibility .25s}
.akibara-search-popup.show{opacity:1;visibility:visible}
.akibara-popup-overlay{position:absolute;inset:0;background:rgba(0,0,0,.82);backdrop-filter:blur(5px)}
.akibara-popup-container{position:relative;height:100%;display:flex;flex-direction:column;padding:64px 20px 20px;max-width:860px;margin:0 auto}
.akibara-popup-header{flex-shrink:0;margin-bottom:24px}
.akibara-search-box{background:#fff;border-radius:50px;box-shadow:0 10px 40px rgba(0,0,0,.3);display:flex;align-items:center;padding:0 24px;transition:box-shadow .25s,transform .25s}
.akibara-search-box:focus-within{box-shadow:0 12px 50px rgba(0,0,0,.4);transform:translateY(-2px)}
#akibara-popup-input{flex:1;border:none;outline:none;padding:20px 12px;font-size:17px;background:transparent;color:#111;min-width:0;-webkit-appearance:none;appearance:none}
#akibara-popup-input::placeholder{color:#aaa}
#akibara-popup-input::-webkit-search-cancel-button,#akibara-popup-input::-webkit-search-decoration,#akibara-popup-input::-ms-clear{-webkit-appearance:none;appearance:none;display:none}
.akibara-close-btn{position:absolute;top:14px;right:14px;z-index:2;width:40px;height:40px;border-radius:50%;border:none;background:rgba(255,255,255,.92);color:#333;cursor:pointer;font-size:26px;font-weight:300;transition:background .2s,color .2s,transform .2s;display:flex;align-items:center;justify-content:center;line-height:1;padding:0;box-shadow:0 4px 14px rgba(0,0,0,.25)}
.akibara-close-btn:hover{background:#D90010;color:#fff;transform:rotate(90deg)}
.akibara-close-btn:focus-visible{outline:2px solid #fff;outline-offset:3px}
.akibara-popup-results{flex:1;background:#fff;border-radius:14px;overflow-y:auto;display:none;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.akibara-popup-results.show{display:block!important}
.ajax-search-result ul,.akibara-popup-results ul{list-style:none;margin:0;padding:6px 0}
.ajax-search-result li,.akibara-popup-results li{margin:0 6px}
.ajax-search-result li a,.akibara-popup-results li a{display:flex;align-items:center;gap:14px;padding:10px 14px;text-decoration:none;color:#222;transition:background .12s;border-radius:8px;margin:2px 0}
.ajax-search-result li a:hover,.ajax-search-result li.akb-active a,
.akibara-popup-results li a:hover,.akibara-popup-results li.akb-active a{background:#f3f4f6}
.search-image{flex-shrink:0;width:52px;height:74px;border-radius:6px;overflow:hidden;background:#f8f8f8;border:1px solid #e8e8e8;display:flex;align-items:center;justify-content:center}
.search-image img{width:100%;height:100%;object-fit:contain}
.search-content{flex:1;min-width:0;display:flex;flex-direction:column;gap:5px}
.search-title{font-weight:500;font-size:13.5px;line-height:1.4;color:#111827;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.search-title mark{background:none;color:#D90010;font-weight:700;padding:0}
.search-price{font-size:15px;font-weight:600;color:#111}
.search-price .amount{color:#dc2626;font-weight:700}
.search-price del{opacity:.55;font-size:12px;margin-right:4px}
.search-price ins{text-decoration:none}
.search-meta{font-size:11px;color:#A0A0A0;margin-top:1px;display:flex;gap:6px;flex-wrap:wrap}.search-serie{color:#A0A0A0}.search-editorial{color:#666}.search-editorial::before{content:"·";margin-right:4px}.search-image{position:relative}.search-badge{position:absolute;bottom:3px;left:3px;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;text-transform:uppercase;letter-spacing:.03em;line-height:1.4}.search-badge--out{background:#D90010;color:#fff}.search-badge--pre{background:#FFD600;color:#0D0D0F}.search-view-all a{display:block;text-align:center;padding:10px;font-size:13px;color:#D90010;font-weight:600;text-decoration:none}.search-view-all a:hover{text-decoration:underline}
.search-loading{padding:40px 20px;text-align:center;color:#9ca3af;font-size:14px}
.search-loading::after{content:'';display:inline-block;width:18px;height:18px;border:2px solid #e5e7eb;border-top-color:#6b7280;border-radius:50%;animation:akb-spin .7s linear infinite;vertical-align:middle;margin-left:8px}
@keyframes akb-spin{to{transform:rotate(360deg)}}
.no-results,.search-error{padding:40px 20px;text-align:center;font-size:14px;color:#9ca3af}
.search-error{color:#ef4444}
.akb-history-header{padding:10px 20px 4px;font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em}
.akb-history-icon{width:20px;height:20px;opacity:.4;flex-shrink:0}
.akb-section{padding:4px 0 8px}
.akb-section+.akb-section{border-top:1px solid #eef0f2}
.akb-section-head{display:flex;align-items:center;justify-content:space-between;padding:12px 20px 6px}
.akb-section-head__title{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.08em;display:flex;align-items:center;gap:8px}
.akb-section-head__ico{width:14px;height:14px;opacity:.55}
.akb-section-head__action{font-size:11px;color:#9ca3af;background:none;border:0;cursor:pointer;padding:4px 6px;border-radius:4px}
.akb-section-head__action:hover{color:#dc2626;background:#fef2f2}
.akb-recent-remove{margin-left:auto;width:22px;height:22px;border:0;background:transparent;color:#9ca3af;cursor:pointer;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;line-height:1;padding:0}
.akb-recent-remove:hover{background:#fee2e2;color:#dc2626}
.akb-chips{display:flex;flex-wrap:wrap;gap:6px;padding:2px 20px 8px}
.akb-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#f4f5f7;color:#1f2937;border-radius:999px;text-decoration:none;font-size:13px;font-weight:500;transition:background .15s,transform .15s}
.akb-chip:hover{background:#e5e7eb;transform:translateY(-1px)}
.akb-chip__fire{color:#f59e0b;font-size:12px}
.akb-suggest-row{display:flex;align-items:center;gap:12px;padding:10px 14px;text-decoration:none;color:#111827;border-radius:8px;margin:2px 6px;transition:background .12s}
.akb-suggest-row:hover,.akibara-popup-results li.akb-active .akb-suggest-row{background:#f3f4f6}
.akb-suggest-ico{width:18px;height:18px;color:#9ca3af;flex-shrink:0}
.akb-suggest-body{flex:1;min-width:0;display:flex;align-items:baseline;gap:8px;overflow:hidden}
.akb-suggest-label{font-size:14px;font-weight:500;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.akb-suggest-label mark{background:none;color:#111;font-weight:700;padding:0}
.akb-suggest-meta{font-size:11px;color:#9ca3af;flex-shrink:0;white-space:nowrap}
.akb-suggest-arrow{color:#d1d5db;font-size:14px;flex-shrink:0;line-height:1}
.site-search-popup,.site-search-popup-wrap{display:none!important;visibility:hidden!important;pointer-events:none!important}
@media(max-width:768px){
	.akibara-popup-container{padding:36px 12px 12px}
	.akibara-search-box{border-radius:12px;padding:0 12px 0 18px}
	#akibara-popup-input{padding:16px 10px;font-size:15px}
	.search-image{width:44px;height:62px}
}
</style>
		<?php
	},
	100
);

add_action(
	'wp_footer',
	function () {
		$min  = AKB_MIN_CHARS;
		$si   = esc_url( plugins_url( 'search.php', AKIBARA_CORE_FILE ) );
		$rest = esc_url( rest_url( 'akibara/v1/search' ) );
		$ajax = esc_url( admin_url( 'admin-ajax.php' ) );
		$home = esc_url( home_url( '/' ) );
		?>
<script>
(function(){
'use strict';
const MIN=<?php echo (int) $min; ?>,SI='<?php echo $si; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $si pre-escaped con esc_url() arriba. ?>',REST='<?php echo $rest; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped esc_url(). ?>',
		SUGGEST='<?php echo esc_url( rest_url( 'akibara/v1/suggest' ) ); ?>',
		AJAX='<?php echo $ajax; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped esc_url(). ?>',HOME='<?php echo $home; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped esc_url(). ?>',
		DELAY=260,CACHE_TTL=45000,HIST='akb_history',MAX_H=5;
const SVG_HISTORY='<svg class="akb-suggest-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 8v4l3 3"/><path d="M3.05 11a9 9 0 1 1 .5 4"/><path d="M3 4v5h5"/></svg>';
const SVG_SEARCH='<svg class="akb-suggest-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';
const SVG_BOOK='<svg class="akb-suggest-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>';
const SVG_USER='<svg class="akb-suggest-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 5-6 8-6s6.5 2 8 6"/></svg>';
const SVG_FIRE='<svg class="akb-section-head__ico" viewBox="0 0 24 24" fill="currentColor"><path d="M13.5 2s1 3 1 5-1 3-1 5 2 3 2 5-1 4-1 4-2-1-2-3 1-3 1-5-2-3-2-5 1-4 1-4S13.5 2 13.5 2z"/></svg>';
const SVG_CLOCK='<svg class="akb-section-head__ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
const SVG_STAR='<svg class="akb-section-head__ico" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3 6.5 7 .9-5 4.9 1.3 7-6.3-3.4L5.7 21 7 14.3l-5-4.9 7-.9z"/></svg>';

let timer=null,ctrlP=null,ctrlS=null,cacheP={},cacheS={};

const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function hi(text,q){if(!q||q.length<MIN)return esc(text);const re=new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi');return esc(text).replace(re,'<mark>$1</mark>');}
function gH(){try{return JSON.parse(localStorage.getItem(HIST)||'[]');}catch(e){return[];}}
function sH(q){if(q.length<MIN)return;let h=gH().filter(x=>x!==q);h.unshift(q);h=h.slice(0,MAX_H);try{localStorage.setItem(HIST,JSON.stringify(h));}catch(e){}}
function rH(q){try{let h=gH().filter(x=>x!==q);localStorage.setItem(HIST,JSON.stringify(h));}catch(e){}}
function clH(){try{localStorage.removeItem(HIST);}catch(e){}}
function searchUrl(q){return HOME+'?s='+encodeURIComponent(q)+'&post_type=product';}

function sugIcon(type){return type==='serie'?SVG_BOOK:type==='autor'?SVG_USER:SVG_SEARCH;}

function renderSuggestions(list,q){
	if(!list||!list.length)return '';
	let html='<div class="akb-section"><div class="akb-section-head"><span class="akb-section-head__title">'+SVG_STAR+'<span>Sugerencias</span></span></div><ul>';
	list.forEach(s=>{
	html+='<li><a class="akb-suggest-row" href="'+esc(s.url)+'" data-label="'+esc(s.label)+'" tabindex="0">'
		+sugIcon(s.type)
		+'<div class="akb-suggest-body">'
		+'<span class="akb-suggest-label">'+hi(s.label,q)+'</span>'
		+(s.meta?'<span class="akb-suggest-meta">'+esc(s.meta)+'</span>':'')
		+'</div>'
		+'<span class="akb-suggest-arrow">→</span>'
	+'</a></li>';
	});
	return html+'</ul></div>';
}

function renderProducts(data,q){
	let html='<div class="akb-section"><div class="akb-section-head"><span class="akb-section-head__title">'+SVG_BOOK+'<span>Productos</span></span></div><ul>';
	data.forEach(i=>{
	if(i.id===-1) return;
	let meta='';
	if(i.serie) meta+='<span class="search-serie">'+esc(i.serie)+'</span>';
	if(i.editorial) meta+='<span class="search-editorial">'+esc(i.editorial)+'</span>';
	let badge='';
	if(i.stock==='outofstock') badge='<span class="search-badge search-badge--out">Agotado</span>';
	else if(i.stock==='preorder'||i.preorder) badge='<span class="search-badge search-badge--pre">Preventa</span>';
	html+='<li><a href="'+esc(i.url)+'" tabindex="0">'
		+'<div class="search-image"><img src="'+esc(i.img)+'" alt="" loading="lazy" decoding="async">'+badge+'</div>'
		+'<div class="search-content"><div class="search-title">'+hi(i.value,q)+'</div>'
		+(meta?'<div class="search-meta">'+meta+'</div>':'')
		+'<div class="search-price">'+i.price+'</div></div></a></li>';
	});
	html+='<li class="search-view-all"><a href="'+searchUrl(q)+'" tabindex="0">Ver todos los resultados para "'+esc(q)+'" →</a></li>';
	return html+'</ul></div>';
}

function renderCombined(products,suggestions,el,q){
	const hasSug=suggestions&&suggestions.length;
	const hasProd=products&&products.length&&products[0].id!==-1;
	if(!hasSug&&!hasProd){
	el.innerHTML='<div class="no-results">Sin resultados para "<strong>'+esc(q)+'</strong>"</div>';
	el.classList.add('show');return;
	}
	let html='';
	if(hasSug) html+=renderSuggestions(suggestions,q);
	if(hasProd) html+=renderProducts(products,q);
	else html+='<div class="akb-section"><div class="no-results">Sin productos para "<strong>'+esc(q)+'</strong>"</div></div>';
	el.innerHTML=html;el.classList.add('show');
}

function renderEmpty(el,rich){
	const recent=gH();
	const trending=(window.akibaraTrending||[]).slice(0,6);
	const series=(window.akibaraTopSeries||[]).slice(0,5);
	const cats=(window.akibaraCategories||[]).slice(0,6);
	if(!recent.length&&!trending.length&&!series.length){el.innerHTML='';el.classList.remove('show');return;}
	let html='';
	// Recientes
	if(recent.length){
	html+='<div class="akb-section"><div class="akb-section-head">'
		+'<span class="akb-section-head__title">'+SVG_CLOCK+'<span>Búsquedas recientes</span></span>'
		+'<button type="button" class="akb-section-head__action" data-action="clear-history">Limpiar</button>'
	+'</div><ul>';
	recent.forEach(q=>{
		html+='<li><a class="akb-suggest-row" href="'+searchUrl(q)+'" data-label="'+esc(q)+'" tabindex="0">'
		+SVG_HISTORY
		+'<div class="akb-suggest-body"><span class="akb-suggest-label">'+esc(q)+'</span></div>'
		+'<button type="button" class="akb-recent-remove" data-action="remove-history" data-q="'+esc(q)+'" aria-label="Quitar">×</button>'
		+'</a></li>';
	});
	html+='</ul></div>';
	}
	// Trending (solo en popup rich)
	if(rich&&trending.length){
	html+='<div class="akb-section"><div class="akb-section-head">'
		+'<span class="akb-section-head__title">'+SVG_FIRE+'<span>Tendencias</span></span>'
	+'</div><div class="akb-chips">';
	trending.forEach(q=>{
		html+='<a class="akb-chip" href="'+searchUrl(q)+'" data-label="'+esc(q)+'"><span class="akb-chip__fire">🔥</span>'+esc(q)+'</a>';
	});
	html+='</div></div>';
	}
	// Top series (solo en popup rich)
	if(rich&&series.length){
	html+='<div class="akb-section"><div class="akb-section-head">'
		+'<span class="akb-section-head__title">'+SVG_STAR+'<span>Series populares</span></span>'
	+'</div><ul>';
	series.forEach(s=>{
		html+='<li><a class="akb-suggest-row" href="'+esc(s.url)+'" data-label="'+esc(s.label)+'" tabindex="0">'
		+SVG_BOOK
		+'<div class="akb-suggest-body"><span class="akb-suggest-label">'+esc(s.label)+'</span>'
		+(s.meta?'<span class="akb-suggest-meta">'+esc(s.meta)+'</span>':'')+'</div>'
		+'<span class="akb-suggest-arrow">→</span>'
		+'</a></li>';
	});
	html+='</ul></div>';
	}
	// Categorías (solo en popup rich, y solo si aún no hay recientes)
	if(rich&&cats.length&&!recent.length){
	html+='<div class="akb-section"><div class="akb-section-head">'
		+'<span class="akb-section-head__title">'+SVG_BOOK+'<span>Explorar</span></span>'
	+'</div><div class="akb-chips">';
	cats.forEach(c=>{
		html+='<a class="akb-chip" href="'+esc(c.url)+'">'+esc(c.label)+'</a>';
	});
	html+='</div></div>';
	}
	el.innerHTML=html;
	el.classList.toggle('show',!!html);
}

function doFetch(url,params,signal){return fetch(url+'?'+new URLSearchParams(params),{signal}).then(r=>{if(!r.ok)throw new Error(r.status);return r.json();});}

function search(q,el,rich){
	if(ctrlP){ctrlP.abort();ctrlP=null;}
	if(ctrlS){ctrlS.abort();ctrlS=null;}
	clearTimeout(timer);
	if(q.length<MIN){renderEmpty(el,rich);return;}

	// Cache hit: render immediately
	const cachedP=cacheP[q],cachedS=cacheS[q];
	if(cachedP&&cachedS&&(Date.now()-cachedP.ts)<CACHE_TTL){
	renderCombined(cachedP.data,cachedS.data,el,q);return;
	}
	el.innerHTML='<div class="search-loading">Buscando</div>';el.classList.add('show');

	timer=setTimeout(()=>{
	const abP=new AbortController();ctrlP=abP;
	const abS=new AbortController();ctrlS=abS;

	// Productos: SI primario, REST fallback, admin-ajax último
	const fetchProducts=()=>doFetch(SI,{q},abP.signal)
		.catch(e=>{if(e.name==='AbortError')throw e;
		return doFetch(REST,{q},abP.signal);})
		.catch(e=>{if(e.name==='AbortError')throw e;
		const fd=new FormData();fd.append('action','akibara_ajax_search_products');fd.append('query',q);
		return fetch(AJAX,{method:'POST',body:fd,signal:abP.signal}).then(r=>r.json());});

	// Suggestions: tolerante a fallo (soft-fail devuelve [])
	const fetchSuggest=()=>doFetch(SUGGEST,{q},abS.signal).catch(e=>{if(e.name==='AbortError')throw e;return [];});

	Promise.all([fetchProducts(),fetchSuggest()])
		.then(([products,suggestions])=>{
		ctrlP=null;ctrlS=null;
		cacheP[q]={data:products,ts:Date.now()};
		cacheS[q]={data:suggestions||[],ts:Date.now()};
		renderCombined(products,suggestions||[],el,q);
		})
		.catch(e=>{
		if(e.name==='AbortError')return;
		el.innerHTML='<div class="search-error">Error al buscar.</div>';el.classList.add('show');
		});
	},DELAY);
}

function nav(input,el){
	input.addEventListener('keydown',e=>{
	const items=[...el.querySelectorAll('li')],act=el.querySelector('li.akb-active'),idx=act?items.indexOf(act):-1;
	if(e.key==='ArrowDown'){e.preventDefault();if(!items.length)return;items.forEach(i=>i.classList.remove('akb-active'));items[(idx+1)%items.length]?.classList.add('akb-active');}
	else if(e.key==='ArrowUp'){e.preventDefault();if(!items.length)return;items.forEach(i=>i.classList.remove('akb-active'));items[(idx-1+items.length)%items.length]?.classList.add('akb-active');}
	else if(e.key==='Tab'&&!e.shiftKey){
		// Tab: si hay sugerencia tipo query activa (o primera), autocompletar en input
		const activeLink=el.querySelector('li.akb-active a[data-label]')||el.querySelector('.akb-suggest-row[data-label]');
		if(activeLink){const lbl=activeLink.getAttribute('data-label');if(lbl&&lbl!==input.value.trim()){e.preventDefault();input.value=lbl;input.dispatchEvent(new Event('input'));}}
	}
	else if(e.key==='Enter'){e.preventDefault();
		const a=el.querySelector('li.akb-active a');
		const term=input.value.trim();
		if(a){if(term.length>=MIN)sH(term);location.href=a.href;}
		else if(term.length>=MIN){sH(term);location.href=searchUrl(term);}
	}
	else if(e.key==='Escape'){el.innerHTML='';el.classList.remove('show');}
	});
}

function wireEmptyStateActions(input,el,rich){
	el.addEventListener('click',e=>{
	const removeBtn=e.target.closest('[data-action="remove-history"]');
	if(removeBtn){e.preventDefault();e.stopPropagation();rH(removeBtn.getAttribute('data-q')||'');renderEmpty(el,rich);return;}
	const clearBtn=e.target.closest('[data-action="clear-history"]');
	if(clearBtn){e.preventDefault();clH();renderEmpty(el,rich);return;}
	});
}

document.addEventListener('DOMContentLoaded',()=>{
	// Inline (widget WooCommerce)
	const main=document.getElementById('woocommerce-product-search-field-1');
	if(main){
	if(!main.closest('.ajax-search')){const w=document.createElement('div');w.className='ajax-search';main.parentNode.insertBefore(w,main);w.appendChild(main);}
	const wrap=main.closest('.ajax-search');
	let res=wrap.querySelector('.ajax-search-result');
	if(!res){res=document.createElement('div');res.className='ajax-search-result';res.setAttribute('role','listbox');wrap.appendChild(res);}
	main.addEventListener('input',()=>search(main.value.trim(),res,false));
	main.addEventListener('focus',()=>{if(!main.value.trim())renderEmpty(res,false);});
	nav(main,res);
	wireEmptyStateActions(main,res,false);
	document.addEventListener('click',e=>{if(!e.target.closest('.ajax-search'))res.classList.remove('show');});
	}

	// Popup (fullscreen)
	if(!document.getElementById('akibara-pro-popup')){
	document.body.insertAdjacentHTML('beforeend',
		'<div id="akibara-pro-popup" class="akibara-search-popup" role="dialog" aria-modal="true" aria-label="Búsqueda">'
		+'<div class="akibara-popup-overlay"></div>'
		+'<div class="akibara-popup-container">'
		+'<button type="button" class="akibara-close-btn" aria-label="Cerrar búsqueda (Esc)" title="Cerrar (Esc)">×</button>'
		+'<div class="akibara-popup-header"><div class="akibara-search-box">'
			+'<input type="search" id="akibara-popup-input" placeholder="¿Qué estás buscando?" autocomplete="off" spellcheck="false">'
		+'</div></div>'
		+'<div class="akibara-popup-results" role="listbox"></div>'
		+'</div></div>');
	}

	const popup=document.getElementById('akibara-pro-popup'),
		pi=document.getElementById('akibara-popup-input'),
		pr=popup?.querySelector('.akibara-popup-results');

	// A11y C3: sync aria-expanded en todos los triggers .button-search-popup.
	const syncTriggers=(expanded)=>{
	document.querySelectorAll('.button-search-popup').forEach(btn=>{btn.setAttribute('aria-expanded',expanded?'true':'false');});
	};

	// Sprint 11 a11y fix #3 (audit 2026-04-26): focus trap + return focus.
	// Reusa window.akibaraCreateFocusTrap (theme main.js) para evitar duplicar
	// la utility. Si por alguna razón main.js no cargó (theme override en page
	// builder, etc.), degradamos grácilmente: solo aria-expanded sync sin trap.
	// returnFocusTo se setea dinámicamente al abrir según el trigger clickeado.
	let popupTrap=null,popupTriggerEl=null;
	if(popup&&typeof window.akibaraCreateFocusTrap==='function'){
	popupTrap=window.akibaraCreateFocusTrap(popup,{
		onEscape:()=>close(),
		initialFocus:'#akibara-popup-input'
	});
	}

	const open=(triggerEl)=>{
	document.querySelectorAll('.site-search-popup').forEach(el=>{el.style.display='none';});
	popup?.classList.add('show');document.body.style.overflow='hidden';
	syncTriggers(true);
	// Tracker del trigger clickeado para devolver foco al cerrar.
	popupTriggerEl=triggerEl||document.activeElement||null;
	if(popupTrap){
		// Trap activa setea foco al input vía initialFocus.
		popupTrap.activate();
		// Empty state render tras la animación CSS open (.25s transition).
		setTimeout(()=>{if(pr&&!pi.value.trim())renderEmpty(pr,true);},250);
	}else{
		// Fallback sin trap: comportamiento legacy (focus al input + render empty).
		setTimeout(()=>{pi?.focus();if(pr&&!pi.value.trim())renderEmpty(pr,true);},250);
	}
	};
	const close=()=>{
	popup?.classList.remove('show');document.body.style.overflow='';
	syncTriggers(false);
	if(pi)pi.value='';
	if(pr){pr.innerHTML='';pr.classList.remove('show');}
	if(ctrlP){ctrlP.abort();ctrlP=null;}if(ctrlS){ctrlS.abort();ctrlS=null;}
	if(popupTrap){
		// Deactivate retorna foco a opts.returnFocusTo (no seteamos) →
		// usa lastActiveElement (capturado al activate). Pero queremos retornar
		// específicamente al trigger clickeado, no a cualquier elemento que
		// tuviera foco. Por eso forzamos foco manual tras deactivate.
		popupTrap.deactivate();
	}
	if(popupTriggerEl&&typeof popupTriggerEl.focus==='function'){
		popupTriggerEl.focus();
	}
	popupTriggerEl=null;
	};

	pi?.addEventListener('input',()=>search(pi.value.trim(),pr,true));
	pi?.addEventListener('focus',()=>{if(!pi.value.trim())renderEmpty(pr,true);});
	if(pr){nav(pi,pr);wireEmptyStateActions(pi,pr,true);}

	document.addEventListener('click',e=>{
	const trig=e.target.closest('.button-search-popup');
	if(trig){e.preventDefault();e.stopImmediatePropagation();open(trig);}
	if(e.target.closest('.akibara-close-btn,.akibara-popup-overlay'))close();
	});
	document.addEventListener('keydown',e=>{
	// Escape: si popup tiene focus trap activo, el trap maneja Escape vía onEscape.
	// Este handler queda como fallback para el caso sin trap (degradación grácil).
	if(e.key==='Escape'&&popup?.classList.contains('show')&&!popupTrap)close();
	if(e.key==='/'&&!popup?.classList.contains('show')&&!e.target.matches('input,textarea,[contenteditable]')){e.preventDefault();open(document.activeElement);}
	});
});
})();
</script>
		<?php
	},
	999
);
