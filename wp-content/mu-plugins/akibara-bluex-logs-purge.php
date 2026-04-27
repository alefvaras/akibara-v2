<?php
/**
 * Plugin Name: Akibara BlueX Logs Purge
 * Description: Cron mensual que elimina filas de wp_bluex_logs > 30 días. Mitiga el riesgo de exposición histórica de la API key BlueX (que el plugin upstream loggea plain-text en cada request — issue upstream, ver disclosure). Cubre B-S1-SEC-04 del Sprint 1.
 * Version: 1.0.0
 * Author: Akibara
 * Requires PHP: 8.1
 *
 * Diseño
 * ------
 * - Hook: cron event `akb_bluex_logs_purge`, recurrence `monthly` (~30 días).
 * - Acción: `DELETE FROM wp_bluex_logs WHERE log_timestamp < NOW() - INTERVAL 30 DAY`.
 * - Idempotente: re-ejecuciones son seguras. Si la tabla está vacía o no existe,
 *   se sale silencioso.
 * - Scheduling: registra en activación del mu-plugin (primer load) y se mantiene.
 *   wp_unschedule_event en deactivation no aplica (mu-plugins no se desactivan).
 *
 * Por qué mensual y no diario
 * ---------------------------
 * Logs son útiles para debug shipping issues (BlueX a veces necesita 7-14 días
 * para resolver tickets de paquetes perdidos/dañados). Retention 30 días balancea
 * exposición vs operabilidad.
 *
 * Si se quiere reducir retention en el futuro:
 *   - Editar `AKB_BLUEX_LOGS_RETENTION_DAYS` abajo.
 *   - Cambiar recurrence a `weekly` o `daily`.
 *
 * Rollback
 * --------
 * - Renombrar a `.disabled` o eliminar archivo.
 * - El cron event queda huérfano hasta el próximo `wp cron event unschedule`.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

const AKB_BLUEX_LOGS_RETENTION_DAYS = 30;
const AKB_BLUEX_LOGS_PURGE_HOOK     = 'akb_bluex_logs_purge';

// ═══════════════════════════════════════════════════════════════════════════
// Schedule cron en cada load del mu-plugin (idempotente — wp_schedule_event
// solo agenda si no existe ya).
// ═══════════════════════════════════════════════════════════════════════════
add_action(
	'init',
	static function (): void {
		if ( ! wp_next_scheduled( AKB_BLUEX_LOGS_PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'monthly', AKB_BLUEX_LOGS_PURGE_HOOK );
		}
	}
);

// ═══════════════════════════════════════════════════════════════════════════
// Custom recurrence "monthly" (WP core no la trae por default).
// ═══════════════════════════════════════════════════════════════════════════
add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = [
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'akibara' ),
			];
		}
		return $schedules;
	}
);

// ═══════════════════════════════════════════════════════════════════════════
// Cron handler: delete rows older than retention.
// ═══════════════════════════════════════════════════════════════════════════
add_action(
	AKB_BLUEX_LOGS_PURGE_HOOK,
	static function (): void {
		global $wpdb;

		$table = $wpdb->prefix . 'bluex_logs';

		// Sanity check: tabla existe.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		if ( ! $exists ) {
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'bluex-logs-purge', 'warn', 'Tabla wp_bluex_logs no existe — skip', [] );
			}
			return;
		}

		// Delete rows > retention.
		$days    = AKB_BLUEX_LOGS_RETENTION_DAYS;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}bluex_logs WHERE log_timestamp < (NOW() - INTERVAL %d DAY)",
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( function_exists( 'akb_log' ) ) {
			akb_log(
				'bluex-logs-purge',
				'info',
				sprintf( 'Purged %d rows >%d days from wp_bluex_logs', (int) $deleted, $days ),
				[ 'deleted' => (int) $deleted, 'retention_days' => $days ]
			);
		} else {
			error_log( sprintf( '[akibara-bluex-logs-purge] Purged %d rows >%d days', (int) $deleted, $days ) );
		}
	}
);
