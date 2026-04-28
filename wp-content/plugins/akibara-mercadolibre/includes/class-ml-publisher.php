<?php
defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// TÍTULO ML CON FÓRMULA SEO
// ══════════════════════════════════════════════════════════════════

/**
 * Construye el título ML siguiendo la fórmula de máxima conversión para
 * manga/cómics en Chile:
 *
 *   [TIPO] [SERIE] TOMO [N] NUEVO
 *
 * Ejemplos:
 *   MANGA BERSERK TOMO 1 NUEVO
 *   MANHWA SOLO LEVELING TOMO 3 NUEVO
 *   COMIC BATMAN TOMO 1 NUEVO
 *
 * Reglas de construcción:
 *   1. Tipo: MANGA / MANHWA / COMIC según narration_type o categoría
 *   2. Serie: nombre de la serie (pa_serie) o nombre del producto limpio
 *      - Se elimina "Tomo X" del nombre si ya lo extraemos por separado
 *   3. TOMO N: si se pudo extraer número de tomo
 *   4. NUEVO: siempre al final (señal de condición = confianza en LATAM)
 *   5. Si excede 60 chars → drop NUEVO → truncar serie
 *
 * @param WC_Product $product   Producto WooCommerce.
 * @param string     $narration Tipo: 'Manga', 'Manhwa', 'Cómic', etc.
 * @param int|null   $tomo      Número de tomo extraído del nombre.
 * @param string     $serie     Nombre de la serie (vacío → usar product name).
 * @return string Título ≤ 60 chars, en mayúsculas.
 */
function akb_ml_build_title( WC_Product $product, string $narration = 'Manga', ?int $tomo = null, string $serie = '' ): string {
	// 1) Tipo
	$narr_lower = mb_strtolower( $narration );
	if ( strpos( $narr_lower, 'manhwa' ) !== false ) {
		$type = 'MANHWA';
	} elseif ( strpos( $narr_lower, 'mic' ) !== false || strpos( $narr_lower, 'ómic' ) !== false ) {
		$type = 'COMIC';
	} else {
		$type = 'MANGA';
	}

	// 2) Nombre base: usar serie si está disponible; si no, nombre del producto
	if ( $serie !== '' ) {
		$base = mb_strtoupper( trim( $serie ) );
	} else {
		$raw = wp_strip_all_tags( $product->get_name() );
		// Eliminar "Tomo N" del nombre para no duplicar (ej: "Berserk Tomo 1" → "Berserk")
		$raw  = preg_replace( '/\s+tomo\s*\d+/i', '', $raw );
		$base = mb_strtoupper( trim( $raw ) );
	}

	// 3) Intentar construir con todos los elementos (60 chars max)
	$suffix_tomo  = $tomo !== null ? ' TOMO ' . $tomo : '';
	$suffix_nuevo = ' NUEVO';

	$title = $type . ' ' . $base . $suffix_tomo . $suffix_nuevo;
	if ( mb_strlen( $title ) <= 60 ) {
		return $title;
	}

	// 4) Sin NUEVO
	$title = $type . ' ' . $base . $suffix_tomo;
	if ( mb_strlen( $title ) <= 60 ) {
		return $title;
	}

	// 5) Sin TOMO ni NUEVO — truncar base
	$max_base = 60 - mb_strlen( $type ) - 1; // -1 por el espacio
	return $type . ' ' . mb_substr( $base, 0, $max_base );
}

// ══════════════════════════════════════════════════════════════════
// DESCRIPCIÓN MANGA AUTOMÁTICA
// ══════════════════════════════════════════════════════════════════

/**
 * Genera descripción profesional para listings ML de manga/cómics.
 * Incluye sinopsis WC + ficha técnica + políticas de despacho.
 */
