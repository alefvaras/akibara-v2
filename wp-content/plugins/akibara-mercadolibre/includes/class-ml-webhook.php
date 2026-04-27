<?php
defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// REST API — Webhook ML notifications
// ══════════════════════════════════════════════════════════════════

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'akibara/v1',
			'/ml/notify',
			array(
				'methods'             => 'POST',
				'callback'            => 'akb_ml_handle_notification',
				'permission_callback' => 'akb_ml_webhook_permission',
			)
		);
	}
);

/**
 * Valida la firma x-signature que MercadoLibre adjunta a cada notificación.
 *
 * Formato del header: `ts=TIMESTAMP,v1=HMAC_SHA256_HEX`
 * Mensaje firmado:    `ts:{ts};url:{data.resource}`
 * Clave:              client_secret de la app ML
 *
 * Protege contra:
 *   - Suplantación (firma inválida → rechazado)
 *   - Replay attacks (timestamp > 5 min → rechazado)
 *
 * Si el header no viene (ML no siempre lo envía en entornos de prueba)
 * o client_secret no está configurado, se omite la validación.
 *
 * @return true si válida o si no se puede verificar, false si inválida.
 */
function akb_ml_validate_webhook_signature( string $header, array $data, string $secret ): bool {
	if ( ! preg_match( '/ts=(\d+)/', $header, $ts_m ) ) {
		return false;
	}
	if ( ! preg_match( '/v1=([a-f0-9]+)/i', $header, $v1_m ) ) {
		return false;
	}

	$ts = (int) $ts_m[1];

	// Replay protection: rechazar si el timestamp tiene más de 5 minutos
	if ( abs( time() - $ts ) > 300 ) {
		akb_ml_log( 'webhook', "Firma rechazada: timestamp {$ts} desfasado " . abs( time() - $ts ) . 's', 'warning' );
		return false;
	}

	$resource = $data['resource'] ?? '';
	$message  = "ts:{$ts};url:{$resource}";
	$expected = hash_hmac( 'sha256', $message, $secret );

	return hash_equals( $expected, strtolower( $v1_m[1] ) );
}

/**
 * Permission callback para el webhook ML.
 *
 * Aplica 4 capas de protección:
 *   1. Rate-limit por IP (máx 60 POSTs/min)
 *   2. Validación estructural del body (topic, resource, user_id)
 *   3. Match de user_id contra nuestro seller_id (si está configurado)
 *   4. Validación HMAC x-signature (si ML la envía y client_secret está configurado)
 *
 * Retorna true si pasa todas las validaciones, false si no.
 */
function akb_ml_webhook_permission( WP_REST_Request $request ): bool {
	// 1) Rate-limit por IP
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
	$rl_key = 'akb_ml_wh_rl_' . md5( $ip );
	$count  = (int) get_transient( $rl_key );
	if ( $count >= 60 ) {
		akb_ml_log( 'webhook', "Rechazado por rate-limit: IP={$ip} count={$count}", 'warning' );
		return false;
	}
	set_transient( $rl_key, $count + 1, 60 );

	// 2) Validar estructura mínima del body
	$data = $request->get_json_params();
	if ( ! is_array( $data ) || empty( $data['topic'] ) || empty( $data['resource'] ) || empty( $data['user_id'] ) ) {
		akb_ml_log( 'webhook', "Rechazado: body incompleto desde IP={$ip}", 'warning' );
		return false;
	}

	// 3) Validar user_id contra nuestro seller_id (si ya está configurado)
	$seller_id = akb_ml_get_seller_id();
	if ( $seller_id > 0 && (int) $data['user_id'] !== $seller_id ) {
		akb_ml_log(
			'webhook',
			sprintf(
				'Rechazado: user_id no coincide (received=%d expected=%d) IP=%s',
				(int) $data['user_id'],
				$seller_id,
				$ip
			),
			'warning'
		);
		return false;
	}

	// 4) Validar firma HMAC x-signature (anti-spoofing + replay protection)
	$sig_header = $request->get_header( 'x_signature' ); // WP normaliza a snake_case
	$secret     = akb_ml_opt( 'client_secret' );
	if ( $sig_header && $secret ) {
		if ( ! akb_ml_validate_webhook_signature( $sig_header, $data, $secret ) ) {
			akb_ml_log( 'webhook', "Rechazado: x-signature inválida. IP={$ip}", 'warning' );
			return false;
		}
	}

	return true;
}

