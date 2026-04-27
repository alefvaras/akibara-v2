<?php
defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════════
// AJAX — Listado de productos
// ══════════════════════════════════════════════════════════════════
// 6 endpoints comparten capability 'manage_woocommerce' + nonce 'akb_ml_nonce'
// vía akb_ajax_endpoint(). Los handlers (akb_ml_ajax_*_handler) llaman wp_send_json_*
// directamente; el wrapper sólo añade capability+nonce+try/catch.
if ( function_exists( 'akb_ajax_endpoint' ) ) {
	akb_ajax_endpoint(
		'akb_ml_get_products',
		array(
			'nonce'      => 'akb_ml_nonce',
			'capability' => 'manage_woocommerce',
			'handler'    => 'akb_ml_ajax_get_products_handler',
		)
	);
	akb_ajax_endpoint(
		'akb_ml_publish',
		array(
			'nonce'      => 'akb_ml_nonce',
			'capability' => 'manage_woocommerce',
			'handler'    => 'akb_ml_ajax_publish_handler',
		)
	);
	akb_ajax_endpoint(
		'akb_ml_toggle_status',
		array(
			'nonce'      => 'akb_ml_nonce',
			'capability' => 'manage_woocommerce',
			'handler'    => 'akb_ml_ajax_toggle_status_handler',
		)
	);
	akb_ajax_endpoint(
		'akb_ml_bulk_publish',
		array(
			'nonce'      => 'akb_ml_nonce',
			'capability' => 'manage_woocommerce',
			'handler'    => 'akb_ml_ajax_bulk_publish_handler',
		)
	);
	akb_ajax_endpoint(
		'akb_ml_publish_all_available',
		array(
			'nonce'      => 'akb_ml_nonce',
			'capability' => 'manage_woocommerce',
			'handler'    => 'akb_ml_ajax_publish_all_available_handler',
		)
	);
	akb_ajax_endpoint(
		'akb_ml_bulk_progress',
		array(
			'nonce'      => 'akb_ml_nonce',
			'capability' => 'manage_woocommerce',
			'handler'    => 'akb_ml_ajax_bulk_progress_handler',
		)
	);
	akb_ajax_endpoint(
		'akb_ml_clear_error',
		array(
			'nonce'      => 'akb_ml_nonce',
			'capability' => 'manage_woocommerce',
			'handler'    => 'akb_ml_ajax_clear_error_handler',
		)
	);
}