function akb_ml_build_description( WC_Product $product ): string {
	$pid = $product->get_id();

	// Metadatos del producto
	$editorial_terms = wp_get_post_terms( $pid, 'product_brand', array( 'fields' => 'names' ) );
	$editorial       = ( ! is_wp_error( $editorial_terms ) && ! empty( $editorial_terms ) ) ? (string) $editorial_terms[0] : '';
	$tomo            = function_exists( 'akb_extract_tomo' ) ? akb_extract_tomo( $product->get_name() ) : '';
	$serie_terms     = get_the_terms( $pid, 'pa_serie' );
	$serie_name      = ( ! is_wp_error( $serie_terms ) && ! empty( $serie_terms ) )
		? $serie_terms[0]->name
		: '';
	$author_terms    = get_the_terms( $pid, 'pa_autor' );
	$author          = ( ! is_wp_error( $author_terms ) && ! empty( $author_terms ) )
		? $author_terms[0]->name
		: get_post_meta( $pid, '_akb_author', true );
	$sku             = $product->get_sku();

	// Sinopsis desde descripción WC
	$raw_synopsis = $product->get_description() ?: $product->get_short_description();
	$synopsis     = trim( wp_strip_all_tags( $raw_synopsis ) );

	$lines = array();

	// ── Bloque 1: Ficha técnica ──────────────────────────────────
	$lines[] = '📚 INFORMACIÓN DEL PRODUCTO';
	$lines[] = str_repeat( '─', 40 );
	if ( $serie_name ) {
		$lines[] = 'Serie:      ' . $serie_name;
	}
	if ( $tomo !== null ) {
		$lines[] = 'Tomo:       ' . $tomo;
	}
	if ( $author ) {
		$lines[] = 'Autor:      ' . $author;
	}
	if ( $editorial ) {
		$lines[] = 'Editorial:  ' . $editorial;
	}
	$lines[] = 'Idioma:     Español';
	$lines[] = 'Formato:    Manga (tankōbon), tapa blanda';
	$lines[] = 'Condición:  Nuevo, sin leer';
	if ( $sku && akb_ml_is_valid_isbn( $sku ) ) {
		$lines[] = 'ISBN:       ' . $sku;
	}
	$lines[] = '';

	// ── Bloque 2: Sinopsis ───────────────────────────────────────
	if ( $synopsis ) {
		$lines[] = '📖 SINOPSIS';
		$lines[] = str_repeat( '─', 40 );
		// Limitar a 1.500 caracteres para no saturar la descripción
		$lines[] = mb_substr( $synopsis, 0, 1500 ) . ( mb_strlen( $synopsis ) > 1500 ? '…' : '' );
		$lines[] = '';
	}

	// ── Bloque 3: Despacho ───────────────────────────────────────
	$lines[] = '🚀 DESPACHO Y ENVÍO';
	$lines[] = str_repeat( '─', 40 );
	$lines[] = '• Despachamos en 1-2 días hábiles por Blue Express';
	$lines[] = '• Envío a todo Chile (aplica envío gratis según monto)';
	$lines[] = '• Empaque reforzado para proteger el manga';
	$lines[] = '• Seguimiento de envío incluido';
	$lines[] = '';

	// ── Bloque 4: Garantía / Confianza ───────────────────────────
	$lines[] = '✅ GARANTÍA Y CONFIANZA';
	$lines[] = str_repeat( '─', 40 );
	$lines[] = '• Producto 100% original, nuevo y en perfecto estado';
	$lines[] = '• Si recibes un producto dañado o incorrecto, te solucionamos en 24h';
	$lines[] = '• Más de 1.300 títulos disponibles en nuestra tienda';
	$lines[] = '';

	// ── Bloque 5: Branding ───────────────────────────────────────
	$lines[] = '🏪 AKIBARA — Tu Distrito del Manga y Cómics';
	$lines[] = 'akibara.cl | Manga · Cómics · Preventas';

	return mb_substr( implode( "\n", $lines ), 0, 4000 );
}

// ══════════════════════════════════════════════════════════════════
// MAPEO WC PRODUCT → ML ITEM
// ══════════════════════════════════════════════════════════════════

