<?php
/**
 * 12 Horas Envíos — HTTP Client
 *
 * Cliente para el API REST de 12 Horas usando WP HTTP API (wp_remote_request).
 * Migrado desde fallback cURL nativo en Sprint 9 (audit P-11): permite filtros
 * WP, retries automáticos y TLS context unificado.
 *
 * Endpoints (paths relativos a base_url; el servidor real expone /api/v1):
 *   POST   {base}/delivery                        create()
 *   GET    {base}/delivery/:trackingCode          get_by_tracking()
 *   GET    {base}/delivery/externalId/:externalId get_by_external_id()
 *   PUT    {base}/delivery/:trackingCode/cancel   cancel()
 *
 * Retorna siempre un array uniforme:
 *   [ 'ok' => bool, 'status' => int, 'data' => array|null, 'error' => string|null,
 *     'errorCode' => string|null, 'errorPath' => string[]|null ]
 *
 * @package Akibara\Shipping\TwelveHoras
 * @since   10.9.0
 */

defined( 'ABSPATH' ) || exit;

class AKB_TwelveHoras_Client {

	private string $base_url;
	private string $api_key;
	private int $timeout;

	public function __construct( string $api_key, string $base_url = 'https://api.12horasenvios.cl/api/v1', int $timeout = 15 ) {
		$this->api_key  = $api_key;
		$this->base_url = rtrim( $base_url, '/' );
		$this->timeout  = $timeout;
	}

	// ── Public API ─────────────────────────────────────────────────

	public function create( array $payload ): array {
		return $this->request( 'POST', '/delivery', $payload );
	}

	public function get_by_tracking( string $trackingCode ): array {
		return $this->request( 'GET', '/delivery/' . rawurlencode( $trackingCode ) );
	}

	public function get_by_external_id( string $externalId ): array {
		return $this->request( 'GET', '/delivery/externalId/' . rawurlencode( $externalId ) );
	}

	public function cancel( string $trackingCode ): array {
		return $this->request( 'PUT', '/delivery/' . rawurlencode( $trackingCode ) . '/cancel' );
	}

	/**
	 * Ping: intenta un GET sobre un tracking inexistente.
	 * - 200 → API viva, key OK
	 * - 404 "No se encontró el envío" → ruta OK, key OK, tracking inexistente (esperado)
	 * - 404 "Ruta no encontrada" → base_url mal configurada
	 * - 401 → key inválida
	 */
	public function test_connection(): array {
		$r             = $this->get_by_tracking( 'DRV000000000' );
		$status        = (int) $r['status'];
		$msg           = (string) ( $r['error'] ?? '' );
		$route_missing = $status === 404 && stripos( $msg, 'Ruta no encontrada' ) !== false;
		$auth_ok       = $status !== 401;
		$ok            = ( $status === 200 ) || ( $status === 404 && ! $route_missing );
		if ( $route_missing ) {
			$message = 'base_url inválida (ruta API no existe): ' . $msg;
		} elseif ( $status === 401 ) {
			$message = 'API Key rechazada (401)';
		} elseif ( $ok ) {
			$message = 'Conectado (API viva, key aceptada)';
		} else {
			$message = $msg !== '' ? $msg : ( 'HTTP ' . $status );
		}
		return array(
			'ok'      => $ok,
			'status'  => $status,
			'auth_ok' => $auth_ok,
			'message' => $message,
		);
	}

	// ── HTTP Core ─────────────────────────────────────────────────

	private function request( string $method, string $path, ?array $body = null ): array {
		$url     = $this->base_url . $path;
		$headers = array(
			'X-API-Key'    => $this->api_key,
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $this->timeout,
			'redirection' => 0,
		);
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$resp = wp_remote_request( $url, $args );
		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'        => false,
				'status'    => 0,
				'data'      => null,
				'error'     => $resp->get_error_message(),
				'errorCode' => 'HTTP_ERROR',
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $resp );
		$raw    = (string) wp_remote_retrieve_body( $resp );
		return $this->parse_response( $status, $raw );
	}

	private function parse_response( int $status, string $raw ): array {
		$data = json_decode( $raw, true );
		$ok   = $status >= 200 && $status < 300;
		if ( $ok ) {
			return array(
				'ok'        => true,
				'status'    => $status,
				'data'      => is_array( $data ) ? $data : null,
				'error'     => null,
				'errorCode' => null,
				'errorPath' => null,
			);
		}
		// API 12 Horas devuelve errores con shape { message, errorCode?, path?: string[] }.
		// `path` identifica el/los campos que fallaron la validación — útil en notas de orden.
		$path = null;
		if ( is_array( $data ) && isset( $data['path'] ) ) {
			if ( is_array( $data['path'] ) ) {
				$path = array_values( array_filter( array_map( 'strval', $data['path'] ) ) );
			} elseif ( is_string( $data['path'] ) && $data['path'] !== '' ) {
				$path = array( $data['path'] );
			}
		}
		return array(
			'ok'        => false,
			'status'    => $status,
			'data'      => is_array( $data ) ? $data : null,
			'error'     => $data['message'] ?? ( 'HTTP ' . $status ),
			'errorCode' => $data['errorCode'] ?? null,
			'errorPath' => $path,
		);
	}
}
