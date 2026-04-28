<?php
/**
 * Akibara – Ordenamiento por Tomo v10
 *
 * Ordena automáticamente los productos WooCommerce por serie y número de tomo,
 * usando el parser de títulos de akibara-core.php.
 *
 * @package Akibara
 * @version 10.0.0
 */

defined( 'ABSPATH' ) || exit;
// Guard: cargar SOLO si plugin akibara legacy (V10) o akibara-core están active.
// Sprint 2 Cell Core Phase 1 — file relocated desde plugins/akibara/ a plugins/akibara-core/.
if ( ! defined( 'AKIBARA_V10_LOADED' ) && ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	return;
}

if ( defined( 'AKIBARA_ORDER_LOADED' ) ) {
	return;
}
define( 'AKIBARA_ORDER_LOADED', '10.0.0' );

// ══════════════════════════════════════════════════════════════════
// CLASE PRINCIPAL
// ══════════════════════════════════════════════════════════════════

if ( ! class_exists( 'Akibara_Order' ) ) {
final class Akibara_Order {

	const VERSION      = '10.0';
	const SERIES_GAP   = 10000;
	const SERIES_START = 1000;
	const META_SERIE   = '_akibara_serie_norm';
	const META_NUMERO  = '_akibara_numero';
	const META_TIPO    = '_akibara_tipo';
	const LOCK_GROUP   = 'akibara_locks';
	const CRON_HOOK    = 'akibara_reorder_cron';

	public static function init(): void {
		add_action( 'save_post_product', array( __CLASS__, 'on_product_save' ), 99 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_reorder' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_filter( 'manage_product_posts_columns', array( __CLASS__, 'add_admin_column' ) );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( __CLASS__, 'sortable_admin_column' ) );
		add_filter( 'woocommerce_default_catalog_orderby', array( __CLASS__, 'default_orderby' ), 999 );
		add_filter( 'woocommerce_get_catalog_ordering_args', array( __CLASS__, 'catalog_args' ), 999 );
		add_action( 'woocommerce_product_query', array( __CLASS__, 'product_query' ), 999 );
		add_filter( 'posts_orderby', array( __CLASS__, 'posts_orderby' ), 999, 2 );
	}

	// ─── Extracción (delega en akibara-core.php) ──────────────────

	public static function extract_info( string $titulo ): array {
		if ( function_exists( 'akb_extract_info' ) ) {
			return akb_extract_info( $titulo );
		}
		return array(
			'serie'      => $titulo,
			'serie_norm' => '',
			'numero'     => 0,
			'tipo'       => 'sin_numero',
			'prioridad'  => 0,
		);
	}

	// ─── Postmeta ─────────────────────────────────────────────────

	public static function write_product_meta( int $post_id ): void {
		$title = get_the_title( $post_id );
		if ( empty( $title ) ) {
			return;
		}
		$info = self::extract_info( $title );
		update_post_meta( $post_id, self::META_SERIE, $info['serie_norm'] );
		update_post_meta( $post_id, self::META_NUMERO, $info['numero'] );
		update_post_meta( $post_id, self::META_TIPO, $info['tipo'] );
	}

	// ─── Reordenamiento bulk ──────────────────────────────────────

	public static function run_reorder( bool $rebuild_meta = false ): array {
		global $wpdb;

		$t = microtime( true );

		// Variables locales para interpolar en SQL (las constantes de clase no se pueden interpolar directamente)
		$ms = self::META_SERIE;
		$mn = self::META_NUMERO;
		$mt = self::META_TIPO;

		if ( $rebuild_meta ) {
			$ids = $wpdb->get_col(
				"SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'product'
                   AND post_status IN ('publish','draft','private')"
			);
			foreach ( $ids as $id ) {
				self::write_product_meta( (int) $id );
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ms/$mn/$mt son meta_keys constantes ('_aki_serie_norm', '_aki_serie_numero', '_aki_serie_tipo') definidas internamente. Sin user input.
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title,
                    COALESCE(pm_s.meta_value,'')  AS serie_norm,
                    COALESCE(pm_n.meta_value,'0') AS numero,
                    COALESCE(pm_t.meta_value,'')  AS tipo
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_s ON (p.ID = pm_s.post_id AND pm_s.meta_key = '{$ms}')
             LEFT JOIN {$wpdb->postmeta} pm_n ON (p.ID = pm_n.post_id AND pm_n.meta_key = '{$mn}')
             LEFT JOIN {$wpdb->postmeta} pm_t ON (p.ID = pm_t.post_id AND pm_t.meta_key = '{$mt}')
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish','draft','private')
             ORDER BY serie_norm ASC"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return array(
				'exito'     => false,
				'series'    => 0,
				'productos' => 0,
				'tiempo'    => 0.0,
				'mensaje'   => 'No se encontraron productos.',
			);
		}

		// Rellenar filas sin meta
		foreach ( $rows as $row ) {
			if ( $row->serie_norm !== '' ) {
				continue;
			}
			self::write_product_meta( (int) $row->ID );
			$info            = self::extract_info( $row->post_title );
			$row->serie_norm = $info['serie_norm'];
			$row->numero     = (string) $info['numero'];
			$row->tipo       = $info['tipo'];
		}

		// Agrupar
		$series = array();
		foreach ( $rows as $row ) {
			$key              = $row->serie_norm ?: '_sin_serie';
			$series[ $key ][] = array(
				'id'     => (int) $row->ID,
				'numero' => (int) $row->numero,
				'tipo'   => $row->tipo,
				'titulo' => $row->post_title,
			);
		}
		ksort( $series );

		$map    = array();
		$offset = self::SERIES_START;
		$esp    = array( 'box_set', 'artbook', 'tomo_unico', 'special_edition' );

		foreach ( $series as $items ) {
			usort(
				$items,
				static function ( $a, $b ) use ( $esp ) {
					$pa = $a['tipo'] === 'sin_numero' ? 99999 : 0;
					$pb = $b['tipo'] === 'sin_numero' ? 99999 : 0;
					if ( $pa !== $pb ) {
						return $pa <=> $pb;
					}
					$sa = in_array( $a['tipo'], $esp, true ) ? 1 : 0;
					$sb = in_array( $b['tipo'], $esp, true ) ? 1 : 0;
					if ( $sa !== $sb ) {
						return $sa <=> $sb;
					}
					if ( $a['numero'] !== $b['numero'] ) {
						return $a['numero'] <=> $b['numero'];
					}
					return strcmp( $a['titulo'], $b['titulo'] );
				}
			);
			foreach ( $items as $i => $item ) {
				$map[ $item['id'] ] = $offset + $i;
			}
			$offset += self::SERIES_GAP;
		}

		$updated = self::bulk_update_menu_order( $map );
		update_option( 'akb_order_last_run', current_time( 'mysql' ) );
		update_option( 'akb_order_series_count', count( $series ) );
		self::flush_cache();

		$tiempo = round( microtime( true ) - $t, 2 );
		return array(
			'exito'     => true,
			'series'    => count( $series ),
			'productos' => $updated,
			'tiempo'    => $tiempo,
			'mensaje'   => "Ordenados {$updated} productos en " . count( $series ) . " series en {$tiempo}s.",
		);
	}

	private static function bulk_update_menu_order( array $map ): int {
		global $wpdb;
		$updated = 0;
		foreach ( array_chunk( $map, 500, true ) as $chunk ) {
			$ids   = implode( ',', array_map( 'intval', array_keys( $chunk ) ) );
			$cases = '';
			foreach ( $chunk as $id => $order ) {
				$cases .= sprintf( 'WHEN %d THEN %d ', (int) $id, (int) $order );
			}
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$r = $wpdb->query( "UPDATE {$wpdb->posts} SET menu_order = CASE ID {$cases} END WHERE ID IN ({$ids})" );
			if ( $r !== false ) {
				$updated += (int) $r;
			}
		}
		return $updated;
	}

	// ─── Lock atómico ─────────────────────────────────────────────

	private static function acquire_lock( string $key, int $ttl = 60 ): bool {
		if ( wp_cache_add( 'lock_' . $key, 1, self::LOCK_GROUP, $ttl ) ) {
			return true;
		}
		global $wpdb;
		$opt = '_akb_lock_' . $key;
		$exp = time() + $ttl;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')
             ON DUPLICATE KEY UPDATE
             option_value = IF(CAST(option_value AS SIGNED) < %d, VALUES(option_value), option_value)",
				$opt,
				(string) $exp,
				time()
			)
		);
		return (int) $wpdb->rows_affected > 0;
	}

	private static function release_lock( string $key ): void {
		wp_cache_delete( 'lock_' . $key, self::LOCK_GROUP );
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => '_akb_lock_' . $key ) );
	}

	// ─── Caché ────────────────────────────────────────────────────

	private static function flush_cache(): void {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		delete_transient( 'wc_term_counts' );
		global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
             WHERE option_name REGEXP '^_transient_(timeout_)?wc_(loop|catalog|product_query)'"
		);
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'posts' );
			wp_cache_flush_group( 'post_meta' );
		}
		if ( function_exists( 'akb_bump_cache_version' ) ) {
			akb_bump_cache_version();
		}
	}

	// ─── Hooks ────────────────────────────────────────────────────

	public static function on_product_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! self::acquire_lock( 'save_' . $post_id, 10 ) ) {
			return;
		}

		self::write_product_meta( $post_id );
		if ( function_exists( 'akb_index_product' ) ) {
			akb_index_product( $post_id );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
		self::release_lock( 'save_' . $post_id );
	}

	public static function cron_reorder(): void {
		if ( ! self::acquire_lock( 'cron_reorder', 300 ) ) {
			return;
		}
		self::run_reorder( false );
		self::release_lock( 'cron_reorder' );
	}

	// ─── Orden frontend ───────────────────────────────────────────

	public static function default_orderby(): string {
		return 'menu_order'; }

	public static function catalog_args( array $args ): array {
		// Respetar la selección del usuario si eligió un orden explícito
		if ( isset( $_GET['orderby'] ) ) {
			return $args;
		}
		$args['orderby'] = 'menu_order';
		$args['order']   = 'ASC';
		return $args;
	}

	public static function product_query( \WP_Query $q ): void {
		// No forzar si el usuario eligió un orden (precio, popularidad, etc.)
		if ( isset( $_GET['orderby'] ) ) {
			return;
		}
		$q->set( 'orderby', 'menu_order' );
		$q->set( 'order', 'ASC' );
	}

	public static function posts_orderby( string $orderby, \WP_Query $query ): string {
		global $wpdb;
		if ( is_admin() ) {
			return $orderby;
		}
		if ( isset( $_GET['orderby'] ) ) {
			return $orderby;
		}
		if ( ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() ) {
			return $orderby;
		}
		if ( $query->get( 'post_type' ) === 'product' ) {
			return "{$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_date DESC";
		}
		return $orderby;
	}

	// ─── Registro de menú ─────────────────────────────────────────

	public static function register_menu(): void {
		if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
			return;
		} add_submenu_page(
			'akibara',
			'Ordenar por Tomo v' . self::VERSION,
			'📚 Ordenar por Tomo',
			'manage_woocommerce',
			'akibara-ordenar-tomos',
			array( __CLASS__, 'admin_page' )
		);
	}

	// ─── Página admin ─────────────────────────────────────────────

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}

		$mensaje  = '';
		$tipo_msg = 'info';

		if ( isset( $_POST['akb_action'] ) && check_admin_referer( 'akb_nonce' ) ) {
			$action = sanitize_key( $_POST['akb_action'] );

			if ( $action === 'reorder_full' ) {
				if ( ! self::acquire_lock( 'manual_reorder', 120 ) ) {
					$tipo_msg = 'warning';
					$mensaje  = '⏳ Reordenamiento en curso. Espera unos segundos.';
				} else {
					$r = self::run_reorder( true );
					self::release_lock( 'manual_reorder' );
					$tipo_msg = $r['exito'] ? 'success' : 'error';
					$mensaje  = $r['exito'] ? "✅ {$r['mensaje']}" : "❌ {$r['mensaje']}";
					if ( $r['exito'] && function_exists( 'akb_rebuild_full_index' ) ) {
						$ri       = akb_rebuild_full_index();
						$mensaje .= " Índice búsqueda: {$ri['productos']} productos en {$ri['tiempo']}s.";
					}
				}
			}
			if ( $action === 'flush_cache' ) {
				self::flush_cache();
				$tipo_msg = 'success';
				$mensaje  = '✅ Caché limpiada.';
			}
			if ( $action === 'rebuild_meta' ) {
				global $wpdb;
				$ids = $wpdb->get_col(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"
				);
				foreach ( $ids as $id ) {
					self::write_product_meta( (int) $id );
					if ( function_exists( 'akb_index_product' ) ) {
						akb_index_product( (int) $id );
					}
				}
				delete_option( 'akibara_needs_rebuild' );
				$tipo_msg = 'success';
				$mensaje  = '✅ Meta + índice reconstruidos para ' . count( $ids ) . ' productos.';
			}
		}

		global $wpdb;
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", 'product', 'publish' ) );
		$ms       = self::META_SERIE;
		$con_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", $ms ) );
		$sin_meta = $total - $con_meta;
		$ultimo   = get_option( 'akb_order_last_run', 'Nunca' );
		$n_series = (int) get_option( 'akb_order_series_count', 0 );

		$tab_param = defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ? 'subtab' : 'tab';
		$tab       = isset( $_GET[ $tab_param ] ) ? sanitize_key( $_GET[ $tab_param ] ) : 'dashboard';

		self::render_header( $tab, $mensaje, $tipo_msg );

		if ( $tab === 'dashboard' ) {
			self::render_dashboard( $total, $n_series, $sin_meta, (string) $ultimo );
		} elseif ( $tab === 'series' ) {
			self::render_series();
		} elseif ( $tab === 'test' ) {
			self::render_test();
		} elseif ( $tab === 'diagnostico' ) {
			self::render_diag();
		}

		echo '</div>'; // .akb-wrap
	}

	// ─── Render helpers ───────────────────────────────────────────

	private static function render_header( string $tab, string $msg, string $tipo ): void {
		?>
		<div class="wrap akb-wrap">
		<style>
		.akb-wrap h1{display:flex;align-items:center;gap:10px}
		.akb-tabs{display:flex;margin:20px 0 0;border-bottom:2px solid #0073aa}
		.akb-tabs a{padding:10px 20px;text-decoration:none;color:#555;font-weight:500;border:1px solid transparent;border-bottom:none;margin-bottom:-2px;border-radius:4px 4px 0 0;background:#f0f0f0}
		.akb-tabs a.active{background:#fff;color:#0073aa;border-color:#0073aa #0073aa #fff;font-weight:700}
		.akb-card{background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:20px;margin:20px 0;max-width:960px}
		.akb-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin:16px 0}
		.akb-stat{background:#f8f9fa;border-radius:6px;padding:16px;text-align:center}
		.akb-stat .num{font-size:30px;font-weight:700;color:#0073aa;display:block}
		.akb-stat .lbl{font-size:12px;color:#666;margin-top:4px;display:block}
		.akb-stat.warn .num{color:#d63638}
		.akb-btn{padding:10px 20px;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:600}
		.akb-btn-primary{background:#0073aa;color:#fff}.akb-btn-primary:hover{background:#005177;color:#fff}
		.akb-btn-secondary{background:#f0f0f0;color:#333;border:1px solid #ccc}
		.akb-checks{list-style:none;margin:10px 0;padding:0}
		.akb-checks li{padding:8px 12px;border-radius:4px;margin:4px 0;font-size:13px}
		.akb-checks .ok{background:#edfaef;color:#1a7c34}
		.akb-checks .warn{background:#fff8e5;color:#7a5400}
		.akb-checks .err{background:#fce8e8;color:#8b0000}
		.akb-series-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin:16px 0}
		.akb-serie-card{border:1px solid #e5e7eb;border-radius:6px;padding:14px;background:#fafafa;font-size:13px}
		.akb-serie-card h4{margin:0 0 8px;font-size:14px;color:#1d4ed8}
		.akb-tag{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;background:#e0f2fe;color:#0369a1;margin:2px}
		.akb-tag.warn{background:#fef3c7;color:#92400e}
		</style>
		<h1>📚 Ordenamiento por Tomo <small style="font-size:13px;color:#666;font-weight:400">v<?php echo esc_html( self::VERSION ); ?></small></h1>
		<?php if ( $msg ) : ?>
		<div class="notice notice-<?php echo esc_attr( $tipo ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php
		endif;
		$tabs_map = array(
			'dashboard'   => '📊 Dashboard',
			'series'      => '📋 Series',
			'test'        => '🧪 Test Parser',
			'diagnostico' => '🔧 Diagnóstico',
		);
		echo '<nav class="akb-tabs">';
		foreach ( $tabs_map as $t => $l ) :
			if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
				$url = add_query_arg(
					array(
						'page'   => 'akibara',
						'tab'    => 'orden',
						'subtab' => $t,
					),
					admin_url( 'admin.php' )
				);
			} else {
				$url = add_query_arg(
					array(
						'page' => 'akibara-ordenar-tomos',
						'tab'  => $t,
					),
					admin_url( 'admin.php' )
				);
			}
			?>
			<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $tab === $t ? 'active' : ''; ?>"><?php echo esc_html( $l ); ?></a>
			<?php
		endforeach;
		echo '</nav>';
	}

	private static function render_dashboard( int $total, int $n_series, int $sin_meta, string $ultimo ): void {

		?>
		<div class="akb-card">
			<h3 style="margin-top:0">Estado del catálogo</h3>
			<div class="akb-stats">
				<div class="akb-stat"><span class="num"><?php echo number_format( $total ); ?></span><span class="lbl">Productos</span></div>
				<div class="akb-stat"><span class="num"><?php echo number_format( $n_series ); ?></span><span class="lbl">Series</span></div>
				<div class="akb-stat <?php echo $sin_meta > 0 ? 'warn' : ''; ?>">
					<span class="num"><?php echo number_format( $sin_meta ); ?></span><span class="lbl">Sin meta</span>
				</div>
				<div class="akb-stat">
					<span class="num" style="font-size:12px;line-height:2.6"><?php echo esc_html( $ultimo ); ?></span>
					<span class="lbl">Último reorden</span>
				</div>
			</div>
		</div>
		<div class="akb-card">
			<h3 style="margin-top:0">Acciones</h3>
			<form method="post" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
				<?php wp_nonce_field( 'akb_nonce' ); ?>
				<button type="submit" name="akb_action" value="reorder_full" class="akb-btn akb-btn-primary">
					🚀 Reordenamiento completo + reconstruir índice búsqueda
				</button>
				<?php if ( $sin_meta > 0 ) : ?>
				<button type="submit" name="akb_action" value="rebuild_meta" class="akb-btn akb-btn-secondary">
					🔄 Reconstruir meta (<?php echo (int) $sin_meta; ?> pendientes)
				</button>
				<?php endif; ?>
				<button type="submit" name="akb_action" value="flush_cache" class="akb-btn akb-btn-secondary">🗑️ Limpiar caché</button>
			</form>
			<p style="margin:12px 0 0;font-size:12px;color:#888">
				"Reordenamiento completo" también reconstruye el índice de búsqueda AJAX (1,300+ productos en ~10s).
			</p>
		</div>
		<?php
	}

	private static function render_series(): void {
		global $wpdb;
		$ms = self::META_SERIE;
		$mn = self::META_NUMERO;
		$mt = self::META_TIPO;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ms/$mn/$mt son meta_keys constantes definidas internamente. Sin user input.
		$all = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.menu_order,
                    COALESCE(pm_s.meta_value,'')           AS serie_norm,
                    COALESCE(pm_n.meta_value,'0')          AS numero,
                    COALESCE(pm_t.meta_value,'sin_numero') AS tipo
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_s ON (p.ID=pm_s.post_id AND pm_s.meta_key='{$ms}')
             LEFT JOIN {$wpdb->postmeta} pm_n ON (p.ID=pm_n.post_id AND pm_n.meta_key='{$mn}')
             LEFT JOIN {$wpdb->postmeta} pm_t ON (p.ID=pm_t.post_id AND pm_t.meta_key='{$mt}')
             WHERE p.post_type='product' AND p.post_status='publish'
             ORDER BY p.menu_order ASC"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ag  = array();
		foreach ( $all as $r ) {
			$k = $r->serie_norm ?: '_sin_clasificar';
			if ( ! isset( $ag[ $k ] ) ) {
				$ag[ $k ] = array(
					'nombre' => self::extract_info( $r->post_title )['serie'],
					'items'  => array(),
				);
			}
			$ag[ $k ]['items'][] = $r;
		}
		ksort( $ag );
		?>
		<div class="akb-card">
			<h3 style="margin:0 0 16px"><?php echo count( $ag ); ?> series · <?php echo count( $all ); ?> productos</h3>
			<input type="text" placeholder="🔍 Filtrar serie..."
				oninput="document.querySelectorAll('.akb-serie-card').forEach(c=>{c.style.display=(!this.value||c.dataset.name.includes(this.value.toLowerCase()))?'':'none'})"
				style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px;width:280px">
		</div>
		<div class="akb-series-grid">
		<?php
		foreach ( $ag as $k => $d ) :
			$tipos = array_unique( array_column( $d['items'], 'tipo' ) );
			$nums  = array_column( $d['items'], 'numero' );
			$max   = $nums ? (int) max( $nums ) : 0;
			?>
			<div class="akb-serie-card" data-name="<?php echo esc_attr( mb_strtolower( $d['nombre'] ) ); ?>">
				<h4><?php echo esc_html( $d['nombre'] ?: '(sin clasificar)' ); ?></h4>
				<div style="color:#666;font-size:12px;margin-bottom:8px">
					<?php echo (int) count( $d['items'] ); ?> vol.
					<?php
					if ( $max > 0 && $max < 9990 ) {
						echo ' · hasta tomo ' . (int) $max;}
					?>
				</div>
				<?php
				foreach ( $tipos as $t ) :
					?>
					<span class="akb-tag <?php echo $t === 'sin_numero' ? 'warn' : ''; ?>"><?php echo esc_html( $t ); ?></span><?php endforeach; ?>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_test(): void {
		$tt = '';
		if ( isset( $_POST['test_titulo'] ) && check_admin_referer( 'akb_nonce' ) ) {
			$tt = sanitize_text_field( wp_unslash( $_POST['test_titulo'] ) );
		}
		?>
		<div class="akb-card">
			<h3 style="margin-top:0">🧪 Test del Parser</h3>
			<form method="post" style="display:flex;gap:10px;align-items:center;margin:16px 0">
				<?php wp_nonce_field( 'akb_nonce' ); ?>
				<input type="text" name="test_titulo" value="<?php echo esc_attr( $tt ); ?>"
					placeholder="Ej: One Piece nº 103 – Ivrea Argentina"
					style="flex:1;padding:10px 14px;border:1px solid #ccc;border-radius:4px;font-size:14px">
				<button type="submit" name="akb_action" value="test_parser" class="akb-btn akb-btn-primary">Analizar</button>
			</form>
			<?php
			if ( $tt ) :
				$r    = self::extract_info( $tt );
				$norm = function_exists( 'akb_normalize' ) ? akb_normalize( $tt ) : $tt;
				?>
			<table class="wp-list-table widefat fixed" style="font-size:13px;max-width:560px">
				<tr><td><strong>Serie</strong></td><td><?php echo esc_html( $r['serie'] ); ?></td></tr>
				<tr><td><strong>serie_norm</strong></td><td><code><?php echo esc_html( $r['serie_norm'] ); ?></code></td></tr>
				<tr><td><strong>Número</strong></td><td><?php echo $r['numero'] >= 9990 ? '<em>Especial</em>' : '<strong>' . (int) $r['numero'] . '</strong>'; ?></td></tr>
				<tr><td><strong>Tipo</strong></td><td><code><?php echo esc_html( $r['tipo'] ); ?></code></td></tr>
				<tr><td><strong>Normalizado</strong></td><td><code><?php echo esc_html( $norm ); ?></code></td></tr>
			</table>
			<?php endif; ?>
			<h3>Ejemplos</h3>
			<table class="wp-list-table widefat fixed striped" style="font-size:13px">
			<thead><tr><th>Título</th><th>Serie</th><th>Tomo</th><th>Tipo</th></tr></thead>
			<tbody>
			<?php
			foreach ( array( 'One Piece nº 103 – Ivrea Argentina', 'Naruto 72 – Panini', 'Berserk Deluxe 5', 'Dragon Ball Super #15', 'Ranma 1/2 38', 'Evangelion Box Set', 'Hunter x Hunter Tomo Único', 'JoJo Parte 5 Vol. 3', 'Spy x Family 12' ) as $ej ) :
				$i = self::extract_info( $ej );
				?>
				<tr>
					<td><?php echo esc_html( $ej ); ?></td>
					<td><strong><?php echo esc_html( $i['serie'] ); ?></strong></td>
					<td><?php echo $i['numero'] >= 9990 ? '<em>Especial</em>' : (int) $i['numero']; ?></td>
					<td><code><?php echo esc_html( $i['tipo'] ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_diag(): void {
		global $wpdb;
		$table = defined( 'AKB_TABLE' ) ? AKB_TABLE : $wpdb->prefix . 'akibara_index';
		// Proteger WC_VERSION — puede no estar definida
		$wc_ok = defined( 'WC_VERSION' );
		$wc_v  = $wc_ok ? constant( 'WC_VERSION' ) : '(no activo)';

		// Verificar tabla sin crashear si no existe
		$table_exists = false;
		$ft_exists    = false;
		try {
            $table_exists = ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ); // phpcs:ignore
			if ( $table_exists ) {
                $ft_exists = (bool) $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name='ft_norm'" ); // phpcs:ignore
			}
		} catch ( \Exception $e ) {
			// ignorar
		}

		$checks = array(
			array( function_exists( 'mb_strtolower' ), 'PHP mbstring disponible', 'PHP mbstring NO disponible' ),
			array( $wc_ok, 'WooCommerce ' . $wc_v . ' activo', 'WooCommerce NO activo' ),
			array( $table_exists, 'Tabla índice búsqueda existe', 'Tabla índice NO existe — desactiva y reactiva el plugin' ),
			array( $ft_exists, 'FULLTEXT index activo ✓', 'FULLTEXT index falta — usa "Recrear tabla" en Akibara Búsqueda' ),
			array( wp_using_ext_object_cache(), 'Object cache externo (Redis/Memcached) activo', 'Sin object cache externo — funciona, pero más lento' ),
			array( get_option( 'woocommerce_default_catalog_orderby' ) === 'menu_order', 'WooCommerce default order: menu_order ✓', 'WooCommerce default order no es menu_order (se fuerza en runtime)' ),
		);
		?>
		<div class="akb-card">
			<h3 style="margin-top:0">🔧 Diagnóstico</h3>
			<ul class="akb-checks">
			<?php foreach ( $checks as [ $ok, $ok_msg, $fail_msg ] ) : ?>
				<li class="<?php echo $ok ? 'ok' : 'warn'; ?>">
					<?php echo $ok ? '✅' : '⚠️'; ?>
					<?php echo esc_html( $ok ? $ok_msg : $fail_msg ); ?>
				</li>
			<?php endforeach; ?>
			</ul>
		</div>
		<div class="akb-card">
			<h3 style="margin-top:0">Entorno</h3>
			<table class="wp-list-table widefat fixed" style="font-size:13px;max-width:480px">
				<tr><td>PHP</td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
				<tr><td>WordPress</td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
				<tr><td>WooCommerce</td><td><?php echo esc_html( $wc_v ); ?></td></tr>
				<tr><td>Memoria PHP</td><td><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></td></tr>
				<tr><td>max_execution_time</td><td><?php echo esc_html( ini_get( 'max_execution_time' ) ); ?>s</td></tr>
				<tr><td>Object cache</td><td><?php echo wp_using_ext_object_cache() ? '✅ Activo' : '⚠️ No'; ?></td></tr>
			</table>
		</div>
		<?php
	}

	// ─── Columna en lista de productos ───────────────────────────

	public static function add_admin_column( array $cols ): array {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( $k === 'name' ) {
				$new['akb_serie'] = 'Serie / Tomo';
			}
		}
		return $new;
	}

	public static function render_admin_column( string $col, int $post_id ): void {
		if ( $col !== 'akb_serie' ) {
			return;
		}
		$serie  = get_post_meta( $post_id, self::META_SERIE, true );
		$numero = get_post_meta( $post_id, self::META_NUMERO, true );
		$tipo   = get_post_meta( $post_id, self::META_TIPO, true );
		if ( ! $serie ) {
			echo '<span style="color:#999;font-size:12px">—</span>';
			return; }
		$colors = array(
			'estandar'         => '#1d4ed8',
			'compilacion'      => '#7c3aed',
			'formato_especial' => '#0369a1',
			'sin_numero'       => '#d97706',
		);
		$color  = $colors[ $tipo ] ?? '#6b7280';
		echo '<span style="font-size:12px"><code style="background:#f3f4f6;color:' . esc_attr( $color ) . ';padding:2px 6px;border-radius:4px">' . esc_html( $serie ) . '</code>';
		$n = (int) $numero;
		if ( $n > 0 && $n < 9990 ) {
			echo ' <strong>#' . (int) $n . '</strong>';
		} elseif ( $n >= 9990 ) {
			echo ' <em style="color:#6b7280">especial</em>';
		}
		echo '</span>';
	}

	public static function sortable_admin_column( array $cols ): array {
		$cols['akb_serie'] = 'menu_order';
		return $cols;
	}
} // end class Akibara_Order
} // end if ! class_exists Akibara_Order

if ( class_exists( 'Akibara_Order' ) && ! did_action( 'akibara_order_inited' ) ) {
	Akibara_Order::init();
	do_action( 'akibara_order_inited' );
}
