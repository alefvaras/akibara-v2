(function () {
  'use strict';

  var btn = document.getElementById('pack-add-btn');
  var cta = document.getElementById('pack-serie-cta');

  if (!btn || !cta) {
    return;
  }

  var packConfig = window.akibaraPack || {};
  var cartConfig = window.akibaraCart || {};
  var idsCsv = cta.dataset.packIds || '';
  var packIds = idsCsv ? idsCsv.split(',').filter(Boolean) : [];
  var packCount = packIds.length;
  var packTotal = parseInt(cta.dataset.packTotal || '0', 10) || 0;
  var packSerie = cta.dataset.packSerie || '';
  var packSerieId = cta.dataset.packSerieId || '';
  var ajaxUrl = packConfig.ajaxUrl || cartConfig.ajaxUrl || '/wp-admin/admin-ajax.php';

  if (!idsCsv) {
    return;
  }

  btn.addEventListener('click', function () {
    if (btn.classList.contains('pack-cta__btn--loading') || btn.classList.contains('pack-cta__btn--done')) {
      return;
    }

    btn.classList.add('pack-cta__btn--loading');

    var formData = new FormData();
    formData.append('action', 'akibara_add_pack_to_cart');
    formData.append('nonce', packConfig.nonce);
    formData.append('product_ids', idsCsv);

    fetch(ajaxUrl, {
      method: 'POST',
      body: formData
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        btn.classList.remove('pack-cta__btn--loading');

        if (data.success) {
          btn.classList.add('pack-cta__btn--done');

          document.dispatchEvent(new CustomEvent('akb:pack_added', {
            detail: {
              total: packTotal,
              count: packCount,
              serie: packSerie,
              serie_id: packSerieId
            }
          }));

          var cartCount = document.getElementById('cart-count');
          var bottomNavCount = document.getElementById('bottom-nav-count');
          if (cartCount) {
            cartCount.textContent = data.data.count;
          }
          if (bottomNavCount) {
            bottomNavCount.textContent = data.data.count;
          }

          if (window.akibaraToast) {
            window.akibaraToast(data.data.message || 'Pack agregado al carrito');
          }

          if (window.openCart) {
            window.openCart();
          }

          setTimeout(function () {
            btn.classList.remove('pack-cta__btn--done');
          }, 3000);
        } else if (window.akibaraToast) {
          window.akibaraToast(data.data && data.data.message ? data.data.message : 'Error al agregar pack');
        }
      })
      .catch(function () {
        btn.classList.remove('pack-cta__btn--loading');

        if (window.akibaraToast) {
          window.akibaraToast('Error de conexion');
        }
      });
  });
})();
