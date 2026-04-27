/**
 * Akibara — Ship PUDO Map: iframe map visibility + agency selection panels.
 *
 * CONSTRAINT: #aki-pudo-map MUST remain in #aki-pudo (rendered by PHP outside
 * .aki-shipping-inner). Moving it inside a WC AJAX-replaced grid destroys the iframe.
 * The update_checkout binding re-parents map/metro elements before WC replaces the grid.
 *
 * Depends on ship-grid.js (window.AkibaraShipping.grid).
 * Part of checkout-shipping-enhancer split (ARQ-3).
 */
(function ($) {
	'use strict';

	var S = window.AkibaraShipping;

	// ═══════════════════════════════════════════════════════════════
	// PUDO map / Metro hint visibility
	// ═══════════════════════════════════════════════════════════════

	function moveAuxPanels() {
		var mode   = S.grid.getCurrentMode();
		var $map   = $('#aki-pudo-map');
		var $metro = $('#aki-pudo-metro');

		if (mode === 'pudo') {
			if ($map.length) $map.addClass('is-open');
			if ($metro.length) $metro.removeClass('is-open');
		} else if (mode === 'metro') {
			if ($metro.length) $metro.addClass('is-open');
			if ($map.length) $map.removeClass('is-open');
		} else {
			if ($map.length) $map.removeClass('is-open');
			if ($metro.length) $metro.removeClass('is-open');
		}
	}

	// Re-parent PUDO map to its home (#aki-pudo) before WC AJAX replaces the grid.
	// The iframe must stay outside .aki-shipping-inner so it survives the update.
	$(document.body).on('update_checkout', function () {
		var $c = S.grid.getContainer();
		if ($c.length) $c.addClass('is-updating');

		var $home = $('#aki-pudo');
		if ($home.length) {
			var $map   = $('#aki-pudo-map');
			var $metro = $('#aki-pudo-metro');
			if ($map.length && !$home.is($map.parent())) {
				$home.append($map);
			}
			if ($metro.length && !$home.is($metro.parent())) {
				$home.append($metro);
			}
		}
	});

	// ═══════════════════════════════════════════════════════════════
	// Public API
	// ═══════════════════════════════════════════════════════════════

	S.pudo = {
		moveAuxPanels: moveAuxPanels
	};

})(jQuery);
