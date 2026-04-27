<?php
/**
 * Akibara Shipping — Admin UI for Order screens.
 *
 * Aporta visibilidad operativa sobre el tracking en el admin de WooCommerce:
 *   - Columna "Envío" en el listado de órdenes con badge del courier
 *     y tracking abreviado.
 *
 * HPOS-safe: usa $order->get_meta() y wc_get_page_screen_id().
 *
 * @package Akibara
 * @since   10.7.0
 * @since   10.8.0  (12 Horas removido)
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKIBARA_V10_LOADED' ) ) {
	return;
}

// ═══════════════════════════════════════════════════════════════════
// COLUMNA "Envío" en listado de órdenes (HPOS + legacy)
// ═══════════════════════════════════════════════════════════════════

/**
 * Agregar columna.
 *
 * @param array $columns Columnas actuales.
 */
function akb_shipping_admin_columns( array $columns ): array {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		// Insertar justo después de 'order_status'.
		if ( 'order_status' === $key ) {
			$new['akb_shipping'] = 'Envío';
		}
	}
	// Si 'order_status' no existe (algunos builds), insertar al final antes de 'wc_actions'.
	if ( ! isset( $new['akb_shipping'] ) ) {
		$new['akb_shipping'] = 'Envío';
	}
	return $new;
}

// HPOS
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'akb_shipping_admin_columns' );
// Legacy CPT
add_filter( 'manage_edit-shop_order_columns', 'akb_shipping_admin_columns' );

/**
 * Agregar columna preventa.
 */
function akb_preventa_admin_columns( array $columns ): array {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'akb_shipping' === $key ) {
			$new['akb_preventa'] = 'Preventa';
		}
	}
	if ( ! isset( $new['akb_preventa'] ) ) {
		$new['akb_preventa'] = 'Preventa';
	}
	return $new;
}
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'akb_preventa_admin_columns' );
add_filter( 'manage_edit-shop_order_columns', 'akb_preventa_admin_columns' );

/**
 * Devuelve si una orden tiene al menos un producto en preventa (_akb_reserva = yes).
 */
function akb_order_has_preventa( WC_Order $order ): bool {
	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		if ( $pid > 0 && get_post_meta( $pid, '_akb_reserva', true ) === 'yes' ) {
			return true;
		}
	}
	return false;
}

/**
 * Render del valor en cada fila (HPOS).
 */