function akb_ml_ajax_get_products_handler( array $post = array() ): void {
	global $wpdb;

	// Nonce/capability ya validados por akb_ajax_endpoint(); $post viene saneado.
	$page      = max( 1, (int) ( $post['page'] ?? 1 ) );
	$per_page  = min( 100, max( 10, (int) ( $post['per_page'] ?? 25 ) ) );
	$offset    = ( $page - 1 ) * $per_page;
	$filter    = sanitize_text_field( $post['filter'] ?? 'all' );
	$search    = sanitize_text_field( $post['search'] ?? '' );
	$editorial = sanitize_text_field( $post['editorial'] ?? '' );
	$serie_id  = (int) ( $post['serie'] ?? 0 );

	// Construir WHERE para filtro ML status
	$status_join  = '';
	$status_where = '';
	switch ( $filter ) {
		case 'published':
			$status_join  = "INNER JOIN {$wpdb->prefix}akb_ml_items ml ON p.ID = ml.product_id AND ml.ml_item_id != ''";
			$status_where = "AND ml.ml_status IN ('active','paused')";
			break;
		case 'not_published':
			$status_join  = "LEFT JOIN {$wpdb->prefix}akb_ml_items ml ON p.ID = ml.product_id";
			$status_where = "AND (ml.product_id IS NULL OR ml.ml_item_id = '' OR ml.ml_status NOT IN ('active','paused'))";
			break;
		case 'error':
			$status_join  = "INNER JOIN {$wpdb->prefix}akb_ml_items ml ON p.ID = ml.product_id";
			$status_where = "AND ml.ml_status = 'error'";
			break;
		case 'available':
			// Productos con stock > 0 no publicados en ML
			$status_join  = "
                LEFT JOIN {$wpdb->prefix}akb_ml_items ml ON p.ID = ml.product_id
                INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id
                    AND pm_stock.meta_key = '_stock' AND pm_stock.meta_value > 0";
			$status_where = "AND (ml.product_id IS NULL OR ml.ml_item_id = '' OR ml.ml_status NOT IN ('active','paused'))";
			break;
		default:
			$status_join = "LEFT JOIN {$wpdb->prefix}akb_ml_items ml ON p.ID = ml.product_id";
			break;
	}

	// Búsqueda por nombre
	$search_where = '';
	if ( $search !== '' ) {
		$search_esc   = '%' . $wpdb->esc_like( $search ) . '%';
		$search_where = $wpdb->prepare( 'AND p.post_title LIKE %s', $search_esc );
	}

	// Filtro por editorial (taxonomía nativa product_brand de WooCommerce)
	$editorial_join  = '';
	$editorial_where = '';
	if ( $editorial !== '' ) {
		$editorial_join  = "INNER JOIN {$wpdb->term_relationships} tr_ed ON p.ID = tr_ed.object_id
                             INNER JOIN {$wpdb->term_taxonomy} tt_ed ON tt_ed.term_taxonomy_id = tr_ed.term_taxonomy_id AND tt_ed.taxonomy = 'product_brand'
                             INNER JOIN {$wpdb->terms} t_ed ON t_ed.term_id = tt_ed.term_id";
		$editorial_where = $wpdb->prepare( 'AND t_ed.name = %s', $editorial );
	}

	// Filtro por serie (término de taxonomía)
	$serie_join  = '';
	$serie_where = '';
	if ( $serie_id > 0 ) {
		$serie_join  = "INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id AND tr.term_taxonomy_id = %d";
		$serie_where = '';
		$serie_join  = $wpdb->prepare(
			"INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'pa_serie' AND tt.term_id = %d",
			$serie_id
		);
	}

	$sql_base = "
        FROM {$wpdb->posts} p
        {$status_join}
        {$editorial_join}
        {$serie_join}
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        {$status_where}
        {$search_where}
        {$editorial_where}
    ";

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql_base construido con cláusulas estáticas + $wpdb->prepare() por filtro (líneas 113/123/132/135 arriba). $per_page/$offset son ints validados (max/min en líneas 73/74).
	$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) {$sql_base}" );

	$rows = $wpdb->get_results(
		"SELECT DISTINCT
            p.ID as product_id,
            p.post_title as name,
            ml.ml_item_id,
            ml.ml_status,
            ml.ml_price,
            ml.ml_price_override,
            ml.ml_stock,
            ml.ml_permalink,
            ml.error_msg,
            ml.synced_at
         {$sql_base}
         ORDER BY p.post_title ASC
         LIMIT {$per_page} OFFSET {$offset}",
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Warm WP object cache y postmeta cache para todos los IDs de la página en 2 queries batch.
	// Sin esto: N×2 queries individuales (get_post + get_post_meta) por producto en el loop.
	if ( ! empty( $rows ) ) {
		$all_pids = array_map( 'intval', array_column( $rows, 'product_id' ) );
		_prime_post_caches( $all_pids, true, true );
		update_meta_cache( 'post', $all_pids );
	}

	$products = array();
	foreach ( $rows as $row ) {
		$product  = wc_get_product( (int) $row['product_id'] );
		$wc_price = $product ? (float) $product->get_price() : 0;
		$wc_stock = $product ? (int) $product->get_stock_quantity() : 0;
		$override = (int) ( $row['ml_price_override'] ?? 0 );
		$ml_price = $wc_price > 0 ? akb_ml_calculate_price( $wc_price, $override ) : 0;

		$products[] = array(
			'id'         => (int) $row['product_id'],
			'title'      => $row['name'],
			'thumb'      => get_the_post_thumbnail_url( (int) $row['product_id'], array( 48, 48 ) ) ?: '',
			'wc_price'   => $wc_price,
			'wc_stock'   => $wc_stock,
			'ml_item_id' => $row['ml_item_id'] ?? '',
			'ml_status'  => $row['ml_status'] ?? '',
			'ml_calc'    => $ml_price,
			'override'   => $override,
			'permalink'  => $row['ml_permalink'] ?? '',
			'error_msg'  => $row['error_msg'] ?? '',
			'synced_at'  => $row['synced_at'] ? substr( $row['synced_at'], 0, 16 ) : '',
			'edit_url'   => get_edit_post_link( (int) $row['product_id'], 'raw' ),
		);
	}

	wp_send_json_success(
		array(
			'items'    => $products,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		)
	);
}

