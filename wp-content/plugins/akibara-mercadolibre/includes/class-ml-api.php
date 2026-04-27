<?php
/**
 * Akibara MercadoLibre — API wrapper, encryption helpers, rate limiter.
 *
 * Migrated verbatim from akibara/modules/mercadolibre/includes/class-ml-api.php.
 * Guard updated to use AKB_ML_PLUGIN_API_LOADED (avoids collision with legacy constant
 * AKIBARA_ML_LOADED from the monolithic plugin during parallel activation).
 *
 * @package Akibara\MercadoLibre
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'AKB_ML_PLUGIN_API_LOADED' ) ) {
	return;
}
define( 'AKB_ML_PLUGIN_API_LOADED', '1.0.0' );

if ( ! function_exists( 'akb_ml_encrypt' ) ) {

// ══════════════════════════════════════════════════════════════════
// CIFRADO DE DATOS SENSIBLES
// Usa AES-256-CBC con clave derivada de AUTH_KEY + SECURE_AUTH_KEY.
// Migración transparente: si el valor no está cifrado (base64 sin IV
// de 16 bytes) se devuelve tal cual; en el próximo save queda cifrado.
// ══════════════════════════════════════════════════════════════════

function akb_ml_encrypt( string $value ): string {
	if ( $value === '' || ! function_exists( 'openssl_encrypt' ) ) {
		return $value;
	}
	$key = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );
	$iv  = random_bytes( 16 );
	$enc = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
	if ( $enc === false ) {
		return $value;
	}
	return base64_encode( $iv . $enc );
}

function akb_ml_decrypt( string $value ): string {
	if ( $value === '' || ! function_exists( 'openssl_decrypt' ) ) {
		return $value;
	}
	$raw = base64_decode( $value, true );
	// Si la decodificación falla o el largo es menor que 17 bytes (IV+1 byte),
	// el valor está en texto plano (migración) → devolver tal cual.
	if ( $raw === false || strlen( $raw ) < 17 ) {
		return $value;
	}
	$key       = substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true ), 0, 32 );
	$iv        = substr( $raw, 0, 16 );
	$encrypted = substr( $raw, 16 );
	$decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
	if ( $decrypted === false ) {
		akb_ml_log( 'api', 'Decrypt failed — possible key change or corrupted value. All ML requests will return 401 until re-saving credentials.', 'warning' );
		return $value;
	}
	return $decrypted;
}

/** Claves de opciones ML que se almacenan cifradas en wp_options. */
function akb_ml_sensitive_keys(): array {
	return array( 'access_token', 'refresh_token', 'client_secret' );
}

// ── Logging estructurado por canal ──────────────────────────────────────────
// Usa wc_get_logger() con source "akibara-ml-{channel}" → visible en
// WooCommerce → Status → Logs con filtro por canal. Fallback a error_log().
function akb_ml_log( string $channel, string $message, string $level = 'info' ): void {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log(
			in_array( $level, array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' ), true ) ? $level : 'info',
			$message,
			array( 'source' => 'akibara-ml-' . $channel )
		);
		return;
	}
	error_log( sprintf( '[Akibara ML][%s] %s', $channel, $message ) );
}

// ── Validación de ISBN-10 / ISBN-13 con check digit ────────────────────────
// MercadoLibre rechaza GTINs inválidos (o los acepta pero no los indexa
// en su catálogo). Validar el check digit evita fallos silenciosos.
function akb_ml_is_valid_isbn13( string $isbn ): bool {
	if ( ! preg_match( '/^\d{13}$/', $isbn ) ) {
		return false;
	}
	$sum = 0;
	for ( $i = 0; $i < 12; $i++ ) {
		$sum += (int) $isbn[ $i ] * ( ( $i % 2 === 0 ) ? 1 : 3 );
	}
	$check = ( 10 - ( $sum % 10 ) ) % 10;
	return $check === (int) $isbn[12];
}

function akb_ml_is_valid_isbn10( string $isbn ): bool {
	if ( ! preg_match( '/^\d{9}[\dX]$/i', $isbn ) ) {
		return false;
	}
	$sum = 0;
	for ( $i = 0; $i < 9; $i++ ) {
		$sum += (int) $isbn[ $i ] * ( 10 - $i );
	}
	$last = strtoupper( $isbn[9] );
	$sum += ( $last === 'X' ) ? 10 : (int) $last;
	return $sum % 11 === 0;
}

