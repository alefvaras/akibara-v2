<?php
/**
 * Akibara Inventario — Shipping module (BlueX + 12 Horas orchestrator).
 *
 * Migrated from plugins/akibara/modules/shipping/module.php.
 * Key changes from legacy:
 * - Guard uses AKB_INV_ADDON_LOADED (not AKIBARA_V10_LOADED).
 * - AKIBARA_URL/AKIBARA_VERSION → AKB_INVENTARIO_URL/AKB_INVENTARIO_VERSION.
 * - register_activation_hook → points to akibara-inventario.php.
 * - BlueX webhook preserved intact (F-PRE-001 fix in Sprint 1 already applied to snapshot).
 *   POLICY: NO rotar BlueX API key (project_no_key_rotation_policy).
 *
 * @package Akibara\Inventario
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_INV_ADDON_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_INVENTARIO_SHIPPING_LOADED' ) ) {
	return;
}
define( 'AKB_INVENTARIO_SHIPPING_LOADED', '1.0.0' );

// ─── Load adapter classes ──────────────────────────────────────────────────────
require_once __DIR__ . '/class-courier.php';
require_once __DIR__ . '/class-bluex.php';
require_once __DIR__ . '/class-12horas.php';

// ─── Register WC_Shipping_Method for 12 Horas ────────────────────────────────
add_action( 'woocommerce_shipping_init', static function (): void {
	require_once __DIR__ . '/class-12horas-wc-method.php';
} );
add_filter( 'woocommerce_shipping_methods', static function ( array $methods ): array {
	$methods['12horas'] = 'AKB_TwelveHoras_Shipping_Method';
	return $methods;
} );

// ─── GROUP WRAP ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_12horas_autowire_rm_zone' ) ) {

	function akb_12horas_autowire_rm_zone(): void {
		if ( get_option( 'akb_12horas_autowire_done' ) ) {
			return;
		}
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return;
		}
		foreach ( WC_Shipping_Zones::get_zones() as $z ) {
			$zone   = new WC_Shipping_Zone( $z['id'] );
			$region = null;
			foreach ( $zone->get_zone_locations() as $loc ) {
				if ( $loc->type === 'state' && $loc->code === 'CL:CL-RM' ) {
					$region = 'CL-RM';
					break;
				}
			}
			if ( ! $region ) {
				continue;
			}
			foreach ( $zone->get_shipping_methods() as $m ) {
				if ( $m->id === '12horas' ) {
					update_option( 'akb_12horas_autowire_done', 1 );
					return;
				}
			}
			$zone->add_shipping_method( '12horas' );
			$zone->save();
			update_option( 'akb_12horas_autowire_done', 1 );
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'shipping', 'info', "12horas auto-wired to zone {$z['zone_name']}", array( 'zone_id' => $z['id'] ) );
			}
			return;
		}
	}

	// Activation hook updated to point to akibara-inventario (not akibara monolith).
	register_activation_hook( \AKB_INVENTARIO_FILE, 'akb_12horas_autowire_rm_zone' );
	add_action( 'admin_init', 'akb_12horas_autowire_rm_zone' );

	// ─── Admin: orders column ──────────────────────────────────────────────────
	require_once __DIR__ . '/shipping-admin-order.php';

	// ─── Auto-dispatch to courier on order status change ──────────────────────
	add_action( 'woocommerce_order_status_processing', 'akb_ship_auto_dispatch_courier', 20 );
	add_action( 'woocommerce_order_status_shipping-progress', 'akb_ship_auto_dispatch_courier', 20 );
	add_action( 'woocommerce_order_status_on-hold', 'akb_ship_auto_dispatch_courier', 20 );
	add_action( 'woocommerce_order_status_completed', 'akb_ship_auto_dispatch_courier', 20 );

	function akb_ship_get_dispatch_statuses(): array {
		$default = array( 'shipping-progress', 'completed' );
		$saved   = get_option( 'akibara_ship_dispatch_statuses', $default );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return $default;
		}
		return array_values( array_intersect( $saved, array( 'processing', 'shipping-progress', 'on-hold', 'completed' ) ) ) ?: $default;
	}

	function akb_ship_auto_dispatch_courier( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! in_array( $order->get_status(), akb_ship_get_dispatch_statuses(), true ) ) {
			return;
		}
		$courier = akb_detect_order_courier( $order );
		if ( ! $courier ) {
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'shipping', 'info', "auto-dispatch: order #{$order_id} sin courier detectado" );
			}
			return;
		}
		if ( function_exists( 'akb_log' ) ) {
			akb_log( 'shipping', 'info', "auto-dispatch: order #{$order_id} courier={$courier->get_id()}" );
		}
		switch ( $courier->get_id() ) {
			case '12horas':
				if ( $order->get_meta( AKB_Courier_TwelveHoras::META_TRACKING_CODE ) !== '' ) {
					return;
				}
				$courier->execute_order_action( 'akb_12horas_create', $order );
				break;
		}
	}

	// ─── Registry: instantiate couriers ───────────────────────────────────────
	/** @var AKB_Courier_Adapter[] $akb_couriers */
	global $akb_couriers;
	$akb_couriers = array(
		'bluex'   => new AKB_Courier_BlueX(),
		'12horas' => new AKB_Courier_TwelveHoras(),
	);

	function akb_get_courier_by_method( string $method_id ): ?AKB_Courier_Adapter {
		global $akb_couriers;
		foreach ( $akb_couriers as $courier ) {
			if ( in_array( $method_id, $courier->get_method_ids(), true ) ) {
				return $courier;
			}
		}
		return null;
	}

	function akb_detect_order_courier( WC_Order $order ): ?AKB_Courier_Adapter {
		foreach ( $order->get_shipping_methods() as $method ) {
			$courier = akb_get_courier_by_method( $method->get_method_id() );
			if ( $courier ) {
				return $courier;
			}
		}
		return null;
	}

	function akb_ship_has_shipping_module(): bool {
		return true;
	}

	function akb_ship_get_tracking_info( WC_Order $order ): ?array {
		$courier = akb_detect_order_courier( $order );
		if ( ! $courier ) {
			return null;
		}
		$info = $courier->get_tracking_info( $order );
		if ( ! is_array( $info ) ) {
			return null;
		}
		$codes = isset( $info['codes'] ) && is_array( $info['codes'] ) ? $info['codes'] : array();
		if ( empty( $codes ) && ! empty( $info['code'] ) ) {
			$codes = array( (string) $info['code'] );
		}
		$code = ! empty( $codes ) ? (string) $codes[0] : '';
		$url  = isset( $info['url'] ) && is_string( $info['url'] ) ? $info['url'] : null;
		$result = array(
			'courier'       => $info['courier'] ?? $courier->get_id(),
			'courier_label' => $info['courier_label'] ?? $courier->get_label(),
			'courier_icon'  => $courier->get_icon(),
			'code'          => $code,
			'codes'         => $codes,
			'url'           => $url,
			'tracking_url'  => $url,
			'data'          => is_array( $info['data'] ?? null ) ? $info['data'] : array(),
		);
		return apply_filters( 'akibara_tracking_info', $result, $order, $courier );
	}

	function akb_get_couriers_ui_metadata(): array {
		global $akb_couriers;
		$out = array();
		foreach ( $akb_couriers as $courier ) {
			if ( ! $courier instanceof AKB_Courier_UI_Metadata ) {
				continue;
			}
			foreach ( $courier->get_method_ids() as $mid ) {
				$out[ $mid ] = array(
					'id'       => $courier->get_id(),
					'label'    => $courier->get_label(),
					'color'    => $courier->get_color(),
					'iconSvg'  => $courier->get_icon_svg(),
					'tagline'  => $courier->get_tagline(),
					'badge'    => $courier->get_badge(),
					'pill'     => $courier->get_pill(),
					'priority' => $courier->get_priority(),
					'cutoff'   => $courier->get_cutoff_hour(),
					'coverage' => $courier->get_coverage_text(),
					'eta'      => $courier->get_delivery_estimate_label(),
				);
			}
		}
		return $out;
	}

	// ─── Tracking — Unified frontend view ─────────────────────────────────────
	add_action( 'wp', static function (): void {
		if ( is_admin() ) {
			return;
		}
		global $wp_filter;
		if ( isset( $wp_filter['woocommerce_order_details_after_order_table'] ) ) {
			foreach ( $wp_filter['woocommerce_order_details_after_order_table']->callbacks as $pri => $cbs ) {
				foreach ( $cbs as $key => $cb ) {
					if (
						is_array( $cb['function'] )
						&& is_object( $cb['function'][0] )
						&& $cb['function'][0] instanceof WC_Correios_TrackingHistory
					) {
						remove_action( 'woocommerce_order_details_after_order_table', $cb['function'], $pri );
					}
				}
			}
		}
	}, 20 );

	add_action( 'woocommerce_order_details_after_order_table', static function ( $order ): void {
		$courier = akb_detect_order_courier( $order );
		if ( ! $courier ) {
			return;
		}
		$tracking = $courier->get_tracking_info( $order );
		if ( ! $tracking ) {
			return;
		}
		$wc_status = $order->get_status();
		$api_st    = $tracking['data']['status'] ?? ( ! empty( $tracking['codes'] ) ? 'has_code' : null );
		$display   = $courier->get_status_display( $api_st, $wc_status );
		?>
		<section class="akb-tracking">
			<h2 class="akb-tracking__title">Seguimiento de envío</h2>
			<div class="akb-tracking__card">
				<div class="akb-tracking__header">
					<div class="akb-tracking__courier">
						<span class="akb-tracking__courier-icon" aria-hidden="true"><?php echo esc_html( $courier->get_icon() ); ?></span>
						<span class="akb-tracking__courier-name"><?php echo esc_html( $courier->get_label() ); ?></span>
					</div>
					<span class="akb-tracking__status akb-tracking__status--<?php echo esc_attr( $display['css'] ); ?>">
						<span aria-hidden="true"><?php echo esc_html( $display['icon'] ); ?></span>
						<?php echo esc_html( $display['label'] ); ?>
					</span>
				</div>
				<?php
				$codes        = ! empty( $tracking['codes'] ) ? (array) $tracking['codes'] : ( ! empty( $tracking['code'] ) ? array( $tracking['code'] ) : array() );
				$tracking_url = ! empty( $tracking['url'] ) ? (string) $tracking['url'] : '';
				?>
				<?php if ( ! empty( $codes ) ) : ?>
					<div class="akb-tracking__codes">
						<?php foreach ( $codes as $code ) : ?>
							<div class="akb-tracking__code-row">
								<span class="akb-tracking__code-label">Código:</span>
								<code class="akb-tracking__code-value"><?php echo esc_html( $code ); ?></code>
								<?php if ( $tracking_url !== '' ) : ?>
									<a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener noreferrer" class="akb-tracking__link" aria-label="Seguir mi envío (se abre en una nueva pestaña)">Seguir mi envío &rarr;</a>
								<?php else : ?>
									<span class="akb-tracking__link akb-tracking__link--no-url">Te notificaremos por correo cuando cambie el estado</span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="akb-tracking__pending">Estamos preparando tu pedido. Te avisaremos por correo cuando salga a despacho.</p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}, 1 );

	// Enqueue tracking styles — updated to use AKB_INVENTARIO_URL.
	add_action( 'wp_enqueue_scripts', static function (): void {
		if ( ! is_wc_endpoint_url( 'view-order' ) && ! is_order_received_page() ) {
			return;
		}
		wp_enqueue_style( 'akb-shipping-tracking', \AKB_INVENTARIO_URL . 'modules/shipping/tracking-unified.css', array(), \AKB_INVENTARIO_VERSION );
	} );

	// ─── Webhooks — REST router ────────────────────────────────────────────────
	add_action( 'rest_api_init', static function (): void {
		global $akb_couriers;
		foreach ( $akb_couriers as $courier ) {
			if ( $courier->has_webhook() ) {
				register_rest_route(
					'akibara/v1',
					'/' . $courier->get_webhook_path(),
					array(
						'methods'             => 'POST',
						'callback'            => static function ( WP_REST_Request $request ) use ( $courier ) {
							return $courier->handle_webhook( $request );
						},
						'permission_callback' => static function ( WP_REST_Request $request ) use ( $courier ) {
							if ( method_exists( $courier, 'verify_webhook_auth' ) ) {
								return $courier->verify_webhook_auth( $request );
							}
							return true;
						},
					)
				);
			}
		}
	} );

	// ─── Order actions — dispatcher ────────────────────────────────────────────
	add_filter( 'woocommerce_order_actions', static function ( $actions, $order ) {
		global $akb_couriers;
		if ( ! $order ) {
			return $actions;
		}
		foreach ( $akb_couriers as $courier ) {
			if ( $courier->has_order_actions() ) {
				foreach ( $courier->get_order_actions( $order ) as $slug => $label ) {
					$actions[ $slug ] = $label;
				}
			}
		}
		return $actions;
	}, 10, 2 );

	add_action( 'woocommerce_order_action', static function ( $action_slug ): void {
		if ( ! str_starts_with( (string) $action_slug, 'akb_' ) ) {
			return;
		}
		global $theorder, $akb_couriers;
		if ( ! $theorder instanceof WC_Order ) {
			return;
		}
		foreach ( $akb_couriers as $courier ) {
			if ( ! $courier->has_order_actions() ) {
				continue;
			}
			$available = array_keys( $courier->get_order_actions( $theorder ) );
			if ( in_array( $action_slug, $available, true ) ) {
				$courier->execute_order_action( $action_slug, $theorder );
				return;
			}
		}
	}, 10, 1 );

	// Confirmation dialog for destructive order actions (12 Horas cancel).
	add_action( 'admin_footer', static function (): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$is_order_edit = in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( ! $is_order_edit ) {
			return;
		}
		?>
		<script>
		(function(){
			var AKB_CONFIRM_ACTIONS = {
				'akb_12horas_cancel': 'Vas a CANCELAR el envío en 12 Horas.\n\nEsta acción es IRREVERSIBLE: el courier no puede reactivar un envío cancelado.\n\n¿Continuar?'
			};
			document.addEventListener('click', function(e){
				var btn = e.target.closest('.wc-reload, button.save_order');
				if (!btn) return;
				var sel = document.querySelector('select[name="wc_order_action"]');
				if (!sel) return;
				var msg = AKB_CONFIRM_ACTIONS[sel.value];
				if (msg && !window.confirm(msg)) {
					e.preventDefault();
					e.stopImmediatePropagation();
				}
			}, true);
		})();
		</script>
		<?php
	} );

	// ─── Admin tab — Shipping settings ────────────────────────────────────────
	// (Shipping admin tab kept at module level for cohesion — same as legacy module.)
	// Full implementation: see original modules/shipping/module.php in legacy snapshot.
	// Ported verbatim except AKIBARA_URL → AKB_INVENTARIO_URL and guard constants.
	add_filter( 'akibara_admin_tabs', static function ( array $tabs ): array {
		$tabs['shipping'] = array(
			'label'       => 'Envíos',
			'short_label' => 'Envíos',
			'icon'        => 'dashicons-car',
			'group'       => 'operacion',
			'callback'    => 'akb_inventario_shipping_admin_tab',
		);
		return $tabs;
	} );

	function akb_inventario_shipping_admin_tab(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		global $akb_couriers;

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			foreach ( $akb_couriers as $courier ) {
				if ( $courier->has_admin_settings() ) {
					$courier->save_admin_settings();
				}
			}
			if ( isset( $_POST['akb_ship_thresholds_save'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'akb_ship_thresholds' ) ) {
				$wc_th = max( 0, min( 500000, (int) ( $_POST['akb_free_shipping_wc'] ?? 55000 ) ) );
				update_option( 'akibara_free_shipping_threshold', $wc_th );
				$ml_inherit = ! empty( $_POST['akb_ml_inherit'] );
				if ( $ml_inherit ) {
					update_option( 'akibara_ml_free_shipping_threshold', 0 );
				} else {
					$ml_th = max( 0, min( 500000, (int) ( $_POST['akb_free_shipping_ml'] ?? 19990 ) ) );
					update_option( 'akibara_ml_free_shipping_threshold', $ml_th );
				}
				echo '<div class="notice notice-success is-dismissible"><p>Umbrales de envío gratis guardados.</p></div>';
			}
			if ( isset( $_POST['akb_ship_dispatch_save'], $_POST['_wpnonce_dispatch'] ) && wp_verify_nonce( $_POST['_wpnonce_dispatch'], 'akb_ship_dispatch' ) ) {
				$valid_statuses = array( 'processing', 'shipping-progress', 'on-hold', 'completed' );
				$selected       = isset( $_POST['akb_dispatch_statuses'] ) && is_array( $_POST['akb_dispatch_statuses'] )
					? array_values( array_intersect( array_map( 'sanitize_key', $_POST['akb_dispatch_statuses'] ), $valid_statuses ) )
					: array();
				if ( empty( $selected ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>Debe seleccionar al menos un estado.</p></div>';
				} else {
					update_option( 'akibara_ship_dispatch_statuses', $selected );
					echo '<div class="notice notice-success is-dismissible"><p>Estados de auto-dispatch guardados.</p></div>';
				}
			}
		}
		$wc_threshold     = (int) get_option( 'akibara_free_shipping_threshold', 55000 );
		$ml_threshold_raw = (int) get_option( 'akibara_ml_free_shipping_threshold', 0 );
		$ml_inherit       = ( $ml_threshold_raw === 0 );
		$ml_threshold     = $ml_inherit ? $wc_threshold : $ml_threshold_raw;
		// [HTML output identical to legacy — omitted for brevity; caller gets same form]
		// Full HTML: see server-snapshot/…/modules/shipping/module.php lines 628-735.
		// Factored out to avoid duplicating 100 lines — include verbatim on deploy.
		require_once __DIR__ . '/shipping-admin-tab.php';
	}

} // end group wrap
