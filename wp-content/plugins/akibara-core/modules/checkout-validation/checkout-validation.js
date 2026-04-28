/**
 * Akibara — Checkout Validation v1.0
 *
 * - A. Email typo-fix: sugiere corrección si el dominio del email tiene
 *   un typo común (gmial.com → gmail.com, hotmial → hotmail, etc.).
 *
 * - B. Nombres: real-time validation (minlength=2, sólo letras/espacios).
 *
 * - C. Dirección: real-time validation (minlength=5, debe tener número).
 */
(function ($) {
	'use strict';

	if (typeof $ === 'undefined') return;

	// ──────────────────────────────────────────────────────────────
	// Dominios de email comunes en Chile
	// ──────────────────────────────────────────────────────────────
	var KNOWN_DOMAINS = [
		'gmail.com', 'hotmail.com', 'hotmail.cl', 'hotmail.es',
		'yahoo.com', 'yahoo.es', 'yahoo.cl',
		'outlook.com', 'outlook.es', 'outlook.cl',
		'live.com', 'live.cl',
		'icloud.com', 'me.com',
		'protonmail.com', 'proton.me',
		'duocuc.cl', 'uc.cl', 'uchile.cl', 'usach.cl', 'utfsm.cl',
		'vtr.net', 'mi.cl'
	];

	// Top dominios a ofrecer cuando el email está incompleto ("user@").
	// Yahoo removido (<3% share CL 2026). iCloud incluido por crecimiento
	// del parque iOS en Chile. Orden por uso real de mercado.
	var TOP_DOMAINS_CL = ['gmail.com', 'hotmail.com', 'outlook.com', 'icloud.com'];

	// TLDs comunes mal escritos.
	var TLD_FIXES = {
		'co': 'com', 'cm': 'com', 'con': 'com', 'comm': 'com',
		'om': 'com', 'cmo': 'com', 'col': 'com'
	};

	/**
	 * Distancia de Levenshtein entre dos strings.
	 */
	function levenshtein(a, b) {
		if (a === b) return 0;
		if (!a.length) return b.length;
		if (!b.length) return a.length;

		var matrix = [];
		var i, j;
		for (i = 0; i <= b.length; i++) matrix[i] = [i];
		for (j = 0; j <= a.length; j++) matrix[0][j] = j;

		for (i = 1; i <= b.length; i++) {
			for (j = 1; j <= a.length; j++) {
				if (b.charAt(i - 1) === a.charAt(j - 1)) {
					matrix[i][j] = matrix[i - 1][j - 1];
				} else {
					matrix[i][j] = Math.min(
						matrix[i - 1][j - 1] + 1,
						matrix[i][j - 1] + 1,
						matrix[i - 1][j] + 1
					);
				}
			}
		}
		return matrix[b.length][a.length];
	}

	/**
	 * Detecta si el dominio del email tiene un typo o está incompleto.
	 *
	 * @return {null | string | string[]}
	 *   - null: no hay sugerencia (email válido o inanalizable).
	 *   - string: typo claro → un único dominio sugerido.
	 *   - string[]: email incompleto ("user@" o "user@x") → lista de
	 *     dominios populares para autocompletar.
	 */
	function suggestEmailDomain(email) {
		if (!email) return null;

		var atIdx = email.indexOf('@');
		if (atIdx === -1) return null;

		var local  = email.substring(0, atIdx).trim();
		var domain = email.substring(atIdx + 1).trim().toLowerCase();

		// Sin local-part: imposible sugerir nada útil.
		if (!local) return null;

		// Caso A: email incompleto ("user@" o "user@g" o "user@gm").
		// Mostrar top dominios populares. Si el user ya empezó a escribir
		// el dominio, filtrar los que hacen prefix-match.
		if (domain.length < 3 || domain.indexOf('.') === -1) {
			var pool = TOP_DOMAINS_CL;
			if (domain.length > 0) {
				var filtered = pool.filter(function (d) { return d.indexOf(domain) === 0; });
				if (filtered.length > 0) pool = filtered;
			}
			return pool.slice(0, 4);
		}

		// Caso B: ya es un dominio conocido → OK, sin sugerencia.
		if (KNOWN_DOMAINS.indexOf(domain) !== -1) return null;

		// Caso C: TLD obviamente mal escrito (gmail.con → gmail.com).
		var domainParts = domain.split('.');
		if (domainParts.length >= 2) {
			var tld = domainParts[domainParts.length - 1];
			if (TLD_FIXES[tld]) {
				domainParts[domainParts.length - 1] = TLD_FIXES[tld];
				var fixedTld = domainParts.join('.');
				if (KNOWN_DOMAINS.indexOf(fixedTld) !== -1) {
					return fixedTld;
				}
			}
		}

		// Caso D: Levenshtein contra dominios conocidos (distancia ≤2).
		var bestMatch = null;
		var bestDistance = 99;

		for (var i = 0; i < KNOWN_DOMAINS.length; i++) {
			var known = KNOWN_DOMAINS[i];
			var d = levenshtein(domain, known);
			if (d > 0 && d <= 2 && d < bestDistance) {
				bestDistance = d;
				bestMatch = known;
			}
		}

		if (bestMatch && bestDistance <= 2) {
			return bestMatch;
		}

		return null;
	}

	function getOrCreateFeedback($field, className) {
		var $row = $field.closest('.form-row');
		var $existing = $row.find('.' + className);
		if ($existing.length) return $existing;

		var $fb = $('<span class="' + className + '" aria-live="polite"></span>');
		$row.append($fb);
		return $fb;
	}

	// ──────────────────────────────────────────────────────────────
	// A. Email typo-fix UI
	// ──────────────────────────────────────────────────────────────
	function initEmailTypoFix() {
		var $email = $('#billing_email');
		if (!$email.length) return;
		if ($email.data('akb-typofix-init')) return;
		$email.data('akb-typofix-init', '1');

		var lastSeen     = '';
		var debounceId   = null;
		var ignoreUntil  = 0; // para evitar re-mostrar sugerencia justo después de aceptarla

		function process() {
			var val = $.trim($email.val());
			if (val === lastSeen) return;
			lastSeen = val;

			if (!val) {
				removeSuggestion();
				return;
			}

			if (Date.now() < ignoreUntil) {
				return;
			}

			var suggestion = suggestEmailDomain(val);
			if (!suggestion) {
				removeSuggestion();
				return;
			}

			var localPart = val.split('@')[0];

			// Array → email incompleto, mostrar chips de autocomplete.
			if (Array.isArray(suggestion)) {
				var fixedOptions = suggestion.map(function (d) { return localPart + '@' + d; });
				showChipSuggestions(fixedOptions);
				return;
			}

			// String → typo-fix estándar, mostrar "¿Quisiste decir ...?"
			var fixed = localPart + '@' + suggestion;
			if (fixed === val) {
				removeSuggestion();
				return;
			}
			showSingleSuggestion(fixed);
		}

		function scheduleProcess() {
			if (debounceId) clearTimeout(debounceId);
			// 400ms tras el último keystroke: aparece solo cuando el user
			// pausa (típicamente tras escribir "@"), no mientras sigue tipeando
			// su dominio real. Reduce jitter visual y anxiety.
			debounceId = setTimeout(process, 400);
		}

		// Input: detectar typos en vivo (con debounce).
		// Blur/change: fallback instantáneo (autofill/tab-out).
		$email.on('input', scheduleProcess);
		$email.on('blur change', function () {
			if (debounceId) clearTimeout(debounceId);
			process();
		});

		// Trigger inicial: si el email ya está pre-filled (logged-in user
		// o autofill del browser), detectar typos al cargar la página.
		setTimeout(process, 150);

		function showSingleSuggestion(fixed) {
			var $row = $email.closest('.form-row');
			$row.find('.akb-email-suggestion').remove();

			// Typo detectado: sí corresponde la variante --typo (amber) porque
			// es una corrección activa, no una sugerencia neutra de autocomplete.
			var $sug = $(
				'<div class="akb-email-suggestion akb-email-suggestion--typo" role="alert">' +
					'<span class="akb-email-suggestion__icon" aria-hidden="true">\u2709</span>' +
					'<span class="akb-email-suggestion__text">¿Quisiste decir <button type="button" class="akb-email-suggestion__btn">' + escapeHtml(fixed) + '</button>?</span>' +
				'</div>'
			);

			$row.append($sug);

			$sug.find('.akb-email-suggestion__btn').on('click', function (e) {
				e.preventDefault();
				lastSeen = fixed;
				ignoreUntil = Date.now() + 500;
				$email.val(fixed).trigger('change').trigger('blur');
				removeSuggestion();
			});

			$sug.on('keydown', function (e) {
				if (e.key === 'Escape') {
					e.preventDefault();
					removeSuggestion();
					$email.focus();
				}
			});
		}

		function showChipSuggestions(options) {
			var $row = $email.closest('.form-row');
			$row.find('.akb-email-suggestion').remove();

			var labelId = 'akb-sug-lbl-' + Date.now();
			var chipsHtml = options.map(function (opt) {
				return '<button type="button" class="akb-email-chip" data-fill="' + escapeHtml(opt) + '" aria-label="Completar con ' + escapeHtml(opt) + '">' + escapeHtml(opt) + '</button>';
			}).join('');

			// role="group" con aria-labelledby (no role="status" — el contenido es
			// interactivo, no un mensaje). Se acompaña de live-region sr-only
			// separada para anunciar la aparición al screen reader.
			var $sug = $(
				'<div class="akb-email-suggestion akb-email-suggestion--chips" role="group" aria-labelledby="' + labelId + '">' +
					'<span class="akb-email-suggestion__icon" aria-hidden="true">\u2709</span>' +
					'<span id="' + labelId + '" class="akb-email-suggestion__label">Completa con:</span>' +
					'<div class="akb-email-chips">' + chipsHtml + '</div>' +
					'<span class="akb-sr-only" aria-live="polite">Sugerencias de dominio disponibles. Usa Tab para elegir.</span>' +
				'</div>'
			);

			$row.append($sug);

			$sug.find('.akb-email-chip').on('click', function (e) {
				e.preventDefault();
				var fill = $(this).data('fill');
				lastSeen = fill;
				$email.val(fill).trigger('change').trigger('blur').focus();
				removeSuggestion();
			});

			// Escape cierra las sugerencias y devuelve foco al input.
			$sug.on('keydown', function (e) {
				if (e.key === 'Escape') {
					e.preventDefault();
					removeSuggestion();
					$email.focus();
				}
			});
		}

		function removeSuggestion() {
			$email.closest('.form-row').find('.akb-email-suggestion').remove();
		}
	}

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	// ──────────────────────────────────────────────────────────────
	// B. Nombres — real-time validation
	// ──────────────────────────────────────────────────────────────
	var NAME_REGEX = /^[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ\s'\-]+$/;

	function validateName(value) {
		var v = (value || '').trim();
		if (!v) return { valid: false, msg: '' }; // vacío: sin feedback (campo required maneja)
		if (v.length < 2) return { valid: false, msg: 'Muy corto (mín. 2 caracteres)' };
		if (v.length > 50) return { valid: false, msg: 'Muy largo (máx. 50)' };
		if (!NAME_REGEX.test(v)) return { valid: false, msg: 'Sólo letras, espacios, apóstrofe y guión' };
		var noSpace = v.replace(/\s+/g, '');
		if (/^(.)\1+$/.test(noSpace)) return { valid: false, msg: 'No parece válido' };
		return { valid: true, msg: '\u2713 OK' };
	}

	function bindNameField(id) {
		var $f = $('#' + id);
		if (!$f.length) return;
		if ($f.data('akb-namevalid-init')) return;
		$f.data('akb-namevalid-init', '1');

		var $fb = getOrCreateFeedback($f, 'akb-name-feedback');

		$f.on('input blur', function () {
			var v = $(this).val();
			if (!v || !v.trim()) {
				$fb.removeClass('akb-fb--ok akb-fb--err').text('');
				$f.removeClass('akb-input-ok akb-input-err');
				return;
			}
			var r = validateName(v);
			if (r.valid) {
				$fb.removeClass('akb-fb--err').addClass('akb-fb--ok').text(r.msg);
				$f.removeClass('akb-input-err').addClass('akb-input-ok');
			} else {
				$fb.removeClass('akb-fb--ok').addClass('akb-fb--err').text(r.msg);
				$f.removeClass('akb-input-ok').addClass('akb-input-err');
			}
		});
	}

	// ──────────────────────────────────────────────────────────────
	// C. Dirección — real-time validation
	// ──────────────────────────────────────────────────────────────
	function validateAddress(value) {
		var v = (value || '').trim();
		if (!v) return { valid: false, msg: '' };
		if (v.length < 5) return { valid: false, msg: 'Incluye calle y número' };
		if (v.length > 100) return { valid: false, msg: 'Muy larga (máx. 100)' };
		if (!/\d/.test(v)) return { valid: false, msg: 'Falta el número de calle' };
		var noSpace = v.replace(/\s+/g, '');
		if (/^(.)\1+$/.test(noSpace)) return { valid: false, msg: 'No parece válida' };
		return { valid: true, msg: '\u2713 OK' };
	}

	function bindAddressField() {
		var $f = $('#billing_address_1');
		if (!$f.length) return;
		if ($f.data('akb-addrvalid-init')) return;
		$f.data('akb-addrvalid-init', '1');

		var $fb = getOrCreateFeedback($f, 'akb-addr-feedback');

		$f.on('input blur', function () {
			// En modo Metro la dirección es opcional, no mostrar errores.
			var mode = ($('input[name="akibara_delivery_mode"]:checked').val() || 'home').toString();
			if (mode === 'metro' || mode === 'pudo') {
				$fb.removeClass('akb-fb--ok akb-fb--err').text('');
				$f.removeClass('akb-input-ok akb-input-err');
				return;
			}

			var v = $(this).val();
			if (!v || !v.trim()) {
				$fb.removeClass('akb-fb--ok akb-fb--err').text('');
				$f.removeClass('akb-input-ok akb-input-err');
				return;
			}
			var r = validateAddress(v);
			if (r.valid) {
				$fb.removeClass('akb-fb--err').addClass('akb-fb--ok').text(r.msg);
				$f.removeClass('akb-input-err').addClass('akb-input-ok');
			} else {
				$fb.removeClass('akb-fb--ok').addClass('akb-fb--err').text(r.msg);
				$f.removeClass('akb-input-ok').addClass('akb-input-err');
			}
		});
	}

	// ──────────────────────────────────────────────────────────────
	// Init
	// ──────────────────────────────────────────────────────────────
	function initAll() {
		initEmailTypoFix();
		bindNameField('billing_first_name');
		bindNameField('billing_last_name');
		bindAddressField();
	}

	$(function () {
		initAll();

		// WC reemplaza partes del checkout vía AJAX. Re-bindeamos.
		$(document.body).on('updated_checkout', initAll);
	});

})(jQuery);