function akb_ml_ajax_publish_handler( array $post = array() ): void {
	// Nonce/capability ya validados por akb_ajax_endpoint().
	$product_id = (int) ( $post['product_id'] ?? 0 );
	$result     = akb_ml_publish( $product_id );
	if ( isset( $result['error'] ) ) {
		wp_send_json_error( array( 'message' => $result['error'] ) );
	}
	wp_send_json_success( $result );
}

function akb_ml_ajax_toggle_status_handler( array $post = array() ): void {
	// Nonce/capability ya validados por akb_ajax_endpoint().
	$product_id = (int) ( $post['product_id'] ?? 0 );
	$action     = sanitize_text_field( $post['ml_action'] ?? '' );
	$result     = ( $action === 'pause' ) ? akb_ml_pause( $product_id ) : akb_ml_reactivate( $product_id );
	if ( isset( $result['error'] ) ) {
		wp_send_json_error( array( 'message' => $result['error'] ) );
	}
	wp_send_json_success( $result );
}

// ── Bulk publish (encola via Action Scheduler) ────────────────────────────
function akb_ml_ajax_bulk_publish_handler( array $post = array() ): void {
	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		wp_send_json_error( array( 'message' => 'Action Scheduler no disponible. Asegúrate de que WooCommerce esté activo.' ) );
	}

	// Nonce/capability ya validados por akb_ajax_endpoint().
	$ids = array_filter( array_map( 'intval', (array) ( $post['ids'] ?? array() ) ) );
	if ( empty( $ids ) ) {
		wp_send_json_error( array( 'message' => 'No se proporcionaron productos' ) );
	}

	$job_id = uniqid( 'bulk_', true );
	update_option(
		'akb_ml_bulk_progress',
		array(
			'job_id'   => $job_id,
			'total'    => count( $ids ),
			'ok'       => 0,
			'errors'   => 0,
			'done'     => false,
			'messages' => array(),
		),
		false
	);

	foreach ( $ids as $pid ) {
		as_enqueue_async_action(
			'akb_ml_publish_single_async',
			array(
				'product_id' => (int) $pid,
				'job_id'     => $job_id,
			),
			'akibara-ml'
		);
	}

	akb_ml_log( 'bulk', "Bulk publish job {$job_id}: " . count( $ids ) . ' productos encolados' );
	wp_send_json_success(
		array(
			'job_id'   => $job_id,
			'total'    => count( $ids ),
			'enqueued' => true,
		)
	);
}

// ── Publish all available (productos con stock, no publicados) ────────────
function akb_ml_ajax_publish_all_available_handler(): void {
	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		wp_send_json_error( array( 'message' => 'Action Scheduler no disponible.' ) );
	}

	global $wpdb;

	// Productos publicados (activos o pausados) → excluir
	$published_ids = $wpdb->get_col(
		"SELECT product_id FROM {$wpdb->prefix}akb_ml_items WHERE ml_item_id != '' AND ml_status IN ('active','paused')"
	);

	// Límite duro para evitar reventar memoria y saturar Action Scheduler.
	// Si hay más productos disponibles, se verá reflejado en el total y se podrá ejecutar nuevamente.
	$args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 500,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'     => '_stock',
				'value'   => 0,
				'compare' => '>',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => '_stock_status',
				'value'   => 'instock',
				'compare' => '=',
			),
		),
	);
	if ( ! empty( $published_ids ) ) {
		$args['post__not_in'] = array_map( 'intval', $published_ids );
	}

	$ids = get_posts( $args );
	if ( empty( $ids ) ) {
		wp_send_json_success(
			array(
				'total'    => 0,
				'enqueued' => false,
				'message'  => 'No hay productos disponibles para publicar',
			)
		);
	}

	$job_id = uniqid( 'pub_all_', true );
	update_option(
		'akb_ml_bulk_progress',
		array(
			'job_id'   => $job_id,
			'total'    => count( $ids ),
			'ok'       => 0,
			'errors'   => 0,
			'done'     => false,
			'messages' => array(),
		),
		false
	);

	foreach ( $ids as $pid ) {
		as_enqueue_async_action(
			'akb_ml_publish_single_async',
			array(
				'product_id' => (int) $pid,
				'job_id'     => $job_id,
			),
			'akibara-ml'
		);
	}

	akb_ml_log( 'bulk', "Publish all encolado: {$job_id} con " . count( $ids ) . ' productos' );
	wp_send_json_success(
		array(
			'job_id'   => $job_id,
			'total'    => count( $ids ),
			'enqueued' => true,
		)
	);
}

