<?php
/**
 * Akibara — Override WooCommerce checkout login form toggle.
 *
 * WC core (templates/checkout/form-login.php) emite:
 *   <a href="#" class="showlogin">Click here to login</a>
 *
 * Problemas:
 * 1. `<a href="#">` sin destino real → failures de accesibilidad (SC 2.4.4 + 4.1.2)
 *    y navegación con teclado problemática.
 * 2. Copy hardcoded en inglés por el locale inconsistente de WC.
 * 3. Sin `aria-expanded` → screen readers no comunican el estado del toggle.
 *
 * Este override:
 * - Reemplaza el `<a>` por `<button type="button">` con `aria-expanded` + `aria-controls`.
 * - Mantiene la clase `.showlogin` para que el JS de WC (`woocommerce.min.js`) siga
 *   registrando el click handler sin cambios.
 * - Traduce los strings al español.
 *
 * Hallazgo CO-6 (audit Sprint 1-2, 2026-04-19).
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$registration_at_checkout   = WC_Checkout::instance()->is_registration_enabled();
$login_reminder_at_checkout = 'yes' === get_option( 'woocommerce_enable_checkout_login_reminder' );

if ( is_user_logged_in() ) {
	return;
}

if ( $login_reminder_at_checkout ) : ?>
	<div class="woocommerce-form-login-toggle">
		<div class="woocommerce-info akb-login-toggle">
			<?php
			echo wp_kses_post(
				apply_filters(
					'woocommerce_checkout_login_message',
					esc_html__( '¿Ya eres cliente?', 'akibara' ) . ' <button type="button" class="showlogin akb-login-toggle__btn" aria-expanded="false" aria-controls="woocommerce-form-login">' . esc_html__( 'Iniciar sesión', 'akibara' ) . '</button>'
				)
			);
			?>
		</div>
	</div>
	<?php
endif;

if ( $registration_at_checkout || $login_reminder_at_checkout ) :

	// Always show the form after a login attempt.
	$show_form = isset( $_POST['login'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	woocommerce_login_form(
		array(
			'message'  => esc_html__( 'Si ya has comprado con nosotros, ingresa tus datos. Si eres cliente nuevo, continúa a los datos de facturación.', 'akibara' ),
			'redirect' => wc_get_checkout_url(),
			'hidden'   => ! $show_form,
		)
	);
endif;

// WC core listener escucha 'a.showlogin' — nuestro override usa <button>, así que
// agregamos un delegated handler que replica el toggle + sincroniza aria-expanded.
// Solo se carga si el toggle se va a renderizar en absoluto.
if ( $login_reminder_at_checkout || $registration_at_checkout ) :
	?>
	<script>
	(function($){
		if ( ! $ ) return;
		$(document).on('click', 'button.showlogin', function(e){
			e.preventDefault();
			var $btn = $(this);
			var $form = $btn.closest('.woocommerce-form-login-toggle').siblings('form.login').first();
			if ( ! $form.length ) { $form = $('form.login').first(); }
			var expanded = $btn.attr('aria-expanded') === 'true';
			$btn.attr('aria-expanded', expanded ? 'false' : 'true');
			$form.slideToggle();
		});
	})(window.jQuery);
	</script>
	<?php
endif;
