<?php
defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// ML ORDER SCOPE — set per-request de product_ids cuya reducción de stock
// proviene de una orden ML. Evita el loop circular (WC reduce stock por
// orden ML → hook de stock → PUT ML) SIN afectar cambios de stock
// legítimos de OTROS productos en la misma request (fix B6).
// ══════════════════════════════════════════════════════════════════

function &_akb_ml_order_scope(): array {
	static $set = array();
	return $set;
}

function akb_ml_order_scope_add( int $product_id ): void {
	$ref                = &_akb_ml_order_scope();
	$ref[ $product_id ] = true;
}

function akb_ml_order_scope_has( int $product_id ): bool {
	$ref = &_akb_ml_order_scope();
	return isset( $ref[ $product_id ] );
}

// ══════════════════════════════════════════════════════════════════
// HOOKS — WooCommerce stock sync
// ══════════════════════════════════════════════════════════════════

/**
 * Realiza la sincronización de stock directamente contra la API ML.
 * Usado tanto desde el hook síncrono (admin/cron) como desde el handler async.
 */
function akb_ml_do_stock_sync( int $product_id, int $new_stock, string $ml_id, string $ml_status, int $db_stock ): void {
	if ( $new_stock <= 0 ) {
		if ( $ml_status === 'paused' && $db_stock === 0 ) {
			return;
		}
		$resp = akb_ml_request( 'PUT', "/items/{$ml_id}", array( 'status' => 'paused' ) );
		if ( isset( $resp['error'] ) ) {
			akb_ml_log( 'sync', "Error pausando {$ml_id}: " . $resp['error'], 'warning' );
			return;
		}
		akb_ml_db_upsert(
			$product_id,
			array(
				'ml_item_id' => $ml_id,
				'ml_status'  => 'paused',
				'ml_stock'   => 0,
			)
		);
	} else {
		if ( $ml_status === 'active' && $db_stock === $new_stock ) {
			return;
		}
		$payload = array( 'available_quantity' => $new_stock );
		if ( $ml_status === 'paused' ) {
			$payload['status'] = 'active';
		}
		$resp = akb_ml_request( 'PUT', "/items/{$ml_id}", $payload );
		if ( ! isset( $resp['error'] ) ) {
			akb_ml_db_upsert(
				$product_id,
				array(
					'ml_item_id' => $ml_id,
					'ml_status'  => $resp['status'] ?? $ml_status,
					'ml_stock'   => $new_stock,
				)
			);
		}
	}
}

// Handler async para sincronización de stock desde contexto frontend (checkout del cliente).
// Evita añadir latencia a la petición HTTP del comprador haciendo la llamada ML en background.
add_action(
	'akb_ml_sync_stock_async',
	static function ( int $product_id, int $new_stock, string $ml_id, string $ml_status, int $db_stock ): void {
		akb_ml_do_stock_sync( $product_id, $new_stock, $ml_id, $ml_status, $db_stock );
	},
	10,
	5
);

add_action(
	'woocommerce_product_set_stock',
	static function ( WC_Product $product ): void {
		if ( ! akb_ml_opt( 'auto_sync_stock', true ) ) {
			return;
		}
		// Guard per-product: evitar loop circular cuando WC reduce stock por orden ML
		// (sólo bloquea el producto específico de la orden ML, no todos)
		if ( akb_ml_order_scope_has( $product->get_id() ) ) {
			return;
		}

		$row = akb_ml_db_row( $product->get_id() );
		if ( ! $row || empty( $row['ml_item_id'] ) || $row['ml_status'] === 'error' ) {
			return;
		}

		$new_stock = (int) $product->get_stock_quantity();
		$ml_id     = $row['ml_item_id'];
		$ml_status = $row['ml_status'];
		$db_stock  = (int) $row['ml_stock'];

		// En contexto frontend (checkout del cliente): enqueue async para no bloquear la respuesta.
		// En admin o cron: sincronización directa e inmediata.
		if ( ! is_admin() && ! wp_doing_cron() ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'akb_ml_sync_stock_async',
					array(
						'product_id' => $product->get_id(),
						'new_stock'  => $new_stock,
						'ml_id'      => $ml_id,
						'ml_status'  => $ml_status,
						'db_stock'   => $db_stock,
					),
					'akibara-ml'
				);
			}
			return;
		}

		akb_ml_do_stock_sync( $product->get_id(), $new_stock, $ml_id, $ml_status, $db_stock );
	}
);

