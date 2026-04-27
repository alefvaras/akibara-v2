/**
 * Akibara Wishlist — Enhanced localStorage-based favorites
 * Features: toggle, counter badges, wishlist page, add-to-cart, cross-tab sync
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'akibara_wishlist';

  /* ── Data Layer ── */

  function getWishlist() {
    try {
      var raw = JSON.parse(localStorage.getItem(STORAGE_KEY));
      if (!raw) return [];
      if (Array.isArray(raw) && raw.length > 0 && typeof raw[0] !== 'object') {
        var migrated = raw.map(function (id) { return { id: String(id), added: Date.now() }; });
        localStorage.setItem(STORAGE_KEY, JSON.stringify(migrated));
        return migrated;
      }
      return Array.isArray(raw) ? raw : [];
    } catch (e) { return []; }
  }

  function saveWishlist(list) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(list)); } catch(e) {}
    updateCounters();
  }

  function getIds() {
    return getWishlist().map(function (item) { return String(item.id); });
  }

  function toggleItem(productId) {
    var list = getWishlist();
    var id = String(productId);
    var idx = -1;
    for (var i = 0; i < list.length; i++) {
      if (String(list[i].id) === id) { idx = i; break; }
    }
    if (idx > -1) {
      list.splice(idx, 1);
      saveWishlist(list);
      return false;
    }
    list.push({ id: id, added: Date.now() });
    saveWishlist(list);
    return true;
  }

  function removeItem(productId) {
    var list = getWishlist().filter(function (item) { return String(item.id) !== String(productId); });
    saveWishlist(list);
  }

  /* ── Counter Badges ── */

  function updateCounters() {
    var count = getWishlist().length;
    document.querySelectorAll('.js-wishlist-count').forEach(function (el) {
      el.textContent = count;
      el.style.display = count > 0 ? '' : 'none';
      el.classList.remove('aki-pulse');
      void el.offsetWidth;
      el.classList.add('aki-pulse');
    });
  }

  /* ── Sync Button States ── */

  function syncButtons() {
    var ids = getIds();
    document.querySelectorAll('.js-wishlist').forEach(function (btn) {
      btn.classList.toggle('active', ids.indexOf(String(btn.dataset.productId)) > -1);
    });
    updateCounters();
  }

  /* ── Init ── */
  syncButtons();

  /* ── Toggle Click (delegated) ── */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-wishlist');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();

    var productId = btn.dataset.productId;
    if (!productId) return;

    var added = toggleItem(productId);

    document.querySelectorAll('.js-wishlist[data-product-id="' + productId + '"]').forEach(function (b) {
      b.classList.toggle('active', added);
    });

    btn.classList.remove('aki-heart-pop');
    void btn.offsetWidth;
    btn.classList.add('aki-heart-pop');
    setTimeout(function () { btn.classList.remove('aki-heart-pop'); }, 600);

    if (window.akibaraToast) {
      window.akibaraToast(added ? 'Guardado en favoritos' : 'Eliminado de favoritos');
    }
  });

  /* ── Wishlist Page ── */

  var container = document.getElementById('wishlist-products');
  var emptyEl = document.getElementById('wishlist-empty');
  var loadingEl = document.getElementById('wishlist-loading');
  var countEl = document.getElementById('wishlist-page-count');
  var actionsEl = document.getElementById('wishlist-actions');

  if (container) initWishlistPage();

  function initWishlistPage() {
    var items = getWishlist();
    var ids = items.map(function (i) { return String(i.id); });

    if (!ids.length) { showEmpty(); return; }

    updatePageCount(ids.length);
    fetchProducts(ids, items);
  }

  function showEmpty() {
    if (loadingEl) loadingEl.style.display = 'none';
    if (emptyEl) emptyEl.style.display = '';
    if (actionsEl) actionsEl.style.display = 'none';
    if (countEl) countEl.textContent = '0 productos';
    if (container) container.innerHTML = '';
  }

  function updatePageCount(n) {
    if (countEl) countEl.textContent = n + (n === 1 ? ' producto' : ' productos');
  }

  function fetchProducts(ids, items) {
    if (typeof akibaraWishlist === 'undefined') return;

    var body = new URLSearchParams({
      action: 'akibara_get_wishlist_products',
      nonce: akibaraWishlist.nonce,
      product_ids: ids.join(',')
    });

    fetch(akibaraWishlist.ajaxUrl, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (loadingEl) loadingEl.style.display = 'none';
        if (!data.success || !data.data.products.length) { showEmpty(); return; }
        if (actionsEl) actionsEl.style.display = '';
        container.innerHTML = data.data.products.map(function (p) { return cardHTML(p, items); }).join('');
      })
      .catch(function () {
        if (loadingEl) loadingEl.style.display = 'none';
        showEmpty();
      });
  }

  function cardHTML(p, items) {
    var item = null;
    for (var i = 0; i < items.length; i++) {
      if (String(items[i].id) === String(p.id)) { item = items[i]; break; }
    }
    var days = item && item.added ? Math.floor((Date.now() - item.added) / 86400000) : null;
    var timeText = '';
    if (days === 0) timeText = 'Agregado hoy';
    else if (days === 1) timeText = 'Hace 1 dia';
    else if (days !== null && days > 1) timeText = 'Hace ' + days + ' dias';

    var safeUrl = escHtml(p.url);
    var safeImg = escHtml(p.image);
    var html = '<div class="wishlist-card" data-product-id="' + p.id + '">';
    html += '<a href="' + safeUrl + '" class="wishlist-card__image">';
    html += '<img src="' + safeImg + '" alt="' + escHtml(p.title) + '" loading="lazy">';
    if (p.on_sale && p.discount) html += '<span class="badge badge--sale"><span>' + escHtml(p.discount) + '</span></span>';
    if (!p.in_stock) html += '<span class="badge badge--out"><span>Agotado</span></span>';
    html += '</a>';
    html += '<div class="wishlist-card__body">';
    if (p.category) html += '<span class="wishlist-card__category">' + escHtml(p.category) + '</span>';
    html += '<h3 class="wishlist-card__title"><a href="' + safeUrl + '">' + escHtml(p.title) + '</a></h3>';
    html += '<div class="wishlist-card__price">' + p.price_html + '</div>';
    if (timeText) html += '<span class="wishlist-card__added">' + timeText + '</span>';
    html += '<div class="wishlist-card__actions">';
    if (p.in_stock) {
      html += '<button class="btn btn--primary btn--sm js-wishlist-atc" data-product-id="' + p.id + '"><span>Agregar al carrito</span></button>';
    } else {
      html += '<span class="wishlist-card__out">Sin stock</span>';
    }
    html += '<button class="wishlist-card__remove js-wishlist-remove" data-product-id="' + p.id + '" aria-label="Eliminar">';
    html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    html += '</button></div></div></div>';
    return html;
  }

  function escHtml(str) {
    var el = document.createElement('span');
    el.textContent = str || '';
    return el.innerHTML;
  }

  /* ── Remove from wishlist page ── */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-wishlist-remove');
    if (!btn) return;
    e.preventDefault();

    var id = btn.dataset.productId;
    var card = btn.closest('.wishlist-card');

    if (card) {
      card.style.transition = 'opacity 250ms ease, transform 250ms ease';
      card.style.opacity = '0';
      card.style.transform = 'scale(0.95)';
      setTimeout(function () {
        card.remove();
        removeItem(id);
        var remaining = container ? container.children.length : 0;
        updatePageCount(remaining);
        if (!remaining) showEmpty();
      }, 300);
    }

    if (window.akibaraToast) window.akibaraToast('Eliminado de favoritos');
  });

  /* ── Add to cart from wishlist ── */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-wishlist-atc');
    if (!btn) return;
    e.preventDefault();
    if (typeof akibaraWishlist === 'undefined') return;

    var id = btn.dataset.productId;
    btn.disabled = true;
    btn.innerHTML = '<span>Agregando...</span>';

    var body = new URLSearchParams({
      action: 'akibara_add_to_cart',
      nonce: akibaraWishlist.cartNonce,
      product_id: id,
      quantity: 1
    });

    fetch(akibaraWishlist.ajaxUrl, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          btn.innerHTML = '<span>Agregado</span>';
          btn.classList.add('btn--added');
          updateCartCounts(data.data.count);
          if (window.akibaraToast) window.akibaraToast(data.data.message);
          setTimeout(function () {
            btn.innerHTML = '<span>Agregar al carrito</span>';
            btn.classList.remove('btn--added');
            btn.disabled = false;
          }, 2000);
        } else {
          btn.innerHTML = '<span>Error</span>';
          setTimeout(function () {
            btn.innerHTML = '<span>Agregar al carrito</span>';
            btn.disabled = false;
          }, 2000);
        }
      })
      .catch(function () {
        btn.innerHTML = '<span>Agregar al carrito</span>';
        btn.disabled = false;
      });
  });

  /* ── Add ALL to cart ── */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('#wishlist-add-all');
    if (!btn) return;
    e.preventDefault();
    if (typeof akibaraWishlist === 'undefined') return;

    var inStockIds = [];
    if (container) {
      container.querySelectorAll('.js-wishlist-atc').forEach(function (b) {
        inStockIds.push(b.dataset.productId);
      });
    }

    if (!inStockIds.length) {
      if (window.akibaraToast) window.akibaraToast('No hay productos disponibles');
      return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span>Agregando...</span>';

    var body = new URLSearchParams({
      action: 'akibara_add_wishlist_to_cart',
      nonce: akibaraWishlist.nonce,
      product_ids: inStockIds.join(',')
    });

    fetch(akibaraWishlist.ajaxUrl, { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          btn.innerHTML = '<span>' + data.data.message + '</span>';
          btn.classList.add('btn--added');
          updateCartCounts(data.data.count);
          if (window.akibaraToast) window.akibaraToast(data.data.message);
          setTimeout(function () {
            btn.innerHTML = '<span>Agregar todo al carrito</span>';
            btn.classList.remove('btn--added');
            btn.disabled = false;
          }, 3000);
        } else {
          btn.innerHTML = '<span>Agregar todo al carrito</span>';
          btn.disabled = false;
          if (window.akibaraToast) window.akibaraToast(data.data?.message || 'Error al agregar al carrito');
        }
      })
      .catch(function () {
        btn.innerHTML = '<span>Agregar todo al carrito</span>';
        btn.disabled = false;
      });
  });

  /* ── Clear all ── */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('#wishlist-clear');
    if (!btn) return;
    e.preventDefault();

    if (!confirm('Seguro que quieres vaciar tus favoritos?')) return;

    saveWishlist([]);
    if (container) {
      container.querySelectorAll('.wishlist-card').forEach(function (card) {
        card.style.transition = 'opacity 250ms ease, transform 250ms ease';
        card.style.opacity = '0';
        card.style.transform = 'scale(0.95)';
      });
      setTimeout(showEmpty, 300);
    }
    syncButtons();
    if (window.akibaraToast) window.akibaraToast('Favoritos vaciados');
  });

  /* ── Helpers ── */

  function updateCartCounts(count) {
    var el1 = document.getElementById('cart-count');
    var el2 = document.getElementById('bottom-nav-count');
    if (el1) el1.textContent = count;
    if (el2) el2.textContent = count;
  }

  /* ── Cross-tab sync ── */
  window.addEventListener('storage', function (e) {
    if (e.key === STORAGE_KEY) {
      syncButtons();
      if (container) initWishlistPage();
    }
  });
})();
