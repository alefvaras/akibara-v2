<?php
/**
 * Checkout Form — Akibara Accordion Override
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Express checkout + back link (via checkout-accordion.php hooks) ──
do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}

$billing_fields = $checkout->get_checkout_fields( 'billing' );

remove_action( 'woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20 );

// ── User data for auto-skip ──
$user_data = [];
if ( is_user_logged_in() ) {
	$uid         = get_current_user_id();
	$saved_email = get_userdata( $uid )->user_email;
	$saved_rut   = get_user_meta( $uid, 'billing_rut', true );
	$saved_fname = get_user_meta( $uid, 'billing_first_name', true );
	$saved_lname = get_user_meta( $uid, 'billing_last_name', true );
	$saved_addr  = get_user_meta( $uid, 'billing_address_1', true );
	$saved_city  = get_user_meta( $uid, 'billing_city', true );
	$saved_state = get_user_meta( $uid, 'billing_state', true );

	if ( $saved_email && $saved_rut ) {
		$user_data['canSkipStep1'] = true;
	}
	if ( $saved_fname && $saved_addr && $saved_city && $saved_state ) {
		$user_data['canSkipStep2'] = true;

		// Preformatear dirección: auto-capitalize + incluir región legible.
		// Antes: "bartolo soto 3700, San Miguel" (sin región + minúsculas)
		// Ahora: "Bartolo Soto 3700, San Miguel, Región Metropolitana"
		$addr_pretty = function_exists( 'mb_convert_case' )
			? mb_convert_case( mb_strtolower( $saved_addr, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' )
			: ucwords( strtolower( $saved_addr ) );

		$region_pretty = '';
		if ( function_exists( 'WC' ) && WC()->countries && function_exists( 'akibara_normalize_chile_state' ) ) {
			$canonical = akibara_normalize_chile_state( $saved_state );
			$states    = WC()->countries->get_states( 'CL' );
			if ( $canonical && isset( $states[ $canonical ] ) ) {
				$region_pretty = $states[ $canonical ];
			}
		}

		$parts = array_filter( [ $addr_pretty, $saved_city, $region_pretty ] );
		$user_data['savedAddress'] = implode( ', ', $parts );
		$user_data['savedName']    = trim( "$saved_fname $saved_lname" );
	}
}
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

<script>var akiCheckoutUser = <?php echo wp_json_encode( $user_data ); ?>;</script>

<div class="aki-co__layout">

	<!-- ═══ ACCORDION ═══ -->
	<div class="aki-co__accordion" id="aki-co-accordion">

		<?php if ( $checkout->get_checkout_fields() ) : ?>
		<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

		<!-- Progress Bar — A21 (a11y): <ol> semántico para que screen readers
		     anuncien "lista con 3 elementos" + paso actual. aria-current="step"
		     marca el paso activo. Líneas decorativas son aria-hidden (no aportan
		     a la navegación; el orden de los <li> ya comunica progresión). -->
		<ol class="aki-progress" id="aki-progress" aria-label="Pasos del checkout">
			<li class="aki-progress__step aki-progress__step--active" data-prog="1" aria-current="step">
				<span class="aki-progress__circle" aria-hidden="true">1</span>
				<span class="aki-progress__label">Contacto</span>
			</li>
			<li class="aki-progress__line" aria-hidden="true"></li>
			<li class="aki-progress__step" data-prog="2">
				<span class="aki-progress__circle" aria-hidden="true">2</span>
				<span class="aki-progress__label">Envío</span>
			</li>
			<li class="aki-progress__line" aria-hidden="true"></li>
			<li class="aki-progress__step" data-prog="3">
				<span class="aki-progress__circle" aria-hidden="true">3</span>
				<span class="aki-progress__label">Pago</span>
			</li>
		</ol>

		<!-- ═══ STEP 1: Contacto ═══ -->
		<div class="aki-step aki-step--active" data-step="1">
			<div class="aki-step__header">
				<span class="aki-step__number">1</span>
				<span class="aki-step__icon">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
				</span>
				<div class="aki-step__title-group">
					<h3 class="aki-step__title">Contacto</h3>
					<?php // UX copy fix 2026-04-25 (ux-copy BG): subtitle declarativo reduce fricción vs pregunta. ?>
					<p class="aki-step__subtitle">Te enviaremos la confirmación a este email</p>
				</div>
			</div>

			<div class="aki-step__summary" style="display:none;">
				<span class="aki-step__summary-text"></span>
				<button type="button" class="aki-step__edit">Editar</button>
			</div>

			<div class="aki-step__content">
				<?php
				$step1_keys = [ 'billing_email', 'billing_rut' ];
				foreach ( $step1_keys as $key ) {
					if ( isset( $billing_fields[ $key ] ) ) {
						woocommerce_form_field( $key, $billing_fields[ $key ], $checkout->get_value( $key ) );
					}
				}
				?>
				<?php // UX copy 2026-04-25 (ux-copy BG) + Sprint 11 a11y #10 (cognitive 2026-04-26):
				// "Ir a envío" → "Continuar a envío" — verbo "Continuar" comunica progresión
				// secuencial, "Ir a" puede percibirse como navegación lateral. Aplica a 3 steps. ?>
				<button type="button" class="aki-step__continue btn btn--primary aki-speed-lines" data-step="1">Continuar a envío</button>
			</div>
		</div>

		<!-- ═══ STEP 2: Datos de envío ═══ -->
		<div class="aki-step aki-step--locked" data-step="2">
			<div class="aki-step__header">
				<span class="aki-step__number">2</span>
				<span class="aki-step__icon">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>
				</span>
				<div class="aki-step__title-group">
					<h3 class="aki-step__title">Datos de envío</h3>
					<p class="aki-step__subtitle">Dirección y método de envío</p>
				</div>
			</div>

			<!-- Trust mini-row: refuerzos de confianza antes de que el
			     cliente decida método de envío. No ocupa mucho espacio
			     pero reduce fricción ("¿llegará? ¿puedo rastrearlo?"). -->
			<ul class="aki-step__trust" aria-label="Garantías de envío">
				<li>
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
					<span>Mismo día en RM</span>
				</li>
				<li>
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					<span>Aviso al despachar</span>
				</li>
				<li>
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					<span>Empaque con protección</span>
				</li>
				<li>
					<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
					<span>Cambios 10 días</span>
				</li>
			</ul>

			<div class="aki-step__summary" style="display:none;">
				<span class="aki-step__summary-text"></span>
				<button type="button" class="aki-step__edit">Editar</button>
			</div>

			<div class="aki-step__content">
				<!-- Saved address for returning users -->
				<div class="aki-saved-address" id="aki-saved-address" style="display:none;">
					<p class="aki-saved-address__text">
						<strong>Dirección guardada:</strong> <span id="aki-saved-address-text"></span>
					</p>
					<button type="button" class="aki-saved-address__btn btn btn--outline" id="aki-use-saved">Usar esta dirección</button>
					<button type="button" class="aki-saved-address__change" id="aki-change-address">Cambiar dirección</button>
				</div>

				<?php
				$step2_keys = [
					'billing_first_name', 'billing_last_name', 'billing_phone',
					'billing_country', 'billing_state', 'billing_city',
					'billing_address_1', 'billing_address_2',
				];
				echo '<div class="aki-step__fields">';
				foreach ( $step2_keys as $key ) {
					if ( isset( $billing_fields[ $key ] ) ) {
						woocommerce_form_field( $key, $billing_fields[ $key ], $checkout->get_value( $key ) );
					}
				}
				echo '</div>';
				?>

				<div id="aki-shipping-methods" class="aki-step__shipping">
					<h4 class="aki-step__shipping-title">Método de envío</h4>
					<div class="aki-shipping-inner">
						<?php
						if ( function_exists( 'akibara_render_shipping_methods' ) && WC()->cart && WC()->cart->needs_shipping() ) {
							akibara_render_shipping_methods();
						}
						?>
					</div>
				</div>

				<?php do_action( 'akibara_checkout_pudo_selector' ); ?>

				<button type="button" class="aki-step__continue btn btn--primary aki-speed-lines" data-step="2">Continuar a pago</button>
			</div>
		</div>

		<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
		<?php endif; ?>

		<!-- ═══ STEP 3: Pago ═══ -->
		<div class="aki-step aki-step--locked" data-step="3">
			<div class="aki-step__header">
				<span class="aki-step__number">3</span>
				<span class="aki-step__icon">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				</span>
				<div class="aki-step__title-group">
					<h3 class="aki-step__title">Pago</h3>
					<p class="aki-step__subtitle">Método de pago y confirmación</p>
				</div>
			</div>

			<div class="aki-step__summary" style="display:none;">
				<span class="aki-step__summary-text"></span>
				<button type="button" class="aki-step__edit">Editar</button>
			</div>

			<div class="aki-step__content">
				<?php
				do_action( 'woocommerce_before_order_notes', $checkout );
				$order_fields = $checkout->get_checkout_fields( 'order' );
				if ( isset( $order_fields['order_comments'] ) ) {
					woocommerce_form_field( 'order_comments', $order_fields['order_comments'], $checkout->get_value( 'order_comments' ) );
				}
				do_action( 'woocommerce_after_order_notes', $checkout );

				woocommerce_checkout_payment();

				// Cuotas sin interés — diferenciador clave de Mercado Pago en Chile.
				// Se muestra destacado en paso 3 para cerrar la venta con una razón
				// financiera fuerte. Auditoría UX pasos 3: estaba ausente pese a
				// estar promocionado en PDP/landing.
				$cart_total = WC()->cart ? (float) WC()->cart->get_total( 'raw' ) : 0;
				if ( $cart_total > 0 ) :
					$per_cuota = (int) round( $cart_total / 3 );
				?>
				<div class="aki-payment-installments" role="note">
					<span class="aki-payment-installments__icon" aria-hidden="true">💳</span>
					<span class="aki-payment-installments__text">
						Con <strong>Mercado Pago</strong> paga en
						<strong>3 cuotas de $<?php echo esc_html( number_format( $per_cuota, 0, ',', '.' ) ); ?></strong>
						sin interés
					</span>
				</div>
				<?php endif; ?>

				<div class="aki-security-badge">
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
					<span>Pago 100% seguro — Tus datos están protegidos</span>
				</div>
			</div>
		</div>

	</div><!-- .aki-co__accordion -->

	<!-- ═══ SIDEBAR ═══ -->
	<div class="aki-co__sidebar">
		<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
		<h3 id="order_review_heading" class="aki-co__sidebar-title">Tu Pedido</h3>
		<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
		<div id="order_review" class="woocommerce-checkout-review-order">
			<?php do_action( 'woocommerce_checkout_order_review' ); ?>
		</div>
		<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>

		<!-- ═══ TRUST SIGNALS ═══
		     Se lee como extensión del #order_review (sin radius ni gap),
		     jerarquía elevada en el primer ítem y pasarelas como landmark
		     único (no se repiten en texto). Copy corregido: sin "respuesta
		     hábil" ni "desde recibido". -->
		<section class="aki-co__trust" aria-labelledby="aki-trust-heading">
			<h4 id="aki-trust-heading" class="aki-co__trust-heading">Tu compra está protegida</h4>
			<ul class="aki-co__trust-list" role="list">
				<li class="aki-co__trust-item aki-co__trust-item--hero">
					<span class="aki-co__trust-icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
					</span>
					<div class="aki-co__trust-text">
						<strong>Pago seguro</strong>
						<span>Protegido por Webpay y Mercado Pago</span>
					</div>
				</li>
				<li class="aki-co__trust-item">
					<span class="aki-co__trust-icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
					</span>
					<div class="aki-co__trust-text">
						<strong>Envíos con Blue Express</strong>
						<span>Despacho a todo Chile con seguimiento</span>
					</div>
				</li>
				<li class="aki-co__trust-item">
					<span class="aki-co__trust-icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
					</span>
					<div class="aki-co__trust-text">
						<strong>Atención por WhatsApp</strong>
						<span>Te respondemos en menos de 24 horas hábiles</span>
					</div>
				</li>
				<li class="aki-co__trust-item">
					<span class="aki-co__trust-icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
					</span>
					<div class="aki-co__trust-text">
						<strong>Cambios y devoluciones</strong>
						<span>Tienes 10 días desde que recibes tu pedido</span>
					</div>
				</li>
			</ul>
			<div class="aki-co__trust-pay">
				<span class="aki-co__trust-pay-label">Pagas con</span>
				<ul class="aki-co__pay-badges" role="list" aria-label="Medios de pago">
					<li><span class="aki-co__pay-badge aki-co__pay-badge--webpay">Webpay</span></li>
					<li><span class="aki-co__pay-badge aki-co__pay-badge--mp">Mercado Pago</span></li>
					<li><span class="aki-co__pay-badge">Visa</span></li>
					<li><span class="aki-co__pay-badge">Mastercard</span></li>
					<li><span class="aki-co__pay-badge">Transferencia</span></li>
				</ul>
			</div>
		</section>
	</div>

</div><!-- .aki-co__layout -->

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