add_action(
	'woocommerce_product_set_stock_status',
	static function ( int $product_id, string $status ): void {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}
		if ( ! akb_ml_opt( 'auto_publish_available', false ) ) {
			return;
		}
		if ( $status !== 'instock' ) {
			return;
		}

		// Verificar que realmente tiene stock > 0 antes de publicar
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_stock_quantity() <= 0 ) {
			return;
		}

		$row = akb_ml_db_row( $product_id );
		if ( $row && $row['ml_status'] === 'paused' ) {
			// Preventa que estaba pausada → reactivar
			akb_ml_reactivate( $product_id );
		} elseif ( ! $row || empty( $row['ml_item_id'] ) ) {
			// Producto nuevo con stock → publicar por primera vez
			akb_ml_publish( $product_id );
		}
	},
	10,
	2
);

// ══════════════════════════════════════════════════════════════════
// CRON — Sync periódico de salud
//
// Dos schedules coexisten (migrados a Action Scheduler 2026-04-25 — P-20):
// - akb_ml_health_sync:  twicedaily (~12h), 50 items en rotación (synced_at ASC)
// - akb_ml_stale_sync:   hourly,         hasta 30 items con synced_at < NOW() - 24h
//
// Con 1.400 items publicados, esto da ciclo completo ~2.5 días vs ~14 días
// del schedule único original.
//
// Migración WP-Cron → Action Scheduler:
// - Mejor reliability (retries automáticos, locking interno, logs en BD)
// - Group 'akibara-ml' para inspección/cleanup centralizado en admin
// - Cleanup legacy WP-Cron en hook init (idempotente, una sola vez)
// ══════════════════════════════════════════════════════════════════

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return; // Action Scheduler no disponible (WC inactivo) — no degradamos a wp_cron para evitar doble-ejecución.
		}

		// Cleanup one-shot del legacy wp_cron (residuos de versiones previas a P-20).
		if ( ! get_option( 'akb_ml_as_migration_done', false ) ) {
			wp_clear_scheduled_hook( 'akb_ml_health_sync' );
			wp_clear_scheduled_hook( 'akb_ml_stale_sync' );
			wp_clear_scheduled_hook( 'akb_ml_retry_errors' );
			update_option( 'akb_ml_as_migration_done', '1.0', false );
		}

		if ( false === as_next_scheduled_action( 'akb_ml_health_sync', array(), 'akibara-ml' ) ) {
			as_schedule_recurring_action( time(), 12 * HOUR_IN_SECONDS, 'akb_ml_health_sync', array(), 'akibara-ml' );
		}
		if ( false === as_next_scheduled_action( 'akb_ml_stale_sync', array(), 'akibara-ml' ) ) {
			as_schedule_recurring_action( time() + 1800, HOUR_IN_SECONDS, 'akb_ml_stale_sync', array(), 'akibara-ml' );
		}
	}
);

/**
 * Ejecuta un batch de health sync contra ML.
 *
 * @param array $args {
 *   @type string $lock       Nombre del lock a tomar (default: 'health_sync')
 *   @type int    $limit      Máximo de items a procesar (default: 50)
 *   @type bool   $only_stale Si true, solo items con synced_at < NOW() - 24h (default: false)
 *   @type int    $lock_ttl   TTL del lock en segundos (default: 600)
 * }
 */