function akb_ml_is_valid_isbn( string $sku ): bool {
	return akb_ml_is_valid_isbn13( $sku ) || akb_ml_is_valid_isbn10( $sku );
}

// ── Traducción de errores ML → español ──────────────────────────────────────
function akb_ml_translate_error( array $data ): string {
	$causes = $data['cause'] ?? array();
	if ( empty( $causes ) ) {
		return $data['message'] ?? $data['error'] ?? 'Error desconocido';
	}

	$map = array(
		'item.category_id.invalid'              => 'Categoría inválida',
		'item.available_quantity.invalid'       => 'Cantidad disponible inválida',
		'item.attributes.missing_required'      => 'Faltan atributos requeridos',
		'item.listing_type_id.invalid'          => 'Tipo de publicación inválido',
		'item.listing_type_id.requiresPictures' => 'Se requieren imágenes para este tipo de publicación',
		'item.pictures.max'                     => 'Máximo de imágenes excedido',
		'item.description.max'                  => 'Descripción excede 50.000 caracteres',
		'item.title.not_modifiable'             => 'El título no se puede modificar (item con ventas)',
		'item.condition.not_modifiable'         => 'La condición no se puede modificar',
		'item.buying_mode.not_modifiable'       => 'El modo de compra no se puede modificar',
		'item.shipping.mode.invalid'            => 'Modo de envío inválido',
		'item.shipping.dimensions.invalid'      => 'Dimensiones inválidas (formato: LxWxH,PESO en cm y gramos)',
		'item.status.invalid'                   => 'Estado inválido para esta operación',
		'item.site_id.invalid'                  => 'Sitio ML inválido',
		'body.invalid'                          => 'Datos del item inválidos',
		'item.start_time.invalid'               => 'Fecha de inicio inválida',
		'item.attributes.invalid_length'        => 'Largo de atributo excede el máximo permitido',
		'item.shipping.mandatory_free_shipping' => 'ML agregó envío gratis obligatorio',
		'catalog_product_id.not_modificable'    => 'No se puede desvincular del catálogo ML',
		'field_not_updatable'                   => 'Campo no modificable',
	);

	$messages = array();
	foreach ( $causes as $cause ) {
		if ( ( $cause['type'] ?? '' ) !== 'error' ) {
			continue;
		}
		$code       = $cause['code'] ?? '';
		$messages[] = $map[ $code ] ?? $cause['message'] ?? $code;
	}

	return $messages ? implode( '. ', $messages ) : ( $data['message'] ?? 'Error de validación' );
}

// ══════════════════════════════════════════════════════════════════
// OPCIONES
// ══════════════════════════════════════════════════════════════════

function akb_ml_opt( string $key, $default = '' ) {
	$opts  = get_option( 'akb_ml_settings', array() );
	$value = $opts[ $key ] ?? $default;
	if ( in_array( $key, akb_ml_sensitive_keys(), true ) && is_string( $value ) && $value !== '' ) {
		return akb_ml_decrypt( $value );
	}
	return $value;
}

function akb_ml_save_opts( array $data ): void {
	$opts = get_option( 'akb_ml_settings', array() );
	foreach ( akb_ml_sensitive_keys() as $key ) {
		if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && $data[ $key ] !== '' ) {
			$data[ $key ] = akb_ml_encrypt( $data[ $key ] );
		}
	}
	update_option( 'akb_ml_settings', array_merge( $opts, $data ), false );
}

// ══════════════════════════════════════════════════════════════════
// RATE LIMITER PREVENTIVO GLOBAL
//
// ML Chile permite ~3.000 req/10 min (≈ 5 req/s).
// Con Action Scheduler corriendo hasta 5 workers en paralelo, cada uno
// haciendo 2-3 llamadas por producto, podemos llegar a ~25 req/s → 429.
//
// Estrategia: ventana deslizante de 10 segundos con máximo 40 requests
// (buffer seguro). Si se alcanza el techo, el worker duerme hasta que
// la ventana libere espacio.
//
// NOTA: los transients de WordPress no son atómicos bajo alta concurrencia.
// Este rate limiter es una "primera línea de defensa" heurística.
// El backoff exponencial en akb_ml_request() maneja los 429 reales.
// ══════════════════════════════════════════════════════════════════

