<?php
/**
 * Akibara Welcome Email Series
 *
 * Serie automática de 3 emails post-suscripción al popup de captura.
 * Se activa con el action `akibara_popup_subscribed` ($email, $timestamp).
 *
 *   Email 1 (t=0)   : Código exclusivo de bienvenida (PRIMERACOMPRA10).
 *   Email 2 (t=24h) : Top 3 shonen para empezar.
 *   Email 3 (t=72h) : FOMO — tu 10% OFF expira.
 *
 * Los templates HTML viven en Brevo (configurables por el admin). Aquí sólo
 * resolvemos el template_id desde opciones WP y disparamos vía AkibaraBrevo.
 *
 * Guard: si el suscriptor ya compró (pedido processing/completed), se aborta
 * el resto de la serie para no enviar FOMO de descuento post-conversión.
 *
 * @package Akibara
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( defined( 'AKIBARA_WS_LOADED' ) ) {
	return;
}
define( 'AKIBARA_WS_LOADED', true );

// ─── Constantes ─────────────────────────────────────────────────
const AKB_WS_HOOK_SEND   = 'akb_ws_send_email';
const AKB_WS_GROUP       = 'akibara-welcome-series';
const AKB_WS_OPTION_SENT = 'akb_ws_sent_log'; // [email => [1 => ts, 2 => ts, 3 => ts]]

/**
 * Verifica si el email sigue siendo suscriptor activo (no blacklisted en Brevo).
 */
function akb_ws_is_still_subscriber( string $email ): bool {
	if ( ! is_email( $email ) ) {
		return false;
	}
	if ( ! class_exists( 'AkibaraBrevo' ) ) {
		return false;
	}

	$api_key = AkibaraBrevo::get_api_key();
	if ( empty( $api_key ) ) {
		return false;
	}

	// fail-open: si la API falla, seguimos enviando (mejor UX que silencio)
	return ! AkibaraBrevo::is_blacklisted( $api_key, $email );
}

/**
 * ¿Ya compró este email? Si sí, no se envían los emails 2 y 3 (FOMO post-compra es ruido).
 * Consulta wp_wc_orders (HPOS). Fallback a wc_get_orders si la tabla no existe.
 */
function akb_ws_has_purchased( string $email ): bool {
	if ( ! is_email( $email ) ) {
		return false;
	}

	global $wpdb;
	$table  = $wpdb->prefix . 'wc_orders';
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	if ( $exists === $table ) {
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
             WHERE billing_email = %s
             AND status IN ('wc-processing','wc-completed')
             LIMIT 1",
				$email
			)
		);
		return $count > 0;
	}

	// Fallback para instalaciones sin HPOS
	if ( function_exists( 'wc_get_orders' ) ) {
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => array( 'processing', 'completed' ),
				'limit'         => 1,
				'return'        => 'ids',
			)
		);
		return ! empty( $orders );
	}

	return false;
}

/**
 * Marca un step como enviado en el log local (persistencia de estado).
 */
