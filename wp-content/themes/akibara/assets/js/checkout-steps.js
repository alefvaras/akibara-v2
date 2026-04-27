/**
 * Akibara Checkout Steps v3.0
 * jQuery-based accordion with progress bar, animations, auto-skip, saved address
 */
(function($) {
  "use strict";

  var currentStep = 1;
  var $body = $(document.body);
  var $steps, $form;
  var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  /* ── Progress Bar ── */
  function updateProgressBar(n) {
    $(".aki-progress__step").each(function() {
      var num = parseInt($(this).data("prog"), 10);
      var $el = $(this);
      $el
        .toggleClass("aki-progress__step--done", num < n)
        .toggleClass("aki-progress__step--active", num === n);
      // A21 (a11y): aria-current="step" comunica el paso actual a screen
      // readers. Solo el paso activo lo lleva; el resto se elimina.
      if (num === n) {
        $el.attr("aria-current", "step");
      } else {
        $el.removeAttr("aria-current");
      }
    });
    $(".aki-progress__line").each(function(i) {
      $(this).toggleClass("aki-progress__line--done", (i + 1) < n);
    });
  }

  /* ── Show Step N ── */
  function showStep(n) {
    $steps.each(function() {
      var $s = $(this);
      var num = $s.data("step");
      if (num < n) {
        $s.removeClass("aki-step--active aki-step--locked").addClass("aki-step--done");
        $s.find(".aki-step__content").hide();
        $s.find(".aki-step__summary").show();
        updateSummary(num, $s);
      } else if (num === n) {
        $s.removeClass("aki-step--done aki-step--locked").addClass("aki-step--active");
        var $content = $s.find(".aki-step__content");
        $content.show().addClass("aki-step__content--entering");
        setTimeout(function() { $content.removeClass("aki-step__content--entering"); }, 400);
        $s.find(".aki-step__summary").hide();
      } else {
        $s.removeClass("aki-step--active aki-step--done").addClass("aki-step--locked");
        $s.find(".aki-step__content").hide();
        $s.find(".aki-step__summary").hide();
      }
    });
    currentStep = n;
    updateProgressBar(n);
    var $stickyBtn = $("#aki-co-sticky-btn");
    if ($stickyBtn.length) $stickyBtn.text(n === 3 ? "PAGAR" : "Ver pedido");
  }

  /* ── Update Summary ── */
  function updateSummary(num, $step) {
    var txt = "";
    if (num === 1) {
      txt = val("billing_email");
      var rut = val("billing_rut");
      if (rut) txt += " \u00b7 " + rut;
    } else if (num === 2) {
      txt = (val("billing_first_name") + " " + val("billing_last_name")).trim();
      var city = $("#billing_city option:selected").text() || val("billing_city");
      if (city) txt += " \u00b7 " + city;
    } else if (num === 3) {
      var $checked = $("input[name=payment_method]:checked");
      txt = $checked.length ? $checked.closest("li").find("label").text().trim() : "Pago seleccionado";
    }
    $step.find(".aki-step__summary-text").text(txt || "Completado");
  }

  function val(id) { var $el = $("#" + id); return $el.length ? $.trim($el.val()) : ""; }

  /* ── Validate Step ── */
  function validateStep(n) {
    var valid = true;
    var $firstError = null;

    function clearPudoStepError() {
      $("#aki-pudo").removeClass("aki-pudo--invalid");
      $("#aki-pudo-step-error").remove();
      // Unified grid: quitar halo pulsante de la card virtual PUDO.
      $("#aki-shipping-methods")
        .find("li.aki-ship-card-virtual, li[data-courier='bluex-pudo']")
        .removeClass("akb-shake akb-pulse-error");
    }

    function showPudoStepError() {
      var $grid    = $("#aki-shipping-methods");
      var $pudoCard = $grid
        .find("li.aki-ship-card-virtual, li[data-courier='bluex-pudo']")
        .first();
      var $pudo = $("#aki-pudo");

      // Anclar mensaje: card en el grid si existe, si no el selector legacy.
      var $anchor = $pudoCard.length ? $pudoCard : $pudo;
      if (!$anchor.length) return;

      $pudo.addClass("aki-pudo--invalid");

      // Halo rojo pulsante + shake corto en la card virtual.
      if ($pudoCard.length) {
        $pudoCard.addClass("akb-pulse-error akb-shake");
        setTimeout(function () { $pudoCard.removeClass("akb-shake"); }, 700);
      }

      // Mensaje inline justo después del grid/selector.
      if (!$("#aki-pudo-step-error").length) {
        var $msg = $('<p id="aki-pudo-step-error" class="aki-pudo-step-error" role="alert" aria-live="assertive">' +
          '<span class="aki-pudo-step-error__icon" aria-hidden="true">\u26A0</span> ' +
          'Selecciona un punto Blue Express en el mapa para continuar.' +
          '</p>');
        if ($pudoCard.length) {
          $grid.after($msg);
        } else {
          $pudo.append($msg);
        }
      }

      // Scroll suave al bloque visible, respetando el header sticky.
      var target = ($pudoCard.length ? $pudoCard : $pudo).get(0);
      if (target && typeof target.scrollIntoView === "function") {
        target.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    }

    $steps.filter("[data-step=" + n + "]").find(".aki-step__content .validate-required").each(function() {
      var $row = $(this);
      var $inp = $row.find("input, select, textarea").first();
      var v = $inp.length ? $.trim($inp.val()) : "";

      if (!v) {
        $row.addClass("woocommerce-invalid woocommerce-invalid-required-field").removeClass("woocommerce-validated");
        valid = false;
        if (!$firstError) $firstError = $inp;
      } else if ($inp.attr("type") === "email" && !emailRegex.test(v)) {
        $row.addClass("woocommerce-invalid").removeClass("woocommerce-validated");
        valid = false;
        if (!$firstError) $firstError = $inp;
      } else if ($inp.attr("id") === "billing_rut") {
        var rutVal = $.trim($inp.val());
        // Bloquea si: (a) campo vacío sin tocar, o (b) módulo RUT lo marcó inválido en real-time.
        if (!rutVal || $row.hasClass("woocommerce-invalid")) {
          valid = false;
          if (!$firstError) $firstError = $inp;
        }
      } else {
        $row.removeClass("woocommerce-invalid woocommerce-invalid-required-field").addClass("woocommerce-validated");
      }
    });

    if (n === 2) {
      var mode = $("input[name='akibara_delivery_mode']:checked").val() || "home";
      var agencyId = $.trim($("#agencyId").val() || "");

      if (mode === "pudo" && !agencyId) {
        valid = false;
        showPudoStepError();
        if (!$firstError) {
          $firstError = $("#aki-pudo");
        }
      } else {
        clearPudoStepError();
      }
    }

    // Nota: step 3 (PAGO) NO pasa por este validator. `validateStep(n)` solo
    // se invoca desde `.aki-step__continue` (botones de paso 1→2 y 2→3).
    // El submit final del paso 3 usa `#place_order` de WC directamente y la
    // validación del método de pago la hace WC core + el gateway (nonce,
    // required fields, términos). Agregar un check duplicado aquí es dead
    // code porque nunca se invoca al click de "Confirmar y pagar".

    if ($firstError) $firstError.trigger("focus");
    return valid;
  }

  /* ── Real-time Field Validation ── */
  function validateField($inp) {
    var $row = $inp.closest(".form-row");
    if (!$row.hasClass("validate-required")) return;
    // Skip RUT field — its own module handles validation classes
    if ($inp.attr("id") === "billing_rut") return;
    var v = $.trim($inp.val());
    if (!v) {
      $row.addClass("woocommerce-invalid").removeClass("woocommerce-validated");
    } else if ($inp.attr("type") === "email" && !emailRegex.test(v)) {
      $row.addClass("woocommerce-invalid").removeClass("woocommerce-validated");
    } else {
      $row.removeClass("woocommerce-invalid woocommerce-invalid-required-field").addClass("woocommerce-validated");
    }
  }

  /* ── Select2 Init ── */
  function initSelect2() {
    if (typeof $.fn.selectWoo !== "function") return;
    $form.find("select.state_select, select.city_select, select.country_select").each(function() {
      var $sel = $(this);
      if ($sel.data("select2")) return;
      $sel.selectWoo({ minimumResultsForSearch: 5, width: "100%" });
    });
  }

  /* ── Remember Payment Method ──
     Persiste el último método elegido con TTL de 7 días. Sin TTL el método
     quedaba preseleccionado indefinidamente incluso en shared devices.
     Formato storage: JSON { method, ts } — legacy plain string se ignora. */
  var PAYMENT_TTL_MS = 7 * 24 * 60 * 60 * 1000;

  function restorePayment() {
    var raw;
    try { raw = localStorage.getItem("aki_payment"); } catch(e) { return; }
    if (!raw) return;
    var method = null;
    try {
      var parsed = JSON.parse(raw);
      if (parsed && typeof parsed.method === "string" && typeof parsed.ts === "number") {
        if (Date.now() - parsed.ts < PAYMENT_TTL_MS) {
          method = parsed.method;
        } else {
          try { localStorage.removeItem("aki_payment"); } catch(e) {}
          return;
        }
      }
    } catch(e) {
      // Legacy plain string guardado antes del cambio. Ignorar y limpiar.
      try { localStorage.removeItem("aki_payment"); } catch(_) {}
      return;
    }
    if (!method || !/^[a-zA-Z0-9_-]+$/.test(method)) return;
    var $radio = $('input[name="payment_method"]').filter(function() { return $(this).val() === method; });
    if ($radio.length && !$radio.prop("checked")) {
      $radio.prop("checked", true).trigger("change");
    }
  }

  function savePaymentMethod(v) {
    if (!v) return;
    try {
      localStorage.setItem("aki_payment", JSON.stringify({ method: v, ts: Date.now() }));
    } catch(e) { /* quota/incognito — ignorar */ }
  }

  function updatePaymentSelected() {
    $(".wc_payment_method").each(function() {
      $(this).toggleClass("is-selected", $(this).find("input[type=radio]").prop("checked"));
    });
  }

  /* ── Init ── */
  $(function() {
    $form = $("form.woocommerce-checkout");
    if (!$form.length) return;
    $steps = $(".aki-step");
    if (!$steps.length) return;

    /* Hide empty express section */
    var $eb = $("#aki-co-express-buttons");
    if ($eb.length && !$eb.find("button, a, iframe, div").length) {
      $eb.closest(".aki-co__express").hide();
    }

    /* Init Select2 */
    initSelect2();

    /* Continue buttons */
    $form.on("click", ".aki-step__continue", function() {
      var n = $(this).data("step");
      if (!validateStep(n)) return;
      showStep(n + 1);
      var $target = $steps.filter("[data-step=" + (n + 1) + "]");
      if ($target.length) {
        $("html, body").animate({ scrollTop: $target.offset().top - 100 }, 350);
      }
    });

    /* Edit buttons */
    $form.on("click", ".aki-step__edit", function() {
      var $step = $(this).closest(".aki-step");
      showStep($step.data("step"));
    });

    /* Payment method selection */
    $form.on("change", ".wc_payment_methods input[type=radio]", function() {
      updatePaymentSelected();
      var v = $(this).val();
      savePaymentMethod(v);
    });

    /* Real-time validation */
    $form.on("input change", ".aki-step input, .aki-step select, .aki-step textarea", function() {
      validateField($(this));
    });
    $form.on("blur", ".aki-step input, .aki-step select", function() {
      validateField($(this));
    });

    /* WC AJAX update_checkout — fix race condition:
       Mientras WC está recalculando totales/envíos vía AJAX, deshabilitamos
       #place_order para evitar que el user submitee con data vieja. */
    $body.on("update_checkout updating_checkout", function() {
      $("#place_order").prop("disabled", true).attr("data-updating", "1");
    });
    $body.on("updated_checkout", function() {
      initSelect2();
      updatePaymentSelected();
      restorePayment();
      $("#place_order").prop("disabled", false).removeAttr("data-updating");
    });

    /* Checkout error — re-enable place order, anunciar a SR, scroll al error */
    $body.on("checkout_error", function() {
      $("#place_order").removeClass("processing").prop("disabled", false);
      var $err = $(".woocommerce-error, .woocommerce-NoticeGroup-checkout").first();
      if ($err.length) {
        // A11Y: que screen readers anuncien automáticamente el error (antes
        // aparecía en DOM pero sin role/aria-live, NVDA/JAWS no lo narraban).
        $err.attr("role", "alert").attr("aria-live", "assertive").attr("tabindex", "-1");
        // Foco programático para que el usuario de teclado vaya al error.
        try { $err.get(0).focus({ preventScroll: true }); } catch(e) {}
        $("html, body").animate({ scrollTop: $err.offset().top - 120 }, 350);
      }
    });

    /* Place order loading state — safety timeout prevents permanently disabled button */
    $form.on("submit", function() {
      var $btn = $("#place_order");
      $btn.addClass("processing").prop("disabled", true);
      setTimeout(function() {
        if ($btn.prop("disabled")) {
          $btn.removeClass("processing").prop("disabled", false);
        }
      }, 30000);
    });

    /* ── Mobile drawer ──
       BUG FIX previo: antes solo se movía #order_review al drawer, dejando
       `.aki-co__trust` (Soporte WhatsApp / Cambios 10 días / pasarelas)
       atrás en el sidebar oculto. Ahora ambos viajan.

       A11Y FIX 2026-04-25 (WCAG 2.4.3 Focus Order + 4.1.2 Name Role Value):
       - Drawer arranca aria-hidden=true (sin role=dialog) para que screen
         readers lo ignoren y axe-core no marque aria-hidden-focus.
       - openDrawer(): promueve a role=dialog + aria-modal=true, mueve foco al
         botón de cerrar, marca <main> como inert para sacar background del flow.
       - closeDrawer(): vuelve a aria-hidden=true, restaura foco al opener.
       - Tab capture loop: Shift+Tab desde primer focusable → último, Tab desde
         último → primero (ciclo dentro del drawer).
       - Escape cierra solo si el drawer está abierto (evita cerrar otros
         overlays globales). */
    var $overlay    = $("#aki-co-drawer-overlay");
    var $drawer     = $("#aki-co-drawer");
    var drawerEl    = $drawer.get(0);
    var $sidebar    = $(".aki-co__sidebar");
    var $drawerBody = $drawer.find(".aki-co__drawer-body");
    var $trust      = $(".aki-co__trust");
    var $orderReview = $("#order_review");
    var $opener     = null;
    var supportsInert = drawerEl ? ('inert' in HTMLElement.prototype) : false;

    function getFocusable() {
      if (!drawerEl) return [];
      var sel = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
      return Array.prototype.filter.call(
        drawerEl.querySelectorAll(sel),
        function(el) { return el.offsetParent !== null || el === document.activeElement; }
      );
    }

    function setMainInert(on) {
      var $main = $("main#main-content");
      if (!$main.length) return;
      if (supportsInert) {
        if (on) $main.attr("inert", "");
        else    $main.removeAttr("inert");
      } else {
        // Fallback Safari <15.5 / browsers viejos: aria-hidden + tabindex=-1 bulk
        // sobre focusables del main. Menos robusto que inert pero suficiente.
        if (on) {
          $main.attr("aria-hidden", "true");
          $main.find("a, button, input, select, textarea, [tabindex]").each(function() {
            if (!this.hasAttribute("data-aki-prev-tabindex")) {
              this.setAttribute("data-aki-prev-tabindex", this.getAttribute("tabindex") || "");
            }
            this.setAttribute("tabindex", "-1");
          });
        } else {
          $main.removeAttr("aria-hidden");
          $main.find("[data-aki-prev-tabindex]").each(function() {
            var prev = this.getAttribute("data-aki-prev-tabindex");
            if (prev) this.setAttribute("tabindex", prev);
            else      this.removeAttribute("tabindex");
            this.removeAttribute("data-aki-prev-tabindex");
          });
        }
      }
    }

    function openDrawer() {
      if (!drawerEl) return;
      $opener = $(document.activeElement);
      if ($orderReview.length && $drawerBody.length) $drawerBody[0].appendChild($orderReview[0]);
      // Mover también el bloque de trust signals para que acompañe al pedido.
      if ($trust.length && $drawerBody.length) $drawerBody[0].appendChild($trust[0]);
      $overlay.removeAttr("hidden").addClass("open");
      $drawer.addClass("open")
             .removeAttr("aria-hidden")
             .attr({ "role": "dialog", "aria-modal": "true" });
      $("body").css("overflow", "hidden");
      setMainInert(true);
      // Foco al botón de cerrar (primer focusable del drawer).
      var $close = $("#aki-co-drawer-close");
      if ($close.length) {
        // RAF para esperar al transition enter — algunos browsers ignoran focus()
        // sobre elementos que están animándose desde transform: translateY(100%).
        requestAnimationFrame(function() { $close.get(0).focus(); });
      }
    }

    function closeDrawer() {
      if (!drawerEl || !$drawer.hasClass("open")) return;
      $overlay.removeClass("open").attr("hidden", "");
      $drawer.removeClass("open")
             .attr("aria-hidden", "true")
             .removeAttr("role")
             .removeAttr("aria-modal");
      $("body").css("overflow", "");
      setMainInert(false);
      if ($orderReview.length && $sidebar.length) $sidebar[0].appendChild($orderReview[0]);
      // Devolver también el bloque de trust signals al sidebar.
      if ($trust.length && $sidebar.length) $sidebar[0].appendChild($trust[0]);
      // Restaurar foco al elemento que abrió el drawer (sticky button).
      if ($opener && $opener.length && document.body.contains($opener.get(0))) {
        try { $opener.get(0).focus(); } catch(e) {}
      }
      $opener = null;
    }

    $("#aki-co-sticky-btn").on("click", openDrawer);
    $("#aki-co-drawer-close").on("click", closeDrawer);
    $overlay.on("click", closeDrawer);

    // Focus trap + Escape sólo dentro del drawer (no global).
    $drawer.on("keydown", function(e) {
      if (!$drawer.hasClass("open")) return;
      if (e.key === "Escape") {
        e.preventDefault();
        closeDrawer();
        return;
      }
      if (e.key !== "Tab") return;
      var focusables = getFocusable();
      if (!focusables.length) {
        e.preventDefault();
        drawerEl.focus(); // fallback al contenedor (tabindex=-1 en HTML)
        return;
      }
      var first = focusables[0];
      var last  = focusables[focusables.length - 1];
      var active = document.activeElement;
      if (e.shiftKey) {
        if (active === first || active === drawerEl) {
          e.preventDefault();
          last.focus();
        }
      } else {
        if (active === last) {
          e.preventDefault();
          first.focus();
        }
      }
    });

    /* ── Saved address ──
       BUG FIX: al hacer click en "Usar esta dirección" saltaba a step 3
       (Pago) sin que el cliente eligiera método de envío. El step 2 abarca
       Dirección + Método de envío — ambos son obligatorios para cotizar
       shipping. Ahora quedamos en step 2 con la dirección pre-rellenada
       para que solo falte elegir el método. */
    var user = window.akiCheckoutUser || {};
    if (user.canSkipStep2) {
      var $saved = $("#aki-saved-address");
      if ($saved.length) {
        $("#aki-saved-address-text").text(user.savedAddress || "");
        $saved.show();

        $("#aki-use-saved").on("click", function() {
          $saved.slideUp(200);
          // Refrescar el checkout para gatillar cálculo de métodos de envío
          // con la dirección guardada ya inyectada en los campos.
          if (window.jQuery) {
            jQuery(document.body).trigger("update_checkout");
          }
          // Permanecer en step 2 — el cliente elige método de envío antes de pagar.
        });
        $("#aki-change-address").on("click", function() {
          $saved.slideUp(200);
        });
      }
    }

    /* ── Auto-skip Step 1 for logged-in users ── */
    if (user.canSkipStep1) {
      showStep(2);
    } else {
      showStep(1);
    }

    /* Restore payment method */
    restorePayment();
  });

  /* ── S1-6: Loader feedback para aplicar/remover cupón ────────────
     WC dispara update_checkout (AJAX) al aplicar/remover cupón. Agregamos
     estado .is-loading al botón clickeado hasta que llegue updated_checkout.
     Usa el spinner global de design-system.css (.btn.is-loading::after).
  ──────────────────────────────────────────────────────────────── */
  var $loadingCoupon = null;

  $body.on("click", "button[name='apply_coupon']", function() {
    var $btn = $(this);
    // Solo si hay input con valor (evita loading sobre submit vacío).
    var $input = $btn.closest("form").find("input[name='coupon_code']");
    if ($input.length && $.trim($input.val()) === "") {
      return;
    }
    $btn.addClass("is-loading").prop("disabled", true);
    $loadingCoupon = $btn;
  });

  $body.on("click", ".woocommerce-remove-coupon", function(e) {
    var $link = $(this);
    $link.addClass("is-loading").attr("aria-busy", "true");
    $loadingCoupon = $link;
  });

  $body.on("updated_checkout applied_coupon_in_checkout removed_coupon_in_checkout", function() {
    if ($loadingCoupon && $loadingCoupon.length) {
      $loadingCoupon.removeClass("is-loading").prop("disabled", false).removeAttr("aria-busy");
      $loadingCoupon = null;
    }
    // Safety net: limpiar cualquier loading residual en el área del cupón.
    $(".checkout_coupon .is-loading, .woocommerce-remove-coupon.is-loading").each(function() {
      $(this).removeClass("is-loading").prop("disabled", false).removeAttr("aria-busy");
    });
  });

  /* ── Cupón toggle (Sprint 6 A5) ──────────────────────────────────
   * El handler nativo de WC (checkout.min.js) registra:
   *   $(document.body).on("click", "a.showcoupon", show_coupon_form)
   * Como el override `woocommerce/checkout/form-coupon.php` migró el
   * elemento a `<button class="showcoupon">` por accesibilidad
   * (WCAG 4.1.2 — toggle de form es acción, no navegación), el handler
   * de WC no matchea. Replicamos su comportamiento aquí: slideToggle
   * + sync de `aria-expanded` + focus al input cupón al abrir.
   * Sólo afecta checkout (cart no tiene `<button class="showcoupon">`).
   * ───────────────────────────────────────────────────────────────── */
  $body.on("click", "button.showcoupon", function(e) {
    e.preventDefault();
    var $btn  = $(this);
    var $form = $("form.checkout_coupon");
    if ( ! $form.length ) return false;
    $form.slideToggle(400, function() {
      var visible = $(this).is(":visible");
      $btn.attr("aria-expanded", visible ? "true" : "false");
      if ( visible ) {
        $(this).find("input[name='coupon_code']").trigger("focus");
      }
    });
    return false;
  });

})(jQuery);