/**
 * Registra una petición saliente y bloquea si se supera el límite preventivo.
 * Llamar ANTES de cada wp_remote_request hacia api.mercadolibre.com.
 */
function akb_ml_rate_limit_tick(): void {
	$key      = 'akb_ml_rltick';
	$window   = 10;    // segundos
	$max_reqs = 40;    // máximo de requests en la ventana (buffer de 5 req/s × 10s × 0.8)

	$hits = get_transient( $key );
	if ( ! is_array( $hits ) ) {
		$hits = array();
	}

	$now = microtime( true );
	// Purgar hits fuera de la ventana
	$hits = array_values( array_filter( $hits, static fn( $t ) => ( $now - $t ) < $window ) );

	if ( count( $hits ) >= $max_reqs ) {
		// Calcular cuánto esperar hasta que el hit más antiguo salga de la ventana
		$oldest  = $hits[0];
		$wait_ms = (int) ceil( ( $window - ( $now - $oldest ) ) * 1000 );
		$wait_ms = max( 100, min( $wait_ms, 5000 ) ); // entre 100ms y 5s
		akb_ml_log(
			'rate',
			sprintf(
				'Rate limit preventivo: %d req en últimos %ds → durmiendo %dms',
				count( $hits ),
				$window,
				$wait_ms
			),
			'warning'
		);
		usleep( $wait_ms * 1000 );
		// Re-purgar después del sleep
		$now  = microtime( true );
		$hits = array_values( array_filter( $hits, static fn( $t ) => ( $now - $t ) < $window ) );
	}

	$hits[] = $now;
	set_transient( $key, $hits, $window + 2 );
}

// ══════════════════════════════════════════════════════════════════
// API WRAPPER
// ══════════════════════════════════════════════════════════════════

function akb_ml_refresh_token(): bool {
	$client_id     = akb_ml_opt( 'client_id' );
	$client_secret = akb_ml_opt( 'client_secret' );
	$refresh_token = akb_ml_opt( 'refresh_token' );
	if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
		return false;
	}

	$resp = wp_remote_post(
		AKB_ML_API_URL . '/oauth/token',
		array(
			'timeout' => 20,
			'headers' => array(
				'accept'       => 'application/json',
				'content-type' => 'application/x-www-form-urlencoded',
			),
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh_token,
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		return false;
	}
	$data = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! empty( $data['access_token'] ) ) {
		akb_ml_save_opts(
			array(
				'access_token'  => $data['access_token'],
				'refresh_token' => $data['refresh_token'] ?? $refresh_token,
			)
		);
		delete_transient( 'akb_ml_seller_id' );
		return true;
	}
	return false;
}

function akb_ml_request( string $method, string $endpoint, ?array $body = null, int $attempt = 0 ): array {
	// Kill-switch de emergencia: si el admin deshabilitó el módulo, abortar sin tocar API.
	if ( akb_ml_opt( 'disabled', false ) ) {
		return array( 'error' => 'Integración ML deshabilitada desde configuración' );
	}

	$token = akb_ml_opt( 'access_token' );
	if ( empty( $token ) ) {
		return array( 'error' => 'Access token no configurado' );
	}

	/**
	 * Timeout configurable via filter. Default 20s.
	 * Uso: add_filter( 'akb_ml_request_timeout', fn( $t, $method, $endpoint ) => 45, 10, 3 );
	 */
	$timeout = (int) apply_filters( 'akb_ml_request_timeout', 20, strtoupper( $method ), $endpoint );
	$args    = array(
		'method'  => strtoupper( $method ),
		'timeout' => max( 5, min( $timeout, 120 ) ),
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		),
	);

	if ( $body !== null ) {
		$args['body'] = wp_json_encode( $body );
	}

	// Rate limiter preventivo: frena antes de enviar si se acerca al límite ML
	akb_ml_rate_limit_tick();

	$response = wp_remote_request( AKB_ML_API_URL . $endpoint, $args );

	if ( is_wp_error( $response ) ) {
		return array( 'error' => $response->get_error_message() );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true ) ?? array();

	if ( $code >= 400 ) {
		// Rate limit con exponential backoff + jitter: 1, 2, 4, 8 seg (máx 4 retries = 15s total)
		if ( $code === 429 && $attempt < 4 ) {
			$delay  = pow( 2, $attempt ); // 1, 2, 4, 8
			$jitter = mt_rand( 0, 300 ) / 1000; // 0-300ms jitter evita thundering herd
			$wait_s = $delay + $jitter;
			akb_ml_log( 'rate', sprintf( 'ML 429 en %s %s → backoff %.2fs (attempt %d/4)', $method, $endpoint, $wait_s, $attempt + 1 ), 'warning' );
			usleep( (int) ( $wait_s * 1000000 ) );
			return akb_ml_request( $method, $endpoint, $body, $attempt + 1 );
		}
		// Token expirado: refrescar y reintentar (sólo una vez)
		if ( in_array( $code, array( 401, 403 ), true ) && $attempt === 0 ) {
			if ( akb_ml_refresh_token() ) {
				return akb_ml_request( $method, $endpoint, $body, 1 );
			}
		}
		// 5xx transient errors: reintentar 1 vez con backoff pequeño
		if ( $code >= 500 && $code < 600 && $attempt === 0 ) {
			akb_ml_log( 'transient', "ML {$code} en {$method} {$endpoint}, retry en 1.5s", 'warning' );
			usleep( 1500000 );
			return akb_ml_request( $method, $endpoint, $body, 1 );
		}
		$msg = akb_ml_translate_error( $data );
		return array(
			'error' => $msg,
			'code'  => $code,
			'data'  => $data,
		);
	}

	return $data;
}