function akb_ws_mark_sent( string $email, int $step ): void {
	$log = get_option( AKB_WS_OPTION_SENT, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[ $email ][ $step ] = time();
	// GC: limpiar emails con step 3 enviado hace más de 30 días
	$cutoff = time() - ( 30 * DAY_IN_SECONDS );
	foreach ( $log as $em => $steps ) {
		if ( ! empty( $steps[3] ) && $steps[3] < $cutoff ) {
			unset( $log[ $em ] );
		}
	}
	update_option( AKB_WS_OPTION_SENT, $log, false );
}

/**
 * ¿Ya se envió este step a este email? Evita duplicados ante re-scheduling.
 */
function akb_ws_already_sent( string $email, int $step ): bool {
	$log = get_option( AKB_WS_OPTION_SENT, array() );
	return is_array( $log ) && ! empty( $log[ $email ][ $step ] );
}

// ─── Trigger: popup subscription ────────────────────────────────
/**
 * Hook principal: cuando el popup confirma suscripción, agendamos los 3 emails.
 *
 * @param string $email     Email del suscriptor.
 * @param int    $timestamp Timestamp de la suscripción (provisto por el popup).
 */
add_action(
	'akibara_popup_subscribed',
	function ( string $email, int $timestamp = 0 ): void {
		if ( ! is_email( $email ) ) {
			akb_log( 'welcome-series', 'warn', 'Email inválido recibido', array( 'email' => $email ) );
			return;
		}

		if ( $timestamp <= 0 ) {
			$timestamp = time();
		}

		// Evitar re-scheduling si ya hay serie activa para este email
		if ( akb_ws_already_sent( $email, 1 ) ) {
			akb_log( 'welcome-series', 'info', 'Serie ya agendada/enviada previamente', array( 'email' => $email ) );
			return;
		}

		// Anti-saturación: si el suscriptor viene via link de referido (cookie akb_ref),
		// el email de referral (akb_referrals_send_referee_email) ya le da bienvenida con
		// cupón $3.000 al completar su primera compra. Saltar toda la welcome-series evita:
		// - Duplicar "Bienvenido" + "Bienvenido al distrito" en 96h
		// - Competir 2 cupones de bienvenida (PRIMERACOMPRA10 vs REFREFERIDO-*)
		// - Unsub rate alto por sobreexposición
		// Referencia: mesa de agentes 2026-04-22, sección "saturación inbox".
		if ( ! empty( $_COOKIE['akb_ref'] ) ) {
			akb_log(
				'welcome-series',
				'info',
				'Serie NO agendada · visitante via referral (cookie akb_ref)',
				array(
					'email'    => $email,
					'ref_code' => sanitize_text_field( wp_unslash( $_COOKIE['akb_ref'] ) ),
				)
			);
			// Marcar los 3 steps como "enviados" para que cualquier re-subscribe no agende
			akb_ws_mark_sent( $email, 1 );
			akb_ws_mark_sent( $email, 2 );
			akb_ws_mark_sent( $email, 3 );
			return;
		}

		$as_available = function_exists( 'as_schedule_single_action' );

		$schedule = static function ( int $when, int $step ) use ( $email, $as_available ): void {
			if ( $as_available ) {
				as_schedule_single_action( $when, AKB_WS_HOOK_SEND, array( $email, $step ), AKB_WS_GROUP );
			} else {
				wp_schedule_single_event( $when, AKB_WS_HOOK_SEND, array( $email, $step ) );
			}
		};

		$schedule( $timestamp, 1 ); // inmediato
		$schedule( $timestamp + DAY_IN_SECONDS, 2 ); // +24h
		$schedule( $timestamp + ( 3 * DAY_IN_SECONDS ), 3 ); // +72h

		akb_log(
			'welcome-series',
			'info',
			'Serie agendada',
			array(
				'email'     => $email,
				'scheduler' => $as_available ? 'action-scheduler' : 'wp-cron',
			)
		);
	},
	10,
	2
);

// ─── Handler: send one email ────────────────────────────────────
add_action( AKB_WS_HOOK_SEND, 'akb_ws_send_email', 10, 2 );

/**
 * Handler invocado por Action Scheduler / WP-Cron.
 * Ejecuta todas las validaciones y dispara el template Brevo correspondiente.
 *
 * @param string $email Email destino.
 * @param int    $step  Paso de la serie (1|2|3).
 */
function akb_ws_send_email( string $email, int $step ): void {
	$step = max( 1, min( 3, (int) $step ) );

	if ( ! is_email( $email ) ) {
		akb_log(
			'welcome-series',
			'warn',
			'Skip: email inválido',
			array(
				'email' => $email,
				'step'  => $step,
			)
		);
		return;
	}

	// Idempotencia: no reenviar si ya fue enviado
	if ( akb_ws_already_sent( $email, $step ) ) {
		akb_log(
			'welcome-series',
			'info',
			'Skip: step ya enviado',
			array(
				'email' => $email,
				'step'  => $step,
			)
		);
		return;
	}

	// Guard: aún suscriptor?
	if ( ! akb_ws_is_still_subscriber( $email ) ) {
		akb_log(
			'welcome-series',
			'info',
			'Skip: unsubscribed (Brevo blacklist)',
			array(
				'email' => $email,
				'step'  => $step,
			)
		);
		return;
	}

	// Guard: ya compró? — sólo bloquea steps 2 y 3 (el 1 con cupón ya se envió o es el actual)
	if ( $step >= 2 && akb_ws_has_purchased( $email ) ) {
		akb_log(
			'welcome-series',
			'info',
			'Skip: usuario ya compró — aborta FOMO',
			array(
				'email' => $email,
				'step'  => $step,
			)
		);
		return;
	}

	if ( ! class_exists( 'AkibaraBrevo' ) || ! class_exists( 'AkibaraEmailTemplate' ) ) {
		akb_log(
			'welcome-series',
			'error',
			'AkibaraBrevo o AkibaraEmailTemplate class missing',
			array(
				'email' => $email,
				'step'  => $step,
			)
		);
		return;
	}

	$api_key = AkibaraBrevo::get_api_key();
	if ( empty( $api_key ) ) {
		akb_log( 'welcome-series', 'error', 'Brevo API key no configurada', array( 'step' => $step ) );
		return;
	}

	// Render HTML con AkibaraEmailTemplate (decisión 2026-04-22 mesa de agentes:
	// Editor Manga + Tech Architect + POV Collector favorecen código PHP sobre
	// Brevo UI — mejor consistency, testability, portabilidad ESP, y voz editorial).
	$coupon   = (string) get_option( 'akibara_popup_coupon', 'PRIMERACOMPRA10' );
	$rendered = akb_ws_render_step( $step, $email, $coupon );
	if ( empty( $rendered ) ) {
		akb_log(
			'welcome-series',
			'warn',
			'Render vacío — skip',
			array(
				'email' => $email,
				'step'  => $step,
			)
		);
		return;
	}

	$ok = AkibaraBrevo::send_transactional(
		$api_key,
		$email,
		'',
		$rendered['subject'],
		$rendered['html']
	);

	if ( $ok ) {
		akb_ws_mark_sent( $email, $step );
		akb_log(
			'welcome-series',
			'info',
			'Email enviado',
			array(
				'email' => $email,
				'step'  => $step,
			)
		);
	} else {
		akb_log(
			'welcome-series',
			'error',
			'Fallo envío Brevo',
			array(
				'email' => $email,
				'step'  => $step,
			)
		);
	}
}

/**
 * Renderiza el HTML del step usando AkibaraEmailTemplate.
 *
 * Copy refactoreado post mesa de agentes (2026-04-22):
 *  - Voz editorial con autoridad (menciona editoriales reales: Ivrea, Panini, Milky Way).
 *  - Firma con nombre real del dueño (Alejandro) vs "Equipo Akibara" genérico.
 *  - Sin "Top 3 shonen" genéricos — coleccionistas serios los ignoran.
 *  - FOMO honesto, sin drama, con opción dignified de opt-out.
 *
 * Hook `akb_ws_render_step_html` permite sobrescribir por segmento a futuro
 * (shonen/seinen/shojo/cómics) cuando la base crezca a ~2000 clientes.
 *
 * @param int    $step   1|2|3
 * @param string $email  Destinatario (para unsub token).
 * @param string $coupon Cupón activo (PRIMERACOMPRA10 u override).
 * @return array{subject:string, html:string}|array{}
 */
function akb_ws_render_step( int $step, string $email, string $coupon ): array {
	$T = 'AkibaraEmailTemplate';

	$home   = home_url( '/' );
	$tienda = home_url( '/tienda/' );

	switch ( $step ) {
		case 1:
			$subject = 'Tu 10% está activo (y algo más importante)';
			$html    = $T::build(
				'Bienvenido al distrito · tu cupón PRIMERACOMPRA10 está activo por 72h.',
				function () use ( $T, $coupon, $tienda ) {
					$out  = $T::headline( 'Bienvenido al distrito' );
					$out .= $T::paragraph( 'Antes de que uses el cupón, te contamos cómo funciona esto.' );
					$out .= $T::paragraph( 'Akibara no es tienda grande. Somos un equipo chico revisando cada caja de <strong>Ivrea, Panini y Milky Way</strong> antes que salga. Si pediste un tomo y llegó con el lomo marcado, lo sabemos antes que tú — y no sale.' );
					$out .= $T::paragraph( 'Eso significa dos cosas: a veces demoramos un día más que los grandes. Pero el tomo que te llega es el tomo que tú elegirías en la librería.' );
					$out .= $T::coupon_box( $coupon, 'Tu 10% de bienvenida' );
					$out .= $T::paragraph( '<strong>Válido 72 horas</strong>. No lo extendemos — no porque seamos malos, sino porque el margen real del manga licenciado en Chile es lo que es y preferimos ser honestos.', 'center' );
					$out .= $T::cta( 'Ver tienda', $tienda, 'welcome-1' );
					$out .= $T::paragraph( '¿Andas perdido entre tanto tomo nuevo? Respóndenos este correo con qué lees (o qué <em>querías</em> leer y nunca partiste). Te armamos una lista rápido.', 'center' );
					$out .= $T::signature();
					return $out;
				},
				$email,
				'akb_welcome_unsub'
			);
			break;

		case 2:
			$subject = 'Lo que viene en preventa (y tu 10% sigue vivo)';
			$html    = $T::build(
				'Preventas confirmadas · el calendario que no encuentras en otras tiendas.',
				function () use ( $T, $coupon, $tienda ) {
					$out  = $T::headline( 'Lo que llega en los próximos 60 días' );
					$out .= $T::paragraph( 'La info que más nos piden los coleccionistas: <strong>cuándo llegan los próximos tomos</strong>. Acá te dejamos el mapa.' );
					$out .= $T::paragraph( '<strong>Abril-Mayo:</strong> revisa tu wishlist — los últimos Ivrea de la temporada entran en preventa 72-96h antes que el público general. Tu 10% aplica en preventa también.' );
					$out .= $T::paragraph( '<strong>Junio:</strong> Panini Chile confirma lote mensual (spoiler: hay seinen fuerte).' );
					$out .= $T::cta( 'Ver preventas activas', home_url( '/preventas/' ), 'welcome-2' );
					$out .= $T::paragraph( 'No te mandamos "Top 3 shonen del mes" porque eso lo ves en cualquier Instagram. Acá va la info que de verdad sirve para armar tu colección.', 'center' );
					$out .= $T::paragraph( 'Tu cupón <strong>' . esc_html( $coupon ) . '</strong> sigue activo.', 'center' );
					$out .= $T::signature();
					return $out;
				},
				$email,
				'akb_welcome_unsub'
			);
			break;

		case 3:
		default:
			$subject = 'Tu 10% vence hoy (sin drama)';
			$html    = $T::build(
				'Tu cupón expira en menos de 24 horas. Si no lo usas, no pasa nada.',
				function () use ( $T, $coupon, $tienda ) {
					$out  = $T::headline( 'Tu 10% vence hoy' );
					$out .= $T::paragraph( 'Sin drama, solo el dato: <strong>' . esc_html( $coupon ) . '</strong> expira en ~24 horas.' );
					$out .= $T::paragraph( 'No te vamos a decir "última oportunidad" porque vamos a tener más promos. Pero este 10% específico, para tu primera compra, no vuelve.' );
					$out .= $T::paragraph( 'Si tenías un tomo en la mira — ese que miraste tres veces esta semana — ahora es el momento.', 'center' );
					$out .= $T::cta( 'Ir a la tienda', $tienda, 'welcome-3' );
					$out .= $T::paragraph( '¿No es el momento? No pasa nada. Quédate en la lista — avisamos preventas de Ivrea y Milky Way antes que nadie, y tenemos alertas cuando un tomo agotado vuelve al stock.', 'center' );
					$out .= $T::signature();
					return $out;
				},
				$email,
				'akb_welcome_unsub'
			);
			break;
	}

	// Filter para segmentación futura (cuando crezca la base).
	$html = apply_filters( 'akb_ws_render_step_html', $html, $step, $email, $coupon );

	return array(
		'subject' => $subject,
		'html'    => $html,
	);
}
