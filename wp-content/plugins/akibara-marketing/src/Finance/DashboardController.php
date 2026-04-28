<?php
/**
 * DashboardController — orquesta los 5 widgets del finance dashboard manga-specific.
 *
 * UI rendering is STUBBED pending Cell H mockup approval.
 * Backend data fetch is fully implemented.
 *
 * @package Akibara\Marketing\Finance
 */

declare(strict_types=1);

namespace Akibara\Marketing\Finance;

use Akibara\Marketing\Finance\Widgets\TopSeriesByVolume;
use Akibara\Marketing\Finance\Widgets\TopEditoriales;
use Akibara\Marketing\Finance\Widgets\EncargosPendientes;
use Akibara\Marketing\Finance\Widgets\TrendingSearches;
use Akibara\Marketing\Finance\Widgets\StockCritico;
use Akibara\Marketing\Brevo\SegmentationService;

defined( 'ABSPATH' ) || exit;

/**
 * Finance Dashboard Controller.
 *
 * Instantiates the 5 manga-specific widgets and exposes:
 *   - data()        → raw data for all widgets (JSON-serializable)
 *   - render()      → admin HTML output (STUB — awaiting Cell H mockup)
 *   - register()    → hooks into admin menu tab system
 */
final class DashboardController {

	private TopSeriesByVolume $top_series;
	private TopEditoriales    $top_editoriales;
	private EncargosPendientes $encargos;
	private TrendingSearches  $trending;
	private StockCritico      $stock_critico;

	public function __construct() {
		$segmentation          = new SegmentationService();
		$this->top_series      = new TopSeriesByVolume();
		$this->top_editoriales = new TopEditoriales( $segmentation );
		$this->encargos        = new EncargosPendientes();
		$this->trending        = new TrendingSearches();
		$this->stock_critico   = new StockCritico();
	}

	/**
	 * Register admin tab + AJAX handler.
	 * Hook into admin_menu (via module.php).
	 */
	public function register(): void {
		// Register tab with the Akibara admin dashboard tab system.
		add_filter(
			'akibara_admin_tabs',
			function ( array $tabs ): array {
				$tabs['finanzas-manga'] = array(
					'label'       => 'Finanzas Manga',
					'short_label' => 'Manga',
					'icon'        => 'dashicons-chart-bar',
					'group'       => 'analytics',
					'callback'    => array( $this, 'render' ),
				);
				return $tabs;
			}
		);

		// AJAX endpoint for async widget data refresh.
		if ( function_exists( 'akb_ajax_endpoint' ) ) {
			akb_ajax_endpoint(
				'akb_finance_manga_data',
				array(
					'nonce'      => 'akb_finance_manga_nonce',
					'capability' => 'manage_woocommerce',
					'handler'    => function ( array $post ): array {
						return $this->data();
					},
				)
			);
		}

		// Invalidate widget caches on order status change.
		add_action( 'woocommerce_order_status_changed', array( $this, 'invalidate_caches' ) );
	}

