<?php
/**
 * Akibara Welcome Discount — Admin Page
 *
 * Settings + live metrics dashboard.
 * Accessible from WooCommerce → Bienvenida, independently
 * of whether the module is active or not (to allow activation).
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/admin.php
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 * Emoji removed from notice text (branding policy).
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

class Akibara_WD_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_akb_wd_save_settings', array( $this, 'handle_save' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			'Descuento de Bienvenida',
			'Bienvenida',
			'manage_woocommerce',
			'akibara-welcome-discount',
			array( $this, 'render_page' )
		);
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'No autorizado', 403 );
		}
		check_admin_referer( 'akb_wd_settings' );

		$enabled = ! empty( $_POST['wd_enabled'] ) ? 1 : 0;
		update_option( 'akibara_wd_enabled', $enabled, false );

		Akibara_WD_Settings::save( $_POST );
		Akibara_WD_Settings::flush();

		wp_safe_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=akibara-welcome-discount' ) ) );
		exit;
	}

	public function render_page(): void {
		$settings = Akibara_WD_Settings::all();
		$enabled  = (int) get_option( 'akibara_wd_enabled', 0 );
		$saved    = ! empty( $_GET['saved'] );

		$metrics = array();
		if ( class_exists( 'Akibara_WD_Log' ) && function_exists( 'akb_wd_table_sub' ) ) {
			try {
				$metrics = Akibara_WD_Log::metrics();
			} catch ( \Throwable $e ) {
				// Tables may not exist yet on fresh install
			}
		}
		?>
		<div class="wrap">
			<h1>Descuento de Bienvenida</h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>Configuracion guardada.</p></div>
			<?php endif; ?>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning"><p>
					<strong>Modulo desactivado.</strong> El formulario de newsletter no genera cupones hasta que actives el modulo abajo.
				</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $metrics ) ) : ?>
			<!-- Metrics grid -->
			<h2>Metricas (all-time)</h2>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px">
				<?php
				$kpis = array(
					'Pendientes'       => $metrics['subscriptions_pending'],
					'Confirmadas'      => $metrics['subscriptions_confirmed'],
					'Cupones emitidos' => $metrics['coupons_issued'],
					'Redimidos'        => $metrics['coupons_redeemed'],
				);
				foreach ( $kpis as $label => $val ) :
					?>
				<div style="background:#fff;border:1px solid #e0e0e0;padding:16px;border-radius:6px;text-align:center">
					<div style="font-size:28px;font-weight:700;color:#1a1a2e"><?php echo (int) $val; ?></div>
					<div style="color:#777;font-size:12px;margin-top:4px"><?php echo esc_html( $label ); ?></div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- Ventana 7 dias (KPI accionable) -->
			<h2>Ultimos 7 dias</h2>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:28px">
				<div style="background:#eff6ff;border:1px solid #3b82f6;padding:14px;border-radius:6px;text-align:center">
					<div style="font-size:22px;font-weight:700;color:#1e3a8a"><?php echo (int) $metrics['coupons_issued_7d']; ?></div>
					<div style="color:#1e40af;font-size:12px;margin-top:4px">Cupones emitidos (7d)</div>
				</div>
				<div style="background:#ecfdf5;border:1px solid #10b981;padding:14px;border-radius:6px;text-align:center">
					<div style="font-size:22px;font-weight:700;color:#065f46"><?php echo (int) $metrics['coupons_redeemed_7d']; ?></div>
					<div style="color:#047857;font-size:12px;margin-top:4px">Redimidos (7d)</div>
				</div>
				<div style="background:#fef3c7;border:1px solid #fbbf24;padding:14px;border-radius:6px;text-align:center">
					<div style="font-size:22px;font-weight:700;color:#92400e"><?php echo esc_html( number_format_i18n( (float) $metrics['redemption_rate_7d'], 1 ) ); ?>%</div>
					<div style="color:#78350f;font-size:12px;margin-top:4px">Tasa de uso (7d)</div>
				</div>
			</div>

				<?php if ( $metrics['abuse_suspects_30d'] + $metrics['ratelimit_hits_30d'] + $metrics['rejections_rut_30d'] > 0 ) : ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:28px">
					<?php
					$abuse = array(
						'Sospechas direccion (30d)'  => $metrics['abuse_suspects_30d'],
						'Rate limit bloqueados (30d)' => $metrics['ratelimit_hits_30d'],
						'Rechazados por RUT (30d)'   => $metrics['rejections_rut_30d'],
					);
					foreach ( $abuse as $label => $val ) :
						?>
				<div style="background:#fffbeb;border:1px solid #fbbf24;padding:14px;border-radius:6px;text-align:center">
					<div style="font-size:22px;font-weight:700;color:#92400e"><?php echo (int) $val; ?></div>
					<div style="color:#78350f;font-size:12px;margin-top:4px"><?php echo esc_html( $label ); ?></div>
				</div>
					<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<?php endif; ?>

			<!-- Settings form -->
			<h2>Configuracion</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'akb_wd_settings' ); ?>
				<input type="hidden" name="action" value="akb_wd_save_settings">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Estado del modulo</th>
						<td>
							<label>
								<input type="checkbox" name="wd_enabled" value="1" <?php checked( $enabled, 1 ); ?>>
								Activar descuento de bienvenida
							</label>
							<p class="description">
								Desactivado por defecto. Activar solo cuando el formulario de newsletter
								este implementado en el frontend.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Double opt-in</th>
						<td>
							<label>
								<input type="checkbox" name="double_optin" value="1"
										<?php checked( (int) $settings['double_optin'], 1 ); ?>>
								Requerir confirmacion por email antes de emitir el cupon
							</label>
							<p class="description">Recomendado — reduce abuso con emails desechables.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Tipo de descuento</th>
						<td>
							<select name="discount_type">
								<option value="percent"    <?php selected( $settings['discount_type'], 'percent' ); ?>>
									Porcentaje (%)
								</option>
								<option value="fixed_cart" <?php selected( $settings['discount_type'], 'fixed_cart' ); ?>>
									Monto fijo (CLP)
								</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Valor del descuento</th>
						<td>
							<input type="number" name="amount"
									value="<?php echo esc_attr( $settings['amount'] ); ?>"
									min="1" max="100000" style="width:90px">
							<p class="description">
								Porcentaje: 1–100. Monto fijo: valor en CLP (ej. 3000).
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Compra minima (CLP)</th>
						<td>
							<input type="number" name="min_order"
									value="<?php echo esc_attr( $settings['min_order'] ); ?>"
									min="0" style="width:130px">
							<p class="description">0 = sin minimo.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Vigencia del cupon (dias)</th>
						<td>
							<input type="number" name="validity_days"
									value="<?php echo esc_attr( $settings['validity_days'] ); ?>"
									min="1" max="365" style="width:80px">
						</td>
					</tr>
					<tr>
						<th scope="row">Remitente de emails</th>
						<td>
							<input type="text" name="from_name"
									value="<?php echo esc_attr( $settings['from_name'] ); ?>"
									placeholder="Akibara" style="width:180px">
							&nbsp;
							<input type="email" name="from_email"
									value="<?php echo esc_attr( $settings['from_email'] ); ?>"
									placeholder="contacto@akibara.cl" style="width:230px">
						</td>
					</tr>
					<tr>
						<th scope="row">Rate limit (por IP/dia)</th>
						<td>
							<input type="number" name="rate_limit_day"
									value="<?php echo esc_attr( $settings['rate_limit_day'] ); ?>"
									min="1" max="20" style="width:70px">
							<p class="description">Numero maximo de suscripciones desde la misma IP en 24h.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Dominios bloqueados extra</th>
						<td>
							<textarea name="blacklist_extra" rows="5" style="width:420px"
										placeholder="Un dominio por linea&#10;spam.com&#10;desechable.net"
							><?php echo esc_textarea( $settings['blacklist_extra'] ); ?></textarea>
							<p class="description">
								Complementa la lista predeterminada de ~40 dominios temporales conocidos.
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Guardar configuracion' ); ?>
			</form>
		</div>
		<?php
	}
}
