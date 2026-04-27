<?php
/**
 * Envío asíncrono de emails de reserva vía Action Scheduler.
 *
 * Razón: los emails se despachaban síncronamente dentro del checkout
 * (class-reserva-orders.php:97-98). Si Brevo tiene latencia >5s el request
 * puede timeout. Esta clase encola el envío en AS y lo procesa en background.
 *
 * Feature flag: wp_option 'akibara_reservas_async_emails' (default 1).
 * Fallback automático a síncrono si AS no está disponible.
 *
 * @see docs/FASE2-RESERVAS-ASYNC.md
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Email_Queue {

	const AS_ACTION   = 'akibara_reservas_send_email';
	const AS_GROUP    = 'akibara-reservas';
	const MAX_RETRIES = 3;

	// ─── Bootstrap ───────────────────────────────────────────────

	public static function init(): void {
		add_action( self::AS_ACTION, [ __CLASS__, 'process' ], 10, 1 );
		add_filter( 'akibara_reservas_email_queue_status', [ __CLASS__, 'get_status' ] );
	}

	// ─── API pública ─────────────────────────────────────────────

	/**
	 * ¿Está habilitado el envío async?
	 * Requiere AS disponible Y opción activa.
	 *
	 * Default activado 2026-04-27 (Sprint 6 A18): el sistema async + retry +
	 * fallback automático sync (cuando AS no disponible) es robusto y elimina
	 * el blocking I/O del email durante el order completion. El admin puede
	 * desactivarlo explícitamente con `wp option update akb_reservas_async_emails 0`.
	 */
	public static function is_async_enabled(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& (bool) get_option( 'akb_reservas_async_emails', true );
	}

	/**
	 * Encolar un email para procesamiento async.
	 *
	 * @param string $email_action Hook WC del email (ej. 'akb_reserva_confirmada_email').
	 * @param int    $order_id     ID de la orden WC.
	 * @param array  $extra_args   Args adicionales para pasar al do_action (ej. product_id, fechas).
	 *                             Deben ser escalares serializables (int, string, float).
	 */
	public static function enqueue( string $email_action, int $order_id, array $extra_args = [] ): void {
		as_enqueue_async_action(
			self::AS_ACTION,
			[
				[
					'email_action' => $email_action,
					'order_id'     => $order_id,
					'attempt'      => 1,
					'extra_args'   => $extra_args,
				],
			],
			self::AS_GROUP
		);

		self::log( 'info', "Enqueued: $email_action for order $order_id" );
	}

	// ─── Handler AS ──────────────────────────────────────────────

	/**
	 * Procesa un email encolado. Llamado por Action Scheduler.
	 *
	 * @param array $args ['email_action', 'order_id', 'attempt']
	 */
	public static function process( array $args ): void {
		$email_action = $args['email_action'] ?? '';
		$order_id     = (int) ( $args['order_id'] ?? 0 );
		$attempt      = (int) ( $args['attempt'] ?? 1 );
		$extra_args   = (array) ( $args['extra_args'] ?? [] );

		if ( ! $email_action || ! $order_id ) {
			self::log( 'error', 'Invalid args: ' . wp_json_encode( $args ) );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			self::log( 'error', "Order $order_id not found for $email_action (possibly deleted)" );
			return;
		}

		// ── Detectar fallo de envío vía hook WC (síncrono durante send()) ──
		$mail_failed   = false;
		$fail_listener = static function () use ( &$mail_failed ): void {
			$mail_failed = true;
		};
		add_action( 'woocommerce_mail_failed', $fail_listener, PHP_INT_MAX );

		WC()->mailer();
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		do_action( $email_action, $order_id, $order, ...$extra_args );

		remove_action( 'woocommerce_mail_failed', $fail_listener, PHP_INT_MAX );

		// ── Resultado ────────────────────────────────────────────
		if ( ! $mail_failed ) {
			self::log( 'info', "Sent OK: $email_action order $order_id (attempt $attempt)" );
			return;
		}

		// ── Fallo: reintentar o marcar permanente ─────────────────
		self::log( 'error', "Send failed: $email_action order $order_id attempt $attempt" );

		if ( $attempt < self::MAX_RETRIES ) {
			$delay = (int) pow( 2, $attempt ) * 60; // 2min, 4min
			as_schedule_single_action(
				time() + $delay,
				self::AS_ACTION,
				[
					[
						'email_action' => $email_action,
						'order_id'     => $order_id,
						'attempt'      => $attempt + 1,
						'extra_args'   => $extra_args,
					],
				],
				self::AS_GROUP
			);
			self::log( 'info', "Retry scheduled in {$delay}s (will be attempt " . ( $attempt + 1 ) . ')' );
		} else {
			self::log(
				'critical',
				"PERMANENT FAILURE: $email_action order $order_id after $attempt attempts"
			);
			$order->add_order_note(
				sprintf(
					'[Reservas] Email "%s" falló permanentemente después de %d intentos. ' .
					'Revisar en WC → Herramientas → Acciones Programadas (grupo: akibara-reservas).',
					$email_action,
					$attempt
				)
			);
			$order->save();
		}
	}

	// ─── Observabilidad ──────────────────────────────────────────

	/**
	 * Retorna el estado actual de la queue.
	 * Disponible vía: apply_filters('akibara_reservas_email_queue_status', [])
	 *
	 * @param array $default Valor por defecto del filtro (ignorado).
	 * @return array
	 */
	public static function get_status( array $default = [] ): array {
		if ( ! class_exists( 'ActionScheduler_Store' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [ 'available' => false, 'message' => 'Action Scheduler no disponible' ];
		}

		$base = [
			'hook'     => self::AS_ACTION,
			'group'    => self::AS_GROUP,
			'per_page' => 100,
		];

		return [
			'available'    => true,
			'async_enabled' => self::is_async_enabled(),
			'pending'      => count( as_get_scheduled_actions( $base + [ 'status' => ActionScheduler_Store::STATUS_PENDING ], 'ids' ) ),
			'running'      => count( as_get_scheduled_actions( $base + [ 'status' => ActionScheduler_Store::STATUS_RUNNING ], 'ids' ) ),
			'failed'       => count( as_get_scheduled_actions( $base + [ 'status' => ActionScheduler_Store::STATUS_FAILED ], 'ids' ) ),
			'complete'     => count( as_get_scheduled_actions( $base + [ 'status' => ActionScheduler_Store::STATUS_COMPLETE ], 'ids' ) ),
		];
	}

	// ─── Logger ──────────────────────────────────────────────────

	private static function log( string $level, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, [ 'source' => 'akibara-reservas-email-queue' ] );
	}
}