/**
 * Wrapper cacheado del mapeo producto → item ML (DT-09).
 *
 * La construcción del payload hace ~10 queries (meta, terms, imágenes, descripción 4KB).
 * El health cron solía llamar esto 50-100 veces por run × 2 runs/día sin cache.
 *
 * Cache key: transient `akb_ml_item_{pid}` con `_ver` md5 que incluye:
 *   - post_modified_time (auto-invalida al editar producto)
 *   - price, stock (invalida al cambiar)
 *   - opts de precio global (commission, extra, shipping, rounding)
 *
 * TTL: 24h. Si el producto no cambia, cero queries hasta el expiry.
 */
function akb_ml_product_to_item( WC_Product $product ): array {
	$pid       = $product->get_id();
	$cache_key = 'akb_ml_item_' . $pid;

	// Incluir terms de taxonomía en el hash — cambiar serie/autor sin tocar
	// post_modified invalidaba mal el cache y enviaba attributes obsoletos a ML.
	// 1 query batch en vez de 4 (regresión de perf cuando se añadió esto).
	$tax_sig  = '';
	$all_tids = wp_get_object_terms(
		$pid,
		array( 'pa_serie', 'pa_autor', 'pa_editorial', 'product_cat' ),
		array( 'fields' => 'ids' )
	);
	if ( ! is_wp_error( $all_tids ) && ! empty( $all_tids ) ) {
		sort( $all_tids );
		$tax_sig = implode( ',', $all_tids );
	}
	$version = md5(
		implode(
			'|',
			array(
				(string) get_post_modified_time( 'U', true, $pid ),
				(string) $product->get_price(),
				(string) $product->get_stock_quantity(),
				(string) akb_ml_opt( 'price_rounding', 'none' ),
				(string) akb_ml_opt( 'commission_pct', 13.0 ),
				(string) akb_ml_opt( 'extra_margin_pct', 3.0 ),
				(string) akb_ml_opt( 'shipping_cost_estimate', 0 ),
				$tax_sig,
			)
		)
	);

	$cached = get_transient( $cache_key );
	if ( is_array( $cached ) && ( $cached['_ver'] ?? '' ) === $version ) {
		unset( $cached['_ver'] );
		return $cached;
	}

	$item         = _akb_ml_product_to_item_build( $product );
	$item['_ver'] = $version;
	set_transient( $cache_key, $item, DAY_IN_SECONDS );
	unset( $item['_ver'] );
	return $item;
}

/**
 * Construcción real del payload ML — NO usar directamente, usa akb_ml_product_to_item().
 * Factored en función separada para poder testear y cachear de forma transparente.
 */
