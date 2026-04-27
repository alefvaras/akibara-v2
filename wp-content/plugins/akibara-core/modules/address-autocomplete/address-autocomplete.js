/**
 * Akibara — Google Places Autocomplete.
 *
 * Usa `google.maps.places.PlaceAutocompleteElement` (Places API New). Inserta
 * un Web Component antes del `<input>` del formulario y oculta el nativo para
 * que WooCommerce siga leyendo `value` al enviar el POST.
 *
 * Al seleccionar un lugar, autocompleta address_1 + city + state + postcode
 * y dispara los eventos que WC escucha para recalcular envío.
 *
 * Dependencias:
 *   - `window.akbPlaces` (config inyectada desde module.php) con `fields` y `regionMap`.
 *   - `AKB_GOOGLE_MAPS_API_KEY` en `wp-config.php` (verificado en module.php).
 */
(function () {
	'use strict';

	var config      = window.akbPlaces || {};
	var initialized = new WeakSet();
	var loaderState = 'idle'; // idle | loading | ready

	/**
	 * Lazy-load de la Google Maps JS API.
	 * Se dispara cuando el usuario hace foco por primera vez en un input
	 * de dirección (ahorra ~500KB de descarga inicial en checkout).
	 * Idempotente: múltiples invocaciones solo agregan el <script> una vez.
	 */
	function loadMaps() {
		if (loaderState !== 'idle') {
return;
		}
		if ( ! config.loaderUrl) {
			console.warn('[akibara] No loaderUrl configured; skipping lazy Maps load.');
			return;
		}
		loaderState = 'loading';
		var s = document.createElement('script');
		s.src    = config.loaderUrl; // incluye key + libraries=places + callback=akbPlacesInit
		s.async  = true;
		s.defer  = true;
		s.onerror = function () {
			loaderState = 'idle';
			console.warn('[akibara] Failed to load Google Maps JS API.');
		};
		document.head.appendChild(s);
	}

	/**
	 * Listener de primer-uso: dispara `loadMaps()` cuando el usuario toca o
	 * enfoca un input de dirección. Se registra UNA vez y se auto-remueve
	 * tras el primer disparo efectivo. Trigger events:
	 *   - focusin:   teclado / click / tab (cubre mayoría de casos)
	 *   - touchstart: mobile (dispara antes de focusin, ganamos ~200ms)
	 *   - pointerdown: fallback universal
	 */
	function setupLazyTrigger() {
		var selectors = Object.keys(config.fields || {});
		if ( ! selectors.length) {
return;
		}
		var selectorStr = selectors.join(',');

		function onFirstInteract(e) {
			if ( ! e.target || ! e.target.matches || ! e.target.matches(selectorStr)) {
return;
			}
			// Desmontar listeners tras primer match efectivo.
			document.removeEventListener('focusin',   onFirstInteract, true);
			document.removeEventListener('touchstart', onFirstInteract, true);
			document.removeEventListener('pointerdown', onFirstInteract, true);
			loadMaps();
		}

		document.addEventListener('focusin',    onFirstInteract, true);
		document.addEventListener('touchstart',  onFirstInteract, true);
		document.addEventListener('pointerdown', onFirstInteract, true);
	}

	/**
	 * Callback desde el loader de Google Maps cuando la librería queda lista.
	 * El loader (module.php) carga con &callback=akbPlacesInit.
	 */
	window.akbPlacesInit = function () {
		loaderState = 'ready';
		if ( ! window.google || ! google.maps) {
return;
		}

		// `importLibrary` está disponible desde v=weekly. Importamos places (New).
		// Si ya cargó vía &libraries=places el namespace ya existe.
		if (google.maps.importLibrary) {
			google.maps.importLibrary('places').then(function () {
				bootstrap();
			}).catch(function (err) {
				console.warn('[akibara] Places library import failed:', err);
			});
		} else {
			bootstrap();
		}
	};

	function bootstrap() {
		if ( ! google.maps.places || ! google.maps.places.PlaceAutocompleteElement) {
			console.warn('[akibara] PlaceAutocompleteElement not available. ' +
				'Check that Places API (New) is enabled in Google Cloud Console.');
			return;
		}

		attachAll();

		// Debounce `attachAll` para no saturar con cada mutación del DOM.
		// En checkout, `updated_checkout` dispara cientos de cambios por clic —
		// sin debounce corremos attach miles de veces con WeakSet lookups.
		var debouncedAttach = null;
		function scheduleAttach() {
			if (debouncedAttach) {
return;
			}
			debouncedAttach = requestAnimationFrame(function () {
				debouncedAttach = null;
				attachAll();
			});
		}

		if (window.jQuery) {
			jQuery(document.body).on('updated_checkout country_to_state_changed', scheduleAttach);
		}

		// MutationObserver con debounce via rAF — una sola llamada por frame.
		var mo = new MutationObserver(scheduleAttach);
		mo.observe(document.body, { childList: true, subtree: true });
	}

	function attachAll() {
		var fields = config.fields || {};
		Object.keys(fields).forEach(function (selector) {
			document.querySelectorAll(selector).forEach(function (input) {
				attachOne(input, fields[selector]);
			});
		});
	}

	/**
	 * ¿Está el elemento en el render tree (offsetParent != null)?
	 * Si está dentro de un ancestor con display:none o no está adjunto al
	 * DOM, el Web Component de Google no inicializa su shadow DOM.
	 */
	function isVisibleInTree(el) {
		if ( ! el || ! el.isConnected) {
return false;
		}
		// offsetParent === null cuando algún ancestor tiene display:none
		// (excepto <body> mismo que puede tener offsetParent=null y estar visible).
		return el.offsetParent !== null || el === document.body;
	}

	function attachOne(input, prefix) {
		if ( ! input || initialized.has(input)) {
return;
		}

		// Si el input está dentro de un parent con display:none (step del accordion
		// aún no visible), postergamos: el Web Component no inicializa su shadow DOM
		// si se conecta fuera del render tree. Reintentaremos vía MutationObserver
		// cuando el contenedor se haga visible.
		if ( ! isVisibleInTree(input)) {
			return;
		}

		initialized.add(input);

		// Crear el Web Component con restricciones regionales.
		// Nota: `requestedRegionCode` no es opción válida del constructor —
		// el sesgo regional se configura vía `includedRegionCodes` y el
		// lenguaje de la UI se hereda del atributo `lang` del documento.
		var pac;
		try {
			pac = new google.maps.places.PlaceAutocompleteElement({
				includedRegionCodes: config.country || ['cl']
			});
		} catch (e) {
			console.warn('[akibara] Failed to init PlaceAutocompleteElement:', e);
			return;
		}

		// Transferir atributos accesibles del input original al componente.
		pac.id = input.id ? input.id + '-pac' : '';
		pac.className = 'akb-pac-element';
		if (input.placeholder) {
			pac.setAttribute('placeholder', input.placeholder);
		}

		// Insertar el componente JUSTO ANTES del input y ocultar el input.
		// El input queda en el DOM con su nombre para que WC lo envíe en el POST.
		if (input.parentNode) {
			input.parentNode.insertBefore(pac, input);
		}
		input.style.display = 'none';
		input.setAttribute('aria-hidden', 'true');
		input.tabIndex = -1;

		// Listener del selector del nuevo Web Component.
		pac.addEventListener('gmp-select', async function (event) {
			try {
				var placePrediction = event.placePrediction;
				if ( ! placePrediction) {
return;
				}

				var place = placePrediction.toPlace();
				await place.fetchFields({
					fields: ['addressComponents', 'formattedAddress']
				});

				var parsed = parseComponentsNew(place.addressComponents);

				// Componer address_1: "Ruta + Número" (formato chileno).
				var streetParts = [];
				if (parsed.route) {
streetParts.push(parsed.route);
				}
				if (parsed.streetNumber) {
streetParts.push(parsed.streetNumber);
				}
				if (streetParts.length) {
					var street = streetParts.join(' ');
					input.value = street;
					triggerChange(input);
				}

				// City
				var cityInput = document.querySelector('#' + prefix + '_city, input[name="' + prefix + '_city"]');
				if (cityInput && parsed.city) {
					cityInput.value = parsed.city;
					triggerChange(cityInput);
				}

				// State (región): normaliza el short_name al código WC (CL-RM, etc.).
				var regionCode = mapRegion(parsed.stateShort, parsed.stateLong);
				if (regionCode) {
					setStateValue(prefix, regionCode);
				}

				// Postcode
				var postcodeInput = document.querySelector('#' + prefix + '_postcode, input[name="' + prefix + '_postcode"]');
				if (postcodeInput && parsed.postcode) {
					postcodeInput.value = parsed.postcode;
					triggerChange(postcodeInput);
				}

				// WC recalcula envío.
				if (window.jQuery && jQuery(document.body).trigger) {
					jQuery(document.body).trigger('update_checkout');
				}
			} catch (err) {
				console.warn('[akibara] gmp-select handler failed:', err);
			}
		});
	}

	/**
	 * Parse de addressComponents de la API NEW (camelCase + longText/shortText).
	 * Valida que `types` sea Array antes de usar `indexOf` para evitar crash
	 * si Google devuelve un schema inesperado en algún componente.
	 */
	function parseComponentsNew(components) {
		var result = {};
		if ( ! components || ! components.length) {
return result;
		}

		components.forEach(function (c) {
			var types = Array.isArray(c.types) ? c.types : [];
			if ( ! types.length) {
return;
			}

			var longT  = c.longText || c.long_name || '';
			var shortT = c.shortText || c.short_name || '';

			if (types.indexOf('street_number') !== -1) {
result.streetNumber = longT;
			}
			if (types.indexOf('route') !== -1) {
result.route = longT;
			}
			if (types.indexOf('locality') !== -1) {
result.city = longT;
			}
			if (types.indexOf('administrative_area_level_3') !== -1 && ! result.city) {
result.city = longT;
			}
			if (types.indexOf('administrative_area_level_2') !== -1 && ! result.city) {
result.city = longT;
			}
			if (types.indexOf('administrative_area_level_1') !== -1) {
				result.stateShort = shortT;
				result.stateLong  = longT;
			}
			if (types.indexOf('postal_code') !== -1) {
result.postcode = longT;
			}
		});
		return result;
	}

	function mapRegion(shortName, longName) {
		var map = config.regionMap || {};
		if (shortName && map[shortName]) {
return map[shortName];
		}

		if (longName) {
			var normalized = longName
				.toLowerCase()
				.normalize('NFD')
				.replace(/[\u0300-\u036f]/g, '');
			var byName = {
				'region metropolitana de santiago': 'CL-RM',
				'region metropolitana': 'CL-RM',
				'santiago metropolitan region': 'CL-RM',
				'arica y parinacota': 'CL-AP',
				'tarapaca': 'CL-TA',
				'antofagasta': 'CL-AN',
				'atacama': 'CL-AT',
				'coquimbo': 'CL-CO',
				'valparaiso': 'CL-VS',
				"o'higgins": 'CL-LI',
				'libertador general bernardo o\'higgins': 'CL-LI',
				'maule': 'CL-ML',
				'nuble': 'CL-NB',
				'biobio': 'CL-BI',
				'bio-bio': 'CL-BI',
				'la araucania': 'CL-AR',
				'araucania': 'CL-AR',
				'los rios': 'CL-LR',
				'los lagos': 'CL-LL',
				'aysen': 'CL-AI',
				'aysen del general carlos ibanez del campo': 'CL-AI',
				'magallanes': 'CL-MA',
				'magallanes y antartica chilena': 'CL-MA'
			};
			if (byName[normalized]) {
return byName[normalized];
			}
		}
		return null;
	}

	function setStateValue(prefix, code) {
		var stateEl = document.querySelector('#' + prefix + '_state, select[name="' + prefix + '_state"], input[name="' + prefix + '_state"]');
		if ( ! stateEl) {
return;
		}

		if (stateEl.tagName === 'SELECT') {
			var sufix = code.replace(/^CL-/, '');
			var matched = false;
			for (var i = 0; i < stateEl.options.length; i++) {
				var v = stateEl.options[i].value;
				if (v === code || v === sufix) {
					stateEl.selectedIndex = i;
					matched = true;
					break;
				}
			}
			if (matched) {
triggerChange(stateEl);
			}
			if (window.jQuery && jQuery.fn.select2) {
				try {
jQuery(stateEl).trigger('change'); } catch (e) {
/* noop */ }
			}
		} else {
			stateEl.value = code;
			triggerChange(stateEl);
		}
	}

	function triggerChange(el) {
		el.dispatchEvent(new Event('input',  { bubbles: true }));
		el.dispatchEvent(new Event('change', { bubbles: true }));
		if (window.jQuery) {
			try {
jQuery(el).trigger('change'); } catch (e) {
/* noop */ }
		}
	}

	// Registrar lazy-trigger inmediatamente al cargar el JS.
	// El script de Google Maps solo se descarga cuando el usuario enfoca un
	// input de dirección por primera vez. Hasta entonces, LCP del checkout
	// no arrastra el peso de la Maps JS API.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', setupLazyTrigger);
	} else {
		setupLazyTrigger();
	}
})();

