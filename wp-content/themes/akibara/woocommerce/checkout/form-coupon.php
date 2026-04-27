<?php
/**
 * Akibara — Override WooCommerce checkout coupon toggle.
 *
 * WC core (templates/checkout/form-coupon.php) usa `wc_print_notice()` que
 * envuelve el mensaje en `<div class="woocommerce-info" role="status">`.
 * Eso crea un live-region que screen readers anuncian como notificación cuando
 * el contenido en realidad es estático.
 *
 * Este override emite el mismo contenido + clases (para compatibilidad con
 * `woocommerce.css`) pero SIN `role="status"` en el wrapper.
 *
 * Hallazgo CO-8 (audit Sprint 1-2, 2026-04-19).
 *
 * A11Y fix Sprint 6 (Cola B A5, 2026-04-25 — forms-specialist):
 * `<a href="#" role="button" class="showcoupon">` → `<button type="button" class="showcoupon">`.
 * El handler nativo de WC (`checkout.min.js`) está bindeado al selector tag-specific
 * `a.showcoupon` y NO matchea `<button>`, por lo que checkout-steps.js agrega un handler
 * propio que replica el slideToggle + sync de `aria-expanded` + focus al input.
 * Razón del cambio: WCAG SC 4.1.2 — un toggle de form no es navegación (`<a>`),
 * es una acción → semántica correcta es `<button>`. Click sobre `<a href="#">` además
 * agrega `#` al URL y rompe back-button del navegador.
 *
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! wc_coupons_enabled() ) {
	return;
}
?>
<div class="woocommerce-form-coupon-toggle">
	<div class="woocommerce-info akb-coupon-toggle">
		<?php
		echo wp_kses_post(
			apply_filters(
				'woocommerce_checkout_coupon_message',
				esc_html__( '¿Tienes un cupón?', 'akibara' ) . ' <button type="button" aria-controls="woocommerce-checkout-form-coupon" aria-expanded="false" class="showcoupon">' . esc_html__( 'Ingresa tu código de cupón', 'akibara' ) . '</button>'
			)
		);
		?>
	</div>
</div>

<?php // El form de cupón — requerido por wc-checkout.js (busca form.woocommerce-form-coupon al click en .showcoupon). ?>
<?php // A11Y fix 2026-04-25 (keyboard-navigator): aria-hidden="true" estático era inconsistente — WC checkout.js no lo actualiza al expand. style="display:none" ya excluye del a11y tree (correcto siempre). ?>
<form class="checkout_coupon woocommerce-form-coupon" id="woocommerce-checkout-form-coupon" method="post" style="display:none">
	<p><?php esc_html_e( 'Si tienes un código de cupón, ingrésalo aquí.', 'akibara' ); ?></p>
	<p class="form-row form-row-first">
		<?php // A11Y fix 2026-04-25 (forms-specialist): label programático para screen readers (placeholder NO es label WCAG 3.3.2/1.3.1). ?>
		<label for="coupon_code" class="screen-reader-text"><?php esc_html_e( 'Código de cupón', 'akibara' ); ?></label>
		<input
			type="text"
			name="coupon_code"
			class="input-text"
			id="coupon_code"
			value=""
			placeholder="<?php esc_attr_e( 'Código de cupón', 'akibara' ); ?>"
			autocomplete="off"
			autocapitalize="none"
		/>
	</p>
	<p class="form-row form-row-last">
		<?php // Solo class="button" — NO btn btn--primary (los pseudo-elements speed-lines + shimmer + clip-path skew se veían glitch en botón secundario; CTAs principales reservan btn--primary). Estilos en checkout.css L2420+. ?>
		<button type="submit" class="button" name="apply_coupon" value="<?php esc_attr_e( 'Aplicar cupón', 'akibara' ); ?>">
			<?php esc_html_e( 'Aplicar cupón', 'akibara' ); ?>
		</button>
	</p>
	<div class="clear"></div>
</form>