// ── Async single publish handler (Action Scheduler) ──────────────────────
add_action(
	'akb_ml_publish_single_async',
	static function ( int $product_id, string $job_id = '' ): void {
		usleep( 300000 ); // 300ms rate limit entre publicaciones

		$result = akb_ml_publish( $product_id );

		// Actualizar progreso protegido con lock atómico (evita lost updates entre workers concurrentes de Action Scheduler)
		if ( $job_id ) {
			$lock_name = 'bulk_progress_' . $job_id;
			$acquired  = false;
			// Spin retry: hasta 20 intentos × 50ms = max 1s de espera
			for ( $attempt = 0; $attempt < 20 && ! $acquired; $attempt++ ) {
				$acquired = akb_ml_acquire_lock( $lock_name, 5 );
				if ( ! $acquired ) {
					usleep( 50000 );
				}
			}
			if ( ! $acquired ) {
				akb_ml_log( 'bulk', "Lock timeout para progreso job={$job_id} product={$product_id} (1s)", 'warning' );
				return;
			}
			try {
				$progress = get_option( 'akb_ml_bulk_progress', array() );
				if ( ( $progress['job_id'] ?? '' ) === $job_id ) {
					if ( isset( $result['error'] ) ) {
						$progress['errors']++;
						$progress['messages'][] = "ID {$product_id}: " . $result['error'];
					} else {
						$progress['ok']++;
					}
					$processed = $progress['ok'] + $progress['errors'];
					if ( $processed >= $progress['total'] ) {
						$progress['done'] = true;
						akb_ml_log( 'bulk', sprintf( 'Job %s completado: %d ok, %d errores de %d', $job_id, $progress['ok'], $progress['errors'], $progress['total'] ) );
						delete_transient( 'akb_ml_remote_map' );
					}
					update_option( 'akb_ml_bulk_progress', $progress, false );
				}
			} finally {
				akb_ml_release_lock( $lock_name );
			}
		}
	},
	10,
	2
);

// ── Polling de progreso bulk ──────────────────────────────────────────────
function akb_ml_ajax_bulk_progress_handler( array $post = array() ): void {
	// Nonce/capability ya validados por akb_ajax_endpoint().
	$progress = get_option( 'akb_ml_bulk_progress', array() );
	$job_id   = sanitize_text_field( $post['job_id'] ?? '' );

	if ( $job_id !== '' && ( $progress['job_id'] ?? '' ) !== $job_id ) {
		wp_send_json_success(
			array(
				'job_id'  => $job_id,
				'waiting' => true,
			)
		);
	}

	wp_send_json_success( $progress );
}

/**
 * Limpia el estado de error de un producto sin republicarlo.
 * Útil cuando el error ya fue resuelto externamente (ej: en panel ML)
 * o cuando se quiere forzar una nueva publicación desde cero.
 */