	/**
	 * Returns all widget data (JSON-serializable).
	 *
	 * @return array{
	 *   top_series: array<int,array{serie:string,units:int}>,
	 *   top_editoriales: array<string,array{id:int,label:string,count:int}>,
	 *   encargos_pendientes: array<int,array{title:string,qty:int,status:string,date:string}>,
	 *   trending_searches: array<int,array{term:string,count:int}>,
	 *   stock_critico: array<int,array{product_id:int,title:string,stock:int,sold_30d:int,sku:string}>,
	 *   generated_at: string
	 * }
	 */
	public function data(): array {
		return array(
			'top_series'          => $this->top_series->fetch( 10 ),
			'top_editoriales'     => $this->top_editoriales->fetch(),
			'encargos_pendientes' => $this->encargos->fetch(),
			'trending_searches'   => $this->trending->fetch( 10 ),
			'stock_critico'       => $this->stock_critico->fetch( 3, 20 ),
			'generated_at'        => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Invalidate all widget caches.
	 */
	public function invalidate_caches(): void {
		$this->top_series->invalidate();
		$this->encargos->invalidate();
		$this->stock_critico->invalidate();
	}

	/**
	 * Render the finance dashboard admin page.
	 *
	 * Sprint 5.5 admin reorg: UI proper usando admin.css classes (.akb-stats,
	 * .akb-card, .akb-table). Removed STUB notice — ahora es UI real.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}

		$data = $this->data();

		// KPIs totales para top row.
		$total_series_units = array_sum( array_column( $data['top_series'], 'units' ) );
		$total_editoriales  = count( $data['top_editoriales'] );
		$total_encargos     = count( $data['encargos_pendientes'] );
		$total_trending     = array_sum( array_column( $data['trending_searches'], 'count' ) );
		$total_stock_low    = count( $data['stock_critico'] );
		?>
		<div class="wrap akb-admin-page akb-finance-manga">
			<div class="akb-page-header">
				<h1 class="akb-page-header__title">💹 Finanzas Manga</h1>
				<p class="akb-page-header__desc">Dashboard de métricas manga-specific: series top, editoriales, encargos pendientes, búsquedas trending, stock crítico.</p>
			</div>

			<!-- KPIs row -->
			<div class="akb-stats">
				<div class="akb-stat">
					<div class="akb-stat__value akb-stat__value--info"><?php echo number_format( $total_series_units ); ?></div>
					<div class="akb-stat__label">Unidades Top 10 Series</div>
				</div>
				<div class="akb-stat">
					<div class="akb-stat__value akb-stat__value--success"><?php echo number_format( $total_editoriales ); ?></div>
					<div class="akb-stat__label">Editoriales Activas</div>
				</div>
				<div class="akb-stat">
					<div class="akb-stat__value <?php echo $total_encargos > 0 ? 'akb-stat__value--warning' : ''; ?>"><?php echo number_format( $total_encargos ); ?></div>
					<div class="akb-stat__label">Encargos Pendientes</div>
				</div>
				<div class="akb-stat">
					<div class="akb-stat__value akb-stat__value--info"><?php echo number_format( $total_trending ); ?></div>
					<div class="akb-stat__label">Búsquedas (30d)</div>
				</div>
				<div class="akb-stat">
					<div class="akb-stat__value <?php echo $total_stock_low > 0 ? 'akb-stat__value--error' : 'akb-stat__value--success'; ?>"><?php echo number_format( $total_stock_low ); ?></div>
					<div class="akb-stat__label">Stock Crítico</div>
				</div>
			</div>

			<!-- Widgets grid -->
			<div class="akibara-cards-grid">

				<!-- Top Series por volumen -->
				<div class="akb-card">
					<h3 class="akb-section-title">📚 Top Series por Volumen</h3>
					<?php if ( empty( $data['top_series'] ) ) : ?>
						<p><em>Sin datos disponibles.</em></p>
					<?php else : ?>
						<table class="akb-table">
							<tbody>
							<?php foreach ( array_slice( $data['top_series'], 0, 10 ) as $i => $row ) : ?>
								<tr>
									<td style="width:30px;color:#50575e"><?php echo esc_html( (string) ( $i + 1 ) ); ?>.</td>
									<td><?php echo esc_html( $row['serie'] ); ?></td>
									<td style="text-align:right"><span class="akb-badge akb-badge--info"><?php echo number_format( (int) $row['units'] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Top Editoriales -->
				<div class="akb-card">
					<h3 class="akb-section-title">🏢 Top Editoriales (Brevo)</h3>
					<?php if ( empty( $data['top_editoriales'] ) ) : ?>
						<p><em>Sin datos. Verificá API key Brevo en <a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-brevo' ) ); ?>">Akibara → 📧 Brevo</a>.</em></p>
					<?php else : ?>
						<table class="akb-table">
							<tbody>
							<?php foreach ( array_slice( $data['top_editoriales'], 0, 10, true ) as $ed ) : ?>
								<tr>
									<td><?php echo esc_html( $ed['label'] ); ?></td>
									<td style="text-align:right"><span class="akb-badge akb-badge--active"><?php echo number_format( (int) $ed['count'] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Encargos Pendientes -->
				<div class="akb-card">
					<h3 class="akb-section-title">📥 Encargos Pendientes</h3>
					<?php if ( empty( $data['encargos_pendientes'] ) ) : ?>
						<p><em>✅ Sin encargos activos.</em></p>
					<?php else : ?>
						<table class="akb-table">
							<tbody>
							<?php foreach ( array_slice( $data['encargos_pendientes'], 0, 10 ) as $enc ) : ?>
								<tr>
									<td><?php echo esc_html( $enc['title'] ); ?></td>
									<td style="text-align:right"><span class="akb-badge akb-badge--warning">×<?php echo esc_html( (string) $enc['qty'] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p style="margin-top:12px"><a class="akb-btn akb-btn--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-encargos' ) ); ?>">Ver todos →</a></p>
					<?php endif; ?>
				</div>

				<!-- Trending Searches -->
				<div class="akb-card">
					<h3 class="akb-section-title">🔥 Búsquedas Trending</h3>
					<?php if ( empty( $data['trending_searches'] ) ) : ?>
						<p><em>Sin datos.</em></p>
					<?php else : ?>
						<table class="akb-table">
							<tbody>
							<?php foreach ( array_slice( $data['trending_searches'], 0, 10 ) as $i => $t ) : ?>
								<tr>
									<td style="width:30px;color:#50575e"><?php echo esc_html( (string) ( $i + 1 ) ); ?>.</td>
									<td><?php echo esc_html( $t['term'] ); ?></td>
									<td style="text-align:right"><span class="akb-badge akb-badge--info"><?php echo number_format( (int) $t['count'] ); ?></span></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

				<!-- Stock Critico -->
				<div class="akb-card" style="grid-column: span 2">
					<h3 class="akb-section-title">⚠️ Stock Crítico (menos de 3 unidades)</h3>
					<?php if ( empty( $data['stock_critico'] ) ) : ?>
						<p><em>✅ Todo el catálogo con stock saludable.</em></p>
					<?php else : ?>
						<table class="akb-table">
							<thead>
								<tr>
									<th>Producto</th>
									<th style="text-align:right">Stock</th>
									<th style="text-align:right">Vendidos 30d</th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( array_slice( $data['stock_critico'], 0, 10 ) as $p ) : ?>
								<tr>
									<td>
										<?php
										$edit_link = admin_url( 'post.php?post=' . (int) $p['product_id'] . '&action=edit' );
										?>
										<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $p['title'] ); ?></a>
										<?php if ( ! empty( $p['sku'] ) ) : ?>
											<small style="color:#50575e"><br><code><?php echo esc_html( $p['sku'] ); ?></code></small>
										<?php endif; ?>
									</td>
									<td style="text-align:right">
										<?php $stock_class = (int) $p['stock'] === 0 ? 'akb-badge--error' : 'akb-badge--warning'; ?>
										<span class="akb-badge <?php echo esc_attr( $stock_class ); ?>"><?php echo esc_html( (string) $p['stock'] ); ?></span>
									</td>
									<td style="text-align:right"><?php echo esc_html( (string) $p['sold_30d'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

			</div><!-- /.akibara-cards-grid -->

			<p style="margin-top:16px;color:#50575e;font-size:12px">
				Datos generados: <code><?php echo esc_html( $data['generated_at'] ); ?> UTC</code> ·
				<a href="<?php echo esc_url( add_query_arg( 'akb_nocache', '1' ) ); ?>">Forzar actualización</a>
			</p>
		</div>
		<?php
	}
}
