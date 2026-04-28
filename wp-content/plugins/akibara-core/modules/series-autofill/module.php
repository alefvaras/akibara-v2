<?php
/**
 * Akibara Core — Series Autofill
 *
 * Extrae automáticamente el nombre de la serie desde el título de cada producto
 * y lo guarda en el meta `_akibara_serie` (+ `_akibara_serie_norm`).
 *
 * Arquitectura (3 capas):
 *  1. Hook en save_post_product — auto-fill para productos nuevos/editados.
 *  2. Migración histórica via Action Scheduler — backlog en chunks de 50.
 *  3. UI admin como tab del hub Akibara (grupo Operación → Auto-Series) — stats
 *     + controles de migración. Se integra vía filtro `akibara_admin_tabs`; si
 *     el hub no está cargado, cae a submenu legacy bajo WooCommerce.
 *
 * Impacto SEO: el schema JSON-LD del enricher `akibara_enrich_product_book_schema`
 * lee `_akibara_serie` y genera `isPartOf: BookSeries`, reconocido por Google.
 *
 * Migrado desde akibara/modules/series-autofill/module.php (Polish #1 2026-04-26).
 * Sentinel + group wrap per HANDOFF §8 (REDESIGN.md §9).
 *
 * @package Akibara\Core
 * @since   10.1.0
 */

// ─── File-level guards en global namespace (bracketed syntax para coexistir con namespaced code abajo) ─
namespace {
	defined( 'ABSPATH' ) || exit;

	if ( defined( 'AKB_CORE_SERIES_AUTOFILL_LOADED' ) ) {
		return;
	}
	define( 'AKB_CORE_SERIES_AUTOFILL_LOADED', '1.0.0' );

	if ( ! defined( 'AKB_CORE_MODULE_SERIES_AUTOFILL_LOADED' ) ) {
		define( 'AKB_CORE_MODULE_SERIES_AUTOFILL_LOADED', '1.0.0' );
	}

	require_once __DIR__ . '/class-extractor.php';
	require_once __DIR__ . '/class-migration.php';
}