function akb_ml_handle_notification( WP_REST_Request $request ): WP_REST_Response {
	$data = $request->get_json_params();

	if ( empty( $data['topic'] ) || empty( $data['resource'] ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 400 );
	}

	$topic    = sanitize_text_field( $data['topic'] );
	$resource = sanitize_text_field( $data['resource'] );

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		akb_ml_log( 'webhook', 'Webhook descartado: Action Scheduler no disponible. Topic: ' . $topic );
		return new WP_REST_Response(
			array(
				'ok'     => false,
				'reason' => 'scheduler_unavailable',
			),
			500
		);
	}

	if ( in_array( $topic, array( 'orders', 'orders_v2' ), true ) ) {
		as_enqueue_async_action( 'akb_ml_process_order_async', array( 'resource' => $resource ), 'akibara-ml' );
	}

	// Items: detectar moderaciones y cambios de estado
	if ( $topic === 'items' ) {
		as_enqueue_async_action( 'akb_ml_process_item_update', array( 'resource' => $resource ), 'akibara-ml' );
	}

	// Questions: loguear para futuro panel de preguntas
	if ( $topic === 'questions' ) {
		as_enqueue_async_action( 'akb_ml_process_question', array( 'resource' => $resource ), 'akibara-ml' );
	}

	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

// ══════════════════════════════════════════════════════════════════
// ITEM MODERATION — detectar cambios de estado silenciosos
// ══════════════════════════════════════════════════════════════════

add_action(
	'akb_ml_process_item_update',
	static function ( string $resource ): void {
		$resp = akb_ml_request( 'GET', $resource );
		if ( isset( $resp['error'] ) || empty( $resp['id'] ) ) {
			return;
		}

		$ml_item_id = $resp['id'];
		$new_status = $resp['status'] ?? '';
		$sub_status = $resp['sub_status'] ?? array();

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT product_id, ml_status FROM {$wpdb->prefix}akb_ml_items WHERE ml_item_id = %s",
				$ml_item_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return;
		}

		// Detectar eliminación → limpiar registro para permitir republicar
		if ( in_array( 'deleted', $sub_status, true ) || $new_status === 'closed' ) {
			// Guardar ID cerrado para posible relist futuro
			if ( ! in_array( 'deleted', $sub_status, true ) ) {
				update_post_meta( (int) $row['product_id'], '_akb_ml_closed_id', $ml_item_id );
			}
			akb_ml_db_upsert(
				(int) $row['product_id'],
				array(
					'ml_item_id'   => '',
					'ml_status'    => '',
					'ml_price'     => 0,
					'ml_stock'     => 0,
					'ml_permalink' => '',
					'error_msg'    => null,
				)
			);
			akb_ml_log(
				'item',
				sprintf(
					'Item %s eliminado/cerrado en ML → registro limpiado, producto #%d disponible para republicar',
					$ml_item_id,
					$row['product_id']
				)
			);
			return;
		}

		// Detectar moderación
		$error_msg = null;
		if ( in_array( 'mercadolibre', $sub_status, true ) ) {
			$error_msg = 'Moderado por MercadoLibre — revisar en panel ML';
		}

		// Solo actualizar si cambió algo
		if ( $new_status !== $row['ml_status'] || $error_msg ) {
			akb_ml_db_upsert(
				(int) $row['product_id'],
				array(
					'ml_item_id' => $ml_item_id,
					'ml_status'  => $new_status ?: $row['ml_status'],
					'error_msg'  => $error_msg,
				)
			);
			akb_ml_log(
				'item',
				sprintf(
					'Item %s cambió: %s → %s%s',
					$ml_item_id,
					$row['ml_status'],
					$new_status,
					$error_msg ? " ({$error_msg})" : ''
				)
			);
		}
	}
);

// ══════════════════════════════════════════════════════════════════
// AUTO-RESPONDER PREGUNTAS — responde automáticamente con info del producto
// ══════════════════════════════════════════════════════════════════

/**
 * Detecta la intención principal de una pregunta ML a partir de keywords.
 *
 * Retorna una de estas intenciones:
 *   'stock'    → pregunta por disponibilidad / cuántos hay
 *   'envio'    → pregunta por despacho / tiempo de entrega / costo envío
 *   'precio'   → pregunta por precio / descuento / oferta / medio de pago
 *   'original' → pregunta por autenticidad / si es original / pirata
 *   'idioma'   → pregunta por idioma / si está en español
 *   'general'  → no se detectó intención específica
 */
