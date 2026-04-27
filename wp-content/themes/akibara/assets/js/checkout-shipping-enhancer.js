/**
 * Akibara — Checkout Shipping Enhancer (orchestrator) v3.0
 *
 * Coordinates the 4 shipping sub-modules loaded before this file:
 *   ship-grid.js            → hero card + accordion rendering + selection sync
 *   ship-free-progress.js   → free shipping progress bar + sidebar summary
 *   ship-pudo-map.js        → PUDO iframe map visibility
 *   ship-tracking.js        → GA4 events + session heartbeat + fallback UI
 *
 * This file only handles document-ready init and WC checkout update hooks.
 * All logic lives in the sub-modules via window.AkibaraShipping.*.
 * wp_localize_script 'akibaraCheckoutShipping' is bound to this handle.
 */
(function ($) {
	'use strict';

	var S = window.AkibaraShipping;

	// Defensive guard: if any sub-module failed to load (404, syntax error),
	// bail gracefully instead of crashing the checkout. WC native shipping UI stays functional.
	if (!S || !S.grid || typeof S.grid.refresh !== 'function') {
		if (window.console && console.warn) {
			console.warn('[Akibara] checkout-shipping-enhancer: sub-modules missing — falling back to WC native shipping UI.');
		}
		return;
	}

	$(document).ready(function () {
		var $c = S.grid.getContainer();
		if ($c.length) $c.addClass('is-updating');
		S.grid.refresh();
	});

	$(document.body).on('updated_checkout updated_shipping_method', function () {
		var $c = S.grid.getContainer();
		if ($c.length) $c.removeClass('is-updating');
		setTimeout(S.grid.refresh, 60);
	});

})(jQuery);
