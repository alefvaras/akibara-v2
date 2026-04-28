<?php
/**
 * Akibara Core — Badge de cuotas sin interés
 *
 * Muestra "X cuotas de $Y sin interés" debajo del precio en:
 *  - Página de producto (single)
 *  - Carrito (junto al total)
 *  - Checkout
 *
 * Configurable desde WooCommerce → Cuotas.
 *
 * Migrado desde akibara/modules/installments/module.php (Polish #1 2026-04-26).
 * Group-wrap pattern + sentinel per HANDOFF §8 (REDESIGN.md §9).
 *
 * @package    Akibara\Core
 * @subpackage Installments
 * @version    2.0.0
 */

defined( 'ABSPATH' ) || exit;

// ─── File-level guard ───────────────────────────────────────────────────────
if ( defined( 'AKB_CORE_INSTALLMENTS_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_INSTALLMENTS_LOADED', '2.0.0' );

// Backward-compat: si el legacy definió AKIBARA_INSTALLMENTS_LOADED, no redeclares.
if ( defined( 'AKIBARA_INSTALLMENTS_LOADED' ) ) {
	return;
}

// Constant signal per ModuleRegistry pattern.
if ( ! defined( 'AKB_CORE_MODULE_INSTALLMENTS_LOADED' ) ) {
	define( 'AKB_CORE_MODULE_INSTALLMENTS_LOADED', '2.0.0' );
}

// ─── Group wrap (REDESIGN.md §9) ────────────────────────────────────────────
if ( ! function_exists( 'akb_installments_mp_active' ) ) {

	/**
	 * Verifica si MercadoPago está activo como gateway.
	 *
	 * Sólo MP tiene las "3 cuotas sin interés" contratadas por Akibara
	 * (costeadas por el comercio). Flow/Webpay ofrecen cuotas del banco
	 * emisor pero NO necesariamente sin interés → el badge sería engañoso.
	 */
	function akb_installments_mp_active(): bool {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$mp_slugs = array( 'woo-mercado-pago-basic', 'woo-mercado-pago-custom', 'mercadopago' );
		foreach ( $gateways as $id => $gw ) {
			foreach ( $mp_slugs as $slug ) {
				if ( stripos( $id, $slug ) !== false ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Compat alias — si alguien externo llamaba la función anterior.
	 */
	function akb_installments_gateway_supports_cuotas(): bool {
		return akb_installments_mp_active();
	}

	function akibara_installments_config(): array {
		$saved = get_option( 'akibara_installments_config', array() );
		return wp_parse_args(
			$saved,
			array(
				'cuotas'     => 3,
				'min_precio' => 9000,
				'activo'     => true,
			)
		);
	}

	// ══════════════════════════════════════════════════════════════════
	// SINGLE PRODUCT — debajo del precio
	// ══════════════════════════════════════════════════════════════════

	add_action( 'woocommerce_single_product_summary', 'akibara_installments_single', 11 );

	function akibara_installments_single(): void {
		$cfg = akibara_installments_config();
		if ( empty( $cfg['activo'] ) || ! akb_installments_gateway_supports_cuotas() ) {
			return;
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$price = (float) $product->get_price();
		if ( $price < $cfg['min_precio'] ) {
			return;
		}

		$per_cuota = ceil( $price / $cfg['cuotas'] );
		?>
		<div class="akb-installments">
			<span class="akb-installments__icon">💳</span>
			<span class="akb-installments__text">
				<strong><?php echo (int) $cfg['cuotas']; ?> cuotas de $<?php echo number_format( $per_cuota, 0, ',', '.' ); ?></strong> sin interés
				<span class="akb-installments__with">con Mercado Pago</span>
			</span>
		</div>
		<?php
	}

	// ══════════════════════════════════════════════════════════════════
	// CARRITO Y CHECKOUT
	// ══════════════════════════════════════════════════════════════════

	add_action( 'woocommerce_cart_totals_after_order_total', 'akibara_installments_cart' );
	add_action( 'woocommerce_review_order_after_order_total', 'akibara_installments_cart' );

	function akibara_installments_cart(): void {
		$cfg = akibara_installments_config();
		if ( empty( $cfg['activo'] ) || ! akb_installments_gateway_supports_cuotas() ) {
			return;
		}
		if ( ! WC()->cart ) {
			return;
		}

		$total = (float) WC()->cart->get_total( 'edit' );
		if ( $total < $cfg['min_precio'] ) {
			return;
		}

		$per_cuota = ceil( $total / $cfg['cuotas'] );
		?>
		<tr class="akb-installments-row">
			<td colspan="2" style="text-align:right;padding-top:var(--space-2)">
				<span class="akb-installments" style="justify-content:flex-end">
					<span class="akb-installments__icon">💳</span>
					<span class="akb-installments__text">
						<strong><?php echo (int) $cfg['cuotas']; ?> cuotas de $<?php echo number_format( $per_cuota, 0, ',', '.' ); ?></strong> sin interés
						<span class="akb-installments__with">con Mercado Pago</span>
					</span>
				</span>
			</td>
		</tr>
		<?php
	}

	// ══════════════════════════════════════════════════════════════════
	// CSS
	// ══════════════════════════════════════════════════════════════════

	add_action( 'wp_head', 'akibara_installments_css', 99 );

	function akibara_installments_css(): void {
		if ( ! is_product() && ! is_cart() && ! is_checkout() ) {
			return;
		}
		?>
		<style>
		.akb-installments{display:inline-flex;align-items:center;gap:var(--space-2,8px);margin:var(--space-2,8px) 0;padding:var(--space-2,8px) var(--space-3,12px);background:var(--aki-surface,#111);border:1px solid var(--aki-border,#2A2A2A);font-size:13px;color:var(--aki-gray-300,#B0B0B0)}
		.akb-installments__icon{font-size:16px}
		.akb-installments__text{display:inline-flex;flex-wrap:wrap;align-items:baseline;gap:4px 8px}
		.akb-installments__text strong{color:var(--aki-white,#F5F5F5)}
		.akb-installments__with{font-size:11px;color:var(--aki-gray-500,#8A8A8A);text-transform:uppercase;letter-spacing:0.04em;font-weight:500}
		</style>
		<?php
	}

	// ══════════════════════════════════════════════════════════════════
	// ADMIN — Configuración
	// ══════════════════════════════════════════════════════════════════

	add_action( 'admin_menu', 'akibara_installments_admin_menu' );

	function akibara_installments_admin_menu(): void {
		if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
			return;
		}

		add_submenu_page(
			'akibara',
			'Cuotas sin Interés',
			'💰 Cuotas',
			'manage_woocommerce',
			'akibara-installments',
			'akibara_installments_admin_page'
		);
	}

	function akibara_installments_admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}

		if ( isset( $_POST['akibara_inst_save'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'akibara_inst_save' ) ) {
			$new_cfg = array(
				'cuotas'     => max( 2, min( 24, (int) ( $_POST['cuotas'] ?? 3 ) ) ),
				'min_precio' => max( 1000, (int) ( $_POST['min_precio'] ?? 9000 ) ),
				'activo'     => isset( $_POST['activo'] ),
			);
			update_option( 'akibara_installments_config', $new_cfg );
			echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
		}

		$cfg = akibara_installments_config();
		?>
		<div class="wrap" style="max-width:560px">
			<h1>Cuotas sin Interés</h1>
			<p style="color:#666;font-size:13px">
				Muestra el desglose de cuotas en el producto, carrito y checkout.
			</p>
			<div style="background:#fff;border:1px solid #ccd0d4;padding:24px;border-radius:6px;margin-top:16px">
				<form method="post">
					<?php wp_nonce_field( 'akibara_inst_save' ); ?>
					<table class="form-table">
						<tr>
							<th style="width:160px">Módulo activo</th>
							<td>
								<label>
									<input type="checkbox" name="activo" value="1" <?php checked( $cfg['activo'] ); ?>>
									Mostrar badge de cuotas
								</label>
							</td>
						</tr>
						<tr>
							<th>Número de cuotas</th>
							<td>
								<input type="number" name="cuotas" value="<?php echo (int) $cfg['cuotas']; ?>"
										min="2" max="24" class="small-text">
								<p class="description">Cuotas a mostrar (ej: 3, 6, 12).</p>
							</td>
						</tr>
						<tr>
							<th>Precio mínimo</th>
							<td>
								<input type="number" name="min_precio" value="<?php echo (int) $cfg['min_precio']; ?>"
										min="1000" step="1000" class="regular-text">
								<p class="description">Solo muestra cuotas si el precio supera este monto (en CLP).</p>
							</td>
						</tr>
					</table>
					<div style="background:#f0f8ff;border:1px solid #c3d9ff;padding:12px 16px;border-radius:4px;margin:12px 0;font-size:13px;color:#004085">
						Con la configuración actual: productos desde
						<strong>$<?php echo number_format( $cfg['min_precio'], 0, ',', '.' ); ?></strong>
						mostrarán <strong><?php echo (int) $cfg['cuotas']; ?> cuotas de
						$<?php echo number_format( ceil( $cfg['min_precio'] / $cfg['cuotas'] ), 0, ',', '.' ); ?></strong> (mínimo).
					</div>
					<p class="submit">
						<button type="submit" name="akibara_inst_save" value="1" class="button button-primary">
							Guardar configuración
						</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

} // end group wrap