function _akb_ml_product_to_item_build( WC_Product $product ): array {
	$pid          = $product->get_id();
	$override     = (int) akb_ml_db_get_override( $pid );
	$price        = akb_ml_calculate_price( (float) $product->get_price(), $override );
	$stock        = max( 1, (int) $product->get_stock_quantity() );
	$listing_type = akb_ml_opt( 'listing_type', 'gold_special' );
	$category_id  = akb_ml_opt( 'default_category', 'MLC1196' );

	// ── Metadata extraída primero (usada en título, descripción y atributos) ──
	$editorial_terms = wp_get_post_terms( $pid, 'product_brand', array( 'fields' => 'names' ) );
	$editorial       = ( ! is_wp_error( $editorial_terms ) && ! empty( $editorial_terms ) ) ? (string) $editorial_terms[0] : '';
	$tomo            = function_exists( 'akb_extract_tomo' )
		? akb_extract_tomo( $product->get_name() )
		: null;
	$serie_terms     = get_the_terms( $pid, 'pa_serie' );
	$serie_name      = ( ! is_wp_error( $serie_terms ) && ! empty( $serie_terms ) )
		? $serie_terms[0]->name
		: '';
	$author          = get_post_meta( $pid, '_akb_author', true );
	if ( empty( $author ) ) {
		$author_terms = get_the_terms( $pid, 'pa_autor' );
		if ( ! is_wp_error( $author_terms ) && ! empty( $author_terms ) ) {
			$author = $author_terms[0]->name;
		}
	}
	$cat_names = array_map( 'strtolower', wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) ) ?: array() );

	// NARRATION_TYPE — inferir de categoría; override con meta _akb_narration_type
	$narration = get_post_meta( $pid, '_akb_narration_type', true );
	if ( empty( $narration ) ) {
		if ( in_array( 'manhwa', $cat_names, true ) ) {
			$narration = 'Manhwa';
		} elseif ( in_array( 'comics', $cat_names, true ) || in_array( 'vertigo', $cat_names, true ) || in_array( 'independiente', $cat_names, true ) ) {
			$narration = 'Cómic';
		} else {
			$narration = 'Manga';
		}
	}

	// ── Título con fórmula ML SEO: [TIPO] [SERIE] TOMO [N] NUEVO ──
	$title = akb_ml_build_title( $product, $narration, $tomo, $serie_name );

	// Imágenes (máx 6)
	$pictures = array();
	$img_ids  = array_filter(
		array_merge(
			array( $product->get_image_id() ),
			$product->get_gallery_image_ids()
		)
	);
	foreach ( array_slice( $img_ids, 0, 6 ) as $img_id ) {
		$url = wp_get_attachment_url( $img_id );
		if ( $url ) {
			$pictures[] = array( 'source' => $url );
		}
	}

	// Descripción manga enriquecida
	$desc = akb_ml_build_description( $product );

	// Atributos ML para categoría Libros Físicos (MLC1196)
	$attributes = array(
		array(
			'id'         => 'BOOK_TITLE',
			'value_name' => $product->get_name(),
		),
		array(
			'id'         => 'BOOK_GENRE',
			'value_name' => 'Cómic, manga y novela gráfica',
		),
		array(
			'id'         => 'LANGUAGE',
			'value_name' => 'Español',
		),
		array(
			'id'         => 'ITEM_CONDITION',
			'value_name' => 'Nuevo',
		),
	);

	if ( ! empty( $author ) ) {
		$attributes[] = array(
			'id'         => 'AUTHOR',
			'value_name' => $author,
		);
	}
	if ( ! empty( $editorial ) ) {
		$attributes[] = array(
			'id'         => 'BOOK_PUBLISHER',
			'value_name' => $editorial,
		);
	}
	if ( ! empty( $serie_name ) ) {
		$attributes[] = array(
			'id'         => 'BOOK_SERIE',
			'value_name' => $serie_name,
		);
	}
	if ( $tomo !== null ) {
		$attributes[] = array(
			'id'         => 'BOOK_VOLUME',
			'value_name' => (string) $tomo,
		);
	}

	// BOOK_COVER — preferir meta explícita; regex solo con marcadores unívocos.
	// "deluxe" se excluye del regex porque en manga a menudo indica "edición mejorada
	// con better paper" pero SIN ser tapa dura (p. ej. Attack on Titan Deluxe).
	// Marcadores conservadores que SIEMPRE implican hardcover:
	// hardcover, tapa dura, integral, omnibus, absolute, compendium
	$cover = get_post_meta( $pid, '_akb_book_cover', true );
	if ( empty( $cover ) ) {
		$is_hardcover = preg_match(
			'/\b(hardcover|tapa dura|integral|omnibus|absolute|compendium)\b/i',
			$product->get_name()
		);
		$cover        = $is_hardcover ? 'Dura' : 'Blanda';
	}
	$attributes[] = array(
		'id'         => 'BOOK_COVER',
		'value_name' => $cover,
	);
	$attributes[] = array(
		'id'         => 'NARRATION_TYPE',
		'value_name' => $narration,
	);

	// BOOK_SUBGENRES — subgénero por taxonomía pa_genero
	$genero_terms = get_the_terms( $pid, 'pa_genero' );
	$subgenero    = ( ! is_wp_error( $genero_terms ) && ! empty( $genero_terms ) )
		? $genero_terms[0]->name
		: '';
	if ( ! empty( $subgenero ) ) {
		$attributes[] = array(
			'id'         => 'BOOK_SUBGENRES',
			'value_name' => $subgenero,
		);
	}

	// PAGES_NUMBER — cantidad de páginas desde meta
	$pages = get_post_meta( $pid, '_akb_pages', true );
	if ( ! empty( $pages ) ) {
		$attributes[] = array(
			'id'         => 'PAGES_NUMBER',
			'value_name' => (string) $pages,
		);
	}

	// MIN_RECOMMENDED_AGE — inferir de categoría demográfica; override con meta _akb_min_age
	$min_age = get_post_meta( $pid, '_akb_min_age', true );
	if ( empty( $min_age ) ) {
		if ( in_array( 'seinen', $cat_names, true ) ) {
			$min_age = '16 años';
		} elseif ( in_array( 'shojo', $cat_names, true ) || in_array( 'shonen', $cat_names, true ) ) {
			$min_age = '13 años';
		} else {
			$min_age = '13 años';
		}
	}
	$attributes[] = array(
		'id'         => 'MIN_RECOMMENDED_AGE',
		'value_name' => $min_age,
	);

	// GTIN / ISBN — solo enviar si tiene check digit válido (ISBN-10 o ISBN-13)
	$sku = $product->get_sku();
	if ( ! empty( $sku ) && akb_ml_is_valid_isbn( $sku ) ) {
		$attributes[] = array(
			'id'         => 'GTIN',
			'value_name' => $sku,
		);
	}

	// MPN — SKU como referencia interna
	if ( ! empty( $sku ) ) {
		$attributes[] = array(
			'id'         => 'MPN',
			'value_name' => $sku,
		);
	}

	// SELLER_SKU — referencia interna del vendedor
	if ( ! empty( $sku ) ) {
		$attributes[] = array(
			'id'         => 'SELLER_SKU',
			'value_name' => $sku,
		);
	}

	// Sale terms: garantía del vendedor (requerido por ML)
	$sale_terms = array(
		array(
			'id'         => 'WARRANTY_TYPE',
			'value_name' => 'Garantía del vendedor',
		),
		array(
			'id'         => 'WARRANTY_TIME',
			'value_name' => '30 días',
		),
	);

	$item = array(
		'title'               => $title,
		'price'               => $price,
		'currency_id'         => AKB_ML_CURRENCY,
		'available_quantity'  => $stock,
		'listing_type_id'     => $listing_type,
		'category_id'         => $category_id,
		'condition'           => 'new',
		'buying_mode'         => 'buy_it_now',
		'catalog_listing'     => false,
		'attributes'          => $attributes,
		'sale_terms'          => $sale_terms,
		'seller_custom_field' => (string) $product->get_id(),
	);

	// Shipping: Mercado Envíos ME2 (xd_drop_off)
	// Envío gratis para items >= $19.990 CLP (obligatorio para competir en ML Chile)
	$free_shipping    = ( $price >= 19990 );
	$item['shipping'] = array(
		'mode'          => 'me2',
		'local_pick_up' => false,
		'free_shipping' => $free_shipping,
	);

	// Dimensiones: ML requiere enteros en formato "LxWxH,PESO_GRAMOS" (ej: 18x12x2,250)
	$weight = (float) $product->get_weight(); // kg en WC
	if ( $weight > 0 ) {
		$length                         = (int) ceil( max( 1, (float) $product->get_length() ?: 18 ) );
		$width                          = (int) ceil( max( 1, (float) $product->get_width() ?: 12 ) );
		$height                         = (int) ceil( max( 1, (float) $product->get_height() ?: 2 ) );
		$weight_g                       = (int) ceil( $weight * 1000 ); // kg → gramos
		$item['shipping']['dimensions'] = "{$length}x{$width}x{$height},{$weight_g}";
	}

	if ( ! empty( $pictures ) ) {
		$item['pictures'] = $pictures;
	}

	// Descripción se almacena para POST separado (ML requiere endpoint /items/{id}/description)
	$item['_akb_description'] = array( 'plain_text' => $desc );

	return $item;
}

