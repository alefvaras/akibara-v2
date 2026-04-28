/**
 * Akibara — Teléfono Chile (patrón Falabella/Paris)
 *
 * UX:
 *  - Prefijo visual "+56 9" fijo via CSS (no editable).
 *  - Input solo acepta 8 dígitos, auto-formateados como "1234 5678".
 *  - Real-time feedback (✓ válido / ✗ inválido / contador).
 *  - Helper text explicativo (Baymard: reduce abandono).
 *
 * Guardado: al submit, PHP normaliza a "+56 9 XXXX XXXX" para storage.
 */
(function () {
	'use strict';

	/**
	 * Extrae dígitos puros del valor, descartando cualquier prefijo
	 * +56, espacios, guiones, etc.
	 */
	function getDigits(value) {
		if ( ! value) {
return '';
		}
		var clean = String(value).replace(/[^0-9+]/g, '');
		// Strip prefijo +56 o 56
		if (clean.indexOf('+56') === 0) {
clean = clean.substring(3);
		} else if (clean.indexOf('56') === 0 && clean.length >= 11) {
clean = clean.substring(2);
		}
		// Sólo dígitos
		clean = clean.replace(/\D/g, '');
		// Si empieza con 9 y tiene 9 dígitos (legacy "9XXXXXXXX"),
		// dropear el 9 para quedar con 8.
		if (clean.length === 9 && clean.charAt(0) === '9') {
			clean = clean.substring(1);
		}
		return clean.substring(0, 8);
	}

	/**
	 * Formatea 8 dígitos como "XXXX XXXX".
	 */
	function formatPhone(digits) {
		if ( ! digits) {
return '';
		}
		if (digits.length <= 4) {
return digits;
		}
		return digits.substring(0, 4) + ' ' + digits.substring(4, 8);
	}

	/**
	 * Valida que los 8 dígitos sean un móvil chileno razonable:
	 *  - Exactamente 8 dígitos
	 *  - No sean todos repetidos (22222222)
	 *  - No empiezan con 0 ni 1 (rangos no asignados)
	 */
	function validatePhone(digits) {
		if (digits.length !== 8) {
return false;
		}
		if (/^(\d)\1+$/.test(digits)) {
return false;
		}
		if (/^[01]/.test(digits)) {
return false;
		}
		return true;
	}

	/**
	 * Envuelve el input en un contenedor flex con el chip "🇨🇱 +56 9"
	 * como elemento real (no pseudo). Esto es robusto a cualquier
	 * variación del markup de WooCommerce.
	 *
	 * Estructura resultante:
	 *   <div class="akb-phone-input-wrap">
	 *     <span class="akb-phone-chip" aria-hidden="true">
	 *       <span class="akb-phone-chip__flag">🇨🇱</span>
	 *       <span class="akb-phone-chip__code">+56 9</span>
	 *     </span>
	 *     <input id="billing_phone" type="tel" ...>
	 *   </div>
	 */
	function wrapInput(field) {
		// Si ya está wrapeado, no re-hacer
		if (field.closest('.akb-phone-input-wrap')) {
return;
		}

		var wrap = document.createElement('div');
		wrap.className = 'akb-phone-input-wrap';

		var chip = document.createElement('span');
		chip.className = 'akb-phone-chip';
		chip.setAttribute('aria-hidden', 'true');
		chip.innerHTML =
			'<span class="akb-phone-chip__flag">\uD83C\uDDE8\uD83C\uDDF1</span>' +
			'<span class="akb-phone-chip__code">+56 9</span>';

		var parent = field.parentNode;
		parent.insertBefore(wrap, field);
		wrap.appendChild(chip);
		wrap.appendChild(field);
	}

	function init() {
		var field = document.getElementById('billing_phone');
		if ( ! field) {
			setTimeout(init, 500);
			return;
		}

		var formRow = field.closest('.akb-phone-field') || field.closest('.form-row') || field.parentElement;
		if ( ! formRow) {
return;
		}

		// Wrap del input (idempotente)
		wrapInput(field);

		// Evitar double-bind de listeners en AJAX refresh
		if (field.getAttribute('data-akb-phone-init') === '1') {
			// Ya tiene listeners; solo re-normalizar por si cambió el valor
			if (typeof field._akbNormalize === 'function') {
field._akbNormalize();
			}
			return;
		}
		field.setAttribute('data-akb-phone-init', '1');

		// Crear feedback si no existe (lo ponemos DESPUÉS del wrap, no dentro)
		var feedback = formRow.querySelector('.akb-phone-feedback');
		if ( ! feedback) {
			feedback = document.createElement('span');
			feedback.className = 'akb-phone-feedback';
			feedback.setAttribute('aria-live', 'polite');
			// Insertar feedback antes del .description si existe, sino al final del row
			var desc = formRow.querySelector('.description');
			if (desc && desc.parentNode) {
				desc.parentNode.insertBefore(feedback, desc);
			} else {
				formRow.appendChild(feedback);
			}
		}

		/**
		 * Normaliza el valor mostrado en el input:
		 *  - Extrae dígitos con getDigits
		 *  - Formatea como "XXXX XXXX"
		 *  - Actualiza feedback
		 */
		function normalize() {
			var digits = getDigits(field.value);
			var formatted = formatPhone(digits);
			if (field.value !== formatted) {
				field.value = formatted;
			}

			if (digits.length === 0) {
				feedback.textContent = '';
				feedback.classList.remove('akb-fb--ok', 'akb-fb--err');
				field.classList.remove('akb-input-ok', 'akb-input-err');
				return;
			}

			if (digits.length < 8) {
				feedback.textContent = digits.length + '/8 dígitos';
				feedback.classList.remove('akb-fb--ok', 'akb-fb--err');
				field.classList.remove('akb-input-ok', 'akb-input-err');
				return;
			}

			if (validatePhone(digits)) {
				feedback.textContent = '\u2713 Válido';
				feedback.classList.remove('akb-fb--err');
				feedback.classList.add('akb-fb--ok');
				field.classList.remove('akb-input-err');
				field.classList.add('akb-input-ok');
			} else {
				feedback.textContent = '\u2717 Número inválido';
				feedback.classList.remove('akb-fb--ok');
				feedback.classList.add('akb-fb--err');
				field.classList.remove('akb-input-ok');
				field.classList.add('akb-input-err');
			}
		}

		field.addEventListener('input', function () {
			var selStart = this.selectionStart || 0;
			var oldLen = this.value.length;

			normalize();

			// Restaurar posición del cursor (aproximada).
			var newLen = this.value.length;
			var newPos = selStart + (newLen - oldLen);
			if (newPos < 0) {
newPos = 0;
			}
			if (newPos > newLen) {
newPos = newLen;
			}
			try {
				this.setSelectionRange(newPos, newPos);
			} catch (e) {
				/* algunos navegadores rechazan setSelectionRange */
			}
		});

		field.addEventListener('blur', normalize);

		// Exponer para re-init tras updated_checkout sin re-bindear listeners
		field._akbNormalize = normalize;

		// Normalizar valor existente (autofill/login/prefill).
		normalize();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Re-init tras cada AJAX update_checkout de WooCommerce.
	if (typeof jQuery !== 'undefined') {
		jQuery(document.body).on('updated_checkout', init);
	}
})();
