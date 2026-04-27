/**
 * Akibara Cart JS
 * AJAX cart functionality
 */

(function () {
  'use strict';

  const config = window.akibaraCart || {};
  function wcAjaxUrl(action) {
    return config.ajaxUrl ? config.ajaxUrl.replace('%%endpoint%%', action) : '/wp-admin/admin-ajax.php';
  }
  const cartToggle = document.getElementById('cart-toggle');
  const cartDrawer = document.getElementById('cart-drawer');
  const cartOverlay = document.getElementById('cart-overlay');
  const cartClose = document.getElementById('cart-close');
  const cartCount = document.getElementById('cart-count');
  const bottomNavCount = document.getElementById('bottom-nav-count');
  const cartTotal = document.getElementById('cart-total');
  const cartItems = document.getElementById('cart-items');

  // Helper: update all cart count badges
  function updateCartBadges(count) {
    if (cartCount) {
      cartCount.textContent = count;
      cartCount.style.transition = 'transform 200ms cubic-bezier(0.34,1.56,0.64,1)';
      cartCount.style.transform = 'scale(1.4)';
      setTimeout(() => { cartCount.style.transform = ''; }, 250);
    }
    if (bottomNavCount) {
      bottomNavCount.textContent = count;
      bottomNavCount.style.transition = 'transform 200ms cubic-bezier(0.34,1.56,0.64,1)';
      bottomNavCount.style.transform = 'scale(1.4)';
      setTimeout(function() { bottomNavCount.style.transform = ''; }, 250);
    }
  }

  function triggerCheckoutUpdate() {
    if (window.jQuery && typeof window.jQuery === 'function') {
      window.jQuery(document.body).trigger('update_checkout');
    } else {
      document.body.dispatchEvent(new CustomEvent('update_checkout'));
    }
  }

  // Use cart open/close from main.js (window.openCart / window.closeCart)

  // ========== Add to cart handler ==========
  const pendingAdds = new Set();
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.js-quick-add');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    const productId = btn.dataset.productId;
    if (!productId || pendingAdds.has(productId)) return;
    pendingAdds.add(productId);

    const btnSpan = btn.querySelector('span') || btn;
    const origText = btnSpan.textContent;

    btn.classList.add('loading');
    btn.style.pointerEvents = 'none';

    try {
      const formData = new FormData();
      formData.append('product_id', productId);
      formData.append('quantity', 1);
      formData.append('nonce', config.nonce);

      // Use standard wc-ajax for perfect session cookie support on guests
      const restUrl = wcAjaxUrl('akibara_add_to_cart');
      const headers = config.restNonce ? { 'X-WP-Nonce': config.restNonce } : {};

      const response = await fetch(restUrl, {
        method: 'POST',
        body: formData,
        headers: headers,
        credentials: 'same-origin',
      });

      var data;
      try { data = await response.json(); } catch (_) { throw new Error('HTTP ' + response.status); }

      // Helper: extract message from various response shapes
      var resData = data.data || {};
      var msg = resData.message || data.message || '';

      if (!response.ok || !data.success) {
        if (window.akibaraToast) window.akibaraToast(msg || 'No se pudo agregar al carrito');
        btn.classList.remove('loading');
        btn.style.pointerEvents = '';
        pendingAdds.delete(productId);
        return;
      }

      // Amazon/Shopify pattern: product already at max stock in cart
      if (resData.already_in_cart || data.already_in_cart) {
        btnSpan.textContent = '✓ En tu carrito';
        btn.classList.add('btn--added');
        setTimeout(function() {
          btnSpan.textContent = origText;
          btn.classList.remove('btn--added');
          btn.style.opacity = '';
          btn.style.pointerEvents = '';
        }, 2500);
        if (window.akibaraToast) window.akibaraToast(msg, 'info');
        if (window.openCart) window.openCart();
      } else {
        // Normal add success
        btnSpan.textContent = '✓ Agregado';
        btn.classList.add('btn--added');
        setTimeout(function() {
          btnSpan.textContent = origText;
          btn.classList.remove('btn--added');
          btn.style.opacity = '';
          btn.style.pointerEvents = '';
        }, 1500);

        // Experiment conversion: Home latest urgency badge (add-to-cart)
        var expSection = btn.closest('[data-akb-exp="home_latest_urgency_badge"]');
        var expCard = btn.closest('.product-card');
        if (expSection && typeof gtag === 'function') {
          gtag('event', 'experiment_home_latest_add_to_cart', {
            experiment_id: 'home_latest_urgency_badge',
            variant_id: expSection.dataset.akbExpVariant || 'unknown',
            product_id: productId || (expCard ? expCard.dataset.productId : ''),
            card_context: expCard ? (expCard.dataset.cardContext || 'home-latest') : 'home-latest'
          });
        }

        // Open cart drawer
        if (window.openCart) window.openCart();
      }

      // Always update badges and totals
      updateCartBadges(resData.count || data.count);
      if (cartTotal && resData.total) {
        cartTotal.textContent = '';
        cartTotal.insertAdjacentHTML('beforeend', resData.total);
      }
      refreshMiniCart();
      triggerCheckoutUpdate();
      refreshCartBackup();
    } catch (err) {
      if (window.akibaraToast) {
        window.akibaraToast('Error: ' + err.message);
      }
    }

    btn.classList.remove('loading');
    if (!btn.classList.contains('btn--added')) { btn.style.pointerEvents = ''; }
    pendingAdds.delete(productId);
  });

  // ========== Refresh mini cart ==========
  function cartSkeletonHTML() {
    var row = "<div class=\"cart-skeleton\"><div class=\"cart-skeleton__img\"></div><div class=\"cart-skeleton__body\"><div class=\"cart-skeleton__line\"></div><div class=\"cart-skeleton__line cart-skeleton__line--short\"></div><div class=\"cart-skeleton__line cart-skeleton__line--price\"></div></div></div>";
    return row + row;
  }

  async function refreshMiniCart() {
    if (!cartItems) return;
    cartItems.innerHTML = cartSkeletonHTML();

    try {
      const formData = new FormData();
      formData.append('action', 'akibara_get_cart');
      formData.append('nonce', config.nonce);

      const response = await fetch(wcAjaxUrl('akibara_get_cart'), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      if (!response.ok) throw new Error('HTTP ' + response.status);

      const data = await response.json();

      if (data.success) {
        cartItems.innerHTML = data.data.html;
        updateCartBadges(data.data.count);
        if (cartTotal) {
          cartTotal.textContent = '';
          cartTotal.insertAdjacentHTML('beforeend', data.data.total);
        }
        backupCartToLocalStorage(data.data);
      }
    } catch (err) {
      cartItems.innerHTML = '<div style="text-align:center;padding:var(--space-6);color:var(--aki-gray-400)"><p>Error al cargar el carrito</p><button class="btn btn--secondary js-cart-reload" style="margin-top:var(--space-3)">Reintentar</button></div>';
      var reloadBtn = cartItems.querySelector('.js-cart-reload');
      if (reloadBtn) reloadBtn.addEventListener('click', function() { location.reload(); });
    }
  }

  // ========== Remove from mini cart ==========
  document.addEventListener('click', async (e) => {
    const removeBtn = e.target.closest('.cart-item__remove');
    if (!removeBtn) return;

    const cartKey = removeBtn.dataset.cartKey;
    if (!cartKey) return;

    removeBtn.textContent = '...';
    removeBtn.disabled = true;

    try {
      const formData = new FormData();
      formData.append('action', 'akibara_remove_from_cart');
      formData.append('nonce', config.nonce);
      formData.append('cart_key', cartKey);

      const response = await fetch(wcAjaxUrl('akibara_remove_from_cart'), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      if (!response.ok) throw new Error('HTTP ' + response.status);
      const data = await response.json();

      if (data.success) {
        refreshMiniCart();
        triggerCheckoutUpdate();
        refreshCartBackup();
      }
    } catch (err) {
      removeBtn.textContent = 'Eliminar';
      removeBtn.disabled = false;
    }
  });

  // ========== Update quantity in mini cart ==========
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.js-cart-qty-minus, .js-cart-qty-plus');
    if (!btn) return;
    if (btn.disabled) return;

    const cartKey = btn.dataset.cartKey;
    const qty = parseInt(btn.dataset.qty, 10);
    if (!cartKey || isNaN(qty)) return;

    btn.disabled = true;

    try {
      const formData = new FormData();
      formData.append('action', 'akibara_update_cart_qty');
      formData.append('nonce', config.nonce);
      formData.append('cart_key', cartKey);
      formData.append('quantity', qty);

      const response = await fetch(wcAjaxUrl('akibara_update_cart_qty'), { method: 'POST', body: formData, credentials: 'same-origin' });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const data = await response.json();

      if (data.success) {
        if (cartItems) cartItems.innerHTML = data.data.html;
        updateCartBadges(data.data.count);
        if (cartTotal) {
          cartTotal.textContent = '';
          cartTotal.insertAdjacentHTML('beforeend', data.data.total);
        }
        triggerCheckoutUpdate();
        refreshCartBackup();
      }
    } catch (err) {
      if (window.akibaraToast) window.akibaraToast('Error al actualizar cantidad');
      refreshMiniCart();
    } finally {
      if (btn && btn.parentNode) btn.disabled = false;
    }
  });

  // Backup cart to localStorage for session recovery
  // REST items are shaped { key, product_id, name, quantity, ... } so we
  // normalize to { id, quantity } regardless of source.
  function backupCartToLocalStorage(cartData) {
    if (!cartData || !Array.isArray(cartData.items) || cartData.items.length === 0) return;
    try {
      const normalized = cartData.items
        .map(item => ({
          id: item.product_id || item.id,
          quantity: parseInt(item.quantity, 10) || 1
        }))
        .filter(i => i.id);
      if (normalized.length === 0) return;
      const cartBackup = {
        timestamp: Date.now(),
        items: normalized,
        hash: generateCartHash(normalized)
      };
      localStorage.setItem('akb_cart_backup', JSON.stringify(cartBackup));
    } catch (err) {
      console.error('Error backing up cart to localStorage:', err);
    }
  }

  // Generate a simple hash of cart items for change detection
  function generateCartHash(items) {
    const itemString = items.map(item => `${item.id}:${item.quantity}`).join(',');
    return btoa(itemString); // Simple base64 encoding as hash
  }

  // Restore cart from localStorage if session expired
  function restoreCartFromLocalStorage() {
    try {
      const backup = localStorage.getItem('akb_cart_backup');
      if (!backup) return false;

      const { timestamp, items, hash } = JSON.parse(backup);
      const age = Date.now() - timestamp;
      // Only restore if backup is less than 24 hours old
      if (age > 24 * 60 * 60 * 1000) {
        localStorage.removeItem('akb_cart_backup');
        return false;
      }

      // Show notification to user
      showCartRecoveryNotification();
      // Attempt to restore items
      return restoreCartItems(items, hash);
    } catch (err) {
      console.error('Error restoring cart from localStorage:', err);
      return false;
    }
  }

  // Show notification during cart recovery
  function showCartRecoveryNotification(outOfStockItems = []) {
    const message = outOfStockItems.length > 0 
      ? `Recuperamos tu carrito, pero ${outOfStockItems.join(', ')} ya no están disponibles.`
      : 'Estamos recuperando tu carrito, un momento...';

    const notification = document.createElement('div');
    notification.setAttribute('data-testid', 'cart-recovery-notification');
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.backgroundColor = '#161618';
    notification.style.color = '#fff';
    notification.style.padding = '10px 20px';
    notification.style.borderRadius = '5px';
    notification.style.boxShadow = '0 4px 6px rgba(0, 0, 0, 0.1)';
    notification.style.zIndex = '9999';
    notification.textContent = message;
    document.body.appendChild(notification);

    setTimeout(() => {
      notification.style.transition = 'opacity 0.5s';
      notification.style.opacity = '0';
      setTimeout(() => notification.remove(), 500);
    }, 3000);
  }

  // Restore cart items via AJAX
  function restoreCartItems(items, originalHash) {
    let success = true;
    const outOfStockItems = [];

    // Sequential AJAX calls to add each item to cart
    items.forEach(async (item) => {
      try {
        const response = await fetch('/wp-json/akibara/v1/cart/add', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({
            product_id: item.id,
            quantity: item.quantity
          })
        });

        const data = await response.json();
        if (!data.success) {
          success = false;
          outOfStockItems.push(data.product_name || `Producto ${item.id}`);
        }
      } catch (err) {
        console.error('Error restoring item:', err);
        success = false;
      }
    });

    if (!success && outOfStockItems.length > 0) {
      showCartRecoveryNotification(outOfStockItems);
    }

    // Update cart UI after restoration
    setTimeout(() => {
      refreshMiniCart();
      triggerCheckoutUpdate();
    }, 1000);

    return success;
  }

  // Check for cart recovery on page load
  // REST wraps payload as { success, data: { count, items, total } }.
  document.addEventListener('DOMContentLoaded', () => {
    fetch('/wp-json/akibara/v1/cart/get', { credentials: 'same-origin' })
      .then(response => response.json())
      .then(resp => {
        const cart = resp && resp.data ? resp.data : null;
        if (cart && parseInt(cart.count, 10) === 0 && localStorage.getItem('akb_cart_backup')) {
          restoreCartFromLocalStorage();
        }
      })
      .catch(err => console.error('Error checking cart on load:', err));
  });

  // Re-fetch current cart from REST and persist the backup in localStorage.
  // Centralized so every mutation path uses the same unwrapping logic.
  // Called from the add/remove/update handlers above (note: those WP_AJAX
  // responses do not include items, so we must fetch from the REST endpoint).
  function refreshCartBackup() {
    fetch('/wp-json/akibara/v1/cart/get', { credentials: 'same-origin' })
      .then(response => response.json())
      .then(resp => {
        const cart = resp && resp.data ? resp.data : null;
        if (cart) backupCartToLocalStorage(cart);
      })
      .catch(err => console.error('Error refreshing cart backup:', err));
  }
})();
