(function () {
  "use strict";

  if (window.akibaraSeriesHubReady) return;
  window.akibaraSeriesHubReady = true;

  function post(url, fd) {
    return fetch(url, { method: "POST", body: fd, credentials: "same-origin" }).then(function (res) {
      if (!res.ok) throw new Error("HTTP " + res.status);
      return res.json();
    });
  }

  function load(root) {
    if (!root || root.dataset.loaded === "1" || root.dataset.loading === "1") return;

    root.dataset.loading = "1";
    var content = root.querySelector(".akb-series-hub__content");
    var fd = new FormData();
    fd.append("action", "akibara_load_series_hub");
    fd.append("product_id", root.dataset.productId || "0");
    fd.append("nonce", root.dataset.loadNonce || "");

    post(root.dataset.ajaxUrl || "/wp-admin/admin-ajax.php", fd)
      .then(function (json) {
        if (!json.success || !json.data || !json.data.html) {
          root.style.display = "none";
          return;
        }

        var count = Number(json.data.count || 0);
        root.dataset.count = String(count);
        root.classList.remove("akb-series-hub--compact", "akb-series-hub--dense");
        if (count >= 60) {
          root.classList.add("akb-series-hub--dense");
        } else if (count >= 28) {
          root.classList.add("akb-series-hub--compact");
        }

        var subtitle = root.querySelector('.akb-series-hub__subtitle');
        if (subtitle && json.data.subtitle) {
          subtitle.textContent = json.data.subtitle;
        }

        content.innerHTML = json.data.html;
        root.dataset.loaded = "1";
      })
      .catch(function () {
        if (content) {
          content.innerHTML = '<div class="akb-series-hub__error">No pudimos cargar la coleccion.</div>';
        }
      })
      .finally(function () {
        delete root.dataset.loading;
      });
  }

  function initObserver() {
    var hubs = document.querySelectorAll(".js-akb-series-hub");
    if (!hubs.length) return;

    if (!("IntersectionObserver" in window)) {
      Array.prototype.forEach.call(hubs, load);
      return;
    }

    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            load(entry.target);
            io.unobserve(entry.target);
          }
        });
      },
      { rootMargin: "220px 0px", threshold: 0.15 }
    );

    Array.prototype.forEach.call(hubs, function (hub) {
      io.observe(hub);
    });
  }

  document.addEventListener("click", function (event) {
    var navBtn = event.target.closest(".js-akb-sh-nav");
    if (navBtn) {
      event.preventDefault();
      var track = navBtn.parentElement.querySelector(".js-akb-sh-track");
      if (track) {
        var dir = parseInt(navBtn.dataset.dir || "1", 10);
        var scrollAmount = track.clientWidth * 0.75 * dir;
        track.scrollBy({ left: scrollAmount, behavior: "smooth" });
      }
      return;
    }

    var btn = event.target.closest(".js-akb-series-bundle");
    if (!btn || btn.dataset.loading === "1") return;

    event.preventDefault();

    var root = btn.closest(".js-akb-series-hub");
    if (!root) return;

    var ids = [];
    try {
      ids = JSON.parse(btn.dataset.ids || "[]");
    } catch (e) {
      ids = [];
    }

    if (!Array.isArray(ids) || !ids.length) {
      if (window.akibaraToast) window.akibaraToast("No hay tomos en stock para agregar.");
      return;
    }

    btn.dataset.loading = "1";
    btn.disabled = true;
    btn.classList.add("loading");

    var fd = new FormData();
    fd.append("action", "akibara_add_pack_to_cart");
    fd.append("nonce", root.dataset.packNonce || "");
    fd.append("product_ids", ids.join(","));

    post(root.dataset.ajaxUrl || "/wp-admin/admin-ajax.php", fd)
      .then(function (json) {
        if (!json.success || !json.data) {
          throw new Error((json.data && json.data.message) ? json.data.message : "No se pudo agregar el bundle");
        }

        ["cart-count", "bottom-nav-count"].forEach(function (id) {
          var el = document.getElementById(id);
          if (el && typeof json.data.count !== "undefined") el.textContent = String(json.data.count);
        });

        if (window.akibaraToast) window.akibaraToast(json.data.message || "Tomos agregados al carrito");
        if (window.openCart) window.openCart();
      })
      .catch(function (err) {
        if (window.akibaraToast) window.akibaraToast(err.message || "No se pudo agregar el bundle");
      })
      .finally(function () {
        delete btn.dataset.loading;
        btn.disabled = false;
        btn.classList.remove("loading");
      });
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initObserver);
  } else {
    initObserver();
  }
})();