/**
 * IIFE auxiliares del checkout:
 *  1. Limpiar `city` cuando cambia `state` (evita comuna residual tipo "Alhué").
 *  2. Bloquear `state` y `city` al seleccionar "Retiro Metro San Miguel".
 *  3. Auto-capitalizar `address_1` y `city` al perder foco.
 */
(function () {
	'use strict';
	if ( ! window.jQuery) {
return;
	}

	jQuery(function ($) {
		$(document.body).on('change', '#billing_state, #shipping_state', function () {
			var prefix = this.id.replace('_state', '');
			var $city = $('#' + prefix + '_city');
			if ($city.length && $city.val() !== '') {
				$city.val('').trigger('change');
			}
		});
	});
})();

(function () {
	'use strict';
	if ( ! window.jQuery) {
return;
	}

	var PICKUP_STATE = 'CL-RM';
	var PICKUP_CITY  = 'San Miguel';
	var LOCK_ATTR    = 'data-akb-pickup-locked';

	function isPickupSelected() {
		var val = jQuery('input.shipping_method:checked').val() || '';
		return val.toLowerCase().indexOf('local_pickup') === 0;
	}

	function lockFields($state, $city) {
		if ($state.attr(LOCK_ATTR) === '1') {
return;
		}
		$state.attr(LOCK_ATTR, '1');
		$city.attr(LOCK_ATTR, '1');

		if ($state.val() !== PICKUP_STATE) {
			$state.val(PICKUP_STATE).trigger('change');
		}
		setTimeout(function () {
			if ($city.val() !== PICKUP_CITY) {
				$city.val(PICKUP_CITY).trigger('change');
			}
		}, 300);

		$state.closest('.form-row').addClass('akb-field--locked');
		$city.closest('.form-row').addClass('akb-field--locked');
	}

	function unlockFields($state, $city) {
		if ($state.attr(LOCK_ATTR) !== '1') {
return;
		}
		$state.removeAttr(LOCK_ATTR);
		$city.removeAttr(LOCK_ATTR);
		$state.closest('.form-row').removeClass('akb-field--locked');
		$city.closest('.form-row').removeClass('akb-field--locked');
	}

	function apply() {
		var $state = jQuery('#billing_state').length ? jQuery('#billing_state') : jQuery('#shipping_state');
		var $city  = jQuery('#billing_city').length ? jQuery('#billing_city') : jQuery('#shipping_city');
		if ( ! $state.length || ! $city.length) {
return;
		}

		if (isPickupSelected()) {
			lockFields($state, $city);
		} else {
			unlockFields($state, $city);
		}
	}

	jQuery(function ($) {
		$(document.body).on('change updated_checkout', 'input.shipping_method', apply);
		$(document.body).on('updated_checkout', apply);
		apply();
	});
})();

/**
 * Auto-capitalización real de address_1 y city al perder foco o submit.
 * Complementa el `text-transform: capitalize` del CSS (que solo afecta
 * visualmente) para que el valor enviado al server también esté formateado.
 */
(function () {
	'use strict';
	if ( ! window.jQuery) {
return;
	}

	function capitalizeWords(s) {
		if ( ! s) {
return s;
		}
		return s.replace(/\S+/g, function (w) {
			// Respeta abreviaciones conocidas (Av., N°, etc.) y números.
			if (/^\d/.test(w)) {
return w;
			}
			if (/^(av|sr|sra|dr|dra|depto|n°)\.?$/i.test(w)) {
				return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
			}
			return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
		});
	}

	var SELECTORS = '#billing_address_1, #shipping_address_1, #billing_city, #shipping_city';
	jQuery(function ($) {
		$(document.body).on('blur', SELECTORS, function () {
			var v = this.value;
			var fixed = capitalizeWords(v);
			if (fixed !== v) {
				this.value = fixed;
			}
		});
	});
})();
