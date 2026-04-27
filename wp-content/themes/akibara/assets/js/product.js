/**
 * Akibara Product Page JS
 * Gallery zoom, image viewer
 */

(function () {
  'use strict';

  // ========== Image zoom on hover ==========
  const mainContainer = document.getElementById('product-main-image');
  const mainImg = document.getElementById('main-product-img');

  if (mainContainer && mainImg) {
    let zoomRect = null;
    mainContainer.addEventListener('mouseenter', () => {
      zoomRect = mainContainer.getBoundingClientRect();
    });
    window.addEventListener('resize', () => { zoomRect = null; }, { passive: true });

    let zoomRafId = null;
    mainContainer.addEventListener('mousemove', (e) => {
      if (zoomRafId) return;
      zoomRafId = requestAnimationFrame(() => {
        if (!zoomRect) zoomRect = mainContainer.getBoundingClientRect();
        const x = ((e.clientX - zoomRect.left) / zoomRect.width) * 100;
        const y = ((e.clientY - zoomRect.top) / zoomRect.height) * 100;
        mainImg.style.transformOrigin = `${x}% ${y}%`;
        mainImg.style.transform = 'scale(1.8)';
        zoomRafId = null;
      });
    });

    mainContainer.addEventListener('mouseleave', () => {
      if (zoomRafId) { cancelAnimationFrame(zoomRafId); zoomRafId = null; }
      mainImg.style.transform = '';
      mainImg.style.transformOrigin = '';
      zoomRect = null;
    });
  }

})();


(function() {
  'use strict';

  // ── Swipe en galería de producto (mobile) ──
  var mainContainer = document.getElementById('product-main-image');
  var mainImgEl = document.getElementById('main-product-img');
  var thumbs = Array.from(document.querySelectorAll('.product-gallery__thumb'));

  if (mainContainer && thumbs.length > 1) {
    var touchStartX = 0;
    var touchStartY = 0;
    var currentThumbIdx = 0;

    // Encontrar thumb activo inicial
    thumbs.forEach(function(th, i) {
      if (th.classList.contains('product-gallery__thumb--active')) currentThumbIdx = i;
    });

    var isAnimating = false;
    function goToThumb(idx) {
      if (isAnimating) return;
      if (idx < 0) idx = thumbs.length - 1;
      if (idx >= thumbs.length) idx = 0;
      var th = thumbs[idx];
      if (!th || !mainImgEl) return;
      // Crossfade with double rAF to prevent race conditions
      isAnimating = true;
      mainImgEl.style.transition = 'opacity 160ms ease';
      mainImgEl.style.opacity = '0';
      var newSrc = th.dataset.full || th.querySelector('img')?.src || mainImgEl.src;
      requestAnimationFrame(function() {
        requestAnimationFrame(function() {
          mainImgEl.src = newSrc;
          mainImgEl.style.opacity = '1';
          setTimeout(function() { isAnimating = false; }, 160);
        });
      });
      // Update active thumb
      thumbs.forEach(function(t) { t.classList.remove('product-gallery__thumb--active'); });
      th.classList.add('product-gallery__thumb--active');
      currentThumbIdx = idx;
    }

    mainContainer.addEventListener('touchstart', function(e) {
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
    }, { passive: true });

    mainContainer.addEventListener('touchend', function(e) {
      var dx = e.changedTouches[0].clientX - touchStartX;
      var dy = e.changedTouches[0].clientY - touchStartY;
      // Solo swipe horizontal con al menos 50px
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
        if (dx < 0) {
          goToThumb(currentThumbIdx + 1); // siguiente
        } else {
          goToThumb(currentThumbIdx - 1); // anterior
        }
      }
    }, { passive: true });

    // Click handler en thumbnails (desktop) — sin esto sólo funcionaba swipe en mobile.
    // Buttons nativos también disparan en Space/Enter para a11y keyboard.
    thumbs.forEach(function(th, idx) {
      th.addEventListener('click', function(e) {
        e.preventDefault();
        goToThumb(idx);
      });
    });
  }

})();

// ── Countdown ticker for preventa products ──
(function() {
  "use strict";

  var el = document.querySelector(".akb-countdown[data-timestamp]");
  if (!el) return;

  var timestamp = parseInt(el.getAttribute("data-timestamp"), 10);
  if (!timestamp || isNaN(timestamp)) return;

  var daysEl   = el.querySelector("[data-unit=\"days\"]");
  var hoursEl  = el.querySelector("[data-unit=\"hours\"]");
  var minsEl   = el.querySelector("[data-unit=\"minutes\"]");

  if (!daysEl || !hoursEl || !minsEl) return;

  function pad(n) { return n < 10 ? "0" + n : "" + n; }

  function tick() {
    var now  = Math.floor(Date.now() / 1000);
    var diff = timestamp - now;

    if (diff <= 0) {
      daysEl.textContent  = "0";
      hoursEl.textContent = "00";
      minsEl.textContent  = "00";
      el.classList.add("akb-countdown--ended");
      return;
    }

    var days  = Math.floor(diff / 86400);
    var hours = Math.floor((diff % 86400) / 3600);
    var mins  = Math.floor((diff % 3600) / 60);

    daysEl.textContent  = "" + days;
    hoursEl.textContent = pad(hours);
    minsEl.textContent  = pad(mins);
  }

  // Initial tick to sync with client time, then every 60s
  tick();
  setInterval(tick, 60000);
})();

// ── ATC form loading state (feedback visual) ──
(function() {
  'use strict';

  var form = document.querySelector('.product-add-to-cart');
  if (!form) return;

  form.addEventListener('submit', function() {
    var btn = form.querySelector('[type="submit"]');
    if (btn && !btn.classList.contains('loading')) {
      btn.classList.add('loading');
      btn.disabled = true;
    }
  });
})();
