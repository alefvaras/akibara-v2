/**
 * Akibara — Campaign Countdown
 *
 * Actualiza todos los elementos `.akb-camp-countdown` con el tiempo restante.
 * Usa `data-akb-camp-end` (timestamp unix en segundos) del span mismo o de su padre
 * `.akb-campaign-banner`. Detiene el ticker cuando todas las campañas expiraron.
 */
(function() {
    'use strict';

    function fmt(ms) {
        if (ms <= 0) return '¡Termina!';
        var s   = Math.floor(ms / 1000);
        var d   = Math.floor(s / 86400);
        var h   = Math.floor((s % 86400) / 3600);
        var m   = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        if (d > 0) return d + 'd ' + h + 'h ' + m + 'm';
        if (h > 0) return h + 'h ' + m + 'm ' + sec + 's';
        return m + 'm ' + sec + 's';
    }

    function update() {
        var nodes = document.querySelectorAll('.akb-camp-countdown');
        if (!nodes.length) return false;
        var now       = Date.now();
        var anyActive = false;
        nodes.forEach(function(n) {
            var raw = n.dataset.akbCampEnd
                   || (n.parentNode && n.parentNode.dataset && n.parentNode.dataset.akbCampEnd)
                   || 0;
            var end = parseInt(raw, 10) * 1000;
            if (!end) return;
            n.textContent = fmt(end - now);
            if (end > now) anyActive = true;
        });
        return anyActive;
    }

    if (document.querySelectorAll('.akb-camp-countdown').length === 0) return;
    update();
    var timer = setInterval(function() {
        if (!update()) clearInterval(timer);
    }, 1000);
})();
