<?php
/**
 * Akibara Marketing — Tracking & Recurrence addon
 *
 * Open pixel, click tracking, and recurring campaign scheduling.
 * Loaded alongside the main marketing-campaigns module.
 *
 * @package Akibara
 * @since   10.3.0
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( ! defined( 'AKB_MKT_TRACKING_OPTION' ) ) {
	define( 'AKB_MKT_TRACKING_OPTION', 'akb_marketing_tracking' );
}

// ─── REST API endpoints ─────────────────────────────────────────
add_action(
	'rest_api_init',
	function (): void {

		// Open pixel: /wp-json/akibara/v1/mkt/open?c=CAMPAIGN_ID&e=EMAIL_HASH
		register_rest_route(
			'akibara/v1',
			'/mkt/open',
			array(
				'methods'             => 'GET',
				'callback'            => 'akb_mkt_track_open',
				'permission_callback' => '__return_true',
			)
		);

		// Click redirect: /wp-json/akibara/v1/mkt/click?c=CAMPAIGN_ID&e=EMAIL_HASH&url=ENCODED_URL
		register_rest_route(
			'akibara/v1',
			'/mkt/click',
			array(
				'methods'             => 'GET',
				'callback'            => 'akb_mkt_track_click',
				'permission_callback' => '__return_true',
			)
		);
	}
);

// ─── Open tracking ──────────────────────────────────────────────
function akb_mkt_track_open( WP_REST_Request $request ): WP_REST_Response {
	$campaign_id = sanitize_text_field( $request->get_param( 'c' ) ?? '' );
	$email_hash  = sanitize_text_field( $request->get_param( 'e' ) ?? '' );

	if ( $campaign_id && $email_hash ) {
		akb_mkt_log_event( $campaign_id, 'open', $email_hash );
	}

	// Serve 1x1 transparent GIF
	$pixel    = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
	$response = new WP_REST_Response( '' );
	$response->set_headers(
		array(
			'Content-Type'   => 'image/gif',
			'Content-Length' => strlen( $pixel ),
			'Cache-Control'  => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'         => 'no-cache',
			'Expires'        => 'Thu, 01 Jan 1970 00:00:00 GMT',
		)
	);

	// Direct output because WP_REST_Response can't send binary
	header( 'Content-Type: image/gif' );
	header( 'Content-Length: ' . strlen( $pixel ) );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	echo $pixel;
	exit;
}

// ─── Click tracking ─────────────────────────────────────────────
function akb_mkt_track_click( WP_REST_Request $request ): void {
	$campaign_id = sanitize_text_field( $request->get_param( 'c' ) ?? '' );
	$email_hash  = sanitize_text_field( $request->get_param( 'e' ) ?? '' );
	$url         = esc_url_raw( $request->get_param( 'url' ) ?? '' );

	if ( $campaign_id && $email_hash ) {
		akb_mkt_log_event( $campaign_id, 'click', $email_hash, $url );
	}

	wp_redirect( akb_mkt_validate_redirect_url( $url ), 302 );
	exit;
}

/**
 * Valida que una URL de redirect pertenezca a un dominio autorizado.
 *
 * Permite el dominio propio (y subdominios) + lista blanca explícita de
 * dominios externos. Expone el filtro `akibara_tracking_allowed_domains`
 * para ampliar la lista sin tocar código (ej: cambio de courier).
 *
 * @param  string $url URL candidata (ya pasada por esc_url_raw).
 * @return string      URL validada, o home_url() si el dominio no está permitido.
 */
function akb_mkt_validate_redirect_url( string $url ): string {
	if ( empty( $url ) ) {
		return home_url();
	}

	$parsed = wp_parse_url( $url );

	// URL sin host (relativa) → permanece en el mismo sitio, segura.
	if ( empty( $parsed['host'] ) ) {
		return $url;
	}

	$host      = strtolower( $parsed['host'] );
	$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	// Dominio propio y cualquier subdominio (ej: checkout.akibara.cl).
	if ( $host === $site_host || str_ends_with( $host, '.' . $site_host ) ) {
		return $url;
	}

	/**
	 * Lista blanca de dominios externos permitidos para el redirect de tracking.
	 *
	 * Dominios activos al 2026-04-23:
	 *   - tracking-unificado.blue.cl : portal de seguimiento BlueExpress
	 *   - wa.me                       : links de WhatsApp (emails de referidos)
	 *   - web.whatsapp.com            : WhatsApp Web desktop
	 *
	 * 12 Horas Envíos no expone URL pública de tracking (confirmado 2026-04-21).
	 * Si en el futuro agregan portal propio, añadir vía este filtro desde
	 * functions.php o un mu-plugin, sin modificar este archivo.
	 *
	 * @param string[] $domains Dominios permitidos (host exacto, sin comodines).
	 */
	$allowed = apply_filters(
		'akibara_tracking_allowed_domains',
		array(
			'tracking-unificado.blue.cl',
			'wa.me',
			'web.whatsapp.com',
		)
	);

	foreach ( $allowed as $domain ) {
		if ( $host === strtolower( (string) $domain ) ) {
			return $url;
		}
	}

	// Dominio no autorizado: log silencioso y fallback seguro.
	wc_get_logger()->warning(
		'akb_mkt_track_click: dominio bloqueado — ' . $host,
		array(
			'source' => 'akibara-tracking',
			'url'    => $url,
		)
	);
	return home_url();
}