// ══════════════════════════════════════════════════════════════════
// PUBLICAR / PAUSAR / REACTIVAR
// ══════════════════════════════════════════════════════════════════

/**
 * Publica o actualiza un producto en ML.
 * Retorna ['success' => true, 'item_id' => 'MLCxxx', 'action' => 'created|updated']
 * o ['error' => '...']
 */
function akb_ml_publish( int $product_id ): array {
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return array( 'error' => 'Producto no encontrado' );
	}
	if ( ! $product->is_in_stock() ) {
		return array( 'error' => 'Producto sin stock' );
	}
	if ( (float) $product->get_price() <= 0 ) {
		return array( 'error' => 'Producto sin precio' );
	}

	// Precio mínimo ML Chile: $1.100 CLP
	$override = (int) akb_ml_db_get_override( $product_id );
	$ml_price = akb_ml_calculate_price( (float) $product->get_price(), $override );
	if ( $ml_price < 1100 ) {
		return array( 'error' => "Precio ML ($ml_price CLP) menor al mínimo de $1.100 CLP" );
	}

	// Advertencia de imágenes insuficientes (no bloquea; ML convierte mejor con ≥ 3 fotos)
	$img_count = count(
		array_filter(
			array_merge(
				array( $product->get_image_id() ),
				$product->get_gallery_image_ids()
			)
		)
	);
	if ( $img_count === 0 ) {
		akb_ml_log( 'publish', "Producto #{$product_id} publicado sin imágenes. ML puede rechazarlo o bajar su ranking.", 'warning' );
	} elseif ( $img_count < 3 ) {
		akb_ml_log( 'publish', "Producto #{$product_id} con solo {$img_count} imagen(es). Recomendado ≥3 para mejor conversión en ML.", 'notice' );
	}

	$item_data = akb_ml_product_to_item( $product );
	$existing  = akb_ml_db_row( $product_id );

	if ( $existing && ! empty( $existing['ml_item_id'] ) && $existing['ml_status'] !== 'error' ) {
		// Actualizar item existente — enviar todos los campos actualizables
		$ml_id   = $existing['ml_item_id'];
		$payload = array(
			'title'              => $item_data['title'],
			'price'              => $item_data['price'],
			'available_quantity' => $item_data['available_quantity'],
			'attributes'         => $item_data['attributes'] ?? array(),
			'shipping'           => $item_data['shipping'] ?? array(),
		);
		if ( ! empty( $item_data['pictures'] ) ) {
			$payload['pictures'] = $item_data['pictures'];
		}
		if ( $existing['ml_status'] === 'paused' ) {
			$payload['status'] = 'active';
		}
		$resp = akb_ml_request( 'PUT', "/items/{$ml_id}", $payload );
		if ( isset( $resp['error'] ) ) {
			akb_ml_db_upsert(
				$product_id,
				array(
					'ml_item_id' => $ml_id,
					'ml_status'  => 'error',
					'error_msg'  => $resp['error'],
				)
			);
			return array( 'error' => $resp['error'] );
		}
		akb_ml_db_upsert(
			$product_id,
			array(
				'ml_item_id'   => $ml_id,
				'ml_status'    => $resp['status'] ?? 'active',
				'ml_price'     => $item_data['price'],
				'ml_stock'     => $item_data['available_quantity'],
				'ml_permalink' => $resp['permalink'] ?? '',
				'error_msg'    => null,
			)
		);
		// Actualizar descripción en ML (requiere endpoint separado)
		if ( ! empty( $item_data['_akb_description'] ) ) {
			akb_ml_request( 'PUT', "/items/{$ml_id}/description", $item_data['_akb_description'] );
		}
		return array(
			'success' => true,
			'item_id' => $ml_id,
			'action'  => 'updated',
		);

	} else {
		// ── Anti-duplicado: verificar en ML via mapa cacheado (multiget eficiente) ──
		$remote_map = akb_ml_get_remote_map();
		$remote     = $remote_map[ $product_id ] ?? null;

		if ( $remote ) {
			$check_ml_id  = $remote['ml_item_id'];
			$check_status = $remote['status'];

			// Item activo o pausado encontrado → registrar localmente y actualizar
			if ( in_array( $check_status, array( 'active', 'paused' ), true ) ) {
				akb_ml_db_upsert(
					$product_id,
					array(
						'ml_item_id'   => $check_ml_id,
						'ml_status'    => $check_status,
						'ml_price'     => $remote['price'],
						'ml_stock'     => $remote['stock'],
						'ml_permalink' => $remote['permalink'],
						'error_msg'    => null,
					)
				);
				akb_ml_log( 'publish', "Anti-duplicado: encontrado {$check_ml_id} ({$check_status}) para producto #{$product_id} → recuperado" );

				$upd_payload = array(
					'title'              => $item_data['title'],
					'price'              => $item_data['price'],
					'available_quantity' => $item_data['available_quantity'],
					'attributes'         => $item_data['attributes'] ?? array(),
					'shipping'           => $item_data['shipping'] ?? array(),
				);
				if ( ! empty( $item_data['pictures'] ) ) {
					$upd_payload['pictures'] = $item_data['pictures'];
				}
				if ( $check_status === 'paused' ) {
					$upd_payload['status'] = 'active';
				}
				$upd_resp = akb_ml_request( 'PUT', "/items/{$check_ml_id}", $upd_payload );
				if ( ! isset( $upd_resp['error'] ) ) {
					akb_ml_db_upsert(
						$product_id,
						array(
							'ml_item_id'   => $check_ml_id,
							'ml_status'    => $upd_resp['status'] ?? 'active',
							'ml_price'     => $item_data['price'],
							'ml_stock'     => $item_data['available_quantity'],
							'ml_permalink' => $upd_resp['permalink'] ?? '',
							'error_msg'    => null,
						)
					);
				}
				if ( ! empty( $item_data['_akb_description'] ) ) {
					akb_ml_request( 'PUT', "/items/{$check_ml_id}/description", $item_data['_akb_description'] );
				}
				delete_transient( 'akb_ml_remote_map' );
				return array(
					'success' => true,
					'item_id' => $check_ml_id,
					'action'  => 'recovered',
				);
			}

			// Item cerrado → candidato para relist
			if ( $check_status === 'closed' ) {
				$sub = $remote['sub_status'] ?? array();
				if ( ! in_array( 'deleted', $sub, true ) ) {
					update_post_meta( $product_id, '_akb_ml_closed_id', $check_ml_id );
				}
			}
		}

		// Intentar relisting si hay un item cerrado previo para este producto
		$closed_item_id = get_post_meta( $product_id, '_akb_ml_closed_id', true );

		if ( $closed_item_id ) {
			// Relist: republicar item cerrado (mantiene historial de ventas y reputación)
			$relist_data = array(
				'listing_type_id' => $item_data['listing_type_id'],
				'price'           => $item_data['price'],
				'quantity'        => $item_data['available_quantity'],
				'catalog_listing' => false,
			);
			$relist_resp = akb_ml_request( 'POST', "/items/{$closed_item_id}/relist", $relist_data );
			if ( ! isset( $relist_resp['error'] ) ) {
				$new_ml_id = $relist_resp['id'] ?? $closed_item_id;
				akb_ml_db_upsert(
					$product_id,
					array(
						'ml_item_id'   => $new_ml_id,
						'ml_status'    => $relist_resp['status'] ?? 'active',
						'ml_price'     => $item_data['price'],
						'ml_stock'     => $item_data['available_quantity'],
						'ml_permalink' => $relist_resp['permalink'] ?? '',
						'error_msg'    => null,
					)
				);
				delete_post_meta( $product_id, '_akb_ml_closed_id' );
				if ( ! empty( $item_data['_akb_description'] ) ) {
					akb_ml_request( 'PUT', "/items/{$new_ml_id}/description", $item_data['_akb_description'] );
				}
				akb_ml_log( 'publish', "Relist exitoso: {$closed_item_id} → {$new_ml_id} (producto #{$product_id})" );
				return array(
					'success' => true,
					'item_id' => $new_ml_id,
					'action'  => 'relisted',
				);
			}
			// Si falla el relist (ej: item no elegible), continuar con creación nueva
			akb_ml_log( 'publish', "Relist falló para {$closed_item_id}: {$relist_resp['error']} — creando nuevo" );
		}

		// Crear nuevo item (sin description, va en endpoint separado)
		$post_data = $item_data;
		unset( $post_data['_akb_description'] );
		$resp = akb_ml_request( 'POST', '/items', $post_data );
		if ( isset( $resp['error'] ) ) {
			akb_ml_db_upsert(
				$product_id,
				array(
					'ml_status' => 'error',
					'error_msg' => $resp['error'],
				)
			);
			return array( 'error' => $resp['error'] );
		}

		$ml_id = $resp['id'] ?? '';
		// Si había un closed_id de un intento de relist fallido, limpiar — ya no aplica
		delete_post_meta( $product_id, '_akb_ml_closed_id' );
		akb_ml_db_upsert(
			$product_id,
			array(
				'ml_item_id'   => $ml_id,
				'ml_status'    => $resp['status'] ?? 'active',
				'ml_price'     => $item_data['price'],
				'ml_stock'     => $item_data['available_quantity'],
				'ml_permalink' => $resp['permalink'] ?? '',
				'error_msg'    => null,
			)
		);

		// Publicar descripción separadamente (API ML requiere endpoint aparte)
		if ( ! empty( $item_data['_akb_description'] ) && $ml_id ) {
			akb_ml_request( 'POST', "/items/{$ml_id}/description", $item_data['_akb_description'] );
		}

		return array(
			'success' => true,
			'item_id' => $ml_id,
			'action'  => 'created',
		);
	}
}

