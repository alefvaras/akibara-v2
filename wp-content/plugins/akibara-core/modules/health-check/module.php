<?php
/**
 * Akibara Core — Health Check Endpoint
 *
 * Lightweight health check for monitoring (UptimeRobot, Pingdom, etc.)
 * Returns JSON with system status: DB, cache, key modules.
 *
 * Migrado desde akibara/modules/health-check/module.php (Polish #1 2026-04-26).
 * Group-wrap pattern + sentinel per HANDOFF §8 (REDESIGN.md §9).
 *
 * NOTE: este módulo TAMBIÉN registra el endpoint /wp-json/akibara/v1/health
 * que ya existía en akibara-core/includes/akibara-search.php (vía akb_rest_handler).
 * Para evitar conflicto, el endpoint de health check usa el mismo namespace
 * /akibara/v1/health — los dos son idempotentes (el primero registrado "gana",
 * el segundo is a no-op por WP REST API route conflict deduplication).
 *
 * @package    Akibara\Core
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ─── File-level guard ───────────────────────────────────────────────────────
if ( defined( 'AKB_CORE_HEALTH_CHECK_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_HEALTH_CHECK_LOADED', '1.0.0' );

// Backward-compat: si el legacy definió AKIBARA_HEALTH_CHECK_LOADED, no redeclares.
if ( defined( 'AKIBARA_HEALTH_CHECK_LOADED' ) ) {
	return;
}

// Constant signal per ModuleRegistry pattern.
if ( ! defined( 'AKB_CORE_MODULE_HEALTH_CHECK_LOADED' ) ) {
	define( 'AKB_CORE_MODULE_HEALTH_CHECK_LOADED', '1.0.0' );
}

// ─── Group wrap (REDESIGN.md §9) ────────────────────────────────────────────
if ( ! function_exists( 'akibara_get_health_status' ) ) {

	// ─── Register query param endpoint for simple monitoring ──────────────────
	add_action(
		'init',
		function () {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['akb_health'] ) && $_GET['akb_health'] === '1' ) {
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				akibara_health_check_json();
				exit;
			}
		}
	);

	/**
	 * Health check for REST API endpoint.
	 * NOTE: si akibara-search.php ya registró /akibara/v1/health, WP REST
	 * API tomará el primero registrado. Este callback se pasa como callback
	 * alternativo en caso de que el endpoint no esté aún registrado.
	 */
	add_action(
		'rest_api_init',
		function () {
			// Solo registrar si akibara-search.php no registró primero.
			// WP REST API register_rest_route ignora duplicados de ruta+método.
			register_rest_route(
				'akibara/v1',
				'/health',
				array(
					'methods'             => 'GET',
					'callback'            => 'akibara_health_check',
					'permission_callback' => '__return_true',
				)
			);
		}
	);

	/**
	 * Health check callback for REST API.
	 */
	function akibara_health_check(): WP_REST_Response {
		$status    = akibara_get_health_status();
		$http_code = $status['status'] === 'ok' ? 200 : 503;

		return new WP_REST_Response( $status, $http_code );
	}

	/**
	 * Health check for query param (plain JSON output).
	 */
	function akibara_health_check_json(): void {
		$status = akibara_get_health_status();
		header( 'Content-Type: application/json' );
		http_response_code( $status['status'] === 'ok' ? 200 : 503 );
		echo wp_json_encode( $status );
	}

	/**
	 * Gather health status information.
	 */
	function akibara_get_health_status(): array {
		global $wpdb;

		$checks = array(
			'db'              => false,
			'cache'           => false,
			'woocommerce'     => false,
			'akibara_modules' => array(),
		);

		// Database check
		$checks['db'] = $wpdb->get_var( 'SELECT 1' ) === '1';

		// Cache check (try to set/get)
		$cache_key = 'akb_health_test_' . time();
		wp_cache_set( $cache_key, 'test', '', 10 );
		$checks['cache'] = wp_cache_get( $cache_key ) === 'test';
		wp_cache_delete( $cache_key );

		// WooCommerce check
		$checks['woocommerce'] = class_exists( 'WooCommerce' );

		// Key Akibara modules check via constants.
		$checks['akibara_modules'] = array(
			'core'              => defined( 'AKIBARA_CORE_PLUGIN_LOADED' ),
			'rut'               => defined( 'AKB_CORE_RUT_LOADED' ) || defined( 'AKIBARA_RUT_LOADED' ),
			'phone'             => defined( 'AKB_CORE_PHONE_LOADED' ) || defined( 'AKIBARA_PHONE_LOADED' ),
			'installments'      => defined( 'AKB_CORE_INSTALLMENTS_LOADED' ) || defined( 'AKIBARA_INSTALLMENTS_LOADED' ),
			'product-badges'    => defined( 'AKB_CORE_PRODUCT_BADGES_LOADED' ) || defined( 'AKB_PRODUCT_BADGES_LOADED' ),
			'series-autofill'   => defined( 'AKB_CORE_SERIES_AUTOFILL_LOADED' ),
		);

		// Overall status
		$all_ok = $checks['db'] && $checks['cache'] && $checks['woocommerce'];

		$result = array(
			'status'    => $all_ok ? 'ok' : 'degraded',
			'timestamp' => current_time( 'mysql' ),
		);

		// Only expose details to authenticated admins or requests with valid token.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$token         = get_option( 'akb_health_token', '' );
		$provided      = $_GET['token'] ?? $_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$is_authorized = ( $token && hash_equals( $token, (string) $provided ) ) || current_user_can( 'manage_woocommerce' );

		if ( $is_authorized ) {
			$checks['dev_cache_global'] = array(
				'enabled' => (bool) get_option( 'akb_dev_nocache_global', 0 ),
			);

			// Disk space
			if ( function_exists( 'disk_free_space' ) ) {
				$free                   = @disk_free_space( ABSPATH );
				$checks['disk_free_mb'] = $free ? round( $free / 1024 / 1024 ) : null;
			}

			// Plugins críticos activos
			$active   = (array) get_option( 'active_plugins', array() );
			$critical = array(
				'woocommerce/woocommerce.php',
				'akibara-core/akibara-core.php',
			);
			$missing  = array();
			foreach ( $critical as $path ) {
				if ( ! in_array( $path, $active, true ) ) {
					$missing[] = $path;
				}
			}
			$checks['critical_plugins_missing'] = $missing;

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['verbose'] ) && '1' === $_GET['verbose'] ) {
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				$brevo_key = function_exists( 'akb_brevo_get_api_key' )
					? akb_brevo_get_api_key()
					: (string) get_option( 'akibara_brevo_api_key', '' );
				if ( $brevo_key ) {
					$resp                 = wp_remote_get(
						'https://api.brevo.com/v3/account',
						array(
							'timeout' => 3,
							'headers' => array(
								'api-key' => $brevo_key,
								'accept'  => 'application/json',
							),
						)
					);
					$code                 = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
					$checks['brevo_http'] = $code;
				} else {
					$checks['brevo_http'] = 'skipped';
				}
			}

			$result['checks']      = $checks;
			$result['environment'] = array(
				'php_version'      => PHP_VERSION,
				'wp_version'       => get_bloginfo( 'version' ),
				'wc_version'       => defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A',
				'akibara_core_ver' => defined( 'AKIBARA_CORE_VERSION' ) ? AKIBARA_CORE_VERSION : 'N/A',
			);
		}

		return $result;
	}

} // end group wrap
