<?php
/**
 * Akibara Inventario — Inventory module (Stock Central).
 *
 * Migrated from plugins/akibara/modules/inventory/module.php.
 * Key changes from legacy:
 * - Guard uses AKB_INV_ADDON_LOADED (not AKIBARA_V10_LOADED).
 * - Constants use AKB_INVENTARIO_DIR / AKB_INVENTARIO_URL (not AKIBARA_DIR / AKIBARA_URL).
 * - AKB_INV_TABLE_STOCK_LOG constant replaces inline $wpdb->prefix . 'akb_stock_log'.
 * - Table is already created by Schema::install() — no akb_inventory_create_table() needed.
 * - Cell H fixes applied:
 *   - mesa-07 F-01: dashboard stock table overflow-x:auto + SKU hidden on mobile (CSS).
 *   - mesa-08 F-04: CTA color changed from --aki-red to --aki-red-bright (4.93:1 PASS AA).
 *   - mesa-05 F-03: stock CTA min-height 32px → 44px (WCAG 2.5.8).
 *
 * @package Akibara\Inventario
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_INV_ADDON_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_INVENTARIO_INV_LOADED' ) ) {
	return;
}
define( 'AKB_INVENTARIO_INV_LOADED', '1.0.0' );

const AKB_INV_DB_VERSION_LEGACY = '2.1'; // Compat sentinel for legacy option check.

/**
 * Helper: send JSON error response.
 */
