/**
 * Akibara WhatsApp — Frontend Script v1.3.0
 * Solo boton flotante, click directo a WhatsApp
 */
(function () {
  'use strict';

  var container = document.getElementById('akibara-wa');
  if (!container) return;

  var settings;
  try { settings = JSON.parse(container.dataset.settings || '{}'); } catch (e) { return; }
  if (!settings.phone) return;

  var btn = container.querySelector('.akibara-wa__btn');

  function getIsMobile() { return window.innerWidth <= 768; }

  if (getIsMobile() && !settings.mobile)  { container.remove(); return; }
  if (!getIsMobile() && !settings.desktop) { container.remove(); return; }

  container.setAttribute('aria-hidden', 'false');
  btn.setAttribute('tabindex', '-1');

  function showBtn() {
    btn.classList.add('is-visible');
    btn.removeAttribute('tabindex');
    // Safety net: si la transición CSS no corrió (tab en background / iOS bfcache),
    // forzar estado final via inline style 800ms después (> 400ms de transición).
    setTimeout(function () {
      if (parseFloat(getComputedStyle(btn).opacity) < 0.5) {
        btn.style.opacity = '1';
        btn.style.transform = 'scale(1) translateY(0)';
      }
    }, 800);
  }

  if (document.hidden) {
    // Página cargó en background — esperar hasta que sea visible para mostrar el botón.
    document.addEventListener('visibilitychange', function onVisible() {
      if (!document.hidden) {
        document.removeEventListener('visibilitychange', onVisible);
        setTimeout(showBtn, settings.delayBtn || 1500);
      }
    });
  } else {
    setTimeout(showBtn, settings.delayBtn || 1500);
  }

  btn.addEventListener('click', function (e) {
    e.preventDefault();
    openWhatsApp();
  });

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest('.akibara-wa-open, a[href="#whatsapp"], a[href="#joinchat"]');
    if (trigger) {
      e.preventDefault();
      openWhatsApp();
    }
  });

  function openWhatsApp() {
    fireAnalytics();
    var url;
    if (getIsMobile()) {
      url = 'https://wa.me/' + settings.phone;
      if (settings.message) url += '?text=' + encodeURIComponent(settings.message);
    } else {
      url = settings.url || ('https://web.whatsapp.com/send?phone=' + settings.phone + (settings.message ? '&text=' + encodeURIComponent(settings.message) : ''));
    }
    window.open(url, '_blank', 'noopener');
  }

  function fireAnalytics() {
    var ctx = settings.isProduct ? 'product' : 'general';
    if (typeof gtag === 'function') {
      gtag('event', 'generate_lead', {
        event_category: 'whatsapp',
        event_label: ctx,
        value: 1
      });
    }
    if (window.dataLayer) {
      window.dataLayer.push({ event: 'akibara_whatsapp_click', wa_context: ctx });
    }
  }
})();