function akb_ml_run_health_batch( array $args = array() ): void {
	$defaults = array(
		'lock'       => 'health_sync',
		'limit'      => 50,
		'only_stale' => false,
		'lock_ttl'   => 600,
	);
	$args     = array_merge( $defaults, $args );

	if ( empty( akb_ml_opt( 'access_token' ) ) ) {
		return;
	}

	if ( ! akb_ml_acquire_lock( $args['lock'], (int) $args['lock_ttl'] ) ) {
		akb_ml_log( 'health', "Sync '{$args['lock']}' ya en ejecución (lock activo). Abortando." );
		return;
	}

	try {
		global $wpdb;
		$limit        = max( 1, min( 200, (int) $args['limit'] ) );
		$stale_clause = $args['only_stale']
			? 'AND (synced_at IS NULL OR synced_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))'
			: '';
		$items        = $wpdb->get_results(
			"SELECT product_id, ml_item_id, ml_status, ml_stock
             FROM {$wpdb->prefix}akb_ml_items
             WHERE ml_item_id != '' AND ml_status IN ('active','paused')
             {$stale_clause}
             ORDER BY synced_at ASC
             LIMIT {$limit}",
			ARRAY_A
		);
		if ( empty( $items ) ) {
			return;
		}

		$seller_id = akb_ml_get_seller_id();
		if ( ! $seller_id ) {
			return;
		}

		// Cache prime: 1 query bulk en lugar de N queries posts + N queries meta dentro del loop.
		// Cada iteración invoca wc_get_product() (línea ~303) y update_post_meta() (línea ~285),
		// que triggerean lookups individuales si los posts no están en object cache.
		// Para batch=50 ahorra ~100 queries por sync run.
		$product_ids = array_map( static fn( $item ) => (int) $item['product_id'], $items );
		if ( ! empty( $product_ids ) ) {
			_prime_post_caches( $product_ids, true, true ); // posts + termcache + metacache
		}

		foreach ( $items as $idx => $item ) {
			if ( $idx > 0 ) {
				usleep( 200000 ); // 200ms entre llamadas API para evitar rate limit
			}
			$resp = akb_ml_request( 'GET', '/items/' . $item['ml_item_id'] );
			if ( isset( $resp['error'] ) ) {
				// Item no existe en ML (404) → limpiar registro
				if ( ( $resp['code'] ?? 0 ) === 404 ) {
					akb_ml_db_upsert(
						(int) $item['product_id'],
						array(
							'ml_item_id'   => '',
							'ml_status'    => '',
							'ml_price'     => 0,
							'ml_stock'     => 0,
							'ml_permalink' => '',
							'error_msg'    => null,
						)
					);
					akb_ml_log( 'health', 'Item ' . $item['ml_item_id'] . ' no existe en ML → registro limpiado' );
				}
				continue;
			}

			$ml_status = $resp['status'] ?? $item['ml_status'];
			$ml_stock  = (int) ( $resp['available_quantity'] ?? $item['ml_stock'] );

			// Item cerrado/eliminado en ML → guardar ID para relist y limpiar registro
			$sub_status = $resp['sub_status'] ?? array();
			if ( $ml_status === 'closed' || in_array( 'deleted', $sub_status, true ) ) {
				if ( $ml_status === 'closed' && ! in_array( 'deleted', $sub_status, true ) ) {
					update_post_meta( (int) $item['product_id'], '_akb_ml_closed_id', $item['ml_item_id'] );
				}
				akb_ml_db_upsert(
					(int) $item['product_id'],
					array(
						'ml_item_id'   => '',
						'ml_status'    => '',
						'ml_price'     => 0,
						'ml_stock'     => 0,
						'ml_permalink' => '',
						'error_msg'    => null,
					)
				);
				akb_ml_log( 'health', 'Item ' . $item['ml_item_id'] . ' cerrado/eliminado → registro limpiado' );
				continue;
			}

			// Verificar stock WC vs ML
			$product  = wc_get_product( (int) $item['product_id'] );
			$wc_stock = $product ? (int) $product->get_stock_quantity() : 0;

			$update = array(
				'ml_item_id'   => $item['ml_item_id'],
				'ml_status'    => $ml_status,
				'ml_stock'     => $ml_stock,
				'ml_permalink' => $resp['permalink'] ?? '',
			);

			// Stock fantasma: activo en ML pero sin stock en WC
			if ( $ml_status === 'active' && $wc_stock <= 0 ) {
				akb_ml_request( 'PUT', '/items/' . $item['ml_item_id'], array( 'status' => 'paused' ) );
				$update['ml_status'] = 'paused';
				$update['ml_stock']  = 0;
				akb_ml_log( 'health', 'Pausado ' . $item['ml_item_id'] . ' — sin stock WC' );
			}
			// Stock disponible pero pausado en ML (no por moderación)
			elseif ( $ml_status === 'paused' && $wc_stock > 0 ) {
				$sub = $resp['sub_status'] ?? array();
				if ( empty( $sub ) || $sub === array( 'out_of_stock' ) ) {
					akb_ml_request(
						'PUT',
						'/items/' . $item['ml_item_id'],
						array(
							'status'             => 'active',
							'available_quantity' => $wc_stock,
						)
					);
					$update['ml_status'] = 'active';
					$update['ml_stock']  = $wc_stock;
					akb_ml_log( 'health', 'Reactivado ' . $item['ml_item_id'] . ' — stock WC=' . $wc_stock );
				}
			}
			// Stock discrepante: ML tiene diferente cantidad que WC
			elseif ( $ml_status === 'active' && $wc_stock > 0 && $ml_stock !== $wc_stock ) {
				akb_ml_request( 'PUT', '/items/' . $item['ml_item_id'], array( 'available_quantity' => $wc_stock ) );
				$update['ml_stock'] = $wc_stock;
			}

			akb_ml_db_upsert( (int) $item['product_id'], $update );

			// ── Quality sync liviano: detectar SOLO discrepancias reales ──
			// La heurística anterior (wc_attr_est > ml_attr_count) disparaba rebuilds
			// innecesarios por sobreestimar atributos opcionales → gastaba cuota API.
			// Ahora sólo se reconstruye cuando hay un cambio verificable en shipping.
			if ( $product && in_array( $ml_status, array( 'active', 'paused' ), true ) ) {
				$ml_price_now = (int) ( $resp['price'] ?? 0 );
				$ml_free      = (bool) ( $resp['shipping']['free_shipping'] ?? false );
				$wc_free      = ( $ml_price_now >= AKB_ML_FREE_SHIPPING_THRESHOLD );

				$needs_quality_update = ( $ml_free !== $wc_free );

				if ( $needs_quality_update ) {
					// Solo actualizar shipping — no necesitamos reconstruir attributes completos.
					$q_resp = akb_ml_request(
						'PUT',
						'/items/' . $item['ml_item_id'],
						array(
							'shipping' => array( 'free_shipping' => $wc_free ),
						)
					);
					if ( ! isset( $q_resp['error'] ) ) {
						akb_ml_log(
							'health',
							sprintf(
								'Quality update %s: free_ship %s→%s',
								$item['ml_item_id'],
								$ml_free ? 'sí' : 'no',
								$wc_free ? 'sí' : 'no'
							)
						);
					} else {
						akb_ml_log( 'health', 'Quality update falló para ' . $item['ml_item_id'] . ': ' . $q_resp['error'] );
					}
					usleep( 200000 );
				}
			}
		}

		akb_ml_log(
			'health',
			sprintf(
				"Sync '%s' completado: %d items verificados%s",
				$args['lock'],
				count( $items ),
				$args['only_stale'] ? ' (modo stale)' : ''
			)
		);
	} finally {
		akb_ml_release_lock( $args['lock'] );
	}
}

