<?php
/**
 * EncargosPendientes widget — encargos sin fulfillment.
 *
 * Data source: wp_option 'akibara_encargos_log' (written by akibara-preventas).
 * Prod known values: Jujutsu kaisen 24 + 26.
 *
 * @package Akibara\Marketing\Finance\Widgets
 */

declare(strict_types=1);

namespace Akibara\Marketing\Finance\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Returns list of pending special orders (encargos) without fulfillment.
 *
 * Reads from 'akibara_encargos_log' option — same source as akibara-preventas
 * EncargosModule (read-only access, no writes here).
 */
final class EncargosPendientes {

	private const OPTION_KEY = 'akibara_encargos_log';
	private const CACHE_KEY  = 'akb_fin_encargos_pending';
	private const CACHE_TTL  = 5 * MINUTE_IN_SECONDS;

	/**
	 * Fetch pending encargos.
	 *
	 * @return array<int,array{title:string,qty:int,status:string,date:string}>
	 */
	public function fetch(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$log    = (array) get_option( self::OPTION_KEY, array() );
		$result = array();

		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// Only include entries without fulfillment.
			$status = (string) ( $entry['status'] ?? 'pending' );
			if ( in_array( $status, array( 'fulfilled', 'cancelled' ), true ) ) {
				continue;
			}
			$result[] = array(
				'title'  => (string) ( $entry['title'] ?? $entry['producto'] ?? '' ),
				'qty'    => (int) ( $entry['qty'] ?? $entry['cantidad'] ?? 1 ),
				'status' => $status,
				'date'   => (string) ( $entry['date'] ?? $entry['fecha'] ?? '' ),
			);
		}

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Invalidate cache — call when encargos log is updated.
	 */
	public function invalidate(): void {
		delete_transient( self::CACHE_KEY );
	}
}
