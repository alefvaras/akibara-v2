<?php
/**
 * Akibara Courier — Blue Express Adapter
 *
 * Wrapper sobre el plugin bluex-for-woocommerce.
 * No tiene API propia — delega tracking al plugin.
 *
 * @package Akibara
 * @since   10.5.0
 */

defined( 'ABSPATH' ) || exit;

class AKB_Courier_BlueX implements AKB_Courier_Adapter, AKB_Courier_UI_Metadata {

	use AKB_Courier_UI_Defaults;

	const PREFIXES = array( 'bluex-', 'correios' );

	// ── UI Metadata (overrides de AKB_Courier_UI_Defaults) ──
	public function get_color(): string {
		return '#0066B3'; }

	public function get_icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>';
	}

	public function get_tagline(): string {
		return 'Te llevamos a la puerta'; }

	public function get_priority(): int {
		return 1; }

	public function get_delivery_estimate_label( array $package = array() ): ?string {
		// Fallback: +2 días hábiles RM, +4 resto. Nulleable para que JS haga cálculo.
		return null;
	}

	public function get_id(): string {
		return 'bluex';
	}

	public function get_label(): string {
		return 'Blue Express';
	}

	public function get_icon(): string {
		return '🔵';
	}

	public function get_method_ids(): array {
		return array( 'bluex-ex', 'bluex-py', 'bluex-md', 'bluex-pudo', 'correios' );
	}

	public function get_tracking_info( WC_Order $order ): ?array {
		if ( function_exists( 'wc_correios_getTrackingCodes' ) ) {
			$codes = wc_correios_getTrackingCodes( $order->get_id() );
			if ( ! empty( $codes ) ) {
				return array(
					'courier'       => 'bluex',
					'courier_label' => 'Blue Express',
					'code'          => $codes[0],
					'codes'         => $codes,
					'url'           => 'https://tracking-unificado.blue.cl/?n_seguimiento=' . urlencode( $codes[0] ),
					'data'          => null,
				);
			}
		}
		return null;
	}

	public function get_status_display( ?string $courier_status, string $wc_status ): array {
		$has_code = ! empty( $courier_status );

		if ( $has_code && in_array( $wc_status, array( 'processing', 'shipping-progress' ), true ) ) {
			return array(
				'icon'  => '🚀',
				'label' => 'En camino',
				'css'   => 'shipped',
			);
		}
		if ( $wc_status === 'completed' ) {
			return array(
				'icon'  => '✅',
				'label' => 'Entregado',
				'css'   => 'completed',
			);
		}
		if ( $has_code ) {
			return array(
				'icon'  => '📦',
				'label' => 'Despachado',
				'css'   => 'shipped',
			);
		}
		return array(
			'icon'  => '⏳',
			'label' => 'Preparando envío',
			'css'   => 'pending',
		);
	}

	public function test_connection(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'bluex-for-woocommerce/woocommerce-bluex.php' );
	}

	public function has_admin_settings(): bool {
		return false; // BlueX se configura desde su propio plugin
	}

	public function render_admin_settings(): void {
		// No settings — BlueX se gestiona desde su plugin
	}

	public function save_admin_settings(): void {
		// No-op
	}

	public function has_order_actions(): bool {
		return false; // BlueX maneja sus propios envíos
	}

	public function get_order_actions( WC_Order $order ): array {
		return array();
	}

	public function execute_order_action( string $action_slug, WC_Order $order ): void {
		// No-op
	}

	public function has_webhook(): bool {
		return false;
	}

	public function get_webhook_path(): string {
		return '';
	}

	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'success' => false ), 404 );
	}

	public function get_30d_stats(): array {
		global $wpdb;

		$orders_table = $wpdb->prefix . 'wc_orders';
		$items_table  = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta     = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$count        = 0;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$orders_table}'" ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT im.meta_value AS method_id, COUNT(DISTINCT o.id) AS cnt
				 FROM {$orders_table} o
				 JOIN {$items_table} oi ON oi.order_id = o.id AND oi.order_item_type = 'shipping'
				 JOIN {$itemmeta} im ON im.order_item_id = oi.order_item_id AND im.meta_key = 'method_id'
				 WHERE o.date_created_gmt >= %s
				   AND o.status IN ('wc-processing','wc-completed','wc-shipping-progress')
				 GROUP BY im.meta_value",
					gmdate( 'Y-m-d', strtotime( '-30 days' ) )
				)
			);

			foreach ( $rows as $row ) {
				foreach ( self::PREFIXES as $prefix ) {
					if ( strpos( $row->method_id, $prefix ) === 0 ) {
						$count += (int) $row->cnt;
					}
				}
			}
		}

		return array(
			'count' => $count,
			'label' => 'Pedidos BlueX (30d)',
		);
	}
}
