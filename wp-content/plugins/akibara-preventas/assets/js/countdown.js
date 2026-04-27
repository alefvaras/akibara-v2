/**
 * Akibara Reservas — Countdown timer para preventas.
 * Se actualiza cada minuto (no cada segundo, para ser sutil y no agresivo).
 */
(function() {
	'use strict';

	var el = document.querySelector('.akb-countdown');
	if (!el) return;

	var timestamp = parseInt(el.getAttribute('data-timestamp'), 10) * 1000;
	if (!timestamp || isNaN(timestamp)) return;

	function pad(n) { return n < 10 ? '0' + n : '' + n; }

	function update() {
		var now  = Date.now();
		var diff = timestamp - now;

		if (diff <= 0) {
			el.innerHTML = '<div class="akb-countdown-label">Ya disponible</div>';
			return;
		}

		var days    = Math.floor(diff / 86400000);
		var hours   = Math.floor((diff % 86400000) / 3600000);
		var minutes = Math.floor((diff % 3600000) / 60000);

		var dEl = el.querySelector('[data-unit="days"]');
		var hEl = el.querySelector('[data-unit="hours"]');
		var mEl = el.querySelector('[data-unit="minutes"]');

		if (dEl) dEl.textContent = days;
		if (hEl) hEl.textContent = pad(hours);
		if (mEl) mEl.textContent = pad(minutes);
	}

	// Actualizar inmediatamente y luego cada 60 segundos
	update();
	var intervalId = setInterval(function() {
		update();
		// Stop interval when countdown reaches zero
		var diff = timestamp - Date.now();
		if (diff <= 0) clearInterval(intervalId);
	}, 60000);
})();