/** Pausa un item activo en ML */
function akb_ml_pause( int $product_id ): array {
	$row = akb_ml_db_row( $product_id );
	if ( ! $row || empty( $row['ml_item_id'] ) ) {
		return array( 'error' => 'Sin item ML asociado' );
	}
	$resp = akb_ml_request( 'PUT', "/items/{$row['ml_item_id']}", array( 'status' => 'paused' ) );
	if ( isset( $resp['error'] ) ) {
		return array( 'error' => $resp['error'] );
	}

	akb_ml_db_upsert(
		$product_id,
		array(
			'ml_item_id' => $row['ml_item_id'],
			'ml_status'  => 'paused',
			'error_msg'  => null,
		)
	);
	return array( 'success' => true );
}

/** Reactiva un item pausado en ML */
function akb_ml_reactivate( int $product_id ): array {
	$row = akb_ml_db_row( $product_id );
	if ( ! $row || empty( $row['ml_item_id'] ) ) {
		return array( 'error' => 'Sin item ML asociado' );
	}
	$resp = akb_ml_request( 'PUT', "/items/{$row['ml_item_id']}", array( 'status' => 'active' ) );
	if ( isset( $resp['error'] ) ) {
		return array( 'error' => $resp['error'] );
	}

	akb_ml_db_upsert(
		$product_id,
		array(
			'ml_item_id' => $row['ml_item_id'],
			'ml_status'  => 'active',
			'error_msg'  => null,
		)
	);
	return array( 'success' => true );
}
