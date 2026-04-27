/**
 * Akibara — Ship Free Progress: free shipping progress bar + sidebar summary.
 * Depends on ship-grid.js (window.AkibaraShipping.grid).
 * Part of checkout-shipping-enhancer split (ARQ-3).
 */
(function ($) {
	'use strict';

	var S = window.AkibaraShipping;

	// ═══════════════════════════════════════════════════════════════
	// Sidebar: free shipping progress + summary
	// ═══════════════════════════════════════════════════════════════

	function updateFreeShippingProgress() {
		var threshold = parseInt((S.cfg.freeShippingThreshold || 55000), 10);
		if (!threshold) return;

		var $sidebar = $('#order_review');
		if (!$sidebar.length) return;

		var subtotal = S.grid.getCartSubtotal();

		var $sideBar = $sidebar.find('.aki-ship-freebar--side');
		if (!$sideBar.length) {
			$sideBar = $(
				'<div class="aki-ship-freebar aki-ship-freebar--side">' +
				'  <div class="aki-ship-freebar__msg" data-freebar-msg></div>' +
				'  <div class="aki-ship-freebar__track"><div class="aki-ship-freebar__fill" data-freebar-fill></div></div>' +
				'</div>'
			);
			var $anchor = $sidebar.find('.cart-subtotal, .cart_totals, .shop_table').first();
			if ($anchor.length) $sideBar.insertBefore($anchor); else $sidebar.prepend($sideBar);
		}

		var $topSlot = $('.aki-co__layout').first();
		var $topBar  = $topSlot.children('.aki-ship-freebar--top').first();
		if ($topSlot.length && !$topBar.length) {
			$topBar = $(
				'<div class="aki-ship-freebar aki-ship-freebar--top">' +
				'  <div class="aki-ship-freebar__msg" data-freebar-msg></div>' +
				'  <div class="aki-ship-freebar__track"><div class="aki-ship-freebar__fill" data-freebar-fill></div></div>' +
				'</div>'
			);
			$topSlot.prepend($topBar);
		}

		var $bars = $sideBar.add($topBar || $());

		if (subtotal >= threshold) {
			$bars.attr('data-complete', '1').attr('data-stage', 'complete');
			$bars.find('[data-freebar-msg]').html('✅ <strong>Envío gratis incluido</strong> — despachamos a todo Chile');
			$bars.find('[data-freebar-fill]').css('width', '100%');
		} else {
			$bars.attr('data-complete', '0');
			var remaining = threshold - subtotal;
			var pct = Math.max(0, Math.min(100, (subtotal / threshold) * 100));
			var fmt = S.grid.formatClp(remaining);

			// Stage buckets: early (<35%) / mid (35-65%) / near (>65%). CSS
			// cambia color del fill según stage para reforzar progreso visual.
			var stage = pct < 35 ? 'early' : (pct < 65 ? 'mid' : 'near');
			$bars.attr('data-stage', stage);

			var msg;
			if (stage === 'near') {
				// Voz chilena neutra (R5): sin "po" coloquial. Mantiene urgencia + brand voice.
				msg = 'Te falta poco — <strong>' + fmt + '</strong> para envío gratis a todo Chile';
			} else if (stage === 'mid') {
				msg = 'Te faltan <strong>' + fmt + '</strong> para envío gratis a todo Chile';
			} else {
				msg = 'Te faltan <strong>' + fmt + '</strong> para envío gratis a todo Chile';
			}
			$bars.find('[data-freebar-msg]').html(msg);
			$bars.find('[data-freebar-fill]').css('width', pct.toFixed(1) + '%');
		}
	}

	function updateSidebarSummary() {
		var $sidebar = $('#order_review');
		if (!$sidebar.length) return;

		var mode    = S.grid.getCurrentMode();
		var courier = mode === 'metro' ? 'pickup' : (mode === 'pudo' ? 'bluex-pudo' : 'bluex-home');
		var def     = S.grid.cardDefinition(courier);
		var eta     = S.grid.etaFor(courier);

		var $summary = $sidebar.find('.aki-ship-summary');
		if (!$summary.length) {
			$summary = $(
				'<div class="aki-ship-summary">' +
				'  <div class="aki-ship-summary__icon" data-summary-icon></div>' +
				'  <div class="aki-ship-summary__body">' +
				'    <span class="aki-ship-summary__label">Envío</span>' +
				'    <strong class="aki-ship-summary__name" data-summary-name></strong>' +
				'    <span class="aki-ship-summary__eta" data-summary-eta></span>' +
				'  </div>' +
				'  <div class="aki-ship-summary__price" data-summary-price></div>' +
				'</div>'
			);
			var $anchor = $sidebar.find('.cart-subtotal, .cart_totals, .shop_table').first();
			if ($anchor.length) $summary.insertBefore($anchor); else $sidebar.prepend($summary);
		}

		$summary.attr('data-courier', courier);
		$summary.find('[data-summary-icon]').html(S.grid.iconSvg(def.icon));
		$summary.find('[data-summary-name]').text(def.name);
		$summary.find('[data-summary-eta]').text(eta);

		// Price from sidebar shipping row
		var $shippingPrice = $sidebar.find('.woocommerce-shipping-totals .woocommerce-Price-amount').first();
		var priceTxt = $shippingPrice.length ? $shippingPrice.text() : 'Gratis';
		$summary.find('[data-summary-price]').text(priceTxt);
	}

	// ═══════════════════════════════════════════════════════════════
	// Public API
	// ═══════════════════════════════════════════════════════════════

	S.freeProgress = {
		update:        updateFreeShippingProgress,
		updateSidebar: updateSidebarSummary
	};

})(jQuery);
