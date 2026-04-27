/**
 * Akibara Cart Page — UX enhancements
 *
 * Syncs mobile sticky total with WooCommerce cart updates.
 * Ship bar updates via WC fragments (no extra JS needed there).
 */
(function () {
  'use strict';

  // ── Sync sticky total after WC cart page form update ──────────────────────
  // WC fires 'updated_cart_totals' on the document.body (via jQuery) after the
  // cart totals section is refreshed by the "Actualizar carrito" flow.
  // We read the fresh total from .order-total .woocommerce-Price-amount.

  function syncStickyTotal() {
    var sticky = document.querySelector('.akb-cart-sticky__amount');
    if (!sticky) return;

    var totalEl = document.querySelector('.order-total .woocommerce-Price-amount');
    if (totalEl) {
      sticky.innerHTML = totalEl.parentElement
        ? totalEl.parentElement.innerHTML
        : totalEl.outerHTML;
    }
  }

  // jQuery event from WooCommerce cart page
  if (window.jQuery) {
    window.jQuery(document.body).on('updated_cart_totals', syncStickyTotal);
    // Also fire once on DOMContentLoaded in case WC already updated
    window.jQuery(syncStickyTotal);
  }

  // ── Wishlist state sync for "Guardar para después" buttons ─────────────────
  // wishlist.js handles click delegation via .js-wishlist globally.
  // Here we just ensure initial active state is reflected on page load.
  function syncSaveLaterStates() {
    try {
      var raw = JSON.parse(localStorage.getItem('akibara_wishlist') || '[]');
      var ids = Array.isArray(raw)
        ? raw.map(function (item) {
            return typeof item === 'object' ? String(item.id) : String(item);
          })
        : [];

      document.querySelectorAll('.akb-save-later__btn').forEach(function (btn) {
        var pid = btn.dataset.productId;
        if (pid && ids.indexOf(pid) > -1) {
          btn.classList.add('active');
        }
      });
    } catch (e) {}
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncSaveLaterStates);
  } else {
    syncSaveLaterStates();
  }

  // Re-sync after wishlist toggle (wishlist.js dispatches no custom event,
  // but the click handler on .js-wishlist already toggles the class via
  // the existing delegation in wishlist.js).
})();
