/**
 * Akibara — Ship Tracking: GA4 events, session heartbeat and session fallback UI.
 * Depends on ship-grid.js (window.AkibaraShipping.grid).
 * Part of checkout-shipping-enhancer split (ARQ-3).
 */
(function ($) {
	'use strict';

	var S = window.AkibaraShipping;

	// ═══════════════════════════════════════════════════════════════
	// GA4 tracking
	// ═══════════════════════════════════════════════════════════════

	function trackShipping(courier, mode) {
		var def = S.grid.cardDefinition(courier);
		var tierMap = {
			'bluex-home':  'Blue Express Domicilio',
			'bluex-pudo':  'Blue Express PUDO',
			'pickup':      'Retiro Metro San Miguel'
		};
		var tier = tierMap[courier] || def.name || 'Otro';
		var payload = {
			shipping_tier: tier,
			value:         0,
			currency:      'CLP',
			courier:       courier,
			mode:          mode
		};

		if (typeof window.gtag === 'function') {
			window.gtag('event', 'select_shipping', payload);
		}
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push({ event: 'select_shipping', ecommerce: payload });
		$(document.body).trigger('akibara:shipping_selected', [payload]);
	}

	// Funnel event: usuario confirmó envío y pasó al siguiente paso
	$(document).on('click', '.aki-step__continue[data-step="2"]', function () {
		var mode    = S.grid.getCurrentMode();
		var courier = mode === 'metro' ? 'pickup' : (mode === 'pudo' ? 'bluex-pudo' : 'bluex-home');
		var def     = S.grid.cardDefinition(courier);
		var tierMap = { 'bluex-home': 'Blue Express Domicilio', 'bluex-pudo': 'Blue Express PUDO', 'pickup': 'Retiro Metro San Miguel' };
		var subtotal = S.grid.getCartSubtotal();
		var free     = S.grid.isFreeShipping();

		var payload = {
			event:         'checkout_shipping_confirmed',
			shipping_tier:  tierMap[courier] || def.name,
			courier:        courier,
			mode:           mode,
			free_shipping:  free,
			cart_subtotal:  subtotal,
			currency:       'CLP'
		};

		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(payload);

		if (typeof window.gtag === 'function') {
			window.gtag('event', 'checkout_shipping_confirmed', {
				shipping_tier: payload.shipping_tier,
				courier:       payload.courier,
				mode:          payload.mode,
				free_shipping: payload.free_shipping,
				value:         subtotal,
				currency:      'CLP'
			});
		}
	});

	// ═══════════════════════════════════════════════════════════════
	// Silent heartbeat to keep session alive during user activity
	// ═══════════════════════════════════════════════════════════════

	let lastActivityTime = Date.now();
	let heartbeatInterval = null;
	const HEARTBEAT_INTERVAL_MINUTES = 5;
	const INACTIVITY_THRESHOLD_MINUTES = 10;

	function recordUserActivity() {
		lastActivityTime = Date.now();
	}

	function sendHeartbeat() {
		const now = Date.now();
		const inactivityDuration = (now - lastActivityTime) / (1000 * 60); // in minutes
		if (inactivityDuration < INACTIVITY_THRESHOLD_MINUTES) {
			// Only send heartbeat if user has been active recently
			fetch('/wp-json/akibara/v1/session/keep-alive', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({ timestamp: now })
			})
			.then(response => {
				if (!response.ok) {
					console.warn('Heartbeat failed to keep session alive');
				}
			})
			.catch(err => console.error('Error sending heartbeat:', err));
		}
	}

	function startHeartbeat() {
		if (heartbeatInterval) return; // Prevent multiple intervals
		heartbeatInterval = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MINUTES * 60 * 1000);
		// Initial heartbeat on start
		sendHeartbeat();
	}

	function stopHeartbeat() {
		if (heartbeatInterval) {
			clearInterval(heartbeatInterval);
			heartbeatInterval = null;
		}
	}

	// Listen for user activity events to update last activity time
	document.addEventListener('mousemove', recordUserActivity);
	document.addEventListener('keydown', recordUserActivity);
	document.addEventListener('scroll', recordUserActivity);
	document.addEventListener('click', recordUserActivity);

	// Start heartbeat when page loads if we're in checkout
	document.addEventListener('DOMContentLoaded', () => {
		if (document.body.classList.contains('woocommerce-checkout')) {
			startHeartbeat();
		}
	});

	// Stop heartbeat when leaving the page
	window.addEventListener('beforeunload', stopHeartbeat);

	// ═══════════════════════════════════════════════════════════════
	// Session error handling
	// ═══════════════════════════════════════════════════════════════

	// Intercept checkout errors for session expiration
	function handleCheckoutError(event) {
		const errorMessage = event.detail && event.detail.message ? event.detail.message : '';
		if (errorMessage.includes('caducad') || errorMessage.includes('session expired')) {
			event.preventDefault();
			showReloadOverlay();
			setTimeout(() => {
				location.reload();
			}, 2000);
		}
	}

	function showReloadOverlay() {
		if (document.querySelector('[data-testid="session-overlay"]')) return;
		const overlay = document.createElement('div');
		overlay.setAttribute('data-testid', 'session-overlay');
		overlay.style.position = 'fixed';
		overlay.style.top = '0';
		overlay.style.left = '0';
		overlay.style.width = '100%';
		overlay.style.height = '100%';
		overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
		overlay.style.display = 'flex';
		overlay.style.flexDirection = 'column';
		overlay.style.justifyContent = 'center';
		overlay.style.alignItems = 'center';
		overlay.style.zIndex = '9999';
		overlay.style.color = '#fff';
		overlay.innerHTML = `
			<div style="background: #161618; padding: 20px; border-radius: 8px; text-align: center;">
				<div class="spinner" style="border: 4px solid #fff; border-top: 4px solid transparent; border-radius: 50%; width: 36px; height: 36px; animation: spin 1s linear infinite; margin: 0 auto 15px;"></div>
				<p>Actualizando tu checkout por seguridad...</p>
				<p style="font-size: 0.8em; color: #A0A0A0;">Akibara protege tus datos.</p>
			</div>
			<style>
				@keyframes spin {
					0% { transform: rotate(0deg); }
					100% { transform: rotate(360deg); }
				}
			</style>
		`;
		document.body.appendChild(overlay);
	}

	// Listen for native (CustomEvent) checkout_error
	document.body.addEventListener('checkout_error', handleCheckoutError);

	// Also listen via jQuery because WooCommerce triggers checkout_error with jQuery.triggerHandler which
	// does NOT reach native listeners.
	if (window.jQuery && typeof window.jQuery === 'function') {
		window.jQuery(document.body).on('checkout_error', function (_e, errorHTML) {
			if (typeof errorHTML === 'string' && (errorHTML.includes('caducad') || errorHTML.includes('session expired'))) {
				showReloadOverlay();
				setTimeout(() => {
					location.reload();
				}, 2000);
			}
		});
	}

	// ═══════════════════════════════════════════════════════════════
	// Proactive fallback UI (empty cart / session expiry)
	// ═══════════════════════════════════════════════════════════════

	// Show proactive fallback UI for empty cart due to session expiration
	function showProactiveFallbackUI() {
		if (document.querySelector('.akibara-empty-cart-fallback')) return;

		const checkoutContainer = document.querySelector('.woocommerce-checkout');
		if (!checkoutContainer) return;

		// Hide default error or empty cart messages
		const errorMessages = document.querySelectorAll('.woocommerce-error');
		errorMessages.forEach(msg => {
			if (msg.textContent.includes('sesión caducada') || msg.textContent.includes('session expired')) {
				msg.style.display = 'none';
			}
		});

		// Create fallback overlay (do not remove checkout markup to avoid breaking UI/tests)
		const fallbackOverlay = document.createElement('div');
		fallbackOverlay.className = 'akibara-empty-cart-fallback';
		fallbackOverlay.setAttribute('role', 'dialog');
		fallbackOverlay.setAttribute('aria-modal', 'true');
		fallbackOverlay.style.position = 'fixed';
		fallbackOverlay.style.top = '0';
		fallbackOverlay.style.left = '0';
		fallbackOverlay.style.width = '100%';
		fallbackOverlay.style.height = '100%';
		fallbackOverlay.style.zIndex = '10000';
		fallbackOverlay.style.display = 'flex';
		fallbackOverlay.style.alignItems = 'center';
		fallbackOverlay.style.justifyContent = 'center';
		fallbackOverlay.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
		fallbackOverlay.style.padding = '20px';

		const fallbackCard = document.createElement('div');
		fallbackCard.style.backgroundColor = '#161618';
		fallbackCard.style.borderRadius = '8px';
		fallbackCard.style.padding = '30px';
		fallbackCard.style.textAlign = 'center';
		fallbackCard.style.color = '#F5F5F5';
		fallbackCard.style.maxWidth = '600px';
		fallbackCard.style.width = '100%';
		fallbackCard.style.boxShadow = '0 4px 10px rgba(0, 0, 0, 0.2)';
		// Theme URI desde wp_localize_script (akibaraCheckoutShipping.themeUri).
		// Fallback a path relativo si la localize falló por alguna razón.
		var themeUri = (S && S.cfg && S.cfg.themeUri) ? S.cfg.themeUri : '/wp-content/themes/akibara';
			// URL canónica de WhatsApp servida desde wp_localize_script (waUrl).
			// Fuente única: akibara_whatsapp_get_business_number() en el plugin akibara-whatsapp.
			// Fallback a /contacto/ si el plugin está deactivado o la localize falló.
			var waUrl = (S && S.cfg && S.cfg.waUrl) ? S.cfg.waUrl : '/contacto/';
		fallbackCard.innerHTML = `
			<img src="${themeUri}/assets/images/cart-sad.png" alt="Carrito triste" style="max-width: 150px; margin-bottom: 20px;">
			<h2 style="color: #D90010; margin-bottom: 15px;">¡Ups! Tu carrito se perdió en el camino</h2>
			<p style="margin-bottom: 25px;">Vamos a recuperarlo juntos. ¿Recuerdas qué manga buscabas?</p>
			<form class="akibara-quick-search" style="display: flex; gap: 10px; justify-content: center; margin-bottom: 30px;">
				<input type="text" placeholder="Escribe el nombre o serie..." style="padding: 10px; width: 70%; border: 1px solid #2A2A2E; background: #0D0D0F; color: #F5F5F5; border-radius: 4px;">
				<button type="submit" style="background: #D90010; color: #fff; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer;">Buscar</button>
			</form>
			<div style="display: flex; justify-content: center; gap: 15px; margin-bottom: 20px;">
				<a href="/tienda/" style="background: #10B981; color: #fff; padding: 10px 20px; border-radius: 4px; text-decoration: none;">Volver a la Tienda</a>
				<a href="${waUrl}" target="_blank" rel="noopener" style="background: #25D366; color: #fff; padding: 10px 20px; border-radius: 4px; text-decoration: none;">Ayuda por WhatsApp</a>
			</div>
		`;

		fallbackOverlay.appendChild(fallbackCard);
		document.body.appendChild(fallbackOverlay);

		checkoutContainer.classList.add('akibara-checkout--fallback');

		// Attach event listener for quick search
		const searchForm = fallbackCard.querySelector('.akibara-quick-search');
		searchForm.addEventListener('submit', function(e) {
			e.preventDefault();
			const query = this.querySelector('input').value.trim();
			if (query) {
				window.location.href = `/tienda/?s=${encodeURIComponent(query)}`;
			}
		});
	}

	// Shared check: show fallback UI only when cart is truly empty AND we
	// have evidence of session expiry (backup in localStorage or a
	// woocommerce-error message). Accesses data.data.count because the REST
	// response is wrapped as { success, data: { count, total, items } }.
	function maybeShowFallback() {
		// Only guard checkout pages; fallback replaces .woocommerce-checkout.
		if (!document.querySelector('form.woocommerce-checkout')) return;
		fetch('/wp-json/akibara/v1/cart/get', { credentials: 'same-origin' })
			.then(function (response) { return response.json(); })
			.then(function (resp) {
				var count = (resp && resp.data && typeof resp.data.count !== 'undefined')
					? parseInt(resp.data.count, 10)
					: NaN;
				if (count !== 0) return;
				var errorMessages = document.querySelectorAll('.woocommerce-error');
				var sessionExpired = Array.from(errorMessages).some(function (msg) {
					return msg.textContent.includes('caducad') || msg.textContent.includes('session expired');
				});
				if (sessionExpired || localStorage.getItem('akb_cart_backup')) {
					showProactiveFallbackUI();
				}
			})
			.catch(function (err) { console.error('Error checking cart for fallback UI:', err); });
	}

	document.addEventListener('DOMContentLoaded', maybeShowFallback);
	document.body.addEventListener('updated_checkout', maybeShowFallback);

	// ═══════════════════════════════════════════════════════════════
	// Public API
	// ═══════════════════════════════════════════════════════════════

	S.tracking = {
		trackShipping: trackShipping
	};

})(jQuery);