function akb_ml_ajax_clear_error_handler( array $post = array() ): void {
	// Nonce/capability ya validados por akb_ajax_endpoint().
	$product_id = (int) ( $post['product_id'] ?? 0 );
	if ( $product_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'ID inválido' ) );
	}

	$row = akb_ml_db_row( $product_id );
	if ( ! $row || $row['ml_status'] !== 'error' ) {
		wp_send_json_error( array( 'message' => 'El producto no tiene estado de error' ) );
	}

	akb_ml_db_upsert(
		$product_id,
		array(
			'ml_item_id'   => '',
			'ml_status'    => '',
			'ml_price'     => 0,
			'ml_stock'     => 0,
			'ml_permalink' => '',
			'error_msg'    => null,
		)
	);

	akb_ml_log( 'admin', "Error limpiado manualmente para producto #{$product_id}" );
	wp_send_json_success( array( 'message' => 'Error limpiado. El producto puede volver a publicarse.' ) );
}

// ══════════════════════════════════════════════════════════════════
// METABOX EN EDIT-PRODUCT — acceso rápido a estado y acciones ML
// Reutiliza endpoints AJAX existentes (akb_ml_publish, akb_ml_toggle_status)
// ══════════════════════════════════════════════════════════════════

add_action(
	'add_meta_boxes_product',
	static function (): void {
		add_meta_box(
			'akb_ml_product_metabox',
			'📦 MercadoLibre',
			'akb_ml_render_product_metabox',
			'product',
			'side',
			'default'
		);
	}
);

