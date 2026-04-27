/**
 * Akibara Main JS
 */
(function () {
  'use strict';


  // ── Scroll lock (reference-counted) ──
  var scrollLocks = new Set();
  function lockScroll(key) { scrollLocks.add(key); document.body.style.overflow = "hidden"; }
  function unlockScroll(key) { scrollLocks.delete(key); if (!scrollLocks.size) document.body.style.overflow = ""; }

  // ── Focus trap utility (Sprint 11 a11y fix #2 audit 2026-04-26) ──
  // Implementa WCAG 2.1.2 (No Keyboard Trap inverse: SI necesita trap dentro modal),
  // 2.4.3 (Focus Order), 2.4.11 (Focus Not Obscured AA 2.2 nuevo).
  // Uso:
  //   const trap = createFocusTrap(modalEl, { onEscape: closeFn, returnFocusTo: btn });
  //   trap.activate();   // al abrir modal
  //   trap.deactivate(); // al cerrar modal
  // Comportamiento:
  //   - Tab/Shift+Tab queda dentro del modal (wrap al primero/último focusable)
  //   - Escape ejecuta onEscape callback (típicamente la close fn)
  //   - Al deactivate, focus retorna al element del returnFocusTo (default: lastActive)
  //   - initialFocus opcional: selector CSS dentro del modal para foco inicial
  //     (default: primer focusable)
  function createFocusTrap(element, options) {
    if (!element) return { activate: function(){}, deactivate: function(){} };
    var opts = options || {};
    var isActive = false;
    var lastActiveElement = null;
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function getFocusable() {
      return Array.prototype.slice.call(element.querySelectorAll(FOCUSABLE)).filter(function(el) {
        // Excluir elementos visualmente hidden (display:none / aria-hidden)
        return !el.hasAttribute('disabled') && el.offsetParent !== null;
      });
    }

    function handleKeydown(e) {
      if (!isActive) return;
      if (e.key === 'Escape' || e.keyCode === 27) {
        if (typeof opts.onEscape === 'function') {
          e.preventDefault();
          opts.onEscape();
        }
        return;
      }
      if (e.key !== 'Tab' && e.keyCode !== 9) return;
      var focusables = getFocusable();
      if (!focusables.length) {
        e.preventDefault();
        return;
      }
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }

    return {
      activate: function() {
        if (isActive) return;
        lastActiveElement = document.activeElement;
        isActive = true;
        document.addEventListener('keydown', handleKeydown);
        // Focus inicial: opts.initialFocus selector si existe, o primer focusable
        var target = opts.initialFocus
          ? element.querySelector(opts.initialFocus)
          : getFocusable()[0];
        if (target && typeof target.focus === 'function') {
          // setTimeout 0 para evitar race con animaciones CSS open transition
          setTimeout(function() { target.focus(); }, 0);
        }
      },
      deactivate: function() {
        if (!isActive) return;
        isActive = false;
        document.removeEventListener('keydown', handleKeydown);
        var returnTo = opts.returnFocusTo || lastActiveElement;
        if (returnTo && typeof returnTo.focus === 'function') {
          returnTo.focus();
        }
      }
    };
  }

  // Sprint 11 a11y fix #3 (audit 2026-04-26):
  // Exponer createFocusTrap globalmente para que el plugin akibara-search
  // (script inline en wp_footer) pueda reusarla sin duplicar código.
  // DRY: una sola fuente de verdad para focus trap en todo el sitio.
  // Guard: si por alguna razón main.js no carga, el plugin debe degradar
  // grácilmente (solo aria-expanded sync, sin trap).
  window.akibaraCreateFocusTrap = createFocusTrap;

  // ── Header scroll ──
  const header = document.getElementById('site-header');
  if (header) {
    window.addEventListener('scroll', () => {
      header.classList.toggle('scrolled', window.scrollY > 10);
    }, { passive: true });
  }

  // ── Scroll progress bar ──
  const scrollBar = document.getElementById('aki-scroll-progress');
  if (scrollBar) {
    window.addEventListener('scroll', () => {
      const scrolled = window.scrollY;
      const total = document.documentElement.scrollHeight - window.innerHeight;
      scrollBar.style.width = (total > 0 ? Math.min((scrolled / total) * 100, 100) : 0) + '%';
    }, { passive: true });
  }

  // ── Mobile drawer ──
  const hamburger = document.getElementById('menu-toggle');
  const drawer = document.getElementById('mobile-drawer');
  const overlay = document.getElementById('mobile-overlay');
  const closeBtn = document.getElementById('mobile-close');

  // Sprint 11 a11y fix #2: focus trap mobile drawer (audit 2026-04-26)
  var drawerTrap = drawer ? createFocusTrap(drawer, {
    onEscape: function() { closeDrawer(); },
    returnFocusTo: hamburger
  }) : null;

  function openDrawer() {
    drawer?.classList.add('open');
    hamburger?.classList.add('active');
    hamburger?.setAttribute('aria-expanded', 'true');
    if (overlay) {
      overlay.style.display = 'block';
      requestAnimationFrame(function() {
        requestAnimationFrame(function() {
          overlay.classList.add('open');
        });
      });
    }
    lockScroll('drawer');
    drawerTrap?.activate();
  }

  function closeDrawer() {
    drawer?.classList.remove('open');
    hamburger?.classList.remove('active');
    hamburger?.setAttribute('aria-expanded', 'false');
    overlay?.classList.remove('open');
    setTimeout(() => { if (overlay) overlay.style.display = 'none'; }, 300);
    unlockScroll('drawer');
    drawerTrap?.deactivate();
  }

  hamburger?.addEventListener('click', openDrawer);
  closeBtn?.addEventListener('click', closeDrawer);
  overlay?.addEventListener('click', closeDrawer);

  // ── Mobile accordions ──
  document.querySelectorAll('.mobile-drawer__toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = document.getElementById(btn.dataset.target);
      if (target) {
        btn.classList.toggle('open');
        target.classList.toggle('open');
      }
    });
  });

  // ── Footer accordion (mobile, accessible) ──
  // Sprint 11 a11y fix #8 (audit 2026-04-26): bug previo seteaba aria-expanded='false'
  // en desktop AUNQUE el panel estaba visible (mentía al SR). Fix: en desktop
  // aria-expanded='true' (panel siempre visible) + hidden=false. En mobile setea
  // aria-expanded='false' + hidden=true (estado colapsado real).
  // NO se setea hidden server-side: en desktop CSS display:flex no override [hidden]
  // HTML attr, generaría FOUC. JS init via DOMContentLoaded gestiona toggle correcto
  // en ambos breakpoints + window.resize listener.
  const footerToggles = Array.from(document.querySelectorAll('.footer-column__toggle'));
  if (footerToggles.length) {
    const setupFooterAccordion = function () {
      const isMobile = window.matchMedia('(max-width: 768px)').matches;

      footerToggles.forEach(function (btn) {
        const panelId = btn.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        if (!panel) return;

        if (!isMobile) {
          // Desktop: panel siempre visible vía CSS → aria-expanded debe reflejar
          // estado real (expanded). hidden=false para que SR/keyboard accedan a links.
          btn.setAttribute('aria-expanded', 'true');
          panel.style.maxHeight = '';
          panel.classList.remove('active');
          panel.hidden = false;
          return;
        }

        // Mobile: estado colapsado por default. aria-expanded='false' ya viene
        // hardcoded server-side, hidden=true sincroniza con CSS visual.
        btn.setAttribute('aria-expanded', 'false');
        panel.hidden = true;
      });
    };

    const closeFooterPanel = function (btn, panel) {
      btn.setAttribute('aria-expanded', 'false');
      btn.classList.remove('active');
      panel.style.maxHeight = '0px';
      panel.classList.remove('active');
      panel.hidden = true;
    };

    const openFooterPanel = function (btn, panel) {
      btn.setAttribute('aria-expanded', 'true');
      btn.classList.add('active');
      panel.hidden = false;
      panel.classList.add('active');
      panel.style.maxHeight = panel.scrollHeight + 'px';
    };

    footerToggles.forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!window.matchMedia('(max-width: 768px)').matches) return;

        const panelId = btn.getAttribute('aria-controls');
        const panel = panelId ? document.getElementById(panelId) : null;
        if (!panel) return;

        const isExpanded = btn.getAttribute('aria-expanded') === 'true';

        footerToggles.forEach(function (otherBtn) {
          const otherPanelId = otherBtn.getAttribute('aria-controls');
          const otherPanel = otherPanelId ? document.getElementById(otherPanelId) : null;
          if (!otherPanel) return;
          closeFooterPanel(otherBtn, otherPanel);
        });

        if (!isExpanded) {
          openFooterPanel(btn, panel);
        }
      });
    });

    setupFooterAccordion();
    window.addEventListener('resize', setupFooterAccordion, { passive: true });
  }

  // ── Cart drawer ──
  const cartToggle = document.getElementById('cart-toggle');
  const bottomNavCart = document.getElementById('bottom-nav-cart');
  const cartDrawer = document.getElementById('cart-drawer');
  const cartOverlay = document.getElementById('cart-overlay');
  const cartClose = document.getElementById('cart-close');

  // Sprint 11 a11y fix #2: focus trap cart drawer (audit 2026-04-26)
  // returnFocusTo se setea dinámicamente al abrir según el trigger
  // (cartToggle desktop o bottomNavCart mobile).
  var cartTrap = cartDrawer ? createFocusTrap(cartDrawer, {
    onEscape: function() { window.closeCart(); }
  }) : null;
  var cartTriggerEl = null;

  window.openCart = function (e) {
    cartDrawer?.classList.add('open');
    cartOverlay?.classList.add('open');
    lockScroll('cart');
    cartToggle?.setAttribute('aria-expanded', 'true');
    bottomNavCart?.setAttribute('aria-expanded', 'true');
    cartTriggerEl = (e && e.currentTarget) || document.activeElement || cartToggle;
    cartTrap?.activate();
  };

  window.closeCart = function () {
    cartDrawer?.classList.remove('open');
    cartOverlay?.classList.remove('open');
    unlockScroll('cart');
    cartToggle?.setAttribute('aria-expanded', 'false');
    bottomNavCart?.setAttribute('aria-expanded', 'false');
    cartTrap?.deactivate();
    if (cartTriggerEl && typeof cartTriggerEl.focus === 'function') {
      cartTriggerEl.focus();
    }
  };

  cartToggle?.addEventListener('click', window.openCart);
  bottomNavCart?.addEventListener('click', window.openCart);
  cartClose?.addEventListener('click', window.closeCart);
  cartOverlay?.addEventListener('click', window.closeCart);

  // ── Keyboard shortcuts ──
  // Escape global removido — cada modal/drawer maneja su Escape vía createFocusTrap.
  // Aquí solo Ctrl+K shortcut búsqueda popup (no es modal trap).
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      document.getElementById('akibara-pro-popup')?.classList.add('show');
    }
  });

  // ── Product tabs ──
  document.querySelectorAll('.product-tabs__tab').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.product-tabs__tab').forEach(b => b.classList.remove('product-tabs__tab--active'));
      btn.classList.add('product-tabs__tab--active');
      document.querySelectorAll('.product-tabs__panel').forEach(p => p.classList.remove('product-tabs__panel--active'));
      document.getElementById('tab-' + btn.dataset.tab)?.classList.add('product-tabs__panel--active');
    });
  });
  document.querySelectorAll('a[href="#tab-reviews"], a[href="#reviews"]').forEach(link => {
    link.addEventListener('click', (e) => {
      const tab = document.querySelector('.product-tabs__tab[data-tab="reviews"]');
      if (!tab) return;
      e.preventDefault();
      tab.click();
      tab.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // ── Quantity +/- ──
  document.addEventListener('click', (e) => {
    const minus = e.target.closest('.js-qty-minus');
    const plus = e.target.closest('.js-qty-plus');
    if (minus || plus) {
      const input = (minus || plus).closest('.product-quantity')?.querySelector('.js-qty-input');
      if (!input) return;
      let val = parseInt(input.value) || 1;
      if (minus) val = Math.max(parseInt(input.min) || 1, val - 1);
      if (plus) val = Math.min(parseInt(input.max) || 99, val + 1);
      input.value = val;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });

  // ── Series carousel: scroll to current volume ──
  const currentVol = document.querySelector('.series-vol--current');
  if (currentVol) {
    const grid = currentVol.closest('.series-nav__grid');
    if (grid) {
      setTimeout(() => {
        const volRect = currentVol.getBoundingClientRect();
        const gridRect = grid.getBoundingClientRect();
        grid.scrollTo({ left: grid.scrollLeft + volRect.left - gridRect.left - (grid.clientWidth / 2) + (volRect.width / 2), behavior: 'smooth' });
      }, 300);
    }
  }

  // ── Gallery thumbs ──
  const mainImg = document.getElementById('main-product-img');
  document.querySelectorAll('.product-gallery__thumb').forEach(th => {
    th.addEventListener('click', () => {
      if (mainImg && th.dataset.full) { mainImg.style.transition = "opacity 160ms ease"; mainImg.style.opacity = "0"; var newSrc = th.dataset.full; requestAnimationFrame(function() { requestAnimationFrame(function() { mainImg.src = newSrc; mainImg.style.opacity = "1"; }); }); }
      document.querySelectorAll('.product-gallery__thumb').forEach(t => t.classList.remove('product-gallery__thumb--active'));
      th.classList.add('product-gallery__thumb--active');
    });
  });

  // ── Sticky ATC (mobile) ──
  const stickyATC = document.getElementById('sticky-atc');
  const atcForm = document.querySelector('.product-add-to-cart');
  if (stickyATC && atcForm) {
    new IntersectionObserver(([e]) => stickyATC.classList.toggle('visible', !e.isIntersecting)).observe(atcForm);
  }

  // Filter drawer is managed entirely by filters-ajax.js (openSheet/closeSheet)

  // ── Toast ──
  window.akibaraToast = function(msg, typeOrDur) {
    var type = typeof typeOrDur === 'string' ? typeOrDur : '';
    var dur = typeof typeOrDur === 'number' ? typeOrDur : (type === 'info' ? 4000 : 3000);
    var t = document.querySelector('.toast');
    if (!t) { t = document.createElement('div'); t.className = 'toast'; document.body.appendChild(t); }
    if (t._toastTimer) { clearTimeout(t._toastTimer); }
    t.textContent = msg;
    t.className = 'toast show' + (type ? ' toast--' + type : '');
    t._toastTimer = setTimeout(function() { t.classList.remove('show'); t._toastTimer = null; }, dur);
  };

  // ── Scroll Reveal (IntersectionObserver) ──
  const revealEls = document.querySelectorAll('.aki-reveal');
  if (revealEls.length && 'IntersectionObserver' in window) {
    const revealObs = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1 });
    revealEls.forEach(el => revealObs.observe(el));
  } else {
    revealEls.forEach(el => el.classList.add('is-visible'));
  }

  // ── Editoriales scroll cue (mobile only, 1-time nudge al entrar viewport) ──
  // Cumple WCAG 2.2.2: animación finita de <5s, no auto-rotate, respeta reduced-motion via CSS.
  const editGrid = document.querySelector('.editoriales-grid');
  if (editGrid && window.matchMedia('(max-width: 768px)').matches && 'IntersectionObserver' in window) {
    const cueObs = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          editGrid.classList.add('editoriales-grid--nudge');
          cueObs.unobserve(entry.target);
        }
      });
    }, { threshold: 0.4 });
    cueObs.observe(editGrid);
  }

  // ── Scroll to top ──
  const scrollBtn = document.getElementById('scroll-top');
  if (scrollBtn) {
    window.addEventListener('scroll', () => {
      scrollBtn.classList.toggle('visible', window.scrollY > 600);
    }, { passive: true });
    scrollBtn.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  // ── Account dropdown: click-toggle (replaces CSS hover — avoids mainnav conflict) ──
  const accountBtn  = document.getElementById('account-btn');
  const accountPanel = document.getElementById('account-panel');
  const accountDrop  = document.getElementById('account-dropdown');

  if (accountBtn && accountPanel) {
    // Sprint 11 a11y fix #2: focus trap account dropdown (audit 2026-04-26)
    var accountTrap = createFocusTrap(accountPanel, {
      onEscape: function() {
        accountPanel.classList.remove('open');
        accountBtn.setAttribute('aria-expanded', 'false');
        accountTrap.deactivate();
        accountBtn.focus();
      },
      returnFocusTo: accountBtn
    });

    accountBtn.addEventListener('click', function (e) {
      // On mobile (<=768px), let the <a> navigate to /mi-cuenta/
      if (window.innerWidth <= 768) return;
      e.preventDefault();
      e.stopPropagation();
      const isOpen = accountPanel.classList.toggle('open');
      accountBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (isOpen) {
        accountTrap.activate();
      } else {
        accountTrap.deactivate();
      }
    });

    // Close when clicking outside
    document.addEventListener('click', function (e) {
      if (accountDrop && !accountDrop.contains(e.target) && accountPanel.classList.contains('open')) {
        accountPanel.classList.remove('open');
        accountBtn.setAttribute('aria-expanded', 'false');
        accountTrap.deactivate();
      }
    });
    // Escape removido — accountTrap maneja Escape via onEscape callback.
  }


  // ── Product image lightbox ──
  const galleryZoomBtn = document.getElementById('gallery-zoom-btn');
  const productLightbox = document.getElementById('product-lightbox');
  const lightboxImg = document.getElementById('lightbox-img');
  const lightboxClose = document.getElementById('lightbox-close');

  // Sprint 11 a11y fix #2 + #9: focus trap lightbox (audit 2026-04-26)
  // returnFocusTo se setea dinámicamente en openLightbox según el trigger
  // (galleryZoomBtn o main-product-img — ambos abren el lightbox).
  var lightboxTrap = productLightbox ? createFocusTrap(productLightbox, {
    onEscape: function() { closeLightbox(); },
    initialFocus: '#lightbox-close'
  }) : null;
  var lightboxTriggerEl = null;

  function openLightbox(e) {
    if (!productLightbox || !lightboxImg) return;
    const mainImg = document.getElementById('main-product-img');
    if (!mainImg) return;
    const src = mainImg.getAttribute('data-fullsize') || mainImg.src;
    lightboxImg.src = src;
    lightboxImg.alt = mainImg.alt || '';
    // Sprint 11 a11y fix #9: hidden attr removido al abrir (markup default hidden
    // previene focus tabular dentro mientras lightbox cerrado). Sync con classList.
    productLightbox.removeAttribute('hidden');
    productLightbox.classList.add('open');
    lockScroll('lightbox');
    // Tracker trigger element para return focus al cerrar
    lightboxTriggerEl = (e && e.currentTarget) || document.activeElement || galleryZoomBtn;
    if (lightboxTrap) {
      lightboxTrap.activate(); // focus al close button (initialFocus)
    } else {
      lightboxClose?.focus();
    }
  }

  function closeLightbox() {
    if (!productLightbox) return;
    productLightbox.classList.remove('open');
    productLightbox.setAttribute('hidden', '');
    unlockScroll('lightbox');
    if (lightboxTrap) {
      lightboxTrap.deactivate();
    }
    // Return focus al trigger (galleryZoomBtn o main-product-img)
    if (lightboxTriggerEl && typeof lightboxTriggerEl.focus === 'function') {
      lightboxTriggerEl.focus();
    }
  }

  if (galleryZoomBtn) {
    galleryZoomBtn.addEventListener('click', openLightbox);
  }

  // Also open on click of the main image
  const mainImgEl = document.getElementById('main-product-img');
  if (mainImgEl) {
    mainImgEl.style.cursor = 'zoom-in';
    mainImgEl.addEventListener('click', openLightbox);
  }

  if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
  if (productLightbox) {
    productLightbox.addEventListener('click', function(e) {
      if (e.target === productLightbox) closeLightbox();
    });
  }
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && productLightbox?.classList.contains('open')) closeLightbox();
  });


  // ── Back-in-stock notifications ──
  var _akiNotifyPid        = null;
  var _akiNotifyTriggerBtn = null;

  function akiNotifySheetOpen(pid, triggerBtn) {
    var sheet = document.getElementById('aki-notify-sheet');
    if (!sheet) return;
    _akiNotifyPid        = pid;
    _akiNotifyTriggerBtn = triggerBtn || null;

    var input  = document.getElementById('aki-notify-sheet-email');
    var ok     = document.getElementById('aki-notify-sheet-ok');
    var err    = document.getElementById('aki-notify-sheet-err');
    var submit = document.getElementById('aki-notify-sheet-submit');
    if (input)  { input.value = ''; }
    if (ok)     { ok.hidden = true; }
    if (err)    { err.hidden = true; err.textContent = ''; }
    if (submit) { submit.disabled = false; submit.style.opacity = ''; submit.textContent = 'Avisar'; }

    sheet.removeAttribute('hidden');
    sheet.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    setTimeout(function() { if (input) input.focus(); }, 80);
  }

  function akiNotifySheetClose() {
    var sheet = document.getElementById('aki-notify-sheet');
    if (!sheet) return;
    sheet.classList.remove('is-open');
    document.body.style.overflow = '';
    var t = _akiNotifyTriggerBtn;
    _akiNotifyPid        = null;
    _akiNotifyTriggerBtn = null;
    if (t && !t.disabled && !t.hidden) {
      setTimeout(function() { t.focus(); }, 280);
    }
  }

  document.addEventListener('click', function(e) {
    // Abrir sheet desde card
    var openBtn = e.target.closest('.js-notify-open');
    if (openBtn) {
      akiNotifySheetOpen(openBtn.dataset.product, openBtn);
      return;
    }
    // Cerrar: backdrop o botón X
    if (e.target.id === 'aki-notify-sheet-bd' || e.target.closest('#aki-notify-sheet-close')) {
      akiNotifySheetClose();
      return;
    }
    // Enviar desde sheet
    if (e.target.id === 'aki-notify-sheet-submit' || e.target.closest('#aki-notify-sheet-submit')) {
      var input  = document.getElementById('aki-notify-sheet-email');
      var ok     = document.getElementById('aki-notify-sheet-ok');
      var err    = document.getElementById('aki-notify-sheet-err');
      var submit = document.getElementById('aki-notify-sheet-submit');
      if (!input || !input.value || !input.checkValidity()) {
        if (input) input.reportValidity();
        return;
      }
      if (err) { err.hidden = true; err.textContent = ''; }
      akibaraNotifySubmit(_akiNotifyPid, input.value, null, ok, submit, function(success, msg) {
        if (!success) {
          if (err) { err.textContent = msg || 'No se pudo guardar. Intenta de nuevo.'; err.hidden = false; }
        } else {
          if (_akiNotifyTriggerBtn) {
            _akiNotifyTriggerBtn.textContent = '\u2713 Anotado';
            _akiNotifyTriggerBtn.disabled = true;
            _akiNotifyTriggerBtn.style.color = 'var(--aki-success)';
          }
          setTimeout(akiNotifySheetClose, 1600);
        }
      });
      return;
    }
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      var sheet = document.getElementById('aki-notify-sheet');
      if (sheet && sheet.classList.contains('is-open')) akiNotifySheetClose();
    }
  });

  // ── Experiment tracking: Home latest urgency badge (CTR) ──
  document.addEventListener('click', function(e) {
    var link = e.target.closest('.home-products--new[data-akb-exp="home_latest_urgency_badge"] .product-card__image, .home-products--new[data-akb-exp="home_latest_urgency_badge"] .product-card__title a');
    if (!link) return;

    var section = link.closest('[data-akb-exp="home_latest_urgency_badge"]');
    var card = link.closest('.product-card');
    if (!section || !card) return;

    if (typeof gtag === 'function') {
      gtag('event', 'experiment_home_latest_click', {
        experiment_id: 'home_latest_urgency_badge',
        variant_id: section.dataset.akbExpVariant || 'unknown',
        product_id: card.dataset.productId || '',
        card_context: card.dataset.cardContext || 'home-latest'
      });
    }
  });

  // Single product page notify form
  var spNotifyForm = document.getElementById('aki-notify-sp-form');
  if (spNotifyForm) {
    spNotifyForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var btn   = spNotifyForm.querySelector('[data-product]');
      var input = document.getElementById('aki-notify-sp-email');
      var ok    = document.getElementById('aki-notify-sp-ok');
      var pid   = btn ? btn.dataset.product : null;
      if (!pid || !input || !input.value) return;
      akibaraNotifySubmit(pid, input.value, spNotifyForm, ok, btn.querySelector('span') || btn);
    });
  }

  function akibaraNotifySubmit(pid, email, formEl, okEl, btnEl, onDone) {
    var cfg = window.akibaraCart || {};
    if (!cfg.ajaxUrl || !cfg.notifyNonce) {
      if (onDone) onDone(false, 'Configuración no disponible');
      return;
    }

    if (btnEl) { btnEl.disabled = true; btnEl.style.opacity = '0.5'; }

    var fd = new FormData();
    fd.append('action',     'akibara_notify_stock');
    fd.append('nonce',      cfg.notifyNonce);
    fd.append('product_id', pid);
    fd.append('email',      email);

    fetch(cfg.ajaxUrl, { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          if (formEl) formEl.hidden = true;
          if (okEl)   okEl.hidden = false;
          if (!onDone) {
            var cardBtn = document.querySelector('.js-notify-open[data-product="' + pid + '"]');
            if (cardBtn) cardBtn.hidden = true;
          }
          if (onDone) onDone(true, '');
        } else {
          var msg = (data && data.data) || 'Error al registrarse';
          if (!onDone && window.akibaraToast) window.akibaraToast(msg);
          if (onDone) onDone(false, msg);
        }
      })
      .catch(function() {
        var msg = 'Error de conexión. Intenta de nuevo.';
        if (!onDone && window.akibaraToast) window.akibaraToast(msg);
        if (onDone) onDone(false, msg);
      })
      .finally(function() {
        if (btnEl) { btnEl.disabled = false; btnEl.style.opacity = ''; }
      });
  }


  // Navigation Performance: View Transitions API (native, zero-delay)
  // LiteSpeed Instant Click handles prefetch on hover — no custom fade needed


  // ── Share buttons (native share + copy URL) ──
  if (navigator.share) {
    document.querySelectorAll('.js-share-native').forEach(btn => {
      btn.style.display = 'inline-flex';
      btn.addEventListener('click', () => {
        navigator.share({ title: btn.dataset.title || '', url: btn.dataset.url || '' }).catch(() => {});
      });
    });
  }

  // ── Country badge emoji fallback (Android < 11) ──
  (function () {
    const ua = navigator.userAgent || '';
    const match = ua.match(/Android\s+(\d+)/i);
    if (!match) return;

    const androidMajor = parseInt(match[1], 10);
    if (!Number.isFinite(androidMajor) || androidMajor >= 11) return;

    document.querySelectorAll('.product-edition-badge__flag[data-fallback]').forEach((el) => {
      const fallback = el.dataset.fallback || '';
      if (!fallback) return;
      el.textContent = fallback;
      el.classList.add('product-edition-badge__flag--fallback');
      el.setAttribute('aria-label', 'Edición ' + fallback);
    });
  })();

  document.querySelectorAll('.js-copy-url').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const url = btn.dataset.clipboardText || btn.dataset.url;
      if (!url) return;
      const origHTML = btn.innerHTML;
      navigator.clipboard.writeText(url).then(() => {
        btn.classList.add('copied');
        const label = btn.querySelector('.copy-label');
        if (label) { label.textContent = 'Copiado'; }
        else { btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>'; }
        setTimeout(() => {
          btn.classList.remove('copied');
          if (label) { label.textContent = 'Copiar'; }
          else { btn.innerHTML = origHTML; }
        }, 2000);
      }).catch(err => {
        if (window.akibaraToast) window.akibaraToast('Error al copiar');
      });
    });
  });

  // ── Serie index filters (/serie/) ──
  (function() {
    var grid = document.getElementById('si-grid');
    if (!grid) return;

    var cards = Array.from(grid.querySelectorAll('.js-si-card'));
    if (!cards.length) return;

    var initialLimit = parseInt(grid.dataset.initialLimit || '36', 10);
    if (!Number.isFinite(initialLimit) || initialLimit < 1) {
      initialLimit = 36;
    }

    var searchIn   = document.getElementById('si-search-input');
    var chips      = document.getElementById('si-chips');
    var editSelect = document.getElementById('si-editorial-filter');
    var countEl    = document.getElementById('si-results-count');
    var noResults  = document.getElementById('si-no-results');
    var loadBtn    = document.getElementById('si-load-more-btn');
    var loadWrap   = document.getElementById('si-load-more');
    var showAll    = false;
    var debounceTimer = null;

    var activeCategory = 'all';
    var activeEditorial = '';

    function applyFilters() {
      var q = (searchIn ? searchIn.value : '').toLowerCase().trim();
      var visible = 0;
      var isFiltered = q || activeCategory !== 'all' || activeEditorial;

      cards.forEach(function(card) {
        var name = card.dataset.name || '';
        var matchName = !q || name.indexOf(q) !== -1;
        var matchCat  = activeCategory === 'all' || card.dataset.category === activeCategory;
        var matchEd   = !activeEditorial || card.dataset.editorial === activeEditorial;

        if (matchName && matchCat && matchEd) {
          visible++;
          if (isFiltered || showAll || visible <= initialLimit) {
            card.classList.remove('si-hidden');
          } else {
            card.classList.add('si-hidden');
          }
        } else {
          card.classList.add('si-hidden');
        }
      });

      if (countEl) {
        countEl.textContent = visible + ' serie' + (visible !== 1 ? 's' : '');
      }
      if (noResults) {
        noResults.hidden = visible > 0;
      }
      if (loadWrap) {
        loadWrap.hidden = isFiltered || showAll || visible <= initialLimit;
      }
    }

    if (searchIn) {
      searchIn.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(applyFilters, 200);
      });
    }

    if (chips) {
      chips.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-filter]');
        if (!btn || btn.tagName === 'SELECT') return;

        chips.querySelectorAll('.si-chip[data-filter]').forEach(function(chip) {
          chip.classList.remove('si-chip--active');
        });
        btn.classList.add('si-chip--active');

        activeCategory = btn.dataset.filter;
        if (editSelect) editSelect.value = '';
        activeEditorial = '';
        applyFilters();
      });
    }

    if (editSelect) {
      editSelect.addEventListener('change', function() {
        activeEditorial = editSelect.value;
        applyFilters();
      });
    }

    if (loadBtn) {
      loadBtn.addEventListener('click', function() {
        showAll = true;
        applyFilters();
      });
    }

    applyFilters();
  })();

  // ── Serie landing: load-more + filter chips (/serie/{slug}/) ──
  (function() {
    var grid = document.querySelector('.js-serie-grid');
    if (!grid) return;

    var cards = Array.from(grid.querySelectorAll('.product-card'));
    if (!cards.length) return;

    var initialLimit = parseInt(grid.dataset.loadLimit || '24', 10);
    if (!Number.isFinite(initialLimit) || initialLimit < 1) initialLimit = 24;
    var limit = initialLimit;

    var loadWrap = document.getElementById('serie-load-more');
    var loadBtn  = document.getElementById('serie-load-more-btn');

    var filterBar    = document.querySelector('.js-serie-filters');
    var filterBtns   = filterBar ? Array.from(filterBar.querySelectorAll('[data-filter]')) : [];
    var emptyWrap    = document.querySelector('.js-serie-empty');
    var resetBtn     = document.querySelector('.js-serie-filter-reset');
    var titleEl      = document.querySelector('.js-serie-grid-title');
    var countEl      = document.querySelector('.js-serie-grid-count');
    var initialTitle = titleEl ? titleEl.textContent : '';

    var VALID = ['all', 'disponible', 'preventa', 'agotado'];
    var TITLES = {
      all: initialTitle || 'Todos los volumenes',
      disponible: 'Volúmenes disponibles',
      preventa: 'Volúmenes en preventa',
      agotado: 'Volúmenes agotados'
    };

    function readFilterFromUrl() {
      try {
        var params = new URLSearchParams(window.location.search);
        var f = (params.get('filtro') || 'all').toLowerCase();
        return VALID.indexOf(f) >= 0 ? f : 'all';
      } catch (e) { return 'all'; }
    }

    function writeFilterToUrl(filter) {
      if (!window.history || !window.history.replaceState) return;
      try {
        var url = new URL(window.location.href);
        if (filter === 'all') url.searchParams.delete('filtro');
        else url.searchParams.set('filtro', filter);
        window.history.replaceState({}, '', url.toString());
      } catch (e) { /* noop */ }
    }

    function apply() {
      var filter = state.filter;
      var visibleIndex = 0;
      var matched = 0;

      cards.forEach(function(card) {
        var status = card.dataset.status || 'disponible';
        var matchesFilter = (filter === 'all') || (status === filter);

        if (!matchesFilter) {
          card.classList.add('serie-filter-hidden');
          card.classList.remove('serie-grid-hidden');
          return;
        }

        matched++;
        card.classList.remove('serie-filter-hidden');

        // Load-more sólo aplica en vista sin filtro; con filtro mostramos todos los que matchean
        if (filter === 'all' && visibleIndex >= limit) {
          card.classList.add('serie-grid-hidden');
        } else {
          card.classList.remove('serie-grid-hidden');
        }
        visibleIndex++;
      });

      // Load-more wrapper: sólo visible en vista "all" y si hay más que el limite
      if (loadWrap) {
        loadWrap.hidden = (filter !== 'all') || (cards.length <= limit);
      }

      // Empty state
      if (emptyWrap) emptyWrap.hidden = matched !== 0;

      // Title + contador + aria
      if (titleEl) titleEl.textContent = TITLES[filter] || TITLES.all;
      if (countEl) {
        var singular = countEl.dataset.labelSingular || 'tomo';
        var plural   = countEl.dataset.labelPlural   || 'tomos';
        countEl.textContent = matched + ' ' + (matched === 1 ? singular : plural);
      }

      filterBtns.forEach(function(btn) {
        var active = btn.dataset.filter === filter;
        btn.classList.toggle('serie-stats__item--active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }

    var state = { filter: readFilterFromUrl() };

    function setFilter(next, opts) {
      opts = opts || {};
      if (VALID.indexOf(next) < 0) next = 'all';
      if (next === state.filter) next = 'all'; // toggle: click en chip activo → reset
      state.filter = next;
      writeFilterToUrl(next);
      apply();
      if (opts.scroll && next !== 'all' && grid.getBoundingClientRect().top < 0) {
        grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    filterBtns.forEach(function(btn) {
      btn.addEventListener('click', function() {
        setFilter(btn.dataset.filter || 'all', { scroll: true });
      });
    });

    if (resetBtn) {
      resetBtn.addEventListener('click', function() {
        state.filter = 'all';
        writeFilterToUrl('all');
        apply();
      });
    }

    if (loadBtn) {
      loadBtn.addEventListener('click', function() {
        limit = cards.length;
        apply();
      });
    }

    apply();
  })();

  // ── Swipe-to-close drawers (mobile) ──
  (function() {
    var swipeTargets = [
      { el: document.getElementById('cart-drawer'),   fn: window.closeCart },
      { el: document.getElementById('mobile-drawer'), fn: function() {
          var d = document.getElementById('mobile-drawer');
          var h = document.getElementById('menu-toggle');
          var o = document.getElementById('mobile-overlay');
          if (d) d.classList.remove('open');
          if (h) h.classList.remove('active');
          if (o) { o.classList.remove('open'); setTimeout(function(){ o.style.display='none'; }, 300); }
          unlockScroll('drawer');
        }
      }
    ];

    swipeTargets.forEach(function(target) {
      var el = target.el;
      if (!el) return;
      var startX = 0;
      var startY = 0;

      el.addEventListener('touchstart', function(e) {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
      }, { passive: true });

      el.addEventListener('touchend', function(e) {
        var dx = e.changedTouches[0].clientX - startX;
        var dy = e.changedTouches[0].clientY - startY;
        // Swipe derecha > 80px y más horizontal que vertical
        if (dx > 80 && Math.abs(dx) > Math.abs(dy) * 1.5) {
          if (target.fn) target.fn();
        }
      }, { passive: true });
    });
  })();

  // ── Bottom nav tap feedback ──
  document.querySelectorAll('.bottom-nav__item').forEach(function(item) {
    item.addEventListener('touchstart', function() {
      this.style.transition = 'transform 100ms ease';
      this.style.transform = 'scale(0.88)';
    }, { passive: true });
    item.addEventListener('touchend', function() {
      var self = this;
      self.style.transition = 'transform 200ms cubic-bezier(0.34,1.56,0.64,1)';
      self.style.transform = '';
    }, { passive: true });
    item.addEventListener('touchcancel', function() {
      this.style.transform = '';
    }, { passive: true });
  });


})();