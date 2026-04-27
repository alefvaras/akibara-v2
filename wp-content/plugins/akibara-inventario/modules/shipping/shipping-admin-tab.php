<?php
/**
 * Akibara Inventario — Shipping admin tab HTML.
 *
 * Included by akb_inventario_shipping_admin_tab() in module.php.
 * Verbatim migration from legacy modules/shipping/module.php lines 628-735.
 * Variables expected from caller: $wc_threshold, $ml_threshold, $ml_inherit, $akb_couriers.
 *
 * @package Akibara\Inventario
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_INV_ADDON_LOADED' ) ) {
	return;
}
?>
<div class="akb-page-header">
	<h2 class="akb-page-header__title">Envíos</h2>
	<p class="akb-page-header__desc">Blue Express (nacional) + 12 Horas Envíos (RM, mismo día).</p>
</div>

<form method="post" class="akb-card akb-card--section akb-card--spaced">
	<?php wp_nonce_field( 'akb_ship_thresholds' ); ?>
	<h3 class="akb-section-title">Envío gratis</h3>
	<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
		<div class="akb-field">
			<label class="akb-field__label">Umbral envío gratis — Tienda (akibara.cl)</label>
			<input type="number" name="akb_free_shipping_wc" min="0" max="500000" step="1000"
				value="<?php echo esc_attr( $wc_threshold ); ?>" class="akb-field__input" required>
			<p class="akb-field__hint">CLP. Compras iguales o mayores a este monto activan envío gratis en el carrito.</p>
		</div>
		<div class="akb-field">
			<label class="akb-field__label">Umbral envío gratis — MercadoLibre</label>
			<label style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-size:13px">
				<input type="checkbox" name="akb_ml_inherit" value="1" <?php checked( $ml_inherit ); ?>>
				Heredar del umbral de la tienda
			</label>
			<input type="number" name="akb_free_shipping_ml" min="0" max="500000" step="1000"
				value="<?php echo esc_attr( $ml_threshold ); ?>" class="akb-field__input" <?php disabled( $ml_inherit ); ?>>
			<p class="akb-field__hint">CLP. Desmarcar para configurar independiente.</p>
		</div>
	</div>
	<div class="akb-card__actions" style="margin-top:12px">
		<button type="submit" name="akb_ship_thresholds_save" value="1" class="akb-btn akb-btn--primary">Guardar umbrales</button>
	</div>
</form>

<script>
(function(){
	var cb = document.querySelector('input[name="akb_ml_inherit"]');
	var inp = document.querySelector('input[name="akb_free_shipping_ml"]');
	if (!cb || !inp) return;
	cb.addEventListener('change', function(){ inp.disabled = cb.checked; });
})();
</script>

<?php
$dispatch_statuses = akb_ship_get_dispatch_statuses();
$status_labels     = array(
	'processing'        => 'Procesado (pago confirmado)',
	'on-hold'           => 'En espera',
	'shipping-progress' => 'Listo para enviar',
	'completed'         => 'Completado',
);
?>
<form method="post" class="akb-card akb-card--section akb-card--spaced">
	<?php wp_nonce_field( 'akb_ship_dispatch', '_wpnonce_dispatch' ); ?>
	<h3 class="akb-section-title">Auto-dispatch al courier</h3>
	<p class="akb-field__hint" style="margin-bottom:12px">
		Selecciona en qué estados de la orden se enviará automáticamente la info al courier (12 Horas crea el envío; BlueX usa webhook propio).
	</p>
	<div class="akb-field" style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">
		<?php foreach ( $status_labels as $slug => $label ) : ?>
			<label style="display:flex;align-items:center;gap:8px;padding:8px;border:1px solid var(--aki-border,#2A2A2A);border-radius:4px;cursor:pointer">
				<input type="checkbox" name="akb_dispatch_statuses[]" value="<?php echo esc_attr( $slug ); ?>"
					<?php checked( in_array( $slug, $dispatch_statuses, true ) ); ?>>
				<span><?php echo esc_html( $label ); ?></span>
				<code style="margin-left:auto;font-size:11px;color:var(--aki-muted,#888)"><?php echo esc_html( $slug ); ?></code>
			</label>
		<?php endforeach; ?>
	</div>
	<div class="akb-card__actions" style="margin-top:12px">
		<button type="submit" name="akb_ship_dispatch_save" value="1" class="akb-btn akb-btn--primary">Guardar estados de dispatch</button>
	</div>
</form>

<div class="akb-stats">
	<?php foreach ( $akb_couriers as $courier ) :
		$stats     = $courier->get_30d_stats();
		$connected = $courier->test_connection();
		?>
		<div class="akb-stat">
			<span class="akb-badge <?php echo $connected ? 'akb-badge--active' : 'akb-badge--inactive'; ?>">
				<?php echo $connected ? 'Conectado' : 'Desconectado'; ?>
			</span>
			<div class="akb-stat__label"><?php echo esc_html( $courier->get_label() ); ?></div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value"><?php echo esc_html( $stats['count'] ); ?></div>
			<div class="akb-stat__label"><?php echo esc_html( $stats['label'] ); ?></div>
		</div>
	<?php endforeach; ?>
</div>

<div class="akb-card akb-card--section akb-card--spaced">
	<h3 class="akb-section-title">Configuración WooCommerce</h3>
	<p class="akb-field__hint">
		Configura las zonas de envío en <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>">WooCommerce &rarr; Ajustes &rarr; Envío</a>.
	</p>
</div>

<?php
foreach ( $akb_couriers as $courier ) {
	if ( $courier->has_admin_settings() ) {
		$courier->render_admin_settings();
	}
}
