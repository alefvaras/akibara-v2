<?php
/**
 * Plugin Name: Akibara Security Headers
 * Description: Security headers complementarios al .htaccess (HSTS via PHP fallback) + REST users endpoint disable (mitigación enumeration). Cubre B-S1-SEC-05 del Sprint 1 audit.
 * Version: 1.0.0
 * Author: Akibara
 * Requires PHP: 8.1
 *
 * Diseño
 * ------
 * Headers estáticos (X-Frame-Options, X-Content-Type-Options, Referrer-Policy,
 * Permissions-Policy, X-XSS-Protection) ya están servidos por .htaccess →
 * Apache/LiteSpeed. NO se duplican aquí para evitar header conflicts.
 *
 * Este mu-plugin agrega solo lo que NO se puede hacer desde .htaccess o
 * lo que el control de WordPress hace mejor:
 *
 *   1. HSTS (Strict-Transport-Security): aunque también va en .htaccess,
 *      se duplica aquí como fallback si Apache/LiteSpeed se reinicia con
 *      config rota. Defense-in-depth.
 *
 *   2. REST users endpoint disable: filtra `rest_endpoints` para remover
 *      `/wp/v2/users` y `/wp/v2/users/(?P<id>\d+)` (mitigación user
 *      enumeration). Esto NO se puede hacer desde .htaccess sin romper
 *      el resto de la REST API.
 *
 * Trade-offs
 * ----------
 * - HSTS arranca con max-age=300 (5 min) para validation segura. Después
 *   de 24h sin reportes de regresión, escalar a max-age=31536000 (1 año)
 *   editando este mu-plugin. NO usar `preload` aún — preload es one-way
 *   (no se puede revertir sin contactar listas browser).
 *
 * - REST users disable rompe ÚNICAMENTE el endpoint /wp/v2/users.
 *   Verificado pre-deploy: Royal MCP, mcp-adapter, plugins activos NO
 *   consumen ese endpoint. Si en el futuro algún feature lo necesita
 *   (ej. listado users en admin SPA), agregar capability check en lugar
 *   de quitar el filter.
 *
 * Rollback
 * --------
 * Renombrar a `akibara-security-headers.php.disabled` o eliminar el archivo.
 * Apache/LiteSpeed sigue sirviendo el resto de headers desde .htaccess.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════
// HSTS fallback (idempotente — si .htaccess ya lo emite, browser usa el
// primero y descarta este).
// ═══════════════════════════════════════════════════════════════════════════
add_action(
	'send_headers',
	static function (): void {
		// Solo en HTTPS. En HTTP no tiene sentido (browser ignora HSTS via
		// HTTP per RFC 6797 § 8.1).
		if ( ! is_ssl() ) {
			return;
		}

		// Empieza bajo (5 min) para validation segura. Escalar a 31536000
		// (1 año) después de 24h confirmados sin regresión.
		header( 'Strict-Transport-Security: max-age=300', false );
	}
);

// ═══════════════════════════════════════════════════════════════════════════
// REST users endpoint disable (mitigación user enumeration).
// ═══════════════════════════════════════════════════════════════════════════
add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		// Lista exacta — evitar regex que pueda perder endpoints del core.
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		unset( $endpoints['/wp/v2/users/me'] );
		return $endpoints;
	}
);