if ( ! function_exists( 'akb_inventario_inv_error' ) ) {

	function akb_inventario_inv_error( string $message, int $status = 400 ): void {
		wp_send_json_error( array( 'message' => $message ), $status );
	}

	/**
	 * Extract tomo number from product title.
	 * e.g., "One Piece 15 — Colección" → 15
	 */
	function akb_inventario_extract_tomo( string $title ): ?int {
		if ( preg_match( '/\s+(\d+)\s*[–—-]/', $title, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/\s+(\d+)$/', preg_replace( '/\s+[–—-]\s+.+$/', '', $title ), $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	// ──── Stock change audit hooks ────────────────────────────────────────────────
	$GLOBALS['akb_inv_before_stock'] = array();

	add_action(
		'woocommerce_product_before_set_stock',
		static function ( $product ): void {
			if ( ! $product instanceof WC_Product ) {
				return;
			}
			$GLOBALS['akb_inv_before_stock'][ $product->get_id() ] = $product->get_stock_quantity();
		}
	);

	add_action(
		'woocommerce_product_set_stock',
		static function ( $product ): void {
			if ( ! $product instanceof WC_Product || defined( 'AKB_INVENTORY_UPDATING' ) ) {
				return;
			}
			global $wpdb;
			$source = 'manual';
			if ( did_action( 'woocommerce_reduce_order_stock' ) ) {
				$source = 'venta';
			} elseif ( did_action( 'woocommerce_restore_order_stock' ) ) {
				$source = 'restauracion';
			}
			$pid = $product->get_id();
			$old = $GLOBALS['akb_inv_before_stock'][ $pid ] ?? null;
			$wpdb->insert(
				\AKB_INV_TABLE_STOCK_LOG,
				array(
					'product_id' => $pid,
					'old_stock'  => $old,
					'new_stock'  => $product->get_stock_quantity(),
					'reason'     => 'venta' === $source ? __( 'Venta', 'akibara-inventario' ) : ( 'restauracion' === $source ? __( 'Restauración', 'akibara-inventario' ) : __( 'WooCommerce', 'akibara-inventario' ) ),
					'source'     => $source,
					'user_id'    => get_current_user_id(),
				)
			);
			unset( $GLOBALS['akb_inv_before_stock'][ $pid ] );
		},
		10,
		1
	);

	// ──── Admin assets ────────────────────────────────────────────────────────────
	add_action(
		'admin_enqueue_scripts',
		static function ( string $hook ): void {
			if ( $hook !== 'toplevel_page_akibara' ) {
				return;
			}
			$tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
			if ( $tab !== 'inventario' ) {
				return;
			}
			$css_path = \AKB_INVENTARIO_DIR . 'modules/inventory/assets/inventory-admin.css';
			$js_path  = \AKB_INVENTARIO_DIR . 'modules/inventory/assets/inventory-admin.js';
			wp_enqueue_style( 'akb-inv-admin', \AKB_INVENTARIO_URL . 'modules/inventory/assets/inventory-admin.css', array(), file_exists( $css_path ) ? (string) filemtime( $css_path ) : \AKB_INVENTARIO_VERSION );
			wp_enqueue_script( 'akb-inv-admin', \AKB_INVENTARIO_URL . 'modules/inventory/assets/inventory-admin.js', array(), file_exists( $js_path ) ? (string) filemtime( $js_path ) : \AKB_INVENTARIO_VERSION, true );
			wp_localize_script(
				'akb-inv-admin',
				'AKB_INV',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'akb_inventory' ),
					'i18n'    => array(
						'networkError' => __( 'Error de red. Reintenta.', 'akibara-inventario' ),
						'genericError' => __( 'Error inesperado.', 'akibara-inventario' ),
						'selectAction' => __( 'Selecciona acción', 'akibara-inventario' ),
						'noProducts'   => __( 'Sin productos seleccionados', 'akibara-inventario' ),
						'confirmBulk'  => __( '¿Aplicar a', 'akibara-inventario' ),
						'updated'      => __( 'actualizados', 'akibara-inventario' ),
					),
				)
			);
		}
	);

	// ──── Register admin tab ──────────────────────────────────────────────────────
	add_filter(
		'akibara_admin_tabs',
		static function ( array $tabs ): array {
			$tabs['inventario'] = array(
				'label'       => 'Inventario',
				'short_label' => 'Stock',
				'icon'        => 'dashicons-archive',
				'group'       => 'operacion',
				'callback'    => 'akibara_inventario_admin_tab_inventario',
			);
			return $tabs;
		}
	);

	// ──── AJAX endpoints ──────────────────────────────────────────────────────────

	// akb_inv_products — paginated product list with filters.
	if ( function_exists( 'akb_ajax_endpoint' ) ) {
		akb_ajax_endpoint(
			'akb_inv_products',
			array(
				'nonce'      => 'akb_inventory',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					global $wpdb;
					$page             = max( 1, (int) ( $post['page'] ?? 1 ) );
					$per_page         = max( 10, min( 100, (int) ( $post['per_page'] ?? 50 ) ) );
					$offset           = ( $page - 1 ) * $per_page;
					$search           = sanitize_text_field( $post['search'] ?? '' );
					$status           = sanitize_key( $post['status'] ?? 'all' );
					$serie            = (int) ( $post['serie'] ?? 0 );
					$editorial_filter = sanitize_text_field( $post['editorial'] ?? '' );
					$manage           = sanitize_key( $post['manage'] ?? 'all' );
					$orderby          = sanitize_key( $post['orderby'] ?? 'title' );
					$order            = strtoupper( sanitize_key( $post['order'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC';
					$default_low      = max( 1, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );

					$where = array( "p.post_type='product'", "p.post_status='publish'" );
					$join  = '';

					if ( $status === 'low' ) {
						$join   .= " JOIN {$wpdb->postmeta} _ss ON _ss.post_id=p.ID AND _ss.meta_key='_stock_status'";
						$join   .= " JOIN {$wpdb->postmeta} _stk ON _stk.post_id=p.ID AND _stk.meta_key='_stock'";
						$join   .= " LEFT JOIN {$wpdb->postmeta} _low ON _low.post_id=p.ID AND _low.meta_key='_low_stock_amount'";
						$where[] = "_ss.meta_value='instock'";
						$where[] = 'CAST(_stk.meta_value AS SIGNED) > 0';
						$where[] = $wpdb->prepare( 'CAST(_stk.meta_value AS SIGNED) <= COALESCE(NULLIF(CAST(_low.meta_value AS SIGNED),0), %d)', $default_low );
					} elseif ( $status === 'no_manage' ) {
						$where[] = "p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_manage_stock' AND meta_value='yes')";
					} elseif ( in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
						$join   .= " JOIN {$wpdb->postmeta} _ss ON _ss.post_id=p.ID AND _ss.meta_key='_stock_status'";
						$where[] = $wpdb->prepare( '_ss.meta_value=%s', $status );
					}

					if ( $search ) {
						$like    = '%' . $wpdb->esc_like( $search ) . '%';
						$where[] = $wpdb->prepare(
							"(p.post_title LIKE %s OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value LIKE %s) OR p.ID IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tt.taxonomy='product_brand' AND t.name LIKE %s))",
							$like,
							$like,
							$like
						);
					}

					if ( $serie ) {
						$join   .= " JOIN {$wpdb->term_relationships} _tr ON _tr.object_id=p.ID";
						$join   .= " JOIN {$wpdb->term_taxonomy} _tt ON _tt.term_taxonomy_id=_tr.term_taxonomy_id AND _tt.taxonomy='pa_serie'";
						$where[] = $wpdb->prepare( '_tt.term_id=%d', $serie );
					}

					if ( $editorial_filter ) {
						$join   .= " JOIN {$wpdb->term_relationships} _tre ON _tre.object_id=p.ID";
						$join   .= " JOIN {$wpdb->term_taxonomy} _tte ON _tte.term_taxonomy_id=_tre.term_taxonomy_id AND _tte.taxonomy='product_brand'";
						$join   .= " JOIN {$wpdb->terms} _te ON _te.term_id=_tte.term_id";
						$where[] = $wpdb->prepare( '_te.name=%s', $editorial_filter );
					}

					if ( $manage === 'yes' ) {
						$where[] = "p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_manage_stock' AND meta_value='yes')";
					} elseif ( $manage === 'no' ) {
						$where[] = "p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_manage_stock' AND meta_value='yes')";
					}

					$orderby_map = array(
						'title' => "p.post_title {$order}",
						'stock' => "CAST(_so.meta_value AS SIGNED) {$order}",
						'price' => "CAST(_so.meta_value AS DECIMAL(10,2)) {$order}",
					);
					if ( ! isset( $orderby_map[ $orderby ] ) ) {
						$orderby = 'title';
					}
					if ( in_array( $orderby, array( 'stock', 'price' ), true ) ) {
						$meta_key = $orderby === 'stock' ? '_stock' : '_price';
						$join   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} _so ON _so.post_id=p.ID AND _so.meta_key=%s", $meta_key );
					}

					$w = implode( ' AND ', $where );
					$o = $orderby_map[ $orderby ];
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join} WHERE {$w}" );
					$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$join} WHERE {$w} ORDER BY {$o} LIMIT %d OFFSET %d", $per_page, $offset ) );
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

					update_meta_cache( 'post', array_map( 'intval', $ids ) );
					$series_map    = array();
					$editorial_map = array();
					if ( ! empty( $ids ) ) {
						$terms = wp_get_object_terms( $ids, array( 'pa_serie', 'product_brand' ), array( 'fields' => 'all_with_object_id' ) );
						if ( ! is_wp_error( $terms ) ) {
							foreach ( $terms as $term ) {
								$oid = (int) $term->object_id;
								if ( $term->taxonomy === 'pa_serie' && ! isset( $series_map[ $oid ] ) ) {
									$series_map[ $oid ] = $term->name;
								} elseif ( $term->taxonomy === 'product_brand' && ! isset( $editorial_map[ $oid ] ) ) {
									$editorial_map[ $oid ] = $term->name;
								}
							}
						}
					}

					$products = array();
					foreach ( $ids as $id ) {
						$id   = (int) $id;
						$prod = wc_get_product( $id );
						if ( ! $prod ) {
							continue;
						}
						$title         = $prod->get_name();
						$low_meta      = (int) get_post_meta( $id, '_low_stock_amount', true );
						$low_threshold = $low_meta > 0 ? $low_meta : $default_low;
						$products[]    = array(
							'id'               => $id,
							'title'            => $title,
							'serie'            => $series_map[ $id ] ?? '',
							'tomo'             => akb_inventario_extract_tomo( $title ),
							'editorial'        => $editorial_map[ $id ] ?? '',
							'thumb'            => get_the_post_thumbnail_url( $id, 'thumbnail' ) ?: '',
							'price'            => (float) $prod->get_price(),
							'stock'            => $prod->get_stock_quantity(),
							'low_stock_amount' => $low_threshold,
							'stock_status'     => $prod->get_stock_status(),
							'manage_stock'     => $prod->get_manage_stock(),
							'edit_url'         => get_edit_post_link( $id, 'raw' ),
						);
					}
					wp_send_json_success(
						array(
							'products' => $products,
							'total'    => $total,
							'page'     => $page,
							'per_page' => $per_page,
							'pages'    => (int) ceil( $total / $per_page ),
						)
					);
				},
			)
		);

		akb_ajax_endpoint(
			'akb_inv_update',
			array(
				'nonce'      => 'akb_inventory',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					$pid   = (int) ( $post['product_id'] ?? 0 );
					$field = sanitize_key( $post['field'] ?? '' );
					$value = sanitize_text_field( $post['value'] ?? '' );
					$prod  = wc_get_product( $pid );
					if ( ! $prod ) {
						akb_inventario_inv_error( __( 'Producto no encontrado', 'akibara-inventario' ), 404 );
					}
					if ( ! defined( 'AKB_INVENTORY_UPDATING' ) ) {
						define( 'AKB_INVENTORY_UPDATING', true );
					}
					global $wpdb;
					if ( $field === 'stock' ) {
						$old    = (int) $prod->get_stock_quantity();
						$new    = max( 0, (int) $value );
						$result = wc_update_product_stock( $prod, $new, 'set' );
						if ( $result === false ) {
							akb_inventario_inv_error( __( 'No se pudo actualizar stock', 'akibara-inventario' ) );
						}
						$wpdb->insert( \AKB_INV_TABLE_STOCK_LOG, array( 'product_id' => $pid, 'old_stock' => $old, 'new_stock' => $new, 'reason' => __( 'Stock Central', 'akibara-inventario' ), 'source' => 'stock_central', 'user_id' => get_current_user_id() ) );
					} elseif ( $field === 'increment' ) {
						$delta = (int) $value;
						if ( $delta === 0 ) {
							wp_send_json_success( array( 'id' => $pid, 'stock' => $prod->get_stock_quantity(), 'stock_status' => $prod->get_stock_status() ) );
						}
						$old    = (int) $prod->get_stock_quantity();
						$op     = $delta > 0 ? 'increase' : 'decrease';
						$result = wc_update_product_stock( $prod, abs( $delta ), $op );
						if ( $result === false ) {
							akb_inventario_inv_error( __( 'No se pudo actualizar stock', 'akibara-inventario' ) );
						}
						$new  = max( 0, (int) wc_get_product( $pid )->get_stock_quantity() );
						$sign = $delta > 0 ? "+{$delta}" : (string) $delta;
						$wpdb->insert( \AKB_INV_TABLE_STOCK_LOG, array( 'product_id' => $pid, 'old_stock' => $old, 'new_stock' => $new, 'reason' => sprintf( __( 'Stock Central (%s)', 'akibara-inventario' ), $sign ), 'source' => 'stock_central', 'user_id' => get_current_user_id() ) );
					} elseif ( $field === 'manage_stock' ) {
						$prod->set_manage_stock( $value === 'yes' );
						if ( $value === 'yes' && $prod->get_stock_quantity() === null ) {
							$prod->set_stock_quantity( 0 );
						}
						$prod->save();
					} else {
						akb_inventario_inv_error( __( 'Campo no válido', 'akibara-inventario' ) );
					}
					$fresh = wc_get_product( $pid );
					wp_send_json_success( array( 'id' => $pid, 'stock' => $fresh ? $fresh->get_stock_quantity() : null, 'stock_status' => $fresh ? $fresh->get_stock_status() : null ) );
				},
			)
		);

		akb_ajax_endpoint(
			'akb_inv_bulk',
			array(
				'nonce'      => 'akb_inventory',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					$action = sanitize_key( $post['bulk_action'] ?? '' );
					$ids    = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( $post['product_ids'] ?? '' ) ) ) );
					$value  = sanitize_text_field( $post['value'] ?? '' );
					if ( empty( $ids ) ) {
						akb_inventario_inv_error( __( 'Sin productos seleccionados', 'akibara-inventario' ) );
					}
					if ( ! in_array( $action, array( 'enable_manage', 'set_stock', 'add_stock' ), true ) ) {
						akb_inventario_inv_error( __( 'Acción inválida', 'akibara-inventario' ) );
					}
					if ( ! defined( 'AKB_INVENTORY_UPDATING' ) ) {
						define( 'AKB_INVENTORY_UPDATING', true );
					}
					global $wpdb;
					$ok   = 0;
					$fail = 0;
					foreach ( $ids as $id ) {
						$prod = wc_get_product( $id );
						if ( ! $prod ) {
							$fail++;
							continue;
						}
						if ( $action === 'enable_manage' ) {
							$prod->set_manage_stock( true );
							if ( $prod->get_stock_quantity() === null ) {
								$prod->set_stock_quantity( 0 );
							}
							$prod->save();
							$ok++;
							continue;
						}
						$qty = max( 0, (int) $value );
						$old = (int) $prod->get_stock_quantity();
						$prod->set_manage_stock( true );
						$prod->save();
						if ( $action === 'set_stock' ) {
							$result = wc_update_product_stock( $prod, $qty, 'set' );
							$new    = $qty;
							$reason = __( 'Bulk set stock', 'akibara-inventario' );
						} else {
							$result = wc_update_product_stock( $prod, $qty, 'increase' );
							$new    = (int) $prod->get_stock_quantity();
							$reason = sprintf( __( 'Bulk +%d', 'akibara-inventario' ), $qty );
						}
						if ( $result === false ) {
							$fail++;
							continue;
						}
						$wpdb->insert( \AKB_INV_TABLE_STOCK_LOG, array( 'product_id' => $id, 'old_stock' => $old, 'new_stock' => $new, 'reason' => $reason, 'source' => 'bulk', 'user_id' => get_current_user_id() ) );
						$ok++;
					}
					wp_send_json_success( array( 'updated' => $ok, 'failed' => $fail ) );
				},
			)
		);

		akb_ajax_endpoint(
			'akb_inv_log',
			array(
				'nonce'      => 'akb_inventory',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					global $wpdb;
					$table  = \AKB_INV_TABLE_STOCK_LOG;
					$page   = max( 1, (int) ( $post['page'] ?? 1 ) );
					$offset = ( $page - 1 ) * 50;
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
					$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT l.*, p.post_title FROM {$table} l LEFT JOIN {$wpdb->posts} p ON p.ID=l.product_id ORDER BY l.created_at DESC LIMIT 50 OFFSET %d", $offset ) );
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$items = array();
					foreach ( (array) $rows as $r ) {
						$u       = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
						$items[] = array(
							'product' => $r->post_title ?: "#{$r->product_id}",
							'old'     => $r->old_stock,
							'new'     => $r->new_stock,
							'reason'  => $r->reason,
							'source'  => $r->source,
							'user'    => $u ? $u->display_name : __( 'Sistema', 'akibara-inventario' ),
							'date'    => $r->created_at,
						);
					}
					wp_send_json_success( array( 'logs' => $items, 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / 50 ) ) );
				},
			)
		);

		akb_ajax_endpoint(
			'akb_inv_stats',
			array(
				'nonce'      => 'akb_inventory',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					global $wpdb;
					$default_low = max( 1, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
					$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" );
					$rows        = $wpdb->get_results( "SELECT pm.meta_value s, COUNT(*) c FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_stock_status' AND p.post_type='product' AND p.post_status='publish' GROUP BY pm.meta_value" );
					$st          = array( 'instock' => 0, 'outofstock' => 0, 'onbackorder' => 0 );
					foreach ( (array) $rows as $r ) {
						$st[ $r->s ] = (int) $r->c;
					}
					$low = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->postmeta} stk JOIN {$wpdb->postmeta} ss ON ss.post_id=stk.post_id AND ss.meta_key='_stock_status' AND ss.meta_value='instock' LEFT JOIN {$wpdb->postmeta} low ON low.post_id=stk.post_id AND low.meta_key='_low_stock_amount' JOIN {$wpdb->posts} p ON p.ID=stk.post_id WHERE stk.meta_key='_stock' AND CAST(stk.meta_value AS SIGNED)>0 AND CAST(stk.meta_value AS SIGNED)<=COALESCE(NULLIF(CAST(low.meta_value AS SIGNED),0),%d) AND p.post_type='product' AND p.post_status='publish'",
							$default_low
						)
					);
					$no_manage = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='product' AND p.post_status='publish' AND p.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_manage_stock' AND meta_value='yes')" );
					$val       = (float) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(s.meta_value AS SIGNED)*CAST(pr.meta_value AS DECIMAL(10,2))),0) FROM {$wpdb->postmeta} s JOIN {$wpdb->postmeta} pr ON pr.post_id=s.post_id AND pr.meta_key='_price' JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE s.meta_key='_stock' AND CAST(s.meta_value AS SIGNED)>0 AND p.post_type='product' AND p.post_status='publish'" );
					wp_send_json_success( compact( 'total', 'low', 'no_manage', 'val' ) + $st );
				},
			)
		);
	}

	// CSV export — not using akb_ajax_endpoint (binary streaming, not JSON).
	add_action(
		'wp_ajax_akb_inv_csv',
		static function (): void {
			check_ajax_referer( 'akb_inventory', 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Sin permisos', 'akibara-inventario' ) );
			}
			global $wpdb;
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY post_title" );
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="akibara-stock-' . gmdate( 'Y-m-d' ) . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			fwrite( $out, "\xEF\xBB\xBF" );
			fputcsv( $out, array( 'ID', 'SKU', 'Producto', 'Serie', 'Tomo', 'Editorial', 'Precio', 'Stock', 'Estado', 'Manage' ), ',', '"', '\\' );
			$chunk_size = 100;
			foreach ( array_chunk( $ids, $chunk_size ) as $chunk ) {
				$chunk_ints    = array_map( 'intval', $chunk );
				update_meta_cache( 'post', $chunk_ints );
				$terms_by_post = array();
				$terms         = wp_get_object_terms( $chunk_ints, array( 'pa_serie', 'product_brand' ), array( 'fields' => 'all_with_object_id' ) );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$oid = (int) $term->object_id;
						if ( ! isset( $terms_by_post[ $oid ][ $term->taxonomy ] ) ) {
							$terms_by_post[ $oid ][ $term->taxonomy ] = $term->name;
						}
					}
				}
				foreach ( $chunk_ints as $id ) {
					$p = wc_get_product( $id );
					if ( ! $p ) {
						continue;
					}
					$title          = $p->get_name();
					$serie_name     = $terms_by_post[ $id ]['pa_serie'] ?? '';
					$editorial_name = $terms_by_post[ $id ]['product_brand'] ?? '';
					fputcsv( $out, array( $id, $p->get_sku(), $title, $serie_name, akb_inventario_extract_tomo( $title ), $editorial_name, $p->get_price(), $p->get_stock_quantity(), $p->get_stock_status(), $p->get_manage_stock() ? 'yes' : 'no' ), ',', '"', '\\' );
				}
				wp_cache_flush_group( 'posts' );
				wp_cache_flush_group( 'post_meta' );
				unset( $terms, $terms_by_post );
			}
			fclose( $out );
			exit;
		}
	);

} // end group wrap

