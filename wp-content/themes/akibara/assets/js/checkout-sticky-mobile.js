/**
 * Akibara — Sticky bottom CTA (mobile only).
 *
 * Sincroniza el total y el CTA con el paso activo del accordion de checkout.
 * Visible únicamente ≤768px via `@media` en checkout.css. Fuera del funnel
 * el markup no se imprime (page-checkout.php ya condiciona por `is_thankyou`).
 *
 * Seguridad: usa `textContent` (no `innerHTML`) para el total → XSS-safe.
 * Performance: selector único específico para evitar traversals redundantes.
 *
 * @package Akibara
 */
(function () {
	'use strict';

	// A11Y fix: Select2 wrap del billing_state crea un combobox sin aria-label.
	// WC label[for=billing_state] apunta al <select> nativo oculto; el <span>
	// role=combobox que renderea Select2 no hereda esa asociación.
	// Lo taggeamos cada vez que updated_checkout reinicia el widget.
	function labelSelect2State() {
		[
			['#select2-billing_state-container',  'Región de facturación'],
			['#select2-billing_city-container',   'Comuna de facturación'],
			['#select2-shipping_state-container', 'Región de envío'],
			['#select2-shipping_city-container',  'Comuna de envío']
		].forEach(function (pair) {
			var el = document.querySelector(pair[0]);
			if (el && !el.getAttribute('aria-label')) {
				el.setAttribute('aria-label', pair[1]);
			}
		});
	}
	if (window.jQuery) {
		jQuery(function ($) {
			labelSelect2State();
			$(document.body).on('updated_checkout country_to_state_changed', labelSelect2State);
		});
	} else {
		document.addEventListener('DOMContentLoaded', labelSelect2State);
	}

	var stickyTotal = document.getElementById('akb-mobile-sticky-total');
	var stickyCta   = document.getElementById('akb-mobile-sticky-cta');
	if (!stickyTotal || !stickyCta) return;

	function updateTotal() {
		var src = document.querySelector('#order_review .order-total .woocommerce-Price-amount');
		if (src) {
			// textContent es XSS-safe. La cursiva del símbolo de moneda se
			// pierde pero es trade-off aceptable vs. riesgo de inyección.
			stickyTotal.textContent = src.textContent.trim();
		}
	}

	function currentStepButton() {
		var active = document.querySelector(
			'.aki-step.aki-step--active .aki-step__cta, ' +
			'.aki-step.aki-step--active button[type="submit"], ' +
			'.aki-step.aki-step--active .button'
		);
		if (active) return active;

		var fallback = document.querySelector('#place_order, .checkout-button, .aki-step__cta');
		if (!fallback && window.console && window.console.warn) {
			console.warn('[akibara] sticky CTA: no step active and no fallback button');
		}
		return fallback;
	}

	/**
	 * A7 (a11y Round 3) — aria-label dinámico per-step.
	 *
	 * El sticky CTA mobile siempre dice visualmente "Continuar", pero
	 * sin contexto del paso actual. Screen readers anuncian "Continuar,
	 * botón" sin diferencia entre paso 1, 2 y 3.
	 *
	 * Solución: leer el data-step del .aki-step--active y mapear a un
	 * aria-label descriptivo que previsualice el siguiente paso. Esto
	 * espeja la voz de los CTAs principales (.aki-step__continue) que
	 * ya usan "Ir a envío" / "Ir a pago" como copy visible.
	 */
	function syncCtaAriaLabel() {
		var activeStep = document.querySelector('.aki-step.aki-step--active');
		if (!activeStep) {
			// Sin paso activo (edge case: thank-you page, error de render),
			// dejamos el aria-label genérico "Continuar" del HTML inicial.
			return;
		}

		var step = activeStep.getAttribute('data-step') || '';
		var label = '';

		if (step === '1') {
			label = 'Continuar a envío';
		} else if (step === '2') {
			label = 'Continuar a pago';
		} else if (step === '3') {
			label = 'Realizar pedido';
		} else {
			label = 'Continuar con el paso actual';
		}

		stickyCta.setAttribute('aria-label', label);
	}

	stickyCta.addEventListener('click', function () {
		var btn = currentStepButton();
		if (btn) btn.click();
	});

	if (window.jQuery) {
		jQuery(document.body).on('updated_checkout updated_cart_totals', updateTotal);
		// A7: re-sincronizar aria-label cada vez que checkout-steps.js
		// avanza/retrocede de paso. El switch de .aki-step--active
		// dispara `aki-step-changed` desde checkout-steps.js (custom
		// event) y también ocurre en updated_checkout (re-render WC).
		jQuery(document.body).on('aki-step-changed updated_checkout', syncCtaAriaLabel);
	}

	// MutationObserver fallback: si checkout-steps.js no dispara el
	// evento (load orden inesperado), igual capturamos el cambio de
	// .aki-step--active observando el contenedor del accordion.
	var accordion = document.getElementById('aki-co-accordion');
	if (accordion && typeof MutationObserver === 'function') {
		var observer = new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				if (mutations[i].attributeName === 'class') {
					syncCtaAriaLabel();
					break;
				}
			}
		});
		observer.observe(accordion, {
			attributes: true,
			attributeFilter: ['class'],
			subtree: true
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		updateTotal();
		syncCtaAriaLabel();
	});
	updateTotal();
	syncCtaAriaLabel();
})();
