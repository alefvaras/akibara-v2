<?php
defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// DB — wp_akb_ml_items
// ══════════════════════════════════════════════════════════════════

function akb_ml_create_table(): void {
	global $wpdb;
	$table   = $wpdb->prefix . 'akb_ml_items';
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id  BIGINT UNSIGNED NOT NULL,
        ml_item_id  VARCHAR(20)  NOT NULL DEFAULT '',
        ml_status   VARCHAR(20)  NOT NULL DEFAULT '',
        ml_price    BIGINT UNSIGNED DEFAULT 0,
        ml_price_override BIGINT UNSIGNED DEFAULT 0,
        ml_stock    INT DEFAULT 0,
        ml_permalink VARCHAR(255) NOT NULL DEFAULT '',
        synced_at   DATETIME DEFAULT NULL,
        error_msg   TEXT DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY   product_id (product_id),
        KEY          ml_item_id (ml_item_id)
    ) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Migración DB explícita — no depende de dbDelta para ALTER TABLE.
 * Verifica columnas existentes y agrega las faltantes.
 */
function akb_ml_migrate_db(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'akb_ml_items';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table = $wpdb->prefix . 'akb_ml_items'; $col/$definition vienen de array literal local (sin user input). Migration DDL.
	// Verificar que la tabla existe
	if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) ) {
		akb_ml_create_table();
		return;
	}

	$existing = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );

	$columns = array(
		'ml_permalink'      => "VARCHAR(255) NOT NULL DEFAULT '' AFTER ml_stock",
		'ml_price_override' => 'BIGINT UNSIGNED DEFAULT 0 AFTER ml_price',
	);

	foreach ( $columns as $col => $definition ) {
		if ( ! in_array( $col, $existing, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$col} {$definition}" );
			akb_ml_log( 'db', "Columna {$col} agregada a {$table}" );
		}
	}

	// Índice en synced_at — admin filtra/ordena por "última sincronización"
	// y el scan full-table costaba ~150ms con 5k productos.
	$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'synced_at'" );
	if ( empty( $indexes ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD INDEX synced_at (synced_at)" );
		akb_ml_log( 'db', "Índice synced_at agregado a {$table}" );
	}
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ── Lock atómico con TTL (inspirado por MeliConnect) ────────────────────────
function akb_ml_acquire_lock( string $name, int $ttl = 300 ): bool {
	$option   = 'akb_ml_lock_' . $name;
	$existing = get_option( $option );

	// Lock expirado → liberar
	if ( $existing && ( time() - (int) $existing ) > $ttl ) {
		delete_option( $option );
		akb_ml_log( 'lock', "Lock {$name} expirado (TTL {$ttl}s) → liberado" );
	}

	// Intento atómico (add_option falla si ya existe)
	return (bool) add_option( $option, (string) time(), '', 'no' );
}

function akb_ml_release_lock( string $name ): void {
	delete_option( 'akb_ml_lock_' . $name );
}

// ── DB row helpers ───────────────────────────────────────────────────────────

function akb_ml_db_row( int $product_id ): ?array {
	global $wpdb;
	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}akb_ml_items WHERE product_id = %d",
			$product_id
		),
		ARRAY_A
	) ?: null;
}

function akb_ml_db_upsert( int $product_id, array $data ): void {
	global $wpdb;
	$data['product_id'] = $product_id;
	$data['synced_at']  = current_time( 'mysql' );

	$table     = $wpdb->prefix . 'akb_ml_items';
	$col_parts = array();
	$val_parts = array();
	$upd_parts = array();
	$bindings  = array();
	foreach ( $data as $col => $val ) {
		$col_esc     = "`{$col}`";
		$col_parts[] = $col_esc;
		if ( $val === null ) {
			// Preservar NULL literal en lugar de coercionar a string vacío con %s
			$val_parts[] = 'NULL';
		} else {
			$val_parts[] = '%s';
			$bindings[]  = $val;
		}
		// product_id es UNIQUE KEY — no se actualiza en conflicto
		if ( $col !== 'product_id' ) {
			$upd_parts[] = "{$col_esc} = VALUES({$col_esc})";
		}
	}
	$sql = sprintf(
		'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
		$table,
		implode( ', ', $col_parts ),
		implode( ', ', $val_parts ),
		implode( ', ', $upd_parts )
	);
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql construido con $col_parts/$val_parts/$upd_parts (claves de $data del caller, no user input directo) + $bindings via prepare().
	$wpdb->query( empty( $bindings ) ? $sql : $wpdb->prepare( $sql, $bindings ) );

	// Post_meta bidireccional — permite buscar producto por ml_item_id sin JOIN
	if ( array_key_exists( 'ml_item_id', $data ) ) {
		$ml_id = $data['ml_item_id'];
		if ( ! empty( $ml_id ) ) {
			update_post_meta( $product_id, '_akb_ml_item_id', $ml_id );
		} else {
			delete_post_meta( $product_id, '_akb_ml_item_id' );
		}
	}
	if ( array_key_exists( 'ml_permalink', $data ) && ! empty( $data['ml_permalink'] ) ) {
		update_post_meta( $product_id, '_akb_ml_permalink', $data['ml_permalink'] );
	}
}