add_action(
	'manage_woocommerce_page_wc-orders_custom_column',
	function ( $column, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			return;
		}

		if ( 'akb_shipping' === $column ) {
			echo akb_shipping_admin_column_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( 'akb_preventa' === $column ) {
			echo akb_preventa_admin_column_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	},
	10,
	2
);

/**
 * Render legacy (CPT shop_order).
 */
add_action(
	'manage_shop_order_posts_custom_column',
	function ( $column, $post_id ): void {
		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return;
		}

		if ( 'akb_shipping' === $column ) {
			echo akb_shipping_admin_column_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( 'akb_preventa' === $column ) {
			echo akb_preventa_admin_column_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	},
	10,
	2
);

/**
 * HTML de la celda para una orden.
 */
function akb_shipping_admin_column_html( WC_Order $order ): string {
	$method_id = '';
	foreach ( $order->get_shipping_methods() as $m ) {
		$method_id = (string) $m->get_method_id();
		break;
	}
	if ( '' === $method_id ) {
		return '<span style="color:#9ca3af;font-size:11px;">—</span>';
	}

	// Blue Express — reusamos el meta nativo del plugin.
	if ( 0 === strpos( $method_id, 'bluex' ) || 'correios' === $method_id ) {
		$codes = function_exists( 'wc_correios_getTrackingCodes' )
			? (array) wc_correios_getTrackingCodes( $order )
			: array();
		$first = $codes ? (string) reset( $codes ) : '';
		$state = $first ? 'Despachado' : 'Sin tracking';
		$dot   = $first ? '#3b82f6' : '#9ca3af';

		return akb_shipping_admin_cell_render( 'BLUEX', '#0066B3', $state, $dot, $first );
	}

	// 12 Horas Envíos — leer meta del adapter. Status rico: in-transit / approved / partial / rejected / cancelled.
	if ( 0 === strpos( $method_id, '12horas' ) ) {
		$code    = (string) $order->get_meta( '_12horas_tracking_code' );
		$st_raw  = (string) $order->get_meta( '_12horas_status' );
		$st      = str_replace( '-', '_', $st_raw );
		$is_canc = $order->get_meta( '_12horas_is_canceled' ) === '1';

		if ( $is_canc || $st === 'cancelled' || $st === 'canceled' ) {
			$state = 'Cancelado';
			$dot   = '#ef4444';
		} elseif ( $st === 'approved' ) {
			$state = 'Entregado';
			$dot   = '#10b981';
		} elseif ( $st === 'partial' ) {
			$state = 'Parcial';
			$dot   = '#10b981';
		} elseif ( $st === 'in_transit' ) {
			$state = 'En camino';
			$dot   = '#3b82f6';
		} elseif ( $st === 'rejected' ) {
			$state = 'Reagendado';
			$dot   = '#f59e0b';
		} elseif ( $code !== '' ) {
			$state = 'En preparación';
			$dot   = '#94a3b8';
		} else {
			$state = 'Sin tracking';
			$dot   = '#9ca3af';
		}

		return akb_shipping_admin_cell_render( '12H', '#FF6B00', $state, $dot, $code );
	}

	// Local pickup (Metro San Miguel u otros)
	if ( 'local_pickup' === $method_id ) {
		return '<div style="font-size:11px;line-height:1.4;">'
			. '<span style="display:inline-block;background:#10B981;color:#fff;padding:1px 6px;border-radius:3px;font-weight:700;letter-spacing:0.04em;">RETIRO</span> '
			. '<span style="color:#64748b;">Coordinar</span>'
			. '</div>';
	}

	// Fallback: otro método (fallback Akibara, etc.)
	return sprintf(
		'<span style="font-size:11px;color:#64748b;">%s</span>',
		esc_html( $method_id )
	);
}

/**
 * HTML de la celda preventa para una orden.
 */
function akb_preventa_admin_column_html( WC_Order $order ): string {
	if ( ! akb_order_has_preventa( $order ) ) {
		return '<span style="color:#9ca3af;font-size:11px;">—</span>';
	}
	$notificada = $order->get_meta( '_akb_preventa_notificada' ) === 'yes';
	if ( $notificada ) {
		return '<span style="display:inline-block;background:#10b981;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:700;">Notificado</span>';
	}
	return '<span style="display:inline-block;background:#3b82f6;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:700;">Preventa</span>';
}

// ═══════════════════════════════════════════════════════════════════
// FILTRO RM / REGIONES en listado de órdenes
// ═══════════════════════════════════════════════════════════════════

/**
 * Dropdown RM / Regiones (HPOS).
 */
add_action(
	'restrict_manage_woocommerce_page_wc-orders',
	function (): void {
		akb_region_filter_dropdown();
	}
);

/**
 * Dropdown RM / Regiones (CPT legacy).
 */
add_action(
	'restrict_manage_orders',
	function (): void {
		akb_region_filter_dropdown();
	}
);

function akb_region_filter_dropdown(): void {
	$current = sanitize_key( $_GET['akb_region'] ?? '' );
	echo '<select name="akb_region" id="akb_region_filter">'
		. '<option value=""' . selected( $current, '', false ) . '>Todas las regiones</option>'
		. '<option value="rm"' . selected( $current, 'rm', false ) . '>Región Metropolitana (RM)</option>'
		. '<option value="regiones"' . selected( $current, 'regiones', false ) . '>Regiones (fuera de RM)</option>'
		. '</select>';
}

/**
 * Aplicar filtro en HPOS — modifica el SQL directo de OrdersTableDataStore.
 */
add_filter(
	'woocommerce_orders_table_query_clauses',
	function ( array $clauses, $query, array $query_vars ): array {
		$region = sanitize_key( $query_vars['akb_region'] ?? ( sanitize_key( $_GET['akb_region'] ?? '' ) ) );
		if ( '' === $region ) {
			return $clauses;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_orders';
		if ( 'rm' === $region ) {
			$clauses['where'] .= $wpdb->prepare( " AND {$table}.shipping_state = %s", 'RM' );
		} elseif ( 'regiones' === $region ) {
			$clauses['where'] .= $wpdb->prepare( " AND ({$table}.shipping_state != %s OR {$table}.shipping_state IS NULL)", 'RM' );
		}
		return $clauses;
	},
	10,
	3
);

/**
 * Aplicar filtro en CPT legacy.
 */
add_action(
	'pre_get_posts',
	function ( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'post_type' ) !== 'shop_order' ) {
			return;
		}
		$region = sanitize_key( $_GET['akb_region'] ?? '' );
		if ( '' === $region ) {
			return;
		}

		$mq = (array) $query->get( 'meta_query' );
		if ( 'rm' === $region ) {
			$mq[] = array(
				'key'     => '_shipping_state',
				'value'   => 'RM',
				'compare' => '=',
			);
		} elseif ( 'regiones' === $region ) {
			$mq[] = array(
				'key'     => '_shipping_state',
				'value'   => 'RM',
				'compare' => '!=',
			);
		}
		$query->set( 'meta_query', $mq );
	}
);

// ═══════════════════════════════════════════════════════════════════
// BULK ACTION — Marcar preventa disponible
// ═══════════════════════════════════════════════════════════════════

/**
 * Registrar bulk action (HPOS).
 */
add_filter(
	'woocommerce_order_list_table_bulk_actions',
	function ( array $actions ): array {
		$actions['akb_mark_preventa_available'] = 'Marcar preventa disponible';
		return $actions;
	}
);

/**
 * Registrar bulk action (legacy CPT).
 */
add_filter(
	'bulk_actions-edit-shop_order',
	function ( array $actions ): array {
		$actions['akb_mark_preventa_available'] = 'Marcar preventa disponible';
		return $actions;
	}
);

/**
 * Manejar bulk action (HPOS + legacy) — usa apply_filters internamente en WP/WC.
 *
 * Hardening (P-12 audit Sprint 8): defensa en profundidad.
 * WP/WC ya validan el nonce nativo del listado (`bulk-orders` HPOS / `bulk-posts` legacy)
 * antes de disparar estos filtros, pero validamos de nuevo aquí para protegernos contra
 * invocaciones programáticas vía `apply_filters()` desde código de terceros sin contexto
 * de request real. El handler también re-chequea capability.
 */
add_filter(
	'handle_bulk_actions-woocommerce_page_wc-orders',
	function ( string $redirect, string $action, array $ids ): string {
		if ( 'akb_mark_preventa_available' !== $action ) {
			return $redirect;
		}
		// Nonce nativo del listado HPOS (WC ListTable usa 'bulk-orders').
		check_admin_referer( 'bulk-orders' );
		return akb_handle_mark_preventa_available( $redirect, $ids );
	},
	10,
	3
);

add_filter(
	'handle_bulk_actions-edit-shop_order',
	function ( string $redirect, string $action, array $ids ): string {
		if ( 'akb_mark_preventa_available' !== $action ) {
			return $redirect;
		}
		// Nonce nativo del listado legacy CPT (WP wp-admin/edit.php usa 'bulk-posts').
		check_admin_referer( 'bulk-posts' );
		return akb_handle_mark_preventa_available( $redirect, $ids );
	},
	10,
	3
);

function akb_handle_mark_preventa_available( string $redirect, array $ids ): string {
	// Capability check explícito (defensa en profundidad — WC ya valida 'edit_shop_orders'
	// antes de mostrar el bulk action, pero re-chequeamos aquí para invocaciones directas).
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return $redirect;
	}

	$count = 0;
	foreach ( $ids as $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			continue;
		}
		if ( ! akb_order_has_preventa( $order ) ) {
			continue;
		}

		$order->update_meta_data( '_akb_preventa_notificada', 'yes' );
		$order->save();
		$order->add_order_note( __( 'Akibara: preventa marcada como disponible por el administrador.', 'akibara' ), false );
		++$count;
	}

	return add_query_arg( 'akb_preventa_marked', $count, $redirect );
}