// Schedule twicedaily: rota por todos los items (50 por run)
add_action(
	'akb_ml_health_sync',
	static function (): void {
		akb_ml_run_health_batch(
			array(
				'lock'       => 'health_sync',
				'limit'      => 50,
				'only_stale' => false,
			)
		);
	}
);

// Schedule hourly: solo items con synced_at > 24h atrás (máx 30 por run)
add_action(
	'akb_ml_stale_sync',
	static function (): void {
		akb_ml_run_health_batch(
			array(
				'lock'       => 'stale_sync',
				'limit'      => 30,
				'only_stale' => true,
				'lock_ttl'   => 300,
			)
		);
	}
);

// ══════════════════════════════════════════════════════════════════
// PRICE SYNC — actualizar precio ML cuando cambia en WC
//
// El hook de stock ya sincroniza al instante. El de precio no existía:
// los cambios de precio solo llegaban a ML cuando el health cron
// rotaba por ese item (podía tardar 2-3 días con catálogos grandes).
// ══════════════════════════════════════════════════════════════════

add_action(
	'woocommerce_after_product_object_save',
	static function ( WC_Product $product ): void {
		if ( ! akb_ml_opt( 'auto_sync_stock', true ) ) {
			return;
		}

		$row = akb_ml_db_row( $product->get_id() );
		if ( ! $row || empty( $row['ml_item_id'] ) || ! in_array( $row['ml_status'], array( 'active', 'paused' ), true ) ) {
			return;
		}

		$override  = (int) ( $row['ml_price_override'] ?? 0 );
		$new_price = akb_ml_calculate_price( (float) $product->get_price(), $override );

		// Solo sincronizar si el precio cambió realmente
		if ( $new_price === (int) $row['ml_price'] ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				'akb_ml_sync_price_async',
				array(
					'product_id' => $product->get_id(),
					'ml_id'      => $row['ml_item_id'],
					'new_price'  => $new_price,
				),
				'akibara-ml'
			);
		}
	}
);

