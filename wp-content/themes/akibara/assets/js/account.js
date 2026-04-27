/**
 * Akibara — Auth form: tabs, password toggle, strength meter, inline validation
 */
(function () {
  'use strict';

  // ── Tab switching ──────────────────────────────────────────────────
  function initAuthTabs() {
    var auth = document.getElementById('aki-auth');
    if (!auth) return;

    function switchTab(tabName) {
      auth.querySelectorAll('.aki-auth__tab').forEach(function (btn) {
        var active = btn.dataset.tab === tabName;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      auth.querySelectorAll('.aki-auth__panel').forEach(function (panel) {
        panel.classList.toggle('is-active', panel.id === 'aki-panel-' + tabName);
      });
    }

    // Tab button clicks
    auth.querySelectorAll('.aki-auth__tab').forEach(function (btn) {
      btn.addEventListener('click', function () { switchTab(btn.dataset.tab); });
    });

    // Inline "switch" links
    auth.querySelectorAll('.js-aki-tab-switch').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        switchTab(link.dataset.tab);
        auth.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
  }

  // ── Password eye toggle ────────────────────────────────────────────
  function initPasswordToggles() {
    document.querySelectorAll('.js-pw-eye').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var targetId = btn.dataset.target;
        var input = document.getElementById(targetId);
        if (!input) return;

        var isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        btn.querySelector('.eye-show').style.display = isHidden ? 'none' : '';
        btn.querySelector('.eye-hide').style.display = isHidden ? '' : 'none';
        btn.setAttribute('aria-label', isHidden ? 'Ocultar contraseña' : 'Mostrar contraseña');
      });
    });
  }

  // ── Password strength meter ────────────────────────────────────────
  function getStrength(pw) {
    var score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[a-z]/.test(pw)) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^a-zA-Z0-9]/.test(pw)) score++;
    return Math.min(5, Math.max(1, score - (pw.length < 6 ? 2 : 0)));
  }

  var LABELS = ['', 'Muy débil', 'Débil', 'Regular', 'Fuerte', 'Muy fuerte'];

  function initStrengthMeter() {
    var pw   = document.getElementById('reg_password');
    var wrap = document.getElementById('aki-pw-strength');
    var bar  = document.getElementById('aki-pw-bar');
    var txt  = document.getElementById('aki-pw-txt');
    if (!pw || !wrap) return;

    pw.addEventListener('input', function () {
      var val = pw.value;
      if (!val) {
        wrap.className = 'aki-auth__strength';
        return;
      }
      var lvl = getStrength(val);
      wrap.className = 'aki-auth__strength is-visible lvl-' + lvl;
      if (txt) txt.textContent = LABELS[lvl];
    });
  }

  // ── Inline validation ──────────────────────────────────────────────
  function validateField(input) {
    var err = input.closest('.aki-auth__field')
                   ? input.closest('.aki-auth__field').querySelector('.aki-auth__err')
                   : null;
    var msg = '';

    if (input.type === 'email' && input.value) {
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
        msg = 'Eso no parece un correo válido.';
      }
    }
    if (input.required && !input.value.trim()) {
      msg = 'Este campo es obligatorio.';
    }
    if (input.id === 'reg_password' && input.value && input.value.length < 8) {
      msg = 'Necesita al menos 8 caracteres.';
    }

    if (err) err.textContent = msg;
    input.classList.toggle('is-invalid', !!msg);
    input.classList.toggle('is-valid',   !msg && !!input.value);
  }

  function initInlineValidation() {
    var forms = document.querySelectorAll('.js-aki-login-form, .js-aki-register-form');
    forms.forEach(function (form) {
      form.querySelectorAll('.aki-auth__input').forEach(function (input) {
        var timer;
        input.addEventListener('blur', function () {
          clearTimeout(timer);
          timer = setTimeout(function () { validateField(input); }, 400);
        });
      });
    });
  }

  // ── Init ───────────────────────────────────────────────────────────
  
  /* ─── Magic Link ─── */
  function initMagicLink() {
    var trigger = document.getElementById('aki-magic-trigger');
    var form    = document.getElementById('aki-magic-form');
    var ok      = document.getElementById('aki-magic-ok');
    var err     = document.getElementById('aki-magic-err');
    if (!trigger || !form) return;

    trigger.addEventListener('click', function() {
      trigger.style.display = 'none';
      form.style.display = 'flex';
      var emailInput = document.getElementById('aki-magic-email');
      if (!emailInput) return;
      // Pre-fill from login email field
      var loginEmail = document.getElementById('username');
      if (loginEmail && loginEmail.value) {
        emailInput.value = loginEmail.value;
      }
      emailInput.focus();
    });

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      var email   = document.getElementById('aki-magic-email').value.trim();
      var btnText = form.querySelector('.aki-magic-text');
      var btnLoad = form.querySelector('.aki-magic-loading');
      var btn     = document.getElementById('aki-magic-btn');

      if (!email) return;

      btnText.style.display = 'none';
      btnLoad.style.display = 'inline';
      btn.disabled = true;
      ok.style.display = 'none';
      err.style.display = 'none';

      var fd = new FormData();
      fd.append('action', 'akibara_magic_link_send');
      fd.append('email', email);
      fd.append('nonce', (window.akibaraMagic && window.akibaraMagic.nonce) || '');

      fetch((window.akibaraMagic && window.akibaraMagic.ajaxUrl) || '/wp-admin/admin-ajax.php', {
        method: 'POST', body: fd
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        btnText.style.display = 'inline';
        btnLoad.style.display = 'none';
        btn.disabled = false;
        if (data.success) {
          ok.textContent = data.data.message || 'Revisa tu correo. El enlace expira en 15 minutos.';
          ok.style.display = 'block';
          form.querySelector('button[type=submit]').style.display = 'none';
          document.getElementById('aki-magic-email').readOnly = true;
        } else {
          err.textContent = data.data || 'Error al enviar.';
          err.style.display = 'block';
        }
      })
      .catch(function() {
        btnText.style.display = 'inline';
        btnLoad.style.display = 'none';
        btn.disabled = false;
        err.textContent = 'Error de conexión. Intenta de nuevo.';
        err.style.display = 'block';
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initAuthTabs();
    initPasswordToggles();
    initStrengthMeter();
    initInlineValidation();
  initMagicLink();
  });

})();