/**
 * Aviso de confirmación tras el bulk action.
 */
add_action(
	'admin_notices',
	function (): void {
		$count = absint( $_GET['akb_preventa_marked'] ?? 0 );
		if ( $count < 1 ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			sprintf(
				esc_html( _n( '%d orden marcada como preventa disponible.', '%d órdenes marcadas como preventa disponible.', $count, 'akibara' ) ),
				$count
			)
		);
	}
);

/**
 * Render unificado de la celda admin (badge + estado + código truncado).
 * Reutilizable entre couriers con badge/estado/color distinto.
 */
function akb_shipping_admin_cell_render( string $badge, string $badge_bg, string $state, string $dot_color, string $code ): string {
	$trk_short = $code !== ''
		? ( strlen( $code ) > 14 ? substr( $code, 0, 6 ) . '…' . substr( $code, -4 ) : $code )
		: '—';

	return sprintf(
		'<div style="font-size:11px;line-height:1.4;">'
		. '<span style="display:inline-block;background:%1$s;color:#fff;padding:1px 6px;border-radius:3px;font-weight:700;letter-spacing:0.04em;">%2$s</span> '
		. '<span style="display:inline-flex;align-items:center;gap:4px;"><span style="width:6px;height:6px;border-radius:50%%;background:%3$s;"></span>%4$s</span>'
		. '<br><code style="font-family:ui-monospace,Menlo,monospace;font-size:10px;color:#64748b;">%5$s</code>'
		. '</div>',
		esc_attr( $badge_bg ),
		esc_html( $badge ),
		esc_attr( $dot_color ),
		esc_html( $state ),
		esc_html( $trk_short )
	);
}