add_action(
	'akb_ml_sync_price_async',
	static function ( int $product_id, string $ml_id, int $new_price ): void {
		$resp = akb_ml_request(
			'PUT',
			"/items/{$ml_id}",
			array(
				'price'    => $new_price,
				'shipping' => array( 'free_shipping' => ( $new_price >= AKB_ML_FREE_SHIPPING_THRESHOLD ) ),
			)
		);
		if ( ! isset( $resp['error'] ) ) {
			akb_ml_db_upsert(
				$product_id,
				array(
					'ml_item_id' => $ml_id,
					'ml_price'   => $new_price,
				)
			);
			// Invalidar cache del payload para que el próximo health rebuild incluya el nuevo precio
			delete_transient( 'akb_ml_item_' . $product_id );
			akb_ml_log( 'price', "Precio actualizado: {$ml_id} → \${$new_price} CLP (producto #{$product_id})" );
		} else {
			akb_ml_log( 'price', "Error actualizando precio {$ml_id}: " . $resp['error'], 'warning' );
		}
	},
	10,
	3
);

// ══════════════════════════════════════════════════════════════════
// AUTO-RETRY — reintentar items en error (daily, máx 20 por run)
//
// Items con ml_status='error' se quedan atascados si nadie los republica.
// Este cron los reintenta automáticamente una vez al día.
// Si siguen fallando, el error_msg se actualiza con el nuevo motivo.
//
// Migrado a Action Scheduler 2026-04-25 (P-20). Cleanup legacy
// wp_clear_scheduled_hook('akb_ml_retry_errors') corre en el hook init
// del bloque CRON arriba (one-shot via opción akb_ml_as_migration_done).
// ══════════════════════════════════════════════════════════════════

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		if ( false === as_next_scheduled_action( 'akb_ml_retry_errors', array(), 'akibara-ml' ) ) {
			as_schedule_recurring_action( time() + 3600, DAY_IN_SECONDS, 'akb_ml_retry_errors', array(), 'akibara-ml' );
		}
	}
);

add_action(
	'akb_ml_retry_errors',
	static function (): void {
		if ( empty( akb_ml_opt( 'access_token' ) ) ) {
			return;
		}
		if ( ! akb_ml_acquire_lock( 'retry_errors', 1800 ) ) {
			return;
		}

		try {
			global $wpdb;
			$items = $wpdb->get_results(
				"SELECT product_id FROM {$wpdb->prefix}akb_ml_items
             WHERE ml_status = 'error'
             ORDER BY synced_at ASC
             LIMIT 20",
				ARRAY_A
			);
			if ( empty( $items ) ) {
				return;
			}

			$ok = $fail = 0;
			foreach ( $items as $item ) {
				usleep( 500000 ); // 500ms entre reintentos
				$result = akb_ml_publish( (int) $item['product_id'] );
				if ( isset( $result['error'] ) ) {
					$fail++;
				} else {
					$ok++;
				}
			}
			akb_ml_log( 'retry', sprintf( 'Auto-retry completado: %d ok, %d siguen en error de %d intentados', $ok, $fail, count( $items ) ) );
		} finally {
			akb_ml_release_lock( 'retry_errors' );
		}
	}
);

// ══════════════════════════════════════════════════════════════════
// DEACTIVATION CLEANUP — limpieza del cron ML al desactivar el plugin
//
// Complementa wp_clear_scheduled_hook() en akibara.php (legacy WP-Cron)
// con as_unschedule_all_actions() para los 3 hooks recurrentes migrados
// a Action Scheduler en P-20 (2026-04-25).
//
// Múltiples register_deactivation_hook() son aditivos en WP — este se
// ejecuta junto al de akibara.php sin solapamiento.
// ══════════════════════════════════════════════════════════════════
// Deactivation cleanup is handled by the entry point
// akibara-mercadolibre/akibara-mercadolibre.php register_deactivation_hook().
// This file no longer registers its own hook to avoid double-cleanup and
// to decouple from the legacy akibara monolith path.
