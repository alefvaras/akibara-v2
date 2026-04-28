<?php
/**
 * Akibara AJAX Endpoint Helper
 *
 * Consolida el patrón AJAX duplicado en ~42 handlers del plugin:
 *   add_action( 'wp_ajax_foo', 'handler' );
 *   function handler() {
 *       current_user_can(...) || wp_send_json_error(...);
 *       check_ajax_referer( 'nonce', 'nonce' );
 *       ...
 *       wp_send_json_success( $data );
 *   }
 *
 * Centraliza:
 *  - Capability check (SIEMPRE antes del nonce — orden correcto de auditoría)
 *  - Nonce verification
 *  - Rate limiting opcional (via transients)
 *  - Sanitización genérica de $_POST
 *  - Normalización de la respuesta (array → success, array con 'error' → error)
 *  - Captura global de Throwable con logging
 *
 * @package Akibara
 * @since   10.5.0 (R1 del plan de arquitectura)
 */

defined( 'ABSPATH' ) || exit;

// ─── Guard contra doble carga ─────────────────────────────────────
// Owns: akibara-core (Polish #1 retry 2026-04-27).
// Backward compat: legacy akibara/includes/helpers/ajax.php uses same constant
// AKIBARA_AJAX_HELPER_LOADED so when both load only one wins.
if ( defined( 'AKB_CORE_AJAX_HELPER_LOADED' ) || defined( 'AKIBARA_AJAX_HELPER_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_AJAX_HELPER_LOADED', true );
define( 'AKIBARA_AJAX_HELPER_LOADED', true );

/**
 * Registra un endpoint AJAX consolidando el patrón seguridad + sanitización + handler.
 *
 * @param string $action Nombre de la acción AJAX (sin prefijo `wp_ajax_`).
 * @param array  $config {
 *     @type string        $nonce      Nombre de la acción del nonce (requerido).
 *     @type string|null   $capability Capability WP (e.g. 'manage_woocommerce'). null = público (sin check).
 *     @type callable      $handler    Recibe el $_POST sanitizado como único argumento.
 *                                     Retorno:
 *                                       - array con key 'error' => wp_send_json_error($array)
 *                                       - array normal          => wp_send_json_success($array)
 *                                       - null                  => asume que el handler ya envió la respuesta
 *     @type array|null    $rate_limit ['window' => int seconds, 'max' => int hits]. null = sin rate limit.
 *     @type bool          $public     Si true, también registra wp_ajax_nopriv_* (default false).
 *     @type callable|null $sanitize   Callback(array $post): array que transforma $_POST.
 *                                     Default: wp_unslash + sanitize_text_field en cada key escalar.
 * }
 * @return void
 *
 * @example Handler admin (finance dashboard):
 *   akb_ajax_endpoint( 'akb_finance_data', [
 *       'nonce'      => 'akb_finance_nonce',
 *       'capability' => 'manage_woocommerce',
 *       'handler'    => function ( array $post ): array {
 *           $period = sanitize_key( $post['period'] ?? 'month' );
 *           return akb_finance_get_data( $period );
 *       },
 *   ] );
 *
 * @example Handler público con rate limit (checkout validation):
 *   akb_ajax_endpoint( 'akb_checkout_validate_email', [
 *       'nonce'      => 'akb_checkout_nonce',
 *       'capability' => null,           // público
 *       'public'     => true,           // frontend
 *       'rate_limit' => [ 'window' => 60, 'max' => 20 ],
 *       'handler'    => function ( array $post ): array {
 *           $email = sanitize_email( $post['email'] ?? '' );
 *           return akb_checkout_validate_email_api( $email );
 *       },
 *   ] );
 */
function akb_ajax_endpoint( string $action, array $config ): void {
	// ── Validar config mínima ──
	if ( empty( $config['nonce'] ) || ! is_string( $config['nonce'] ) ) {
		_doing_it_wrong( __FUNCTION__, 'akb_ajax_endpoint requiere config[nonce] string', '10.5.0' );
		return;
	}
	if ( empty( $config['handler'] ) ) {
		_doing_it_wrong( __FUNCTION__, 'akb_ajax_endpoint requiere config[handler]', '10.5.0' );
		return;
	}
	// NOTA: is_callable() check movido al wrapper runtime — el group-wrap pattern
	// (REDESIGN.md §9) usado en addons declara la función handler DESPUÉS de
	// llamar a akb_ajax_endpoint(), porque las funciones dentro de `if(...)` no
	// son hoisted. Validar al request-time (no al register-time) evita el ruido
	// de "handler not callable" en file-include time. Sentry PHP-C4 fix 2026-04-27.

	$nonce      = $config['nonce'];
	$capability = array_key_exists( 'capability', $config ) ? $config['capability'] : null;
	$handler    = $config['handler'];
	$rate_limit = $config['rate_limit'] ?? null;
	$public     = ! empty( $config['public'] );
	$sanitize   = $config['sanitize'] ?? null;

	$wrapped = static function () use ( $action, $nonce, $capability, $handler, $rate_limit, $sanitize ): void {
		try {
			// 0) Validate handler callable at request-time (group-wrap deferred).
			if ( ! is_callable( $handler ) ) {
				if ( function_exists( 'akb_log' ) ) {
					akb_log( 'akb_ajax_endpoint', 'error', "Handler no callable at request: $action" );
				} else {
					error_log( "[akb_ajax_endpoint:$action] handler not callable at request time" );
				}
				wp_send_json_error(
					array(
						'error'   => 'handler_unavailable',
						'message' => 'Handler no disponible.',
					),
					500
				);
			}

			// 1) Capability check SIEMPRE antes del nonce (orden correcto de auditoría).
			// Un usuario sin permisos no debe siquiera ver la validación del nonce.
			if ( $capability !== null ) {
				if ( ! current_user_can( $capability ) ) {
					wp_send_json_error( 'Sin permisos', 403 );
				}
			}

			// 2) Nonce check — WP mata el request con 403 si falla (die=true).
			check_ajax_referer( $nonce, 'nonce' );

			// 3) Rate limit (opcional) via transient por IP.
			if ( is_array( $rate_limit ) && ! empty( $rate_limit['window'] ) && ! empty( $rate_limit['max'] ) ) {
				$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
				$rl_key = 'akb_rl_' . $action . '_' . md5( $ip );
				$hits   = (int) get_transient( $rl_key );
				$max    = (int) $rate_limit['max'];
				$window = (int) $rate_limit['window'];

				if ( $hits >= $max ) {
					wp_send_json_error(
						array(
							'error'   => 'rate_limited',
							'message' => 'Demasiadas solicitudes, intenta más tarde.',
						),
						429
					);
				}
				set_transient( $rl_key, $hits + 1, $window );
			}

			// 4) Sanitize $_POST.
			$raw = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificado arriba.
			if ( is_callable( $sanitize ) ) {
				$clean = (array) call_user_func( $sanitize, $raw );
			} else {
				$clean = akb_ajax_default_sanitize( $raw );
			}

			// 5) Llamar al handler.
			$result = call_user_func( $handler, $clean );

			// 6) Normalizar respuesta.
			if ( $result === null ) {
				// Handler ya envió respuesta (ej: wp_send_json_* o streaming).
				return;
			}
			if ( is_array( $result ) && isset( $result['error'] ) ) {
				wp_send_json_error( $result );
			}
			if ( is_array( $result ) ) {
				wp_send_json_success( $result );
			}
			// Scalar u otro: devolverlo tal cual dentro de success.
			wp_send_json_success( $result );

		} catch ( \Throwable $e ) {
			if ( function_exists( 'akb_log' ) ) {
				akb_log(
					sprintf(
						'[akb_ajax_endpoint:%s] %s in %s:%d',
						$action,
						$e->getMessage(),
						$e->getFile(),
						$e->getLine()
					)
				);
			} else {
				error_log( '[akb_ajax_endpoint:' . $action . '] ' . $e->getMessage() );
			}
			wp_send_json_error(
				array(
					'error'   => 'server_error',
					'message' => 'Error interno, se registró el incidente.',
				),
				500
			);
		}
	};

	add_action( 'wp_ajax_' . $action, $wrapped );
	if ( $public ) {
		add_action( 'wp_ajax_nopriv_' . $action, $wrapped );
	}
}

/**
 * Sanitización por defecto: wp_unslash + sanitize_text_field recursivo en cada clave escalar.
 *
 * Arrays anidados se recorren; objetos o recursos se descartan.
 *
 * @param array $post Raw $_POST.
 * @return array Sanitizado.
 */
function akb_ajax_default_sanitize( array $post ): array {
	$out = array();
	foreach ( $post as $key => $value ) {
		$k = sanitize_key( (string) $key );
		if ( is_array( $value ) ) {
			$out[ $k ] = akb_ajax_default_sanitize( $value );
		} elseif ( is_scalar( $value ) ) {
			$out[ $k ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}
		// objetos/recursos se descartan silenciosamente
	}
	return $out;
}

/**
 * Helper inverso: genera el nonce para un endpoint AJAX registrado.
 *
 * Usar en wp_localize_script / wp_add_inline_script para inyectar el nonce en el frontend.
 *
 * NOTA: recibe el NONCE ACTION (no la AJAX action). Esto permite compartir el mismo
 * nonce entre varios endpoints relacionados (e.g. todos los endpoints de finance
 * pueden compartir 'akb_finance_nonce').
 *
 * @param string $nonce_action Acción del nonce (debe coincidir con config[nonce] del endpoint).
 * @return string Nonce generado.
 *
 * @example
 *   wp_localize_script( 'akb-finance', 'AKB_FINANCE', [
 *       'ajax_url' => admin_url( 'admin-ajax.php' ),
 *       'nonce'    => akb_ajax_nonce( 'akb_finance_nonce' ),
 *   ] );
 */
function akb_ajax_nonce( string $nonce_action ): string {
	return wp_create_nonce( $nonce_action );
}