namespace Akibara\SeriesAutofill {

// ─── Capa 2: Registrar hook del Action Scheduler ──────────────────
Migration::register_hooks();

// ─── Capa 1: Auto-fill en productos nuevos/editados ───────────────
add_action( 'woocommerce_update_product', __NAMESPACE__ . '\autofill_on_product_save', 20, 1 );
add_action( 'woocommerce_new_product', __NAMESPACE__ . '\autofill_on_product_save', 20, 1 );

// ─── Capa 1.5: Defensa contra writes externos al meta _akibara_serie_norm ──
add_action( 'updated_post_meta', __NAMESPACE__ . '\ensure_serie_consistency', 10, 4 );
add_action( 'added_post_meta', __NAMESPACE__ . '\ensure_serie_consistency', 10, 4 );

/**
 * Garantiza que _akibara_serie + _akibara_serie_norm + pa_serie term estén sincronizados
 * cuando algún flujo externo escribe solo el norm meta.
 */
function ensure_serie_consistency( int $meta_id, int $object_id, string $meta_key, $meta_value ): void {
	if ( $meta_key !== '_akibara_serie_norm' ) {
		return;
	}
	if ( $meta_value === '' || $meta_value === false ) {
		return;
	}
	if ( get_post_type( $object_id ) !== 'product' ) {
		return;
	}

	$display = get_post_meta( $object_id, '_akibara_serie', true );

	if ( $display === '' || $display === false ) {
		$slug_form = sanitize_title( (string) $meta_value );
		$norm_form = str_replace( '-', '', $slug_form );
		$name_map  = function_exists( '\akibara_serie_name_map' ) ? \akibara_serie_name_map() : array();

		if ( isset( $name_map[ $norm_form ] ) ) {
			$display = $name_map[ $norm_form ];
		} elseif ( isset( $name_map[ $slug_form ] ) ) {
			$display = $name_map[ $slug_form ];
		} else {
			$display = ucwords( str_replace( array( '-', '_' ), ' ', $slug_form ) );
		}

		if ( $display === '' ) {
			return;
		}
		update_post_meta( $object_id, '_akibara_serie', $display );
	}

	sync_pa_serie_term( $object_id, (string) $display );
}

/**
 * Hook callback: si el producto no tiene `_akibara_serie`, la extrae del título.
 *
 * @param int $product_id
 */
function autofill_on_product_save( int $product_id ): void {
	$post = get_post( $product_id );
	if ( ! $post || $post->post_type !== 'product' ) {
		return;
	}

	if ( wp_is_post_revision( $product_id ) || wp_is_post_autosave( $product_id ) ) {
		return;
	}

	$existing = get_post_meta( $product_id, '_akibara_serie', true );

	if ( $existing !== '' && $existing !== false ) {
		sync_pa_serie_term( $product_id, (string) $existing );
		return;
	}

	$number = (string) get_post_meta( $product_id, '_akibara_numero', true );
	$brands = get_the_terms( $product_id, 'product_brand' );
	$brand  = ( $brands && ! is_wp_error( $brands ) && ! empty( $brands[0] ) ) ? $brands[0]->name : '';

	$serie = Extractor::extract_serie( $post->post_title, $number, $brand );
	if ( $serie === '' ) {
		return;
	}

	update_post_meta( $product_id, '_akibara_serie', $serie );
	update_post_meta( $product_id, '_akibara_serie_norm', Extractor::normalize_serie_slug( $serie ) );

	sync_pa_serie_term( $product_id, $serie );

	if ( $number === '' || $number === '0' ) {
		$num = Extractor::extract_numero( $post->post_title );
		if ( $num !== '' ) {
			update_post_meta( $product_id, '_akibara_numero', $num );
		}
	}
}

/**
 * Canonicaliza un display name de serie usando akibara_serie_name_map().
 */
function canonicalize_serie_name( string $name ): string {
	if ( $name === '' ) {
		return $name;
	}
	static $name_map = null;
	if ( $name_map === null ) {
		$name_map = function_exists( '\akibara_serie_name_map' ) ? \akibara_serie_name_map() : array();
	}
	$slug_form = sanitize_title( $name );
	$norm_form = str_replace( '-', '', $slug_form );
	if ( isset( $name_map[ $norm_form ] ) ) {
		return $name_map[ $norm_form ];
	}
	if ( isset( $name_map[ $slug_form ] ) ) {
		return $name_map[ $slug_form ];
	}
	return $name;
}

/**
 * Asegura que existe el term `pa_serie` correspondiente a la serie y lo asigna al producto.
 *
 * @param int    $product_id
 * @param string $serie_name Display name.
 * @return int|null term_id asignado, o null si no se pudo.
 */
function sync_pa_serie_term( int $product_id, string $serie_name ): ?int {
	if ( $serie_name === '' ) {
		return null;
	}

	$serie_name = canonicalize_serie_name( $serie_name );
	$slug       = Extractor::normalize_serie_slug( $serie_name );
	if ( $slug === '' ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, 'pa_serie' );
	if ( $term ) {
		$term_id = (int) $term->term_id;
	} else {
		$created = wp_insert_term( $serie_name, 'pa_serie', array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			if ( $created->get_error_code() === 'term_exists' ) {
				$existing_id = $created->get_error_data();
				if ( $existing_id ) {
					$term_id = (int) $existing_id;
				} else {
					return null;
				}
			} else {
				return null;
			}
		} else {
			$term_id = (int) $created['term_id'];
		}
	}

	wp_set_object_terms( $product_id, array( $term_id ), 'pa_serie', false );
	return $term_id;
}

// ─── Capa 3: UI admin (tab del hub Akibara) ───────────────────────

add_action( 'admin_post_akibara_series_migrate_start', __NAMESPACE__ . '\handle_admin_migrate_start' );
add_action( 'admin_post_akibara_series_migrate_cancel', __NAMESPACE__ . '\handle_admin_migrate_cancel' );
add_action( 'admin_post_akibara_series_sync_pa_serie', __NAMESPACE__ . '\handle_admin_sync_pa_serie' );

/**
 * Backfill pa_serie taxonomy desde _akibara_serie meta existente.
 *
 * @return array{scanned:int, synced:int, skipped:int}
 */
function backfill_pa_serie_terms(): array {
	global $wpdb;

	@set_time_limit( 300 );
	wp_suspend_cache_invalidation( true );

	$products = $wpdb->get_results(
		"SELECT p.ID, pm.meta_value AS serie_name
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			AND pm.meta_key = '_akibara_serie'
			AND pm.meta_value != ''
		 WHERE p.post_type = 'product' AND p.post_status = 'publish'"
	);

	$scanned       = 0;
	$synced        = 0;
	$skipped       = 0;
	$touched_terms = array();

	foreach ( (array) $products as $row ) {
		++$scanned;
		$pid  = (int) $row->ID;
		$name = (string) $row->serie_name;

		$canonical_name = canonicalize_serie_name( $name );
		$canonical_slug = Extractor::normalize_serie_slug( $canonical_name );
		$existing       = wp_get_object_terms( $pid, 'pa_serie', array( 'fields' => 'slugs' ) );

		if ( ! is_wp_error( $existing ) && in_array( $canonical_slug, (array) $existing, true ) ) {
			++$skipped;
			continue;
		}

		$term_id = sync_pa_serie_term( $pid, $name );
		if ( $term_id ) {
			$touched_terms[ $term_id ] = true;
			++$synced;
		}
	}

	// Pasada 2: productos huérfanos.
	$orphans = $wpdb->get_results(
		"SELECT p.ID, pm_norm.meta_value AS serie_norm
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm_norm ON pm_norm.post_id = p.ID
			AND pm_norm.meta_key = '_akibara_serie_norm'
			AND pm_norm.meta_value != ''
		 LEFT JOIN {$wpdb->postmeta} pm_serie ON pm_serie.post_id = p.ID
			AND pm_serie.meta_key = '_akibara_serie'
		 WHERE p.post_type = 'product' AND p.post_status = 'publish'
		   AND ( pm_serie.meta_value IS NULL OR pm_serie.meta_value = '' )"
	);

	$name_map = function_exists( '\akibara_serie_name_map' ) ? \akibara_serie_name_map() : array();

	foreach ( (array) $orphans as $row ) {
		++$scanned;
		$pid       = (int) $row->ID;
		$slug_form = sanitize_title( (string) $row->serie_norm );
		$norm_form = str_replace( '-', '', $slug_form );

		if ( isset( $name_map[ $norm_form ] ) ) {
			$display = $name_map[ $norm_form ];
		} elseif ( isset( $name_map[ $slug_form ] ) ) {
			$display = $name_map[ $slug_form ];
		} else {
			$display = ucwords( str_replace( array( '-', '_' ), ' ', $slug_form ) );
		}

		if ( $display === '' ) {
			++$skipped;
			continue;
		}

		update_post_meta( $pid, '_akibara_serie', $display );

		$term_id = sync_pa_serie_term( $pid, $display );
		if ( $term_id ) {
			$touched_terms[ $term_id ] = true;
			++$synced;
		}
	}

	wp_suspend_cache_invalidation( false );

	if ( ! empty( $touched_terms ) ) {
		wp_update_term_count_now( array_keys( $touched_terms ), 'pa_serie' );
	}

	clean_taxonomy_cache( 'pa_serie' );

	return compact( 'scanned', 'synced', 'skipped' );
}

function handle_admin_sync_pa_serie(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos' );
	}
	check_admin_referer( 'akibara_series_sync_pa_serie' );

