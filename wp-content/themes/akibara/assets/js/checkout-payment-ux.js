/**
 * Akibara — UX dinámico del paso 3 (PAGO).
 *
 * Ajusta el texto del botón "Confirmar y pagar" y la visibilidad del
 * banner de cuotas según el método de pago seleccionado:
 *
 *  - Basic (redirect a mercadopago.cl) → botón "Continuar a Mercado Pago"
 *    (reduce ansiedad: el user sabe que va a abandonar el sitio).
 *  - Custom (tarjeta inline) / Transferencia / Flow → "Confirmar y pagar".
 *
 *  - Banner "Con Mercado Pago paga en 3 cuotas…" solo visible cuando el
 *    método seleccionado es MP (Basic o Custom). Oculto para BACS y Flow
 *    porque la promoción no aplica.
 *
 * Hooks con eventos nativos de WC (change en input[name=payment_method])
 * y con `updated_checkout` para re-aplicar tras cada AJAX del sidebar.
 *
 * @package Akibara
 */
(function () {
	'use strict';

	// Copy del botón dinámico según método. 3 categorías:
	// 1. REDIRECT (MP Basic, Flow): "Continuar a X" — anticipa que sale del sitio.
	// 2. INLINE (MP Custom): "Confirmar y pagar" — procesa aquí mismo.
	// 3. NO-PAYMENT-NOW (BACS): "Confirmar pedido" — el pago real es después
	//    por transferencia bancaria, decir "pagar" aquí es confuso.
	var BTN_DEFAULT    = 'Confirmar y pagar';  // MP Custom y fallback
	var BTN_MP_BASIC   = 'Continuar a Mercado Pago';
	var BTN_FLOW       = 'Continuar a Flow';
	var BTN_BACS       = 'Confirmar pedido';
	var BTN_PROCESSING = 'Procesando…'; // visible durante submit

	function selectedMethod() {
		var el = document.querySelector('input[name="payment_method"]:checked');
		return el ? el.value : '';
	}

	function isMP(method)      { return method.indexOf('woo-mercado-pago') === 0; }
	function isMPBasic(method) { return method === 'woo-mercado-pago-basic'; }
	function isFlow(method)    { return method.indexOf('flowpayment') === 0; }
	function isBacs(method)    { return method === 'bacs'; }

	function buttonCopyFor(method) {
		if (isMPBasic(method)) return BTN_MP_BASIC;
		if (isFlow(method))    return BTN_FLOW;
		if (isBacs(method))    return BTN_BACS;
		return BTN_DEFAULT;
	}

	function updateButtonText() {
		var btn = document.querySelector('#place_order, button[name="woocommerce_checkout_place_order"]');
		if (!btn) return;
		// No sobreescribir si el form está en processing (texto "Procesando…" ya puesto).
		var form = btn.closest('form.checkout');
		if (form && form.classList.contains('processing')) return;
		btn.textContent = buttonCopyFor( selectedMethod() );
	}

	function updateInstallmentsBanner() {
		var banner = document.querySelector('.aki-payment-installments');
		if (!banner) return;
		banner.style.display = isMP( selectedMethod() ) ? 'flex' : 'none';
	}

	function apply() {
		updateButtonText();
		updateInstallmentsBanner();
	}

	// Change de método de pago (radio) — event delegation en document.
	document.addEventListener('change', function (e) {
		if (e.target && e.target.name === 'payment_method') {
			apply();
		}
	});

	// WC re-renderea #order_review vía AJAX — re-aplicar tras cada update.
	if (window.jQuery) {
		jQuery(function ($) {
			$(document.body).on('updated_checkout', apply);
		});
	}

	// Primera pasada al cargar el DOM.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', apply);
	} else {
		apply();
	}

	// ═══════════════════════════════════════════════════════════════
	// OVERLAY de procesamiento al submitear
	// ═══════════════════════════════════════════════════════════════
	var overlayTimer = null;

	function copyForMethod(method) {
		if (isMPBasic(method)) {
			return {
				title: 'Te llevamos a Mercado Pago…',
				sub:   'No cierres esta ventana. En unos segundos estarás en el <strong>sitio seguro de Mercado Pago</strong> para completar tu compra.'
			};
		}
		if (isMP(method)) {
			return {
				title: 'Procesando tu pago…',
				sub:   'Confirmando con <strong>Mercado Pago</strong>. Esto puede tomar unos segundos.'
			};
		}
		if (isFlow(method)) {
			return {
				title: 'Te llevamos a Flow…',
				sub:   'No cierres esta ventana. En unos segundos estarás en el <strong>sitio seguro de Flow</strong> para completar tu compra.'
			};
		}
		if (isBacs(method)) {
			return {
				title: 'Confirmando tu pedido…',
				sub:   'Te mostraremos los <strong>datos bancarios</strong> para que hagas la transferencia. Tu pedido se reservará mientras confirmamos el pago.'
			};
		}
		return {
			title: 'Procesando tu pedido…',
			sub:   'Esto puede tomar unos segundos. No cierres esta ventana.'
		};
	}

	function buildOverlay(method) {
		var existing = document.getElementById('akb-checkout-processing-overlay');
		if (existing) return existing;

		var copy = copyForMethod(method);
		var wrap = document.createElement('div');
		wrap.id = 'akb-checkout-processing-overlay';
		wrap.className = 'akb-checkout-processing-overlay';
		wrap.setAttribute('role', 'status');
		wrap.setAttribute('aria-live', 'polite');
		wrap.innerHTML =
			'<div class="akb-checkout-processing-overlay__spinner" aria-hidden="true"></div>' +
			'<h2 class="akb-checkout-processing-overlay__title"></h2>' +
			'<p class="akb-checkout-processing-overlay__sub"></p>';
		wrap.querySelector('.akb-checkout-processing-overlay__title').textContent = copy.title;
		// sub contiene <strong> — usar innerHTML pero con copy hardcoded en este archivo (XSS-safe).
		wrap.querySelector('.akb-checkout-processing-overlay__sub').innerHTML = copy.sub;
		document.body.appendChild(wrap);
		return wrap;
	}

	function showOverlay() {
		var method = selectedMethod();
		var overlay = buildOverlay(method);
		// Microtask para que la transition CSS aplique
		requestAnimationFrame(function () {
			overlay.classList.add('is-active');
		});
	}

	function hideOverlay() {
		var overlay = document.getElementById('akb-checkout-processing-overlay');
		if (overlay) overlay.classList.remove('is-active');
		if (overlayTimer) { clearTimeout(overlayTimer); overlayTimer = null; }
	}

	// Submit del form de checkout — activar overlay tras 500ms y cambiar texto botón.
	if (window.jQuery) {
		jQuery(function ($) {
			$(document.body).on('submit', 'form.checkout', function () {
				var btn = document.querySelector('#place_order, button[name="woocommerce_checkout_place_order"]');
				if (btn) btn.textContent = BTN_PROCESSING;
				// Delay 500ms: si la respuesta es rápida (error de validación que
				// dispara `checkout_error` inmediato), no se alcanza a ver el overlay.
				overlayTimer = setTimeout(showOverlay, 500);
			});

			// Si WC dispara `checkout_error`, ocultar overlay y restaurar botón.
			$(document.body).on('checkout_error', function () {
				hideOverlay();
				apply(); // restaurar texto según método seleccionado
			});
		});
	}
})();