// ─── Event logger ───────────────────────────────────────────────
function akb_mkt_log_event( string $campaign_id, string $type, string $email_hash, string $extra = '' ): void {
	$tracking = get_option( AKB_MKT_TRACKING_OPTION, array() );
	if ( ! is_array( $tracking ) ) {
		$tracking = array();
	}

	if ( ! isset( $tracking[ $campaign_id ] ) ) {
		$tracking[ $campaign_id ] = array(
			'opens'  => array(),
			'clicks' => array(),
		);
	}

	$key = $type === 'open' ? 'opens' : 'clicks';

	// Deduplicate opens per email_hash, but count all clicks
	if ( $type === 'open' ) {
		if ( ! in_array( $email_hash, $tracking[ $campaign_id ]['opens'], true ) ) {
			$tracking[ $campaign_id ]['opens'][] = $email_hash;
		}
	} else {
		$tracking[ $campaign_id ]['clicks'][] = array(
			'hash' => $email_hash,
			'url'  => $extra,
			'time' => time(),
		);
		// Keep max 500 click entries per campaign
		if ( count( $tracking[ $campaign_id ]['clicks'] ) > 500 ) {
			$tracking[ $campaign_id ]['clicks'] = array_slice( $tracking[ $campaign_id ]['clicks'], -500 );
		}
	}

	update_option( AKB_MKT_TRACKING_OPTION, $tracking, false );
}

// ─── Get tracking stats ─────────────────────────────────────────
function akb_mkt_get_tracking_stats( string $campaign_id ): array {
	$tracking = get_option( AKB_MKT_TRACKING_OPTION, array() );
	if ( ! is_array( $tracking ) || ! isset( $tracking[ $campaign_id ] ) ) {
		return array(
			'unique_opens'    => 0,
			'total_clicks'    => 0,
			'unique_clickers' => 0,
		);
	}

	$data            = $tracking[ $campaign_id ];
	$unique_clickers = count( array_unique( array_column( $data['clicks'] ?? array(), 'hash' ) ) );

	return array(
		'unique_opens'    => count( $data['opens'] ?? array() ),
		'total_clicks'    => count( $data['clicks'] ?? array() ),
		'unique_clickers' => $unique_clickers,
	);
}

// ─── Inject tracking into email HTML ────────────────────────────
function akb_mkt_inject_tracking( string $html, string $campaign_id, string $email ): string {
	$email_hash = md5( strtolower( trim( $email ) ) );
	$site_url   = rest_url( 'akibara/v1/mkt' );

	// 1. Open pixel — before </body> or at end
	$pixel_url = add_query_arg(
		array(
			'c' => $campaign_id,
			'e' => $email_hash,
		),
		$site_url . '/open'
	);
	$pixel_tag = '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0" />';

	if ( stripos( $html, '</body>' ) !== false ) {
		$html = str_ireplace( '</body>', $pixel_tag . '</body>', $html );
	} else {
		$html .= $pixel_tag;
	}

	// 2. Click tracking — wrap <a href="..."> links (skip mailto: and #)
	$click_base = $site_url . '/click';
	$html       = preg_replace_callback(
		'/<a\s([^>]*?)href=["\']([^"\']+)["\']([^>]*?)>/i',
		function ( $m ) use ( $campaign_id, $email_hash, $click_base ) {
			$url = $m[2];
			// Skip mailto, tel, anchors, and already-tracked
			if ( preg_match( '/^(mailto:|tel:|#|.*\/mkt\/click)/i', $url ) ) {
				return $m[0];
			}
			$tracked_url = add_query_arg(
				array(
					'c'   => $campaign_id,
					'e'   => $email_hash,
					'url' => rawurlencode( $url ),
				),
				$click_base
			);
			return '<a ' . $m[1] . 'href="' . esc_url( $tracked_url ) . '"' . $m[3] . '>';
		},
		$html
	);

	return $html;
}

// ─── Recurring campaigns ────────────────────────────────────────
// After a campaign is sent, if it has recurrence, schedule the next one
add_action(
	'akb_marketing_after_send',
	function ( string $campaign_id ): void {
		$campaigns = akb_marketing_get_campaigns();
		if ( empty( $campaigns[ $campaign_id ] ) ) {
			return;
		}

		$c          = $campaigns[ $campaign_id ];
		$recurrence = $c['recurrence'] ?? 'none';

		if ( $recurrence === 'none' ) {
			return;
		}

		$interval = match ( $recurrence ) {
			'weekly'  => 7 * DAY_IN_SECONDS,
			'monthly' => 30 * DAY_IN_SECONDS,
			default   => 0,
		};

		if ( $interval <= 0 ) {
			return;
		}

		$next_ts = (int) $c['send_at'] + $interval;
		// If next_ts is in the past (delayed execution), schedule from now
		if ( $next_ts < time() ) {
			$next_ts = time() + $interval;
		}

		$new_id               = 'cmp_' . wp_generate_password( 10, false, false );
		$campaigns[ $new_id ] = array(
			'id'         => $new_id,
			'name'       => $c['name'] . ' (auto)',
			'segment'    => $c['segment'],
			'subject'    => $c['subject'],
			'body'       => $c['body'],
			'send_at'    => $next_ts,
			'coupon'     => $c['coupon'] ?? '',
			'template'   => $c['template'] ?? 'custom',
			'recurrence' => $recurrence,
			'parent_id'  => $campaign_id,
			'status'     => 'scheduled',
			'created_at' => time(),
			'sent'       => 0,
			'failed'     => 0,
		);

		akb_marketing_save_campaigns( $campaigns );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $next_ts, 'akb_marketing_execute_campaign', array( 'campaign_id' => $new_id ), 'akibara-marketing' );
		} else {
			wp_schedule_single_event( $next_ts, 'akb_marketing_execute_campaign_wpcron', array( $new_id ) );
		}
	},
	10,
	1
);