function akb_ml_detect_question_intent( string $text ): string {
	$lower = mb_strtolower( $text );

	$patterns = array(
		'stock'    => array( 'stock', 'disponible', 'disponibilidad', 'cuántos', 'cuantos', 'queda', 'quedan', 'tiene', 'tienen', 'hay' ),
		'envio'    => array( 'envío', 'envio', 'despacho', 'despacha', 'llega', 'llegada', 'demora', 'cuándo', 'cuando', 'entrega', 'flete', 'blue express', 'starken', 'retiro' ),
		'precio'   => array( 'precio', 'costo', 'descuento', 'oferta', 'rebaja', 'cuánto cuesta', 'cuanto cuesta', 'pago', 'cuotas', 'transferencia', 'efectivo' ),
		'original' => array( 'original', 'pirata', 'copia', 'auténtico', 'autentico', 'editorial', 'licensed', 'licenciado', 'garantía', 'garantia' ),
		'idioma'   => array( 'español', 'espanol', 'idioma', 'castellano', 'traducción', 'traduccion', 'inglés', 'ingles' ),
	);

	foreach ( $patterns as $intent => $keywords ) {
		foreach ( $keywords as $kw ) {
			if ( strpos( $lower, $kw ) !== false ) {
				return $intent;
			}
		}
	}

	return 'general';
}

add_action(
	'akb_ml_process_question',
	static function ( string $resource ): void {
		$question = akb_ml_request( 'GET', $resource );
		if ( isset( $question['error'] ) || empty( $question['id'] ) ) {
			return;
		}
		if ( ( $question['status'] ?? '' ) !== 'UNANSWERED' ) {
			return;
		}

		$ml_item_id    = $question['item_id'] ?? '';
		$question_id   = $question['id'];
		$question_text = $question['text'] ?? '';

		// Buscar producto WC asociado
		global $wpdb;
		$product_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->prefix}akb_ml_items WHERE ml_item_id = %s",
				$ml_item_id
			)
		);

		$product = $product_id ? wc_get_product( $product_id ) : null;
		$stock   = $product ? (int) $product->get_stock_quantity() : -1;

		// Detectar intención para personalizar la respuesta
		$intent = akb_ml_detect_question_intent( $question_text );

		$answer_parts = array( '¡Hola! Gracias por tu consulta.' );

		switch ( $intent ) {
			case 'stock':
				if ( $stock > 0 ) {
					$answer_parts[] = "Sí, tenemos {$stock} unidad(es) disponible(s) en stock.";
					$answer_parts[] = 'Podemos despachar de inmediato.';
				} elseif ( $stock === 0 ) {
					$answer_parts[] = 'Por el momento no tenemos stock de este producto, pero pronto puede volver a estar disponible.';
					$answer_parts[] = 'Te recomendamos marcarlo como favorito para recibir notificaciones.';
				} else {
					$answer_parts[] = 'El producto está disponible para despacho.';
				}
				break;

			case 'envio':
				$answer_parts[] = 'Despachamos en 1-2 días hábiles por Blue Express a todo Chile.';
				$answer_parts[] = 'El envío incluye seguimiento en línea.';
				$answer_parts[] = 'Embalamos con refuerzo para proteger el manga durante el transporte.';
				break;

			case 'precio':
				$answer_parts[] = 'El precio que ves en la publicación es el precio final, sin costos adicionales.';
				$answer_parts[] = 'Aceptamos todos los medios de pago disponibles en MercadoLibre.';
				if ( $product && (float) $product->get_price() > 0 ) {
					$answer_parts[] = 'Para compras de más de un tomo, te invitamos a revisar nuestra tienda — tenemos toda la serie disponible.';
				}
				break;

			case 'original':
				$answer_parts[] = 'Sí, todos nuestros productos son 100% originales y licenciados.';
				$answer_parts[] = 'Trabajamos directamente con distribuidores oficiales.';
				$answer_parts[] = 'Producto nuevo, sin leer, con embalaje original intacto.';
				break;

			case 'idioma':
				$answer_parts[] = 'El manga está en español (castellano), con traducción oficial.';
				break;

			default: // 'general'
				if ( $product && $stock > 0 ) {
					$answer_parts[] = "Tenemos {$stock} unidad(es) disponible(s).";
				}
				$answer_parts[] = 'Despachamos en 1-2 días hábiles por Blue Express a todo Chile.';
				$answer_parts[] = 'Producto 100% original, nuevo y sellado.';
				break;
		}

		$answer_parts[] = '¡Saludos! — Akibara';

		$answer_text = implode( ' ', $answer_parts );

		// Responder via API
		$resp = akb_ml_request(
			'POST',
			'/answers',
			array(
				'question_id' => $question_id,
				'text'        => mb_substr( $answer_text, 0, 2000 ),
			)
		);

		if ( isset( $resp['error'] ) ) {
			akb_ml_log( 'question', "Error respondiendo pregunta #{$question_id}: " . $resp['error'] );
		} else {
			akb_ml_log(
				'question',
				sprintf(
					'Pregunta #%d respondida (intent=%s) para %s',
					$question_id,
					$intent,
					$ml_item_id
				)
			);
		}
	}
);
