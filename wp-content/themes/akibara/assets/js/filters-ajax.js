/**
 * Akibara AJAX Filters v3.1
 * - Intercepts filter clicks, fetches via AJAX, updates grid + URL
 * - pushState for back/forward navigation
 * - Handles sort, pagination, all filter types
 * - Skeleton loading cards instead of overlay
 */
(function() {
  'use strict';

  var shopContent = document.querySelector('.shop-content');
  var sidebar = document.getElementById('shop-sidebar');
  if (!shopContent) return;

  var ajaxUrl = (typeof akibaraFilters !== 'undefined') ? akibaraFilters.ajaxUrl : '/wp-admin/admin-ajax.php';
  var currentRequest = null;
  var isFiltering = false;
  // Coalesce clicks dentro de una ventana corta: si el usuario toca 3 checkboxes
  // rápido, dispara 1 request con todos acumulados en lugar de 3 rondas.
  var coalesceTimer = null;
  var pendingParams = null;
  var pendingPush = true;
  // Evita flicker del skeleton cuando la respuesta llega <200ms (cache hit).
  var skeletonShownAt = 0;
  var SKELETON_MIN_MS = 200;
  var COALESCE_MS = 120;

  // ══════════════════════════════════════════════════
  // SKELETON LOADING
  // ══════════════════════════════════════════════════

  function buildSkeletonCard() {
    return '<div class="skeleton-card">' +
      '<div class="skeleton-card__image"></div>' +
      '<div class="skeleton-card__body">' +
        '<div class="skeleton-card__line skeleton-card__line--short"></div>' +
        '<div class="skeleton-card__line skeleton-card__line--medium"></div>' +
        '<div class="skeleton-card__line"></div>' +
        '<div class="skeleton-card__line skeleton-card__line--price"></div>' +
        '<div class="skeleton-card__line skeleton-card__line--btn"></div>' +
      '</div>' +
    '</div>';
  }

  function showSkeletons() {
    var oldProducts = shopContent.querySelector('.product-grid');
    var oldEmpty = shopContent.querySelector('.shop-empty');
    var oldPagination = shopContent.querySelector('.aki-pagination');
    if (oldProducts) oldProducts.remove();
    if (oldEmpty) oldEmpty.remove();
    if (oldPagination) oldPagination.remove();
    shopContent.querySelectorAll('.product-grid').forEach(function(el) { el.remove(); });

    var skeletonHtml = '<div class="product-grid product-grid--large products--skeleton" role="status" aria-live="polite" aria-label="Cargando productos">';
    for (var i = 0; i < 8; i++) {
      skeletonHtml += buildSkeletonCard();
    }
    skeletonHtml += '</div>';

    var activeFilters = shopContent.querySelector('.akb-active-filters');
    var controls = shopContent.querySelector('.shop-controls');
    var insertAfter = activeFilters || controls;

    if (insertAfter) {
      insertAfter.insertAdjacentHTML('afterend', skeletonHtml);
    } else {
      shopContent.insertAdjacentHTML('beforeend', skeletonHtml);
    }
    skeletonShownAt = Date.now();
  }

  function removeSkeletons() {
    var skeletonGrid = shopContent.querySelector('.products--skeleton');
    if (skeletonGrid) skeletonGrid.remove();
  }

  // Espera lo que falte del mínimo antes de ejecutar el callback. Evita que
  // una respuesta cacheada (~20ms) haga aparecer/desaparecer el skeleton en un
  // flash que se percibe como glitch.
  function deferUntilMinSkeleton(fn) {
    var elapsed = Date.now() - skeletonShownAt;
    var wait = Math.max(0, SKELETON_MIN_MS - elapsed);
    if (wait === 0) fn();
    else setTimeout(fn, wait);
  }

  // ══════════════════════════════════════════════════
  // URL PARAM HELPERS
  // ══════════════════════════════════════════════════

  function getParams() {
    return new URLSearchParams(window.location.search);
  }

  // Extract taxonomy context from a URL path.
  // /categoria-producto/manga/seinen/ → product_cat=seinen (deepest segment wins)
  // /marca/panini-argentina/          → product_brand=panini-argentina
  // Trailing /page/N/ is ignored.
  function getTaxonomyContext(pathname) {
    var result = {};
    var clean = pathname.replace(/\/page\/\d+\/?$/, '/');
    var catMatch = clean.match(/\/categoria-producto\/(.+?)\/?$/);
    if (catMatch) {
      var segments = catMatch[1].split('/').filter(Boolean);
      if (segments.length) result.product_cat = segments[segments.length - 1];
    }
    var brandMatch = clean.match(/\/marca\/([^\/]+)\/?$/);
    if (brandMatch) result.product_brand = brandMatch[1];
    return result;
  }

  // Extract paged number from either ?paged=N query or /page/N/ path segment.
  function extractPagedFromUrl(url) {
    var fromQuery = url.searchParams.get('paged');
    if (fromQuery) return fromQuery;
    var m = url.pathname.match(/\/page\/(\d+)\/?$/);
    return m ? m[1] : null;
  }

  function setParam(key, value) {
    var params = getParams();
    if (value === null || value === '' || value === undefined) {
      params.delete(key);
    } else {
      params.set(key, value);
    }
    params.delete('paged');
    return params;
  }

  function toggleInList(key, slug) {
    var params = getParams();
    var current = params.get(key);
    var list = current ? current.split(',').filter(Boolean) : [];
    var idx = list.indexOf(slug);
    if (idx >= 0) {
      list.splice(idx, 1);
    } else {
      list.push(slug);
    }
    if (list.length > 0) {
      params.set(key, list.join(','));
    } else {
      params.delete(key);
    }
    params.delete('paged');
    return params;
  }

  // ══════════════════════════════════════════════════
  // AJAX FETCH
  // ══════════════════════════════════════════════════

  // API pública: encola la petición con coalescing. Si llegan varios clicks en
  // ventana de COALESCE_MS, sólo se dispara el último set de params.
  function fetchProducts(params, pushToHistory) {
    pendingParams = params;
    pendingPush = pushToHistory !== false;
    if (coalesceTimer) {
      clearTimeout(coalesceTimer);
    } else {
      // Primera vez que entramos al ciclo: mostrar skeleton de inmediato para
      // dar feedback, aunque el request real salga en COALESCE_MS.
      showSkeletons();
    }
    coalesceTimer = setTimeout(function() {
      coalesceTimer = null;
      var p = pendingParams;
      var pp = pendingPush;
      pendingParams = null;
      doFetchProducts(p, pp);
    }, COALESCE_MS);
  }

  function doFetchProducts(params, pushToHistory) {
    if (currentRequest) { currentRequest.abort(); currentRequest = null; isFiltering = false; }
    if (isFiltering) return;
    isFiltering = true;

    // Skeleton ya puesto por fetchProducts(); si alguien llama doFetch directo,
    // asegurar que esté visible.
    if (!shopContent.querySelector('.products--skeleton')) showSkeletons();

    var fetchParams = new URLSearchParams(params);
    fetchParams.set('action', 'akibara_filter');

    var ctx = getTaxonomyContext(window.location.pathname);
    if (ctx.product_cat) fetchParams.set('product_cat', ctx.product_cat);
    if (ctx.product_brand) fetchParams.set('product_brand', ctx.product_brand);

    var fetchUrl = ajaxUrl + '?' + fetchParams.toString();

    currentRequest = new XMLHttpRequest();
    currentRequest.open('GET', fetchUrl, true);
    currentRequest.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    currentRequest.onload = function() {
      var status = currentRequest.status;
      var body = currentRequest.responseText;
      deferUntilMinSkeleton(function() {
        isFiltering = false;
        removeSkeletons();

        if (status !== 200) return;

        try {
          var data = JSON.parse(body);
          if (!data.success) return;

        var controls = shopContent.querySelector('.shop-controls');
        var activeFilters = shopContent.querySelector('.akb-active-filters');

        // Remove any remaining old content
        var oldProducts = shopContent.querySelector('.product-grid');
        var oldPagination = shopContent.querySelector('.aki-pagination');
        var oldEmpty = shopContent.querySelector('.shop-empty');
        if (oldProducts) oldProducts.remove();
        if (oldPagination) oldPagination.remove();
        if (oldEmpty) oldEmpty.remove();
        shopContent.querySelectorAll('.product-grid').forEach(function(el) { el.remove(); });

        // Insert new HTML
        var temp = document.createElement('div');
        temp.innerHTML = data.data.html;
        var insertPoint = activeFilters || controls;
        while (temp.firstChild) {
          if (insertPoint && insertPoint.nextSibling) {
            shopContent.insertBefore(temp.firstChild, insertPoint.nextSibling);
            insertPoint = insertPoint.nextSibling;
          } else {
            shopContent.appendChild(temp.firstChild);
          }
        }

        updateResultCount(data.data.total);
        updateActiveChips(params);
        updateFilterBadge(params);
        updateStickyClearBadge(params);
        updateSidebarState(params);
        if (data.data.counts) updateFilterCounts(data.data.counts);

        if (pushToHistory !== false) {
          var newUrl = window.location.pathname;
          var paramStr = params.toString();
          if (paramStr) newUrl += '?' + paramStr;
          history.pushState({ filters: paramStr }, '', newUrl);
        }

        var rect = shopContent.getBoundingClientRect();
        if (rect.top < 0) {
          window.scrollTo({ top: window.scrollY + rect.top - 100, behavior: 'smooth' });
        }

        if (typeof akibaraInitCards === 'function') akibaraInitCards();

        } catch(e) {
          console.error('Filter parse error:', e);
        }
      });
    };

    currentRequest.onabort = function() {
      isFiltering = false;
      removeSkeletons();
    };
    currentRequest.onerror = function() {
      deferUntilMinSkeleton(function() {
        isFiltering = false;
        removeSkeletons();
        // Feedback visual al usuario (R19): no dejar al usuario con skeleton infinito
        // si el request falló.
        var msg = document.createElement('div');
        msg.className = 'shop-empty';
        msg.setAttribute('role', 'alert');
        msg.innerHTML = '<h3>No pudimos actualizar los productos</h3><p class="shop-empty__desc">Revisa tu conexión y vuelve a intentar.</p>';
        var controls = shopContent.querySelector('.shop-controls');
        if (controls) controls.insertAdjacentElement('afterend', msg);
      });
    };

    currentRequest.send();
  }

  // ══════════════════════════════════════════════════
  // UPDATE UI HELPERS
  // ══════════════════════════════════════════════════

  function updateResultCount(total) {
    var countEl = shopContent.querySelector('.woocommerce-result-count');
    if (countEl) {
      countEl.textContent = total + ' producto' + (total !== 1 ? 's' : '');
    }
    // UX-DRAWER-REDESIGN A1 — sync sticky apply count
    var stickyCount = document.querySelector('.drawer-cta-sticky__apply-count');
    if (stickyCount) {
      stickyCount.textContent = formatThousands(total);
    }
  }

  function formatThousands(n) {
    try { return Number(n).toLocaleString('es-CL'); }
    catch (e) { return String(n); }
  }

  // UX-DRAWER-REDESIGN A1 — actualiza badge del clear con número de filtros activos
  function updateStickyClearBadge(params) {
    var clearBtn = document.getElementById('drawer-clear-filters');
    if (!clearBtn) return;
    var skipKeys = ['paged', 'orderby', 's', 'post_type', 'product_cat', 'product_brand'];
    var count = 0;
    var seenMaxPrice = false;
    params.forEach(function(value, key) {
      if (skipKeys.indexOf(key) >= 0) return;
      if (key === 'max_price') { seenMaxPrice = true; return; }
      if (key.startsWith('filter_')) {
        count += value.split(',').filter(Boolean).length;
      } else {
        count++;
      }
    });
    var badge = clearBtn.querySelector('.drawer-cta-sticky__clear-count');
    if (count > 0) {
      if (badge) badge.textContent = '(' + count + ')';
      clearBtn.hidden = false;
    } else {
      if (badge) badge.textContent = '';
      clearBtn.hidden = true;
    }
  }

  function updateFilterBadge(params) {
    var filterToggle = document.getElementById('filter-toggle');
    if (!filterToggle) return;
    var skipKeys = ['paged', 'orderby', 's', 'post_type', 'product_cat', 'product_brand'];
    var count = 0;
    var seenMaxPrice = false;
    params.forEach(function(value, key) {
      if (skipKeys.indexOf(key) >= 0) return;
      if (key === 'max_price') { seenMaxPrice = true; return; }
      if (key.startsWith('filter_')) {
        count += value.split(',').filter(Boolean).length;
      } else {
        count++;
      }
    });
    var badge = filterToggle.querySelector('.akb-filter-badge');
    if (count > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'akb-filter-badge';
        filterToggle.appendChild(badge);
      }
      badge.textContent = count;
    } else if (badge) {
      badge.remove();
    }
  }

  function updateActiveChips(params) {
    var existing = shopContent.querySelector('.akb-active-filters');
    if (existing) existing.remove();

    var chips = [];
    var taxLabels = {
      'filter_product_brand': 'product_brand',
      'filter_pa_genero': 'pa_genero',
      'filter_pa_serie': 'pa_serie'
    };

    params.forEach(function(value, key) {
      if (key === 'paged' || key === 'orderby') return;
      if (key === 'stock') { chips.push({label: 'Solo disponibles', param: key}); return; }
      if (key === 'preorder') { chips.push({label: 'Preventas', param: key}); return; }
      if (key === 'nuevo') { chips.push({label: 'Novedades', param: key}); return; }
      if (key === 'sale') { chips.push({label: 'En oferta', param: key}); return; }
      if (key === 'min_price') {
        var min = parseInt(value);
        var max = params.get('max_price');
        var label = max ? '$' + min.toLocaleString('es-CL') + ' - $' + parseInt(max).toLocaleString('es-CL') : 'Mas de $' + min.toLocaleString('es-CL');
        chips.push({label: label, param: key});
        return;
      }
      if (key === 'max_price') return;
      if (key.startsWith('filter_')) {
        value.split(',').forEach(function(slug) {
          chips.push({label: slug.replace(/-/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); }), param: key, slug: slug});
        });
      }
    });

    if (chips.length === 0) return;

    var html = '<div class="akb-active-filters">';
    chips.forEach(function(chip) {
      html += '<button type="button" class="akb-filter-chip" data-remove-param="' + chip.param + '"';
      if (chip.slug) html += ' data-remove-slug="' + chip.slug + '"';
      html += '>' + chip.label + ' <span class="akb-filter-chip__x">&#10005;</span></button>';
    });
    html += '<button type="button" class="akb-filter-clear" data-filter-clear>Limpiar filtros</button>';
    html += '</div>';

    var controls = shopContent.querySelector('.shop-controls');
    if (controls) {
      controls.insertAdjacentHTML('afterend', html);
    }
  }

  function updateSidebarState(params) {
    sidebar?.querySelectorAll('.filter-check').forEach(function(el) {
      var param = el.dataset.param;
      var slug = el.dataset.slug;
      if (!param || !slug) return;
      var current = params.get(param);
      var list = current ? current.split(',') : [];
      var isActive = list.indexOf(slug) >= 0;
      el.classList.toggle('filter-check--active', isActive);
      var cb = el.querySelector('.filter-checkbox');
      if (cb) {
        if (isActive) cb.setAttribute('checked', '');
        else cb.removeAttribute('checked');
      }
    });

    sidebar?.querySelectorAll('.filter-toggle-link').forEach(function(el) {
      var param = el.dataset.param;
      var isActive = params.has(param);
      el.classList.toggle('filter-toggle-link--active', isActive);
      var sw = el.querySelector('.filter-toggle-switch');
      if (sw) sw.classList.toggle('filter-toggle-switch--on', isActive);
    });

    sidebar?.querySelectorAll('.sidebar-accordion__badge').forEach(function(b) { b.remove(); });
    var taxParams = ['filter_product_brand', 'filter_pa_genero', 'filter_pa_serie'];
    taxParams.forEach(function(param) {
      var val = params.get(param);
      if (!val) return;
      var count = val.split(',').length;
      var checklist = sidebar?.querySelector('[data-taxonomy="' + param + '"]');
      if (!checklist) return;
      var toggle = checklist.closest('.sidebar-accordion')?.querySelector('.sidebar-accordion__toggle');
      if (toggle && !toggle.querySelector('.sidebar-accordion__badge')) {
        var badge = document.createElement('span');
        badge.className = 'sidebar-accordion__badge';
        badge.textContent = count;
        toggle.querySelector('.accordion-chevron')?.before(badge);
      }
    });

    sidebar?.querySelectorAll(".filter-group[data-group]").forEach(function(group) {
      var header = group.querySelector(".filter-group__header");
      if (!header) return;
      var slugsAttr = header.dataset.groupSlugs;
      if (!slugsAttr) return;
      var groupSlugs = slugsAttr.split(",");
      var paramKey = header.dataset.param;
      if (!paramKey) return;
      var currentVal = params.get(paramKey);
      var activeList = currentVal ? currentVal.split(",") : [];
      var activeInGroup = groupSlugs.filter(function(s) { return activeList.indexOf(s) >= 0; });
      var state = "none";
      if (activeInGroup.length === groupSlugs.length) state = "all";
      else if (activeInGroup.length > 0) state = "partial";
      group.dataset.groupState = state;
      var cb = group.querySelector(".filter-group__checkbox");
      if (cb) {
        cb.className = "filter-group__checkbox" + (state === "all" ? " checked" : state === "partial" ? " partial" : "");
      }
      if (header) {
        var newSlugs;
        if (state === "all") {
          newSlugs = activeList.filter(function(s) { return groupSlugs.indexOf(s) < 0; });
        } else {
          newSlugs = activeList.slice();
          groupSlugs.forEach(function(s) { if (newSlugs.indexOf(s) < 0) newSlugs.push(s); });
        }
        var newParams = new URLSearchParams(params);
        if (newSlugs.length > 0) newParams.set(paramKey, newSlugs.join(","));
        else newParams.delete(paramKey);
        var newUrl = window.location.pathname;
        var pStr = newParams.toString();
        if (pStr) newUrl += "?" + pStr;
        header.setAttribute("href", newUrl);
      }
    });
  }

  // ══════════════════════════════════════════════════
  // DYNAMIC FACETED COUNTS
  // ══════════════════════════════════════════════════

  function updateFilterCounts(counts) {
    if (!sidebar || !counts) return;

    // counts = { 'filter_product_brand': {'ivrea-argentina': 500, ...}, 'filter_pa_genero': {'accion': 300, ...} }
    Object.keys(counts).forEach(function(param) {
      var sectionCounts = counts[param];
      var checklist = sidebar.querySelector('[data-taxonomy="' + param + '"]');
      if (!checklist) return;

      checklist.querySelectorAll('.filter-check[data-slug]').forEach(function(el) {
        var slug = el.dataset.slug;
        var countEl = el.querySelector('.filter-check__count');
        if (!countEl) return;

        var newCount = sectionCounts[slug] !== undefined ? sectionCounts[slug] : 0;
        var oldCount = parseInt(countEl.textContent) || 0;

        // Update count text
        countEl.textContent = newCount;

        // Animate if changed
        if (newCount !== oldCount) {
          countEl.classList.add('filter-check__count--updated');
          setTimeout(function() { countEl.classList.remove('filter-check__count--updated'); }, 400);
        }

        // Dim zero-count items (but don't hide — user should see what's unavailable)
        var li = el.closest('li');
        if (li) {
          li.classList.toggle('filter-check--empty', newCount === 0);
        }
      });

      // Also update group header counts if grouped
      var accordion = checklist.closest('.sidebar-accordion');
      if (!accordion) return;
      accordion.querySelectorAll('.filter-group[data-group]').forEach(function(group) {
        var countEl = group.querySelector('.filter-group__count');
        if (!countEl) return;
        var groupSlugs = (group.querySelector('.filter-group__header')?.dataset.groupSlugs || '').split(',');
        var groupTotal = 0;
        groupSlugs.forEach(function(s) {
          groupTotal += sectionCounts[s] || 0;
        });
        countEl.textContent = groupTotal;
      });
    });
  }

  // ══════════════════════════════════════════════════
  // EVENT DELEGATION
  // ══════════════════════════════════════════════════

  document.addEventListener('click', function(e) {
    var groupHeader = e.target.closest(".filter-group__header[data-group-slugs]");
    if (groupHeader) {
      e.preventDefault();
      var groupSlugs = groupHeader.dataset.groupSlugs.split(",");
      var paramKey = groupHeader.dataset.param;
      var params = getParams();
      var current = params.get(paramKey);
      var activeList = current ? current.split(",").filter(Boolean) : [];
      var activeInGroup = groupSlugs.filter(function(s) { return activeList.indexOf(s) >= 0; });
      var allActive = activeInGroup.length === groupSlugs.length;
      var newList;
      if (allActive) {
        newList = activeList.filter(function(s) { return groupSlugs.indexOf(s) < 0; });
      } else {
        newList = activeList.slice();
        groupSlugs.forEach(function(s) { if (newList.indexOf(s) < 0) newList.push(s); });
      }
      if (newList.length > 0) params.set(paramKey, newList.join(","));
      else params.delete(paramKey);
      params.delete("paged");
      fetchProducts(params);
      return;
    }

    var check = e.target.closest('.filter-check[data-param]');
    if (check) {
      e.preventDefault();
      var params = toggleInList(check.dataset.param, check.dataset.slug);
      fetchProducts(params);
      return;
    }

    var toggle = e.target.closest('.filter-toggle-link[data-param]');
    if (toggle) {
      e.preventDefault();
      var param = toggle.dataset.param;
      var params = getParams();
      if (params.has(param)) {
        params.delete(param);
      } else {
        params.set(param, toggle.dataset.value);
      }
      params.delete('paged');
      fetchProducts(params);
      return;
    }

    var priceLink = e.target.closest('.filter-checklist:not([data-taxonomy]) .filter-check');
    if (priceLink) {
      e.preventDefault();
      var href = priceLink.getAttribute('href');
      var url = new URL(href, window.location.origin);
      var params = getParams();
      if (url.searchParams.has('min_price')) {
        if (params.get('min_price') === url.searchParams.get('min_price')) {
          params.delete('min_price');
          params.delete('max_price');
        } else {
          params.set('min_price', url.searchParams.get('min_price'));
          var mp = url.searchParams.get('max_price');
          if (mp) params.set('max_price', mp);
          else params.delete('max_price');
        }
      } else {
        params.delete('min_price');
        params.delete('max_price');
      }
      params.delete('paged');
      fetchProducts(params);
      return;
    }

    var chip = e.target.closest('[data-remove-param]');
    if (chip) {
      e.preventDefault();
      var param = chip.dataset.removeParam;
      var slug = chip.dataset.removeSlug;
      var params;
      if (slug) {
        params = getParams();
        var current = params.get(param);
        var list = current ? current.split(',').filter(function(s) { return s !== slug; }) : [];
        if (list.length > 0) params.set(param, list.join(','));
        else params.delete(param);
        params.delete('paged');
      } else {
        params = getParams();
        params.delete(param);
        if (param === 'min_price') params.delete('max_price');
        params.delete('paged');
      }
      fetchProducts(params);
      return;
    }

    var clear = e.target.closest('[data-filter-clear]');
    if (clear) {
      e.preventDefault();
      fetchProducts(new URLSearchParams());
      return;
    }

    var pageLink = e.target.closest('.aki-pagination a');
    if (pageLink) {
      e.preventDefault();
      var href = pageLink.getAttribute('href');
      var pageUrl = new URL(href, window.location.origin);
      var params = getParams();
      var paged = extractPagedFromUrl(pageUrl) || '1';
      if (paged && paged !== '1') params.set('paged', paged);
      else params.delete('paged');
      fetchProducts(params);
      return;
    }

    var stockToggle = e.target.closest('.stock-toggle');
    if (stockToggle) {
      e.preventDefault();
      var params = getParams();
      if (params.has('stock')) params.delete('stock');
      else params.set('stock', 'instock');
      params.delete('paged');
      fetchProducts(params);
      return;
    }
  });

  var sortSelect = document.querySelector('.woocommerce-ordering select, .orderby');
  if (sortSelect) {
    sortSelect.addEventListener('change', function() {
      var params = setParam('orderby', this.value === 'date' ? null : this.value);
      fetchProducts(params);
    });
  }
  var sortForm = document.querySelector('.woocommerce-ordering');
  if (sortForm) {
    sortForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var select = sortForm.querySelector('select');
      if (select) {
        var params = setParam('orderby', select.value === 'date' ? null : select.value);
        fetchProducts(params);
      }
    });
  }

  window.addEventListener('popstate', function(e) {
    var params = new URLSearchParams(window.location.search);
    fetchProducts(params, false);
  });

  // ══════════════════════════════════════════════════
  // MOBILE BOTTOM SHEET
  // ══════════════════════════════════════════════════

  var filterToggle = document.getElementById('filter-toggle');
  var sidebarClose = document.getElementById('sidebar-close');

  if (filterToggle && sidebar) {
    var sheetOverlay = document.createElement('div');
    sheetOverlay.className = 'filter-sheet-overlay';
    sheetOverlay.setAttribute('aria-hidden', 'true');
    document.body.appendChild(sheetOverlay);

    var _prevFocus = null;
    var _savedScrollY = 0;

    function openSheet() {
      _prevFocus = document.activeElement;
      // S1-13: scroll lock robusto — guardar scrollY + position:fixed body
      // (requerido para bloquear scroll en iOS Safari donde overflow:hidden
      // solo no alcanza). CSS body.filter-drawer-open aplica overflow+touch-action.
      _savedScrollY = window.scrollY || window.pageYOffset || 0;
      document.body.style.top = '-' + _savedScrollY + 'px';
      sidebar.classList.add('open');
      sheetOverlay.classList.add('open');
      document.body.classList.add('filter-drawer-open');
      sidebar.setAttribute('role', 'dialog');
      sidebar.setAttribute('aria-modal', 'true');
      sidebar.setAttribute('aria-label', 'Filtros de productos');
      // Move focus to close button for screen readers
      var closeBtn = sidebar.querySelector('#sidebar-close');
      if (closeBtn) setTimeout(function() { closeBtn.focus(); }, 50);
    }

    function closeSheet() {
      sidebar.classList.remove('open');
      sheetOverlay.classList.remove('open');
      document.body.classList.remove('filter-drawer-open');
      // S1-13: restaurar scroll position original (quita position:fixed del body via CSS).
      document.body.style.top = '';
      window.scrollTo(0, _savedScrollY);
      _savedScrollY = 0;
      sidebar.removeAttribute('role');
      sidebar.removeAttribute('aria-modal');
      if (_prevFocus) { _prevFocus.focus(); _prevFocus = null; }
    }

    filterToggle.addEventListener('click', function(e) {
      e.preventDefault();
      openSheet();
    });

    if (sidebarClose) sidebarClose.addEventListener('click', closeSheet);

    // UX-DRAWER-REDESIGN A1 — sticky CTA bindings
    var stickyCta = sidebar.querySelector('.drawer-cta-sticky');
    var applyCta = document.getElementById('drawer-apply-cta');
    if (applyCta) {
      applyCta.addEventListener('click', function(e) {
        e.preventDefault();
        closeSheet();
        // Smooth scroll al inicio del grid de productos
        var anchor = document.getElementById('main-content') || shopContent;
        if (anchor) {
          var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          var rect = anchor.getBoundingClientRect();
          var top = window.scrollY + rect.top - 80;
          if (prefersReduced) window.scrollTo(0, Math.max(0, top));
          else window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }
      });
    }
    // Mantener `inert` sincronizado con estado open del drawer (a11y).
    // A11Y FIX 2026-04-25 (WCAG 4.1.2): antes usábamos aria-hidden, pero el CTA tiene
    // <button> y <a> focusables — axe disparaba aria-hidden-focus. `inert` saca al
    // contenedor del flow de tab y del a11y tree sin marcar aria-hidden con focusables.
    if (stickyCta) {
      var syncStickyInert = function() {
        if (sidebar.classList.contains('open')) {
          stickyCta.removeAttribute('inert');
        } else {
          stickyCta.setAttribute('inert', '');
        }
      };
      // Observamos cambios de class via MutationObserver (open/close llegan por classList).
      var mo = new MutationObserver(syncStickyInert);
      mo.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
      syncStickyInert();
    }
    // Click en "Limpiar (N)" del sticky → reusa handler [data-filter-clear] existente.
    // (no necesita listener propio: ya tiene data-filter-clear en el HTML.)


    // Outside-click via composedPath — overlay has pointer-events:none so
    // clicks on the backdrop reach document and we check if they were inside
    document.addEventListener('click', function(e) {
      if (!sidebar.classList.contains('open')) return;
      var path = e.composedPath ? e.composedPath() : [];
      if (path.indexOf(sidebar) !== -1 || path.indexOf(filterToggle) !== -1) return;
      closeSheet();
    });

    // Escape key
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && sidebar.classList.contains('open')) {
        e.preventDefault();
        closeSheet();
      }
    });

    // Focus trap: keep Tab inside the open drawer
    sidebar.addEventListener('keydown', function(e) {
      if (e.key !== 'Tab' || !sidebar.classList.contains('open')) return;
      var focusable = Array.from(sidebar.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      )).filter(function(el) { return !el.disabled && el.offsetParent !== null; });
      if (!focusable.length) return;
      var first = focusable[0];
      var last  = focusable[focusable.length - 1];
      if (e.shiftKey) {
        if (document.activeElement === first) { e.preventDefault(); last.focus(); }
      } else {
        if (document.activeElement === last)  { e.preventDefault(); first.focus(); }
      }
    });
  }

  // UX-DRAWER-REDESIGN A1 — initial render del clear badge según URL params actuales.
  updateStickyClearBadge(getParams());

})();