/**
 * Construye mapa remoto seller_custom_field → {ml_item_id, status, sub_status} de TODOS los items en ML.
 * Se cachea en transient por 10 minutos para evitar llamadas repetidas durante bulk publish.
 */
function akb_ml_get_remote_map( bool $force = false ): array {
	$cache_key = 'akb_ml_remote_map';
	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$seller_id = akb_ml_get_seller_id();
	if ( ! $seller_id ) {
		return array();
	}

	$map = array(); // product_id → [ 'ml_item_id' => ..., 'status' => ..., 'sub_status' => ... ]

	// ML search devuelve max 50 por página; paginar si hay más
	$offset = 0;
	$limit  = 50;
	do {
		$search = akb_ml_request( 'GET', "/users/{$seller_id}/items/search?limit={$limit}&offset={$offset}" );
		$ids    = $search['results'] ?? array();
		$total  = $search['paging']['total'] ?? count( $ids );

		// Multiget: ML acepta hasta 20 IDs por llamada
		foreach ( array_chunk( $ids, 20 ) as $chunk ) {
			$ids_str = implode( ',', $chunk );
			$items   = akb_ml_request( 'GET', "/items?ids={$ids_str}&attributes=id,seller_custom_field,status,sub_status,price,available_quantity,permalink" );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $wrapper ) {
				$item = $wrapper['body'] ?? array();
				if ( empty( $item['id'] ) ) {
					continue;
				}
				$scf = $item['seller_custom_field'] ?? '';
				if ( $scf === '' || ! is_numeric( $scf ) ) {
					continue;
				}

				$pid    = (int) $scf;
				$status = $item['status'] ?? '';

				// Si ya tenemos un activo para este product_id, no sobrescribir con un cerrado
				if ( isset( $map[ $pid ] ) && in_array( $map[ $pid ]['status'], array( 'active', 'paused' ), true ) && ! in_array( $status, array( 'active', 'paused' ), true ) ) {
					continue;
				}

				$map[ $pid ] = array(
					'ml_item_id' => $item['id'],
					'status'     => $status,
					'sub_status' => $item['sub_status'] ?? array(),
					'price'      => (int) ( $item['price'] ?? 0 ),
					'stock'      => (int) ( $item['available_quantity'] ?? 0 ),
					'permalink'  => $item['permalink'] ?? '',
				);
			}
			usleep( 100000 );
		}

		$offset += $limit;
	} while ( $offset < $total );

	set_transient( $cache_key, $map, 600 ); // 10 min cache
	return $map;
}

function akb_ml_get_seller_id(): ?int {
	$cached = get_transient( 'akb_ml_seller_id' );
	if ( $cached !== false ) {
		return (int) $cached;
	}

	$resp = akb_ml_request( 'GET', '/users/me' );
	if ( isset( $resp['id'] ) ) {
		set_transient( 'akb_ml_seller_id', $resp['id'], DAY_IN_SECONDS );
		return (int) $resp['id'];
	}
	return null;
}

} // end group wrap
