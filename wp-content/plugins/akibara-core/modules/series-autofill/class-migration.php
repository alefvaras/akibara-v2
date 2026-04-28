<?php
/**
 * Akibara — Series Autofill: Migración histórica en chunks via Action Scheduler.
 *
 * Diseño:
 *  - `enqueue_all()` escanea productos sin `_akibara_serie` y encola un job
 *    por chunk de 50 IDs, espaciados 60s para no saturar.
 *  - `process_chunk()` procesa cada chunk: extrae serie por extractor y la
 *    escribe en `_akibara_serie` + `_akibara_serie_norm`.
 *  - Tracking de progreso en wp_options (`akibara_series_migration_status`).
 *
 * Idempotente: si un producto ya tiene serie, se salta (no sobrescribe).
 * Resiliente: errores por producto no abortan el chunk.
 */

namespace Akibara\SeriesAutofill;

defined( 'ABSPATH' ) || exit;

class Migration {

	public const ACTION_HOOK  = 'akibara_series_migrate_chunk';
	public const OPTION_STATS = 'akibara_series_migration_status';
	public const CHUNK_SIZE   = 50;
	public const CHUNK_DELAY  = 60; // segundos entre chunks

	/**
	 * Encola jobs para todos los productos sin serie.
	 *
	 * @param bool $include_with_serie Si true, re-procesa también productos con serie existente.
	 * @return array Stats: total, chunks, first_scheduled_at.
	 */
	public static function enqueue_all( bool $include_with_serie = false ): array {
		global $wpdb;

		$sql = "
			SELECT p.ID
			FROM {$wpdb->posts} p
			" . ( ! $include_with_serie
				? "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_akibara_serie'"
				: '' ) . "
			WHERE p.post_type='product' AND p.post_status IN ('publish','draft','private')
			" . ( ! $include_with_serie
				? "AND (pm.meta_value IS NULL OR pm.meta_value='')"
				: '' ) . '
			ORDER BY p.ID ASC
		';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Hardcoded query: only WP core tables ({$wpdb->posts}, {$wpdb->postmeta}) and trusted bool flag $include_with_serie. No user input.
		$ids = $wpdb->get_col( $sql );
		$ids = array_map( 'intval', $ids );
		$ids = array_filter( $ids );

		$total = count( $ids );
		if ( $total === 0 ) {
			self::update_status(
				array(
					'status'       => 'idle',
					'total'        => 0,
					'processed'    => 0,
					'filled'       => 0,
					'skipped'      => 0,
					'chunks_total' => 0,
					'chunks_done'  => 0,
					'started_at'   => 0,
					'last_run_at'  => 0,
				)
			);
			return array(
				'total'              => 0,
				'chunks'             => 0,
				'first_scheduled_at' => 0,
			);
		}

		// Limpiar jobs previos
		self::clear_scheduled();

		$chunks = array_chunk( $ids, self::CHUNK_SIZE );
		$now    = time();

		foreach ( $chunks as $i => $chunk_ids ) {
			as_schedule_single_action(
				$now + ( $i * self::CHUNK_DELAY ),
				self::ACTION_HOOK,
				array( $chunk_ids ),
				'akibara-series-migration'
			);
		}

		self::update_status(
			array(
				'status'       => 'running',
				'total'        => $total,
				'processed'    => 0,
				'filled'       => 0,
				'skipped'      => 0,
				'chunks_total' => count( $chunks ),
				'chunks_done'  => 0,
				'started_at'   => $now,
				'last_run_at'  => 0,
			)
		);

		return array(
			'total'              => $total,
			'chunks'             => count( $chunks ),
			'first_scheduled_at' => $now,
		);
	}

	/**
	 * Cancela todos los jobs pendientes de migración.
	 */
	public static function clear_scheduled(): int {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return 0;
		}
		return (int) as_unschedule_all_actions( self::ACTION_HOOK, null, 'akibara-series-migration' );
	}

	/**
	 * Procesa un chunk de IDs. Hook callback del Action Scheduler.
	 *
	 * @param int[] $product_ids IDs de productos a procesar.
	 */
	public static function process_chunk( array $product_ids ): void {
		$filled  = 0;
		$skipped = 0;

		foreach ( $product_ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) {
				continue;
			}

			try {
				$result = self::process_single( $pid );
				if ( $result === 'filled' ) {
					++$filled;
				} elseif ( $result === 'skipped' ) {
					++$skipped;
				}
			} catch ( \Throwable $e ) {
				// Loguear pero no abortar
				error_log( '[akibara/series-autofill] Error procesando #' . $pid . ': ' . $e->getMessage() );
				++$skipped;
			}
		}

		// Actualizar progreso atómicamente
		$stats                = self::get_status();
		$stats['processed']   = (int) $stats['processed'] + count( $product_ids );
		$stats['filled']      = (int) $stats['filled'] + $filled;
		$stats['skipped']     = (int) $stats['skipped'] + $skipped;
		$stats['chunks_done'] = (int) $stats['chunks_done'] + 1;
		$stats['last_run_at'] = time();

		if ( $stats['chunks_done'] >= $stats['chunks_total'] ) {
			$stats['status'] = 'completed';
		}

		self::update_status( $stats );
	}

	/**
	 * Procesa un solo producto: extrae serie y la escribe si está vacía.
	 *
	 * @return string 'filled' | 'skipped'
	 */
	public static function process_single( int $product_id ): string {
		$post = get_post( $product_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return 'skipped';
		}

		// Skip si ya tiene serie (idempotente)
		$existing = get_post_meta( $product_id, '_akibara_serie', true );
		if ( $existing !== '' && $existing !== false ) {
			return 'skipped';
		}

		$number = (string) get_post_meta( $product_id, '_akibara_numero', true );
		$brands = get_the_terms( $product_id, 'product_brand' );
		$brand  = ( $brands && ! is_wp_error( $brands ) && ! empty( $brands[0] ) ) ? $brands[0]->name : '';

		$serie = Extractor::extract_serie( $post->post_title, $number, $brand );

		if ( $serie === '' ) {
			return 'skipped';
		}

		update_post_meta( $product_id, '_akibara_serie', $serie );
		update_post_meta( $product_id, '_akibara_serie_norm', Extractor::normalize_serie_slug( $serie ) );

		// Sincronizar term pa_serie (ver module.php::sync_pa_serie_term).
		\Akibara\SeriesAutofill\sync_pa_serie_term( $product_id, $serie );

		// Si no había número y se pudo extraer, también guardarlo
		if ( $number === '' || $number === '0' ) {
			$num = Extractor::extract_numero( $post->post_title );
			if ( $num !== '' ) {
				update_post_meta( $product_id, '_akibara_numero', $num );
			}
		}

		return 'filled';
	}

	/**
	 * Obtiene el estado actual de la migración.
	 */
	public static function get_status(): array {
		$defaults = array(
			'status'       => 'idle', // idle | running | completed
			'total'        => 0,
			'processed'    => 0,
			'filled'       => 0,
			'skipped'      => 0,
			'chunks_total' => 0,
			'chunks_done'  => 0,
			'started_at'   => 0,
			'last_run_at'  => 0,
		);
		$saved    = get_option( self::OPTION_STATS, array() );
		return array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Actualiza el estado persistente.
	 */
	public static function update_status( array $stats ): void {
		update_option( self::OPTION_STATS, $stats, false );
	}

	/**
	 * Registra el hook de Action Scheduler.
	 */
	public static function register_hooks(): void {
		add_action( self::ACTION_HOOK, array( __CLASS__, 'process_chunk' ), 10, 1 );
	}
}