// SIDEBAR SCROLL DETECTION
(function() {
  var sidebar = document.getElementById('shop-sidebar');
  if (!sidebar) return;
  function checkScroll() {
    var atBottom = sidebar.scrollTop + sidebar.clientHeight >= sidebar.scrollHeight - 20;
    sidebar.classList.toggle('scrolled-bottom', atBottom);
  }
  sidebar.addEventListener('scroll', checkScroll, { passive: true });
  setTimeout(checkScroll, 500);
  window.addEventListener('resize', checkScroll, { passive: true });
})();

// MOBILE: Collapse all but first accordion
(function() {
  if (window.innerWidth > 768) return;
  var details = document.querySelectorAll('.shop-sidebar details[open]');
  if (details.length <= 1) return;
  for (var i = 1; i < details.length; i++) {
    details[i].removeAttribute('open');
  }
})();

// "Ver mas" toggle \u2014 Sprint 7 D5: a11y sync aria-expanded + focus al primer item expandido
document.addEventListener("click", function(e) {
  var btn = e.target.closest(".filter-show-more");
  if (!btn) return;
  var checklist = btn.previousElementSibling;
  if (!checklist) return;
  var expanded = btn.dataset.expanded === "true";
  var newExpanded = !expanded;
  btn.dataset.expanded = newExpanded ? "true" : "false";
  btn.setAttribute("aria-expanded", newExpanded ? "true" : "false");
  checklist.classList.toggle("expanded", newExpanded);
  var overflowCount = checklist.querySelectorAll(".filter-check--overflow").length;
  btn.innerHTML = newExpanded
    ? "Ver menos <svg width=\"10\" height=\"10\" viewBox=\"0 0 12 12\"><path d=\"M3 4.5L6 7.5L9 4.5\" stroke=\"currentColor\" stroke-width=\"1.5\" fill=\"none\"/></svg>"
    : "Ver " + overflowCount + " m\u00e1s <svg width=\"10\" height=\"10\" viewBox=\"0 0 12 12\"><path d=\"M3 4.5L6 7.5L9 4.5\" stroke=\"currentColor\" stroke-width=\"1.5\" fill=\"none\"/></svg>";
  // Focus management: al expandir, mover focus al primer item overflow visible
  if (newExpanded) {
    var firstOverflow = checklist.querySelector(".filter-check--overflow .filter-check");
    if (firstOverflow) firstOverflow.focus();
  }
});