function akb_ml_render_product_metabox( WP_Post $post ): void {
	$product = wc_get_product( $post->ID );
	if ( ! $product ) {
		echo '<p style="color:#666;">Producto no disponible.</p>';
		return;
	}

	$pid       = (int) $post->ID;
	$row       = akb_ml_db_row( $pid );
	$status    = $row['ml_status'] ?? '';
	$item_id   = $row['ml_item_id'] ?? '';
	$error     = $row['error_msg'] ?? '';
	$permalink = $row['ml_permalink'] ?? '';
	$override  = (int) ( $row['ml_price_override'] ?? 0 );
	$synced    = $row['synced_at'] ?? '';

	$wc_price = (float) $product->get_price();
	$wc_stock = (int) $product->get_stock_quantity();
	$ml_calc  = $wc_price > 0 ? akb_ml_calculate_price( $wc_price, $override ) : 0;

	$nonce = wp_create_nonce( 'akb_ml_nonce' );

	// Determinar estado y badge
	$badge_color = '#666';
	$badge_text  = 'No publicado';
	if ( $status === 'active' ) {
		$badge_color = '#10B981';
		$badge_text  = '● Activo'; } elseif ( $status === 'paused' ) {
		$badge_color = '#F59E0B';
		$badge_text  = '⏸ Pausado'; } elseif ( $status === 'error' ) {
			$badge_color = '#D90010';
			$badge_text  = '✕ Error'; }

		// Kill-switch check (deshabilitar botones si disabled=true)
		$disabled_global = (bool) akb_ml_opt( 'disabled', false );

		if ( ! $permalink && $item_id ) {
			$permalink = 'https://articulo.mercadolibre.cl/' . preg_replace( '/^(MLC)(\d)/', '$1-$2', $item_id );
		}
		?>
	<div class="akb-ml-metabox" data-pid="<?php echo esc_attr( $pid ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
		<?php if ( $disabled_global ) : ?>
			<div style="background:#FEF2F2;border:1px solid #D90010;color:#991B1B;padding:8px;border-radius:4px;margin-bottom:10px;font-size:12px;">
				🚨 <strong>Integración ML deshabilitada</strong> (kill-switch). Activa desde WooCommerce → MercadoLibre.
			</div>
		<?php endif; ?>

		<p style="margin:0 0 8px;">
			<span style="display:inline-block;padding:3px 10px;border-radius:12px;background:<?php echo esc_attr( $badge_color ); ?>;color:#fff;font-size:12px;font-weight:600;">
				<?php echo esc_html( $badge_text ); ?>
			</span>
		</p>

		<?php if ( $item_id ) : ?>
			<p style="margin:4px 0;font-size:12px;">
				<strong>Item ID:</strong>
				<a href="<?php echo esc_url( $permalink ); ?>" target="_blank" rel="noopener" style="text-decoration:none;">
					<?php echo esc_html( $item_id ); ?> ↗
				</a>
			</p>
		<?php endif; ?>

		<?php if ( $wc_price > 0 ) : ?>
			<p style="margin:4px 0;font-size:12px;">
				<strong>Precio WC:</strong> $<?php echo number_format( $wc_price, 0, ',', '.' ); ?> CLP<br>
				<strong>Precio ML:</strong> $<?php echo number_format( $ml_calc, 0, ',', '.' ); ?> CLP
				<?php if ( $override > 0 ) : ?>
					<span style="color:#F59E0B;" title="Precio manual (override)">✏️</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<p style="margin:4px 0;font-size:12px;">
			<strong>Stock WC:</strong> <?php echo (int) $wc_stock; ?>
		</p>

		<?php if ( $synced ) : ?>
			<p style="margin:4px 0;font-size:11px;color:#666;">
				Sync: <?php echo esc_html( substr( $synced, 0, 16 ) ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $error ) : ?>
			<div style="background:#FEF2F2;border-left:3px solid #D90010;padding:6px 8px;margin:8px 0;font-size:11px;color:#991B1B;max-height:80px;overflow:auto;">
				<?php echo esc_html( $error ); ?>
			</div>
		<?php endif; ?>

		<div class="akb-ml-metabox-actions" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:4px;">
			<?php if ( ! $item_id || $status === '' || $status === 'error' ) : ?>
				<button type="button" class="button button-primary akb-ml-mb-publish" <?php disabled( $disabled_global ); ?>>
					📤 Publicar en ML
				</button>
			<?php elseif ( $status === 'active' ) : ?>
				<button type="button" class="button button-primary akb-ml-mb-publish" <?php disabled( $disabled_global ); ?>>
					🔄 Actualizar
				</button>
				<button type="button" class="button akb-ml-mb-toggle" data-action="pause" <?php disabled( $disabled_global ); ?>>
					⏸ Pausar
				</button>
			<?php elseif ( $status === 'paused' ) : ?>
				<button type="button" class="button akb-ml-mb-toggle" data-action="activate" <?php disabled( $disabled_global ); ?>>
					▶ Activar
				</button>
				<button type="button" class="button akb-ml-mb-publish" <?php disabled( $disabled_global ); ?>>
					🔄 Actualizar
				</button>
			<?php endif; ?>
		</div>

		<div class="akb-ml-mb-result" style="margin-top:8px;font-size:12px;display:none;"></div>

		<p style="margin:10px 0 0;font-size:11px;color:#666;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara&tab=mercadolibre' ) ); ?>">Ver panel ML completo →</a>
		</p>
	</div>

	<script>
	(function () {
		var box    = document.querySelector('.akb-ml-metabox');
		if (!box) return;
		var pid    = box.dataset.pid;
		var nonce  = box.dataset.nonce;
		var result = box.querySelector('.akb-ml-mb-result');

		function doAjax(action, extra, btn) {
			var labelOrig = btn.textContent;
			btn.textContent = '…';
			btn.disabled = true;
			result.style.display = 'none';

			var data = new URLSearchParams();
			data.append('action', action);
			data.append('nonce', nonce);
			data.append('product_id', pid);
			Object.keys(extra || {}).forEach(function (k) { data.append(k, extra[k]); });

			fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					result.style.display = 'block';
					if (res.success) {
						result.style.color = '#10B981';
						result.innerHTML = '✓ Actualizado. <a href="' + location.href + '">Recargar</a>';
					} else {
						result.style.color = '#D90010';
						result.textContent = '✕ ' + ((res.data && res.data.message) || 'Error');
						btn.textContent = labelOrig;
						btn.disabled = false;
					}
				})
				.catch(function (e) {
					result.style.display = 'block';
					result.style.color = '#D90010';
					result.textContent = '✕ Error de red';
					btn.textContent = labelOrig;
					btn.disabled = false;
				});
		}

		var pubBtn = box.querySelector('.akb-ml-mb-publish');
		if (pubBtn) pubBtn.addEventListener('click', function () { doAjax('akb_ml_publish', {}, pubBtn); });

		var tgBtn = box.querySelector('.akb-ml-mb-toggle');
		if (tgBtn) tgBtn.addEventListener('click', function () {
			doAjax('akb_ml_toggle_status', { ml_action: tgBtn.dataset.action }, tgBtn);
		});
	})();
	</script>
	<?php
}
