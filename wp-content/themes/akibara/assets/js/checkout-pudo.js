/**
 * Akibara — Checkout PUDO selector
 *
 * Controla el selector "Modo de entrega" (domicilio / retiro en punto
 * Blue Express) integrado en el paso 2 del checkout, y la comunicación
 * con el iframe `widget-pudo.blue.cl` para capturar el punto elegido.
 */
(function ($) {
	'use strict';

	var $root,
		$options,
		$mapWrap,
		$iframe,
		$iframeWrap,
		$selected,
		$isPudoInput,
		$agencyInput,
		$shippingBlueInput,
		widgetUrl,
		prePudoBillingSnapshot = null,
		hasPudoAutofill = false;

	function init() {
		$root = $('#aki-pudo');
		if (!$root.length) {
			return;
		}

		$options           = $root.find('input[name="akibara_delivery_mode"]');
		$mapWrap           = $('#aki-pudo-map');
		$iframe            = $('#aki-pudo-iframe');
		$iframeWrap        = $('#aki-pudo-iframe-wrap');
		$selected          = $('#aki-pudo-selected');
		$isPudoInput       = $('#isPudoSelected');
		$agencyInput       = $('#agencyId');
		$shippingBlueInput = $('#shippingBlue');
		widgetUrl          = $iframe.data('widget-url') || 'https://widget-pudo.blue.cl';

		cleanupStalePudoAutofill();
		refreshMetroEligibility();
		refreshMetroAddressFields();

		$options.on('change', onModeChange);
		$(document).on('click', '#aki-pudo-change', onChangePoint);
		$(document).on('change', '#billing_state, #shipping_state', refreshMetroEligibility);

		// Listener de negocio (mismo que el global pero con lógica).
		window.addEventListener('message', onWidgetMessage, false);

		// A6 (a11y Round 3) — Escape handler + focus trap.
		// El iframe widget-pudo.blue.cl es third-party (no controlable
		// internamente), así que el escape/trap se monta en el wrapper
		// del panel. Decisión: NO interceptamos focus dentro del iframe
		// (cross-origin, browser nos lo prohibe igual); solo manejamos
		// el "salir del panel hacia atrás" y el cierre con Esc.
		$(document).on('keydown', onPudoKeydown);

		// Si el checkout refresca (update_checkout), re-bind porque el DOM
		// del step 2 no se reemplaza, pero por seguridad re-evaluamos.
		// Los inputs hidden persisten el estado entre updates, no necesitamos
		// re-render de DOM aquí.
		$(document.body).on('updated_checkout', function () {
			refreshMetroEligibility();
			refreshMetroAddressFields();
		});

		// Después de cada actualización de WooCommerce, aplicar nuestra
		// política de selección de shipping method.
		$(document.body).on('updated_shipping_method updated_checkout', enforceShippingMethodPolicy);

		// Aplicar policy al cargar la página.
		setTimeout(enforceShippingMethodPolicy, 500);
	}

	/**
	 * Obtiene el modo actual desde `data-delivery-mode` del contenedor.
	 * Valores posibles: 'home' | 'pudo' | 'metro'.
	 */
	function getCurrentMode() {
		return ($root && $root.attr('data-delivery-mode')) || 'home';
	}

	/**
	 * El retiro gratis metro San Miguel solo aplica para Región
	 * Metropolitana (CL-RM).
	 */
	function isMetroEligible() {
		var states = [
			($('#billing_state').val() || '').toString().trim().toUpperCase(),
			($('#shipping_state').val() || '').toString().trim().toUpperCase()
		];

		for (var i = 0; i < states.length; i++) {
			if (states[i] === 'CL-RM' || states[i] === 'RM') {
				return true;
			}
		}

		return false;
	}

	/**
	 * UX Metro: marcar dirección/comuna como OPCIONALES (no ocultas)
	 * cuando el cliente elige retiro gratis en metro San Miguel.
	 *
	 * Los valores se conservan (no se borran). Se agrega clase visual
	 * `.aki-metro-optional` para atenuarlos y sacar el asterisco rojo,
	 * pero el usuario puede seguir editándolos si quiere (útil para
	 * datos de facturación o un eventual cambio de modo a domicilio).
	 */
	function refreshMetroAddressFields() {
		if (!$root || !$root.length) {
			return;
		}

		var isMetro = getCurrentMode() === 'metro';
		var fieldKeys = ['billing_address_1', 'billing_city', 'billing_address_2'];

		for (var i = 0; i < fieldKeys.length; i++) {
			var key = fieldKeys[i];
			var $field = $('#' + key + '_field');
			var $input = $('#' + key);

			if (!$field.length || !$input.length) {
				continue;
			}

			if (typeof $field.data('aki-required-original') === 'undefined') {
				var wasRequired = $input.prop('required') || $field.hasClass('validate-required');
				$field.data('aki-required-original', wasRequired ? '1' : '0');
			}

			// Asegurar que NO queden ocultos por implementaciones viejas
			// (versiones previas setteaban attr 'hidden').
			$field.removeAttr('hidden').removeClass('aki-metro-hidden');

			if (isMetro) {
				$field.addClass('aki-metro-optional').removeClass('validate-required');
				$input.prop('required', false).attr('aria-required', 'false');
			} else {
				$field.removeClass('aki-metro-optional');

				var shouldBeRequired = String($field.data('aki-required-original')) === '1';
				$input.prop('required', shouldBeRequired);
				if (shouldBeRequired) {
					$field.addClass('validate-required');
					$input.attr('aria-required', 'true');
				}
			}
		}
	}

	/**
	 * Sincronizar eligibilidad Metro.
	 *
	 * La UI visible vive en el unified grid (ship-grid.js decide mostrar
	 * la alt-card "Retiro gratis metro San Miguel" según si el server
	 * devuelve el rate `local_pickup:70`). Aquí solo:
	 *  1. Mantenemos el radio hidden `metro` como disabled si no aplica.
	 *  2. Hacemos fallback a `home` si el usuario estaba en Metro y la
	 *     región deja de ser RM (cambio de dirección durante checkout).
	 */
	function refreshMetroEligibility() {
		if (!$root || !$root.length) {
			return false;
		}

		var eligible = isMetroEligible();
		var $metroInput = $root.find('input[name="akibara_delivery_mode"][value="metro"]');

		if ($metroInput.length) {
			$metroInput.prop('disabled', !eligible).attr('aria-disabled', eligible ? 'false' : 'true');
		}

		if (eligible) {
			return true;
		}

		closeMetro();

		if (getCurrentMode() === 'metro') {
			var $homeMode = $root.find('input[name="akibara_delivery_mode"][value="home"]');
			if ($homeMode.length) {
				$homeMode.prop('checked', true);
				onModeChange.call($homeMode.get(0));
			}
		}

		return false;
	}

	/**
	 * Política de selección del shipping method según el modo elegido
	 * en nuestro selector "Modo de entrega":
	 *
	 *  - home  → seleccionar `bluex-ex*` y ocultar `local_pickup`.
	 *            (El cliente quiere envío a domicilio con Blue Express.)
	 *  - pudo  → seleccionar `bluex-ex*` y ocultar `local_pickup`.
	 *            (El cliente retirará en un punto Blue Express.)
	 *  - metro → seleccionar `local_pickup:70` y ocultar `bluex-ex`.
	 *            (El cliente retira gratis en metro San Miguel.)
	 *
	 * Esta política se aplica en cada `updated_checkout` de WC para evitar
	 * que WooCommerce resetee la selección a un default distinto.
	 */
	function enforceShippingMethodPolicy() {
		var mode = getCurrentMode();
		var $methods = $('input[name^="shipping_method"]');
		if (!$methods.length) return;

		var $blueEx      = $methods.filter('[value^="bluex-ex"]').first();
		var $localPickup = $methods.filter('[value^="local_pickup"]').first();
		var $allBluex    = $methods.filter('[value^="bluex-ex"]');
		var $allLocal    = $methods.filter('[value^="local_pickup"]');
		var agencyId     = ($agencyInput.val() || '').trim();
		var metroEligible = isMetroEligible();

		// Unified grid mode: todos los li se muestran siempre.
		// La elección visual vive en el enhancer (checkout-shipping-enhancer.js).
		// Acá solo manejamos fallback a home si Metro no aplica + validaciones.

		if (mode === 'metro' && !metroEligible) {
			var $homeModeInput = $root.find('input[name="akibara_delivery_mode"][value="home"]');
			if ($homeModeInput.length) $homeModeInput.prop('checked', true);
			mode = 'home';
			$root.attr('data-delivery-mode', 'home');
			closeMetro();
		}

		if (mode === 'metro') {
			if ($localPickup.length && !$localPickup.prop('checked')) {
				$localPickup.prop('checked', true).trigger('change');
			}
			setSidebarPudoPending(false);
			markUnchosenInSidebar();
			return;
		}

		if (mode === 'sameday') {
			var $sameDay = $methods.filter('[value^="12horas"]').first();
			if ($sameDay.length && !$sameDay.prop('checked')) {
				$sameDay.prop('checked', true).trigger('change');
			}
			setSidebarPudoPending(false);
			markUnchosenInSidebar();
			return;
		}

		if (mode === 'pudo') {
			if (!agencyId) {
				// Marcar pending, pero NO descheckear para preservar la selección en la grid.
				setSidebarPudoPending(true);
			} else {
				if ($blueEx.length && !$blueEx.prop('checked')) {
					$blueEx.prop('checked', true).trigger('change');
				}
				setSidebarPudoPending(false);
			}
			markUnchosenInSidebar();
			return;
		}

		// mode === 'home': el enhancer ya asegura que el mejor método quede
		// seleccionado.
		setSidebarPudoPending(false);
		markUnchosenInSidebar();
	}

	/**
	 * En el sidebar, reemplazar la fila de envío por un aviso cuando el
	 * cliente está en modo PUDO pero no ha elegido un punto aún.
	 */
	function setSidebarPudoPending(isPending) {
		var $row = $('#order_review tr.woocommerce-shipping-totals');
		if (!$row.length) return;

		if (isPending) {
			$row.addClass('aki-pudo-pending');
			var $td = $row.find('td');
			if (!$td.find('.aki-pudo-pending-msg').length) {
				$td.prepend('<span class="aki-pudo-pending-msg">Selecciona un punto en el mapa para calcular el envío</span>');
			}
		} else {
			$row.removeClass('aki-pudo-pending');
			$row.find('.aki-pudo-pending-msg').remove();
		}
	}

	/**
	 * En el `#order_review` del sidebar, marcar con clase `is-not-chosen`
	 * los `<li>` cuyo radio NO está checked. El CSS usa esta clase para
	 * ocultarlos en navegadores que no soportan `:has()`.
	 */
	function markUnchosenInSidebar() {
		$('#order_review #shipping_method li').each(function () {
			var $li = $(this);
			var $input = $li.find('input[type="radio"]');
			if ($input.length && !$input.prop('checked')) {
				$li.addClass('is-not-chosen');
			} else {
				$li.removeClass('is-not-chosen');
			}
		});
	}

	function onModeChange() {
		var mode = $(this).val(); // 'home' | 'pudo' | 'metro'
		clearPudoStepErrorUI();

		if (mode === 'metro' && !isMetroEligible()) {
			var $homeMode = $root.find('input[name="akibara_delivery_mode"][value="home"]');
			if ($homeMode.length) {
				$homeMode.prop('checked', true);
				onModeChange.call($homeMode.get(0));
				return;
			}
		}

		$root.attr('data-delivery-mode', mode);

		if (mode === 'pudo') {
			openMap();
			closeMetro();
		} else if (mode === 'metro') {
			openMetro();
			closeMap();
			clearSelection();
			// No restauramos snapshot al entrar a Metro: los campos
			// son opcionales en metro, y si el usuario no tenía
			// dirección previa (snapshot vacío) se borraría el
			// autofill del PUDO dejando al cliente confundido.
			// Preferimos conservar lo que haya.
		} else {
			closeMap();
			closeMetro();
			clearSelection();
			restoreBillingSnapshot();
		}

		enforceShippingMethodPolicy();
		refreshMetroAddressFields();
		triggerCheckoutUpdate();
	}

	function openMetro() {
		$('#aki-pudo-metro').addClass('is-open');
	}

	function closeMetro() {
		$('#aki-pudo-metro').removeClass('is-open');
	}

	function onChangePoint(e) {
		e.preventDefault();
		clearSelection();
		restoreBillingSnapshot();
		$iframeWrap.removeAttr('hidden');
		// Re-mostrar el panel de value prop para convencer en el cambio.
		$('.aki-pudo__features').removeAttr('hidden');
		if ($selected.length) {
			$selected.remove();
		}
	}

	function openMap() {
		$mapWrap.addClass('is-open');
		$isPudoInput.val('pudoShipping');
		$shippingBlueInput.val('pudoShipping');

		// Cargar el iframe solo cuando se abre (lazy).
		if ($iframe.attr('src') === 'about:blank' || !$iframe.attr('src')) {
			$iframe.attr('src', widgetUrl);
		}

		// A6 (a11y Round 3): convertir el wrapper del iframe en dialog
		// modal accesible. Permite que screen readers anuncien el cambio
		// de contexto y habilita el Escape handler abajo.
		if ($iframeWrap.length) {
			$iframeWrap
				.attr('role', 'dialog')
				.attr('aria-modal', 'true')
				.attr('aria-label', 'Selector de punto Blue Express')
				.attr('tabindex', '-1');
		}
	}

	function closeMap() {
		$mapWrap.removeClass('is-open');
		$isPudoInput.val('');
		$shippingBlueInput.val('normalShipping');
		hideAutofillNotice();

		// A6 (a11y Round 3): limpiar atributos de dialog al cerrar para
		// que el wrapper vuelva a su estado pasivo (no atrapa focus,
		// no anuncia como modal).
		if ($iframeWrap.length) {
			$iframeWrap
				.removeAttr('role')
				.removeAttr('aria-modal')
				.removeAttr('aria-label')
				.removeAttr('tabindex');
		}
	}

	/**
	 * A6 (a11y Round 3) — Escape handler para cerrar el panel PUDO.
	 *
	 * Cuando el panel está abierto y el usuario presiona Esc, cerramos
	 * el panel cambiando el delivery mode de vuelta a 'home' y devolvemos
	 * el focus al hero del unified grid (la card que el usuario clickeó
	 * para entrar al modo PUDO).
	 *
	 * Nota: el iframe del widget es cross-origin (widget-pudo.blue.cl),
	 * así que el browser bloquea Esc forwarding y key events dentro del
	 * iframe nunca llegan a este handler. Solución pragmática: el usuario
	 * Tab fuera del iframe (Shift+Tab → vuelve al wrapper o al body),
	 * desde ahí Esc cierra. Esto es consistente con cómo Apple/Stripe
	 * resuelven iframes embebidos third-party.
	 */
	function onPudoKeydown(event) {
		if (event.key !== 'Escape' && event.keyCode !== 27) {
			return;
		}

		// Solo cuando el panel está abierto. Sin esto rompemos el Esc
		// global del checkout (cerrar modales hermanos, etc).
		if (!$mapWrap || !$mapWrap.length || !$mapWrap.hasClass('is-open')) {
			return;
		}

		// Evitar que otros handlers también reaccionen al mismo Esc.
		event.preventDefault();
		event.stopPropagation();

		// Volver a modo 'home' (el más seguro: domicilio normal).
		var $homeMode = $root.find('input[name="akibara_delivery_mode"][value="home"]');
		if ($homeMode.length) {
			$homeMode.prop('checked', true);
			onModeChange.call($homeMode.get(0));
		}

		// Focus return: a la card del unified grid que disparó el modo
		// PUDO. Si no existe (grid no renderizó aún), focus al heading
		// del paso 2 como fallback razonable.
		var $trigger = $('[data-mode="pudo"]').first();
		if (!$trigger.length) {
			$trigger = $('.aki-step[data-step="2"] .aki-step__title').first();
		}
		if ($trigger.length && typeof $trigger.get(0).focus === 'function') {
			$trigger.get(0).focus();
		}
	}

	function clearSelection() {
		$agencyInput.val('');
		hideAutofillNotice();
	}

	/**
	 * Captura mensajes postMessage del iframe del widget PUDO.
	 *
	 * Formato esperado (confirmado en el JS original del plugin
	 * `custom-checkout-map.js`):
	 *   {
	 *     type: 'pudo:select',
	 *     payload: {
	 *       agency_id: '...',
	 *       agency_name: '...',
	 *       location: {
	 *         street_name, street_number, city_name,
	 *         state_name, country_name
	 *       }
	 *     }
	 *   }
	 */
	function onWidgetMessage(event) {
		if (!event || !event.data) {
			return;
		}

		// Seguridad básica: aceptar solo del dominio del widget.
		try {
			var origin = event.origin || '';
			if (origin.indexOf('blue.cl') === -1) {
				return;
			}
		} catch (e) {
			return;
		}

		var data = event.data;
		if (typeof data === 'string') {
			try { data = JSON.parse(data); } catch (err) { return; }
		}

		// Aceptar pudo:select (formato del plugin original) y
		// variantes alternativas que algunas versiones del widget emiten.
		var validTypes = ['pudo:select', 'pudo:selected', 'pudoSelected', 'onPudoSelect'];
		if (validTypes.indexOf(data.type) === -1) {
			return;
		}

		var payload  = data.payload || {};
		var location = payload.location || {};

		var agencyId   = payload.agency_id || payload.agencyId;
		var agencyName = payload.agency_name || payload.name || '';

		if (!agencyId) {
			return;
		}

		// Replicar el comportamiento del plugin oficial:
		// al seleccionar un PUDO, rellenamos los campos de envío con
		// la ubicación del punto (no del cliente) para que el pricing
		// se calcule correctamente contra esa comuna.
		fillBillingFromLocation(location, agencyName);

		var address = '';
		if (location.street_name) {
			address = [location.street_name, location.street_number].filter(Boolean).join(' ');
			if (location.city_name) {
				address += ', ' + location.city_name;
			}
		}

		selectAgency(agencyId, agencyName, address);
	}

	/**
	 * Completar los campos billing_* con la ubicación del punto PUDO
	 * para que WooCommerce recalcule el shipping contra esa comuna.
	 */
	function fillBillingFromLocation(loc, agencyName) {
		if (!loc) return;

		captureBillingSnapshot();

		var $addr1 = $('#billing_address_1');
		var $addr2 = $('#billing_address_2');
		var $city  = $('#billing_city');
		var $state = $('#billing_state');

		if ($addr1.length && loc.street_name) {
			$addr1.val([loc.street_name, loc.street_number].filter(Boolean).join(' '));
		}
		if ($addr2.length && agencyName) {
			$addr2.val(agencyName);
		}
		if ($city.length && loc.city_name) {
			$city.val(loc.city_name);
		}
		if ($state.length && loc.state_name) {
			var stateCode = mapStateNameToCode(loc.state_name);
			if (stateCode) {
				$state.val(stateCode).trigger('change');
			}
		}

		hasPudoAutofill = true;

		showAutofillNotice(agencyName);
	}

	/**
	 * Muestra una nota explicativa arriba de los campos de dirección
	 * indicando que se llenaron automáticamente con el PUDO elegido.
	 */
	function showAutofillNotice(agencyName) {
		var $notice = $('#aki-pudo-autofill-notice');
		var label   = agencyName || 'el punto seleccionado';
		var html    = '<div id="aki-pudo-autofill-notice" class="aki-pudo-notice" role="status">' +
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>' +
			'<span>Los campos de dirección se llenaron con la ubicación de <strong>' + escapeHtml(label) + '</strong>. Si quieres cambiar de punto, haz click en <em>Cambiar</em> arriba.</span>' +
			'</div>';

		if ($notice.length) {
			$notice.replaceWith(html);
		} else {
			var $fields = $('.aki-step[data-step="2"] .aki-step__fields').first();
			if ($fields.length) {
				$fields.before(html);
			}
		}
	}

	function hideAutofillNotice() {
		$('#aki-pudo-autofill-notice').remove();
	}

	function clearPudoStepErrorUI() {
		$('#aki-pudo').removeClass('aki-pudo--invalid');
		$('#aki-pudo-step-error').remove();
	}

	function cleanupStalePudoAutofill() {
		var mode     = getCurrentMode();
		var agencyId = ($agencyInput.val() || '').trim();

		if (mode === 'pudo' || agencyId) {
			return;
		}

		var $addr1 = $('#billing_address_1');
		var $addr2 = $('#billing_address_2');
		var $city  = $('#billing_city');
		var $state = $('#billing_state');
		var addr2  = ($addr2.val() || '').toLowerCase();

		// Si no hay PUDO activo pero el checkout trae restos del último
		// punto seleccionado, limpiar para evitar confundir al cliente.
		if (addr2.indexOf('punto blue express') === -1) {
			return;
		}

		if ($addr1.length) {
			$addr1.val('');
		}
		if ($addr2.length) {
			$addr2.val('');
		}
		if ($city.length) {
			$city.val('');
		}
		if ($state.length) {
			$state.val('').trigger('change');
		}

		hideAutofillNotice();
	}

	function captureBillingSnapshot() {
		if (prePudoBillingSnapshot) return;

		prePudoBillingSnapshot = {
			address1: $('#billing_address_1').val() || '',
			address2: $('#billing_address_2').val() || '',
			city: $('#billing_city').val() || '',
			state: $('#billing_state').val() || ''
		};
	}

	function restoreBillingSnapshot() {
		if (!hasPudoAutofill || !prePudoBillingSnapshot) return;

		var snap = prePudoBillingSnapshot;

		// Si el snapshot está vacío (el usuario no tenía dirección
		// llenada antes de seleccionar PUDO), NO restaurar: sería
		// borrar el autofill del PUDO y dejar todos los campos
		// vacíos. Mejor conservar lo que hay.
		var snapIsEmpty = !String(snap.address1 || '').trim()
			&& !String(snap.city || '').trim()
			&& !String(snap.state || '').trim();

		if (snapIsEmpty) {
			hasPudoAutofill = false;
			prePudoBillingSnapshot = null;
			return;
		}

		var $addr1 = $('#billing_address_1');
		var $addr2 = $('#billing_address_2');
		var $city  = $('#billing_city');
		var $state = $('#billing_state');

		if ($addr1.length) {
			$addr1.val(snap.address1);
		}
		if ($addr2.length) {
			$addr2.val(snap.address2);
		}
		if ($city.length) {
			$city.val(snap.city);
		}
		if ($state.length) {
			$state.val(snap.state).trigger('change');
		}

		hasPudoAutofill = false;
		prePudoBillingSnapshot = null;
	}

	/**
	 * Mapa reducido de nombres de región → códigos CL-XX que usa WC.
	 * Basado en el listado del plugin oficial.
	 */
	function mapStateNameToCode(name) {
		if (!name) return '';
		var n = ('' + name).toLowerCase();
		var map = {
			'metropolitana': 'CL-RM',
			'santiago': 'CL-RM',
			'región metropolitana': 'CL-RM',
			'valparaíso': 'CL-VS',
			'valparaiso': 'CL-VS',
			'biobío': 'CL-BI',
			'biobio': 'CL-BI',
			'maule': 'CL-ML',
			'ñuble': 'CL-NB',
			'nuble': 'CL-NB',
			'araucanía': 'CL-AR',
			'araucania': 'CL-AR',
			'los ríos': 'CL-LR',
			'los rios': 'CL-LR',
			'los lagos': 'CL-LL',
			'aysén': 'CL-AI',
			'aysen': 'CL-AI',
			'magallanes': 'CL-MA',
			'coquimbo': 'CL-CO',
			'atacama': 'CL-AT',
			'antofagasta': 'CL-AN',
			'tarapacá': 'CL-TA',
			'tarapaca': 'CL-TA',
			'arica y parinacota': 'CL-AP',
			"libertador general bernardo o'higgins": 'CL-LI',
			"o'higgins": 'CL-LI'
		};
		return map[n] || '';
	}

	function selectAgency(agencyId, name, address) {
		$agencyInput.val(agencyId);
		$isPudoInput.val('pudoShipping');
		$shippingBlueInput.val('pudoShipping');
		clearPudoStepErrorUI();

		// Render badge de punto seleccionado.
		var $badge = $('#aki-pudo-selected');
		var label  = name ? name : 'Punto #' + agencyId;
		if (address) {
			label += ' — ' + address;
		}

		var html = '<div class="aki-pudo__selected" id="aki-pudo-selected">' +
			'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>' +
			'<span>' + escapeHtml(label) + '</span>' +
			'<button type="button" class="aki-pudo__change" id="aki-pudo-change">Cambiar</button>' +
			'</div>';

		if ($badge.length) {
			$badge.replaceWith(html);
		} else {
			$mapWrap.prepend(html);
		}
		$iframeWrap.attr('hidden', 'hidden');
		// Ocultar panel de value prop: ya convencimos, el cliente eligió.
		// Se re-muestra si hace click en "Cambiar" (ver onChangePoint).
		$('.aki-pudo__features').attr('hidden', 'hidden');

		triggerCheckoutUpdate();
	}

	function triggerCheckoutUpdate() {
		$(document.body).trigger('update_checkout');
	}

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	$(document).ready(init);
})(jQuery);