	$result = backfill_pa_serie_terms();
	wp_safe_redirect(
		get_return_url(
			array(
				'synced'  => $result['synced'],
				'scanned' => $result['scanned'],
			)
		)
	);
	exit;
}

// Tab en el hub Akibara (grupo Operación).
add_filter(
	'akibara_admin_tabs',
	function ( array $tabs ): array {
		$tabs['series_autofill'] = array(
			'label'       => 'Auto-Series',
			'short_label' => 'Auto-Series',
			'icon'        => 'dashicons-tag',
			'group'       => 'operacion',
			'callback'    => __NAMESPACE__ . '\render_admin_page',
		);
		return $tabs;
	}
);

// Legacy fallback: submenu suelto bajo WooCommerce si el hub no está cargado.
add_action( 'admin_menu', __NAMESPACE__ . '\register_legacy_menu' );

function register_legacy_menu(): void {
	if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
		return;
	}
	add_submenu_page(
		'akibara',
		'Auto-Series',
		'🔁 Auto-Series',
		'manage_woocommerce',
		'akibara-series-autofill',
		__NAMESPACE__ . '\render_admin_page'
	);
}

/**
 * Estadísticas de cobertura del catálogo.
 *
 * @return array{total:int, with:int, without:int, coverage:float}
 */
