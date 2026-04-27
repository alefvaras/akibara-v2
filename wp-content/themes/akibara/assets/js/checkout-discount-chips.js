/**
 * Akibara — Interacción del chip summary de descuentos (checkout).
 *
 * El chip consolidado (N≥3 series con descuento) tiene un tooltip que se
 * abre con hover en desktop pero necesita toggle manual en mobile touch
 * (sin hover real). Este script:
 *   - Click en el botón: toggle aria-expanded + show/hide tooltip.
 *   - Click fuera: cierra cualquier tooltip abierto.
 *   - Escape: cierra todos los tooltips y devuelve foco al botón.
 *
 * No requiere jQuery. Se activa solo si hay summary en el DOM.
 *
 * @package Akibara
 */
(function () {
	'use strict';

	var SEL_SUMMARY = '.akb-vol-notice--summary';

	function getTooltip(btn) {
		var id = btn.getAttribute('aria-describedby');
		return id ? document.getElementById(id) : null;
	}

	function closeAll(except) {
		document.querySelectorAll(SEL_SUMMARY + '[aria-expanded="true"]').forEach(function (b) {
			if (b === except) return;
			b.setAttribute('aria-expanded', 'false');
		});
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest(SEL_SUMMARY);
		if (btn) {
			e.preventDefault();
			var expanded = btn.getAttribute('aria-expanded') === 'true';
			closeAll(btn);
			btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
			return;
		}

		// Click fuera del botón y fuera del tooltip: cerrar.
		if (!e.target.closest('.akb-discount-tooltip')) {
			closeAll(null);
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') return;
		var any = document.querySelector(SEL_SUMMARY + '[aria-expanded="true"]');
		if (any) {
			closeAll(null);
			any.focus();
		}
	});
})();