// ──── Admin tab render ────────────────────────────────────────────────────────
// Defined outside group wrap so it can be called from the akibara_admin_tabs callback.

function akibara_inventario_admin_tab_inventario(): void {
	$nonce  = wp_create_nonce( 'akb_inventory' );
	$csv    = admin_url( 'admin-ajax.php?action=akb_inv_csv&nonce=' . $nonce );
	$series = get_terms( array( 'taxonomy' => 'pa_serie', 'hide_empty' => true, 'orderby' => 'name' ) );

	$editorial_terms = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => true, 'orderby' => 'name' ) );
	$editorials      = is_wp_error( $editorial_terms ) ? array() : wp_list_pluck( $editorial_terms, 'name' );
	?>
	<div class="akb-inv" id="akb-inv-root" data-csv-url="<?php echo esc_url( $csv ); ?>">
		<div class="akb-page-header">
			<h2 class="akb-page-header__title"><?php echo esc_html__( 'Inventario', 'akibara-inventario' ); ?></h2>
			<p class="akb-page-header__desc"><?php echo esc_html( sprintf( __( 'Stock Central — %1$d productos · %2$d series · %3$d editoriales', 'akibara-inventario' ), number_format_i18n( (int) wp_count_posts( 'product' )->publish ), count( $series ), count( $editorials ) ) ); ?></p>
		</div>

		<div class="akb-inv-stats" id="inv-st">
			<div class="akb-inv-stat"><b id="s-total">—</b><small><?php esc_html_e( 'Total catálogo', 'akibara-inventario' ); ?></small></div>
			<div class="akb-inv-stat"><b class="st-ok" id="s-in">—</b><small><?php esc_html_e( 'En stock', 'akibara-inventario' ); ?></small></div>
			<div class="akb-inv-stat"><b class="st-bad" id="s-out">—</b><small><?php esc_html_e( 'Agotados', 'akibara-inventario' ); ?></small></div>
			<div class="akb-inv-stat"><b class="st-warn" id="s-low">—</b><small><?php esc_html_e( 'Stock bajo', 'akibara-inventario' ); ?></small></div>
			<div class="akb-inv-stat"><b class="st-bad" id="s-nm">—</b><small><?php esc_html_e( 'Sin gestión', 'akibara-inventario' ); ?></small></div>
			<div class="akb-inv-stat"><b class="st-brand" id="s-val">—</b><small><?php esc_html_e( 'Valor inventario', 'akibara-inventario' ); ?></small></div>
		</div>

		<div id="akb-inv-alert" class="akb-inv-alert" style="display:none"></div>

		<div class="akb-inv-tabs">
			<button class="akb-inv-tab on" data-v="stock"><?php esc_html_e( 'Stock Central', 'akibara-inventario' ); ?></button>
			<button class="akb-inv-tab" data-v="log"><?php esc_html_e( 'Historial', 'akibara-inventario' ); ?></button>
		</div>

		<div id="v-stock">
			<div class="akb-inv-bar">
				<input type="text" id="f-search" placeholder="<?php esc_attr_e( 'Buscar por nombre o SKU…', 'akibara-inventario' ); ?>">
				<select id="f-status">
					<option value="all"><?php esc_html_e( 'Todo', 'akibara-inventario' ); ?></option>
					<option value="instock"><?php esc_html_e( 'En stock', 'akibara-inventario' ); ?></option>
					<option value="outofstock"><?php esc_html_e( 'Agotados', 'akibara-inventario' ); ?></option>
					<option value="onbackorder"><?php esc_html_e( 'Backorder', 'akibara-inventario' ); ?></option>
					<option value="low"><?php esc_html_e( 'Stock bajo', 'akibara-inventario' ); ?></option>
					<option value="no_manage"><?php esc_html_e( 'Sin gestión', 'akibara-inventario' ); ?></option>
				</select>
				<select id="f-manage">
					<option value="all"><?php esc_html_e( 'Gestión: todo', 'akibara-inventario' ); ?></option>
					<option value="yes"><?php esc_html_e( 'Gestión activa', 'akibara-inventario' ); ?></option>
					<option value="no"><?php esc_html_e( 'Gestión desactivada', 'akibara-inventario' ); ?></option>
				</select>
				<select id="f-serie">
					<option value="0"><?php esc_html_e( 'Serie…', 'akibara-inventario' ); ?></option>
					<?php foreach ( $series as $s ) : ?>
						<option value="<?php echo (int) $s->term_id; ?>"><?php echo esc_html( $s->name ); ?> (<?php echo (int) $s->count; ?>)</option>
					<?php endforeach; ?>
				</select>
				<select id="f-ed">
					<option value=""><?php esc_html_e( 'Editorial…', 'akibara-inventario' ); ?></option>
					<?php foreach ( $editorials as $ed ) : ?>
						<option value="<?php echo esc_attr( $ed ); ?>"><?php echo esc_html( $ed ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="akb-inv-restock-toggle"><input type="checkbox" id="f-restock"> <?php esc_html_e( 'Modo Restock', 'akibara-inventario' ); ?></label>
			</div>

			<div class="akb-inv-bulk" id="bulk" style="display:none">
				<strong><span id="bulk-n">0</span></strong> <?php esc_html_e( 'seleccionados', 'akibara-inventario' ); ?>
				<select id="bulk-act">
					<option value=""><?php esc_html_e( 'Acción…', 'akibara-inventario' ); ?></option>
					<option value="enable_manage"><?php esc_html_e( 'Activar gestión de stock', 'akibara-inventario' ); ?></option>
					<option value="set_stock"><?php esc_html_e( 'Establecer stock', 'akibara-inventario' ); ?></option>
					<option value="add_stock"><?php esc_html_e( 'Sumar stock (+N)', 'akibara-inventario' ); ?></option>
				</select>
				<input type="number" id="bulk-val" min="0" placeholder="N" style="display:none;width:60px">
				<button class="akb-btn akb-btn--sm akb-btn--primary" id="bulk-go"><?php esc_html_e( 'Aplicar', 'akibara-inventario' ); ?></button>
			</div>

			<!-- mesa-07 F-01: overflow-x:auto wrapper para responsividad en 375px -->
			<div class="akb-inv-wrap">
				<table class="akb-inv-t" id="tbl">
					<thead><tr>
						<th style="width:30px"><input type="checkbox" id="sel-all"></th>
						<th style="width:40px"></th>
						<th data-s="title"><?php esc_html_e( 'Producto', 'akibara-inventario' ); ?></th>
						<!-- mesa-07 F-01: columna SKU oculta en mobile (ver CSS akb-inv-col-sku) -->
						<th class="akb-inv-col-sku" style="width:120px"><?php esc_html_e( 'Editorial', 'akibara-inventario' ); ?></th>
						<th data-s="price" style="width:80px"><?php esc_html_e( 'Precio', 'akibara-inventario' ); ?></th>
						<th data-s="stock" style="width:120px"><?php esc_html_e( 'Stock', 'akibara-inventario' ); ?></th>
						<th style="width:80px"><?php esc_html_e( 'Estado', 'akibara-inventario' ); ?></th>
						<th style="width:60px"><?php esc_html_e( 'Gestión', 'akibara-inventario' ); ?></th>
					</tr></thead>
					<tbody id="tbody"><tr><td colspan="8" class="inv-load"><?php esc_html_e( 'Cargando…', 'akibara-inventario' ); ?></td></tr></tbody>
				</table>
			</div>
			<div class="akb-inv-pg" id="pg"></div>
			<div class="akb-inv-footer"><a href="<?php echo esc_url( $csv ); ?>" class="akb-btn akb-btn--sm" target="_blank"><?php esc_html_e( 'Exportar CSV', 'akibara-inventario' ); ?></a></div>
		</div>

		<div id="v-log" style="display:none">
			<div class="akb-inv-wrap">
				<table class="akb-inv-t" id="log-tbl">
					<thead><tr><th><?php esc_html_e( 'Fecha', 'akibara-inventario' ); ?></th><th><?php esc_html_e( 'Producto', 'akibara-inventario' ); ?></th><th><?php esc_html_e( 'Cambio', 'akibara-inventario' ); ?></th><th><?php esc_html_e( 'Razón', 'akibara-inventario' ); ?></th><th><?php esc_html_e( 'Usuario', 'akibara-inventario' ); ?></th></tr></thead>
					<tbody id="log-tb"><tr><td colspan="5" class="inv-load"><?php esc_html_e( 'Cargando…', 'akibara-inventario' ); ?></td></tr></tbody>
				</table>
			</div>
			<div class="akb-inv-pg" id="log-pg"></div>
		</div>
	</div>
	<?php
}