function get_coverage(): array {
	global $wpdb;

	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
			'product',
			'publish'
		)
	);

	$with = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
		 WHERE meta_key = %s AND meta_value != ''",
			'_akibara_serie'
		)
	);

	return array(
		'total'    => $total,
		'with'     => $with,
		'without'  => max( 0, $total - $with ),
		'coverage' => $total > 0 ? round( $with / $total * 100, 1 ) : 0.0,
	);
}

/**
 * URL de retorno tras un handler admin-post.
 */
function get_return_url( array $extra = array() ): string {
	$base = defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' )
		? array(
			'page' => 'akibara',
			'tab'  => 'series_autofill',
		)
		: array( 'page' => 'akibara-series-autofill' );
	return add_query_arg( array_merge( $base, $extra ), admin_url( 'admin.php' ) );
}

function render_admin_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$stats    = Migration::get_status();
	$coverage = get_coverage();

	$is_running = $stats['status'] === 'running';
	$pct        = $stats['chunks_total'] > 0
		? round( $stats['chunks_done'] / $stats['chunks_total'] * 100, 1 )
		: 0;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$just_started   = isset( $_GET['started'] );
	$just_cancelled = isset( $_GET['cancelled'] );
	$just_synced    = isset( $_GET['synced'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	?>
	<div class="akb-page-header">
		<h2 class="akb-page-header__title">Auto-Series</h2>
		<p class="akb-page-header__desc">
			Extrae automáticamente el nombre de la serie desde el título de cada producto
			y lo guarda en <code>_akibara_serie</code>. El schema JSON-LD
			(<code>@type: Book</code>) usa este valor para generar
			<code>isPartOf: BookSeries</code>, reconocido por Google.
		</p>
	</div>

	<?php if ( $just_started ) : ?>
		<div class="akb-notice akb-notice--info">
			<strong>Migración iniciada</strong> — <?php echo (int) $_GET['started']; ?>
			productos encolados en <?php echo (int) $_GET['chunks']; ?> chunks de
			<?php echo (int) Migration::CHUNK_SIZE; ?>.
		</div>
	<?php elseif ( $just_cancelled ) : ?>
		<div class="akb-notice akb-notice--warning">
			<strong>Migración cancelada</strong> — jobs pendientes eliminados del Action Scheduler.
		</div>
	<?php elseif ( $just_synced ) : ?>
		<div class="akb-notice akb-notice--success">
			<strong>Sincronización pa_serie completa</strong> —
			<?php echo (int) $_GET['synced']; ?> términos sincronizados
			de <?php echo (int) $_GET['scanned']; ?> productos con meta.
		</div>
	<?php endif; ?>

	<!-- Stats -->
	<div class="akb-stats">
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--brand"><?php echo esc_html( number_format_i18n( $coverage['total'] ) ); ?></div>
			<div class="akb-stat__label">Productos</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--success"><?php echo esc_html( number_format_i18n( $coverage['with'] ) ); ?></div>
			<div class="akb-stat__label">Con serie</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo $coverage['without'] > 0 ? 'akb-stat__value--warning' : 'akb-stat__value--success'; ?>">
				<?php echo esc_html( number_format_i18n( $coverage['without'] ) ); ?>
			</div>
			<div class="akb-stat__label">Pendientes</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value"><?php echo esc_html( number_format_i18n( $coverage['coverage'], 1 ) ); ?>%</div>
			<div class="akb-stat__label">Cobertura</div>
		</div>
	</div>

	<!-- Migración histórica -->
	<div class="akb-card akb-card--section">
		<h3 class="akb-section-title">Migración histórica</h3>

		<?php if ( $is_running ) : ?>
			<div class="akb-notice akb-notice--info">
				<strong>Migración en curso</strong><br>
				Chunks: <strong><?php echo (int) $stats['chunks_done']; ?></strong>
				/ <?php echo (int) $stats['chunks_total']; ?> (<?php echo esc_html( (string) $pct ); ?>%)<br>
				Productos procesados:
				<strong><?php echo esc_html( number_format_i18n( $stats['processed'] ) ); ?></strong>
				/ <?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?><br>
				Series llenadas:
				<strong><?php echo esc_html( number_format_i18n( $stats['filled'] ) ); ?></strong>
				· Saltadas: <?php echo esc_html( number_format_i18n( $stats['skipped'] ) ); ?><br>
				Iniciada
				<?php
				echo $stats['started_at']
					? esc_html( human_time_diff( $stats['started_at'] ) ) . ' atrás'
					: '—';
				?>
				· Último chunk
				<?php
				echo $stats['last_run_at']
					? esc_html( human_time_diff( $stats['last_run_at'] ) ) . ' atrás'
					: '—';
				?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="akibara_series_migrate_cancel">
				<?php wp_nonce_field( 'akibara_series_migrate_cancel' ); ?>
				<button type="submit" class="akb-btn akb-btn--danger"
					onclick="return confirm('¿Cancelar jobs pendientes del Action Scheduler?')">
					Cancelar migración
				</button>
			</form>

		<?php elseif ( $stats['status'] === 'completed' ) : ?>
			<div class="akb-notice akb-notice--success">
				<strong>Migración completada</strong><br>
				Total: <?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?>
				· Llenadas: <strong><?php echo esc_html( number_format_i18n( $stats['filled'] ) ); ?></strong>
				· Saltadas: <?php echo esc_html( number_format_i18n( $stats['skipped'] ) ); ?><br>
				Terminada hace
				<?php
				echo $stats['last_run_at']
					? esc_html( human_time_diff( $stats['last_run_at'] ) )
					: '—';
				?>
			</div>
		<?php endif; ?>

		<?php if ( ! $is_running ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="akibara_series_migrate_start">
				<?php wp_nonce_field( 'akibara_series_migrate_start' ); ?>

				<div class="akb-field">
					<label class="akb-field__label">
						<input type="checkbox" name="include_with_serie" value="1">
						Incluir productos que ya tienen serie (re-procesar todo)
					</label>
					<p class="akb-field__hint">
						Por defecto solo procesa productos sin <code>_akibara_serie</code>.
						Activar si cambió el extractor y hay que re-generar todas las series.
					</p>
				</div>

				<button type="submit" class="akb-btn akb-btn--primary">
					Iniciar migración
					<span style="opacity:.7;font-weight:400;margin-left:6px">
						(chunks de <?php echo (int) Migration::CHUNK_SIZE; ?>,
						cada <?php echo (int) Migration::CHUNK_DELAY; ?>s)
					</span>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<!-- Sync pa_serie taxonomy -->
	<div class="akb-card akb-card--section">
		<h3 class="akb-section-title">Sincronizar taxonomía pa_serie</h3>
		<p class="akb-card__body" style="margin-bottom:var(--akb-s4)">
			Crea/asigna el término <code>pa_serie</code> a cada producto que tenga
			<code>_akibara_serie</code> meta pero sin term correspondiente.
			Idempotente, no modifica metas.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="akibara_series_sync_pa_serie">
			<?php wp_nonce_field( 'akibara_series_sync_pa_serie' ); ?>
			<button type="submit" class="akb-btn akb-btn--primary"
				onclick="return confirm('¿Sincronizar pa_serie con _akibara_serie para todos los productos? (Idempotente — no rompe nada)')">
				Sincronizar pa_serie
			</button>
		</form>
	</div>

	<!-- Vista previa del extractor -->
	<div class="akb-card akb-card--section">
		<h3 class="akb-section-title">Vista previa del extractor</h3>
		<p class="akb-card__body" style="margin-bottom:var(--akb-s4)">
			Muestra de 10 productos sin serie con la extracción que se aplicaría al ejecutar la migración.
		</p>
		<?php render_preview_table(); ?>
	</div>
	<?php
}

function render_preview_table(): void {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title
		 FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
		 WHERE p.post_type = %s AND p.post_status = %s
		   AND ( pm.meta_value IS NULL OR pm.meta_value = '' )
		 ORDER BY RAND()
		 LIMIT 10",
			'_akibara_serie',
			'product',
			'publish'
		)
	);

	if ( ! $rows ) {
		echo '<div class="akb-notice akb-notice--success" style="margin:0"><strong>Cobertura 100%</strong> — no hay productos sin serie.</div>';
		return;
	}

	echo '<table class="akb-table"><thead><tr>';
	echo '<th>ID</th><th>Título</th><th>Serie extraída</th></tr></thead><tbody>';

	foreach ( $rows as $r ) {
		$num    = (string) get_post_meta( $r->ID, '_akibara_numero', true );
		$brands = get_the_terms( $r->ID, 'product_brand' );
		$brand  = ( $brands && ! is_wp_error( $brands ) && ! empty( $brands[0] ) ) ? $brands[0]->name : '';
		$serie  = Extractor::extract_serie( $r->post_title, $num, $brand );

		echo '<tr>';
		echo '<td><code>' . esc_html( (string) $r->ID ) . '</code></td>';
		echo '<td>' . esc_html( $r->post_title ) . '</td>';
		if ( $serie !== '' ) {
			echo '<td><span class="akb-badge akb-badge--active">' . esc_html( $serie ) . '</span></td>';
		} else {
			echo '<td><span class="akb-badge akb-badge--warning">sin resultado</span></td>';
		}
		echo '</tr>';
	}

	echo '</tbody></table>';
}

function handle_admin_migrate_start(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos' );
	}
	check_admin_referer( 'akibara_series_migrate_start' );

	$include_with_serie = ! empty( $_POST['include_with_serie'] );
	$result             = Migration::enqueue_all( $include_with_serie );

	wp_safe_redirect(
		get_return_url(
			array(
				'started' => $result['total'],
				'chunks'  => $result['chunks'],
			)
		)
	);
	exit;
}

function handle_admin_migrate_cancel(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos' );
	}
	check_admin_referer( 'akibara_series_migrate_cancel' );

	Migration::clear_scheduled();
	$stats           = Migration::get_status();
	$stats['status'] = 'idle';
	Migration::update_status( $stats );

	wp_safe_redirect( get_return_url( array( 'cancelled' => 1 ) ) );
	exit;
}
}

