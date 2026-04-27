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
	 * STUB: UI pending Cell H mockup approval.
	 * Backend data fetch is fully functional.
	 * See: audit/sprint-3/cell-b/STUBS.md
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.' );
		}
		?>
		<div class="wrap akb-finance-manga">
			<h2>Finanzas Manga</h2>

			<?php /* STUB NOTICE — remove when Cell H mockup is approved */ ?>
			<div class="notice notice-info inline" style="margin:0 0 20px">
				<p>
					<strong>UI en espera de mockup:</strong>
					El diseño de este panel requiere aprobacion de Cell H (sprint-3/cell-h/REQUESTS-FROM-B.md).
					Los datos estan disponibles via AJAX (<code>akb_finance_manga_data</code>).
					Esta vista es un placeholder temporal — ver <code>audit/sprint-3/cell-b/STUBS.md</code>.
				</p>
			</div>

			<div id="akb-finance-manga-stub" style="display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
				<?php $data = $this->data(); ?>

				<!-- Top Series -->
				<div class="akb-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px">
					<h3 style="margin-top:0">Top series por volumen</h3>
					<?php if ( empty( $data['top_series'] ) ) : ?>
						<p><em>Sin datos</em></p>
					<?php else : ?>
						<ol style="margin:0;padding-left:20px">
						<?php foreach ( array_slice( $data['top_series'], 0, 5 ) as $row ) : ?>
							<li><?php echo esc_html( $row['serie'] ); ?> <strong>(<?php echo esc_html( (string) $row['units'] ); ?>)</strong></li>
						<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>

				<!-- Top Editoriales -->
				<div class="akb-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px">
					<h3 style="margin-top:0">Top editoriales (suscriptores Brevo)</h3>
					<?php if ( empty( $data['top_editoriales'] ) ) : ?>
						<p><em>Sin datos (verificar API key Brevo)</em></p>
					<?php else : ?>
						<ol style="margin:0;padding-left:20px">
						<?php foreach ( array_slice( $data['top_editoriales'], 0, 5, true ) as $ed ) : ?>
							<li><?php echo esc_html( $ed['label'] ); ?> <strong>(<?php echo esc_html( (string) $ed['count'] ); ?>)</strong></li>
						<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>

				<!-- Encargos Pendientes -->
				<div class="akb-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px">
					<h3 style="margin-top:0">Encargos pendientes</h3>
					<?php if ( empty( $data['encargos_pendientes'] ) ) : ?>
						<p><em>Sin encargos activos</em></p>
					<?php else : ?>
						<ul style="margin:0;padding-left:20px">
						<?php foreach ( $data['encargos_pendientes'] as $enc ) : ?>
							<li><?php echo esc_html( $enc['title'] ); ?> &times;<?php echo esc_html( (string) $enc['qty'] ); ?></li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<!-- Trending Searches -->
				<div class="akb-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px">
					<h3 style="margin-top:0">Busquedas mas buscadas</h3>
					<?php if ( empty( $data['trending_searches'] ) ) : ?>
						<p><em>Sin datos</em></p>
					<?php else : ?>
						<ol style="margin:0;padding-left:20px">
						<?php foreach ( array_slice( $data['trending_searches'], 0, 5 ) as $t ) : ?>
							<li><?php echo esc_html( $t['term'] ); ?> <strong>(<?php echo number_format( (int) $t['count'] ); ?>)</strong></li>
						<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</div>

				<!-- Stock Critico -->
				<div class="akb-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:16px">
					<h3 style="margin-top:0">Stock critico (&lt; 3 unidades)</h3>
					<?php if ( empty( $data['stock_critico'] ) ) : ?>
						<p><em>Sin productos en stock critico</em></p>
					<?php else : ?>
						<table style="width:100%;border-collapse:collapse;font-size:13px">
							<thead><tr style="border-bottom:1px solid #ddd">
								<th style="text-align:left;padding:4px">Producto</th>
								<th style="text-align:right;padding:4px">Stock</th>
								<th style="text-align:right;padding:4px">Vendidos 30d</th>
							</tr></thead>
							<tbody>
							<?php foreach ( array_slice( $data['stock_critico'], 0, 10 ) as $p ) : ?>
								<tr style="border-bottom:1px solid #eee">
									<td style="padding:4px"><?php echo esc_html( $p['title'] ); ?></td>
									<td style="padding:4px;text-align:right;color:<?php echo $p['stock'] === 0 ? 'red' : 'orange'; ?>"><?php echo esc_html( (string) $p['stock'] ); ?></td>
									<td style="padding:4px;text-align:right"><?php echo esc_html( (string) $p['sold_30d'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

			</div><!-- /#akb-finance-manga-stub -->

			<p style="margin-top:16px;color:#888;font-size:12px">
				Datos generados: <?php echo esc_html( $data['generated_at'] ); ?> UTC |
				<a href="<?php echo esc_url( add_query_arg( 'akb_nocache', '1' ) ); ?>">Forzar actualizacion</a>
			</p>
		</div>
		<?php
	}
}
