/**
 * Akibara — Ship Grid: hero card + accordion grid rendering, rate detection,
 * selection sync and refresh orchestration.
 * Loaded before ship-free-progress, ship-pudo-map, ship-tracking and the
 * checkout-shipping-enhancer orchestrator.
 * Part of checkout-shipping-enhancer split (ARQ-3).
 */
(function ($) {
	'use strict';

	window.AkibaraShipping = window.AkibaraShipping || {};
	var S = window.AkibaraShipping;

	var CFG = S.cfg = window.akibaraCheckoutShipping || {};
	var UNIFIED_GRID = CFG.unifiedGrid !== false;
	var listObserver = null;
	var virtualNormalizeLock = false;
	var accordionOpen = false;

	var MONTHS_SHORT = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

	// ═══════════════════════════════════════════════════════════════
	// Helpers
	// ═══════════════════════════════════════════════════════════════

	function isAddressComplete() {
		var s = ($('#billing_state').val() || $('#shipping_state').val() || '').toString().trim();
		var c = ($('#billing_city').val() || $('#shipping_city').val() || '').toString().trim();
		var a = ($('#billing_address_1').val() || $('#shipping_address_1').val() || '').toString().trim();
		return !!(s && c && a);
	}

	function nextBusinessDay(from, days) {
		var d = new Date(from.getTime());
		var added = 0;
		while (added < days) {
			d.setDate(d.getDate() + 1);
			var dow = d.getDay();
			if (dow !== 0 && dow !== 6) added++;
		}
		return d;
	}

	function warmLabel(target, now) {
		var base = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
		var t    = new Date(target.getFullYear(), target.getMonth(), target.getDate()).getTime();
		var diff = Math.round((t - base) / 86400000);
		if (diff === 0) return 'hoy';
		if (diff === 1) return 'mañana';
		// Siempre incluir día + fecha para evitar ambigüedad:
		// "el jueves" deja al usuario preguntándose ¿qué jueves?.
		// "el jueves 23 de abr" es inequívoco sin ser verboso.
		var full = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
		return 'el ' + full[target.getDay()] + ' ' + target.getDate() + ' ' + MONTHS_SHORT[target.getMonth()];
	}

	function iconSvg(type) {
		switch (type) {
			case 'truck':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>';
			case 'pin':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 7-8 12-8 12s-8-5-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';
			case 'store':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 2-5h16l2 5"/><path d="M2 7h20v4a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-2-2.83"/><path d="M4 12v9h16v-9"/><path d="M10 21V12h4v9"/></svg>';
			case 'check':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
			case 'chevron':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
			case 'gift':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/></svg>';
		}
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="m3 7 3-4h12l3 4"/></svg>';
	}

	// ═══════════════════════════════════════════════════════════════
	// Rate type detection & definitions
	// ═══════════════════════════════════════════════════════════════

	function detectRateType(methodValue) {
		var v = (methodValue || '').toLowerCase();
		if (v.indexOf('bluex') === 0)       return 'bluex';
		if (v.indexOf('local_pickup') === 0) return 'pickup';
		if (v.indexOf('12horas') === 0)     return 'sameday';
		return 'other';
	}

	// Cutoff 13:30 hora Chile, L-S. Domingo no hay operación.
	// Se usa `Intl.DateTimeFormat` con timezone Santiago para evitar
	// que el reloj del dispositivo del usuario nos dé respuestas erradas.
	function isSameDayAvailable() {
		try {
			var parts = new Intl.DateTimeFormat('en-US', {
				timeZone: 'America/Santiago',
				weekday:  'short',
				hour:     'numeric',
				minute:   'numeric',
				hour12:   false
			}).formatToParts(new Date());
			var map = {};
			parts.forEach(function (p) { map[p.type] = p.value; });
			if (map.weekday === 'Sun') return false;
			var cutoff = 13 * 60 + 30;
			var now    = (parseInt(map.hour, 10) || 0) * 60 + (parseInt(map.minute, 10) || 0);
			return now < cutoff;
		} catch (e) {
			return true; // fallback: permitir; WC rechazará si no aplica
		}
	}

	function findCourierMetadata(methodValue) {
		var couriers = CFG.couriers || {};
		var v = (methodValue || '');
		if (couriers[v]) return couriers[v];
		var colonIdx = v.indexOf(':');
		var prefix = colonIdx >= 0 ? v.substring(0, colonIdx) : v;
		if (couriers[prefix]) return couriers[prefix];
		return null;
	}

	function cardDefinition(key, methodValue) {
		if (key === 'other' && methodValue) {
			var meta = findCourierMetadata(methodValue);
			if (meta) {
				return {
					name:     meta.label || 'Envío',
					sub:      meta.tagline || '',
					icon:     null,
					iconSvg:  meta.iconSvg,
					badge:    meta.badge || null,
					pill:     meta.pill || null,
					mode:     'home',
					priority: (typeof meta.priority === 'number') ? meta.priority : 5,
					color:    meta.color
				};
			}
		}
		switch (key) {
			case 'pickup':
				return {
					name:    'Retiro gratis metro San Miguel',
					sub:     'Coordinamos por WhatsApp · Solo RM',
					icon:    'store',
					badge:   'GRATIS',
					mode:    'metro',
					priority: 0
				};
			case 'bluex-home':
				return {
					name:    'Envío Blue Express',
					sub:     'A tu domicilio',
					icon:    'truck',
					badge:   null,
					mode:    'home',
					priority: 1
				};
			case 'bluex-pudo':
				return {
					name:    'Retiro en punto Blue Express',
					sub:     '+3.200 puntos · retira cuando quieras',
					icon:    'pin',
					badge:   null,
					mode:    'pudo',
					priority: 2
				};
			default:
				return { name: methodValue || 'Envío', sub: '', icon: 'box', badge: null, mode: 'home', priority: 9 };
		}
	}

	function etaFor(key) {
		var now = new Date();
		if (key === 'bluex-home' || key === 'bluex-pudo') {
			return 'Llega ' + warmLabel(nextBusinessDay(now, 2), now);
		}
		if (key === 'pickup') {
			return 'Coordinar por WhatsApp';
		}
		return '';
	}

	// Formato moneda Chile: "$2.600" (punto separador miles, sin decimales).
	function formatClp(n) {
		var val = Math.round(Number(n) || 0);
		return '$' + val.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
	}

	// ═══════════════════════════════════════════════════════════════
	// Subtotal helper (reads from sidebar)
	// ═══════════════════════════════════════════════════════════════

	function getCartSubtotal() {
		var $subtotalCell = $('#order_review .cart-subtotal .woocommerce-Price-amount').first();
		if (!$subtotalCell.length) return 0;
		var txt = $subtotalCell.text().replace(/[^0-9]/g, '');
		return parseInt(txt || '0', 10) || 0;
	}

	function isFreeShipping() {
		var threshold = parseInt(CFG.freeShippingThreshold || 55000, 10);
		return threshold > 0 && getCartSubtotal() >= threshold;
	}

	// ═══════════════════════════════════════════════════════════════
	// Rendering
	// ═══════════════════════════════════════════════════════════════

	function getContainer() {
		return $('#aki-shipping-methods .aki-shipping-inner').first();
	}

	function renderPlaceholder($container) {
		if ($container.find('.aki-ship-placeholder').length) return;
		$container.find('#shipping_method, .aki-ship-regional, .aki-ship-hero-wrap').hide();
		$container.append([
			'<div class="aki-ship-placeholder">',
			'  <div class="aki-ship-placeholder__icon">' + iconSvg('pin') + '</div>',
			'  <div class="aki-ship-placeholder__body">',
			'    <strong>Completa tu dirección para ver las opciones de envío</strong>',
			'    <span>Ingresa región, comuna y dirección arriba y te mostraremos todas las alternativas.</span>',
			'  </div>',
			'</div>'
		].join(''));
	}

	function clearPlaceholder($container) {
		$container.find('.aki-ship-placeholder').remove();
		$container.find('#shipping_method, .woocommerce-shipping-methods').show();
	}

	// ═══════════════════════════════════════════════════════════════
	// Hero card builder
	// ═══════════════════════════════════════════════════════════════

	function buildHeroHtml(homeCost, etaText) {
		var free = isFreeShipping() || homeCost <= 0;
		var priceHtml = free
			? '<span class="aki-ship-hero__price-free">GRATIS</span>'
			: '<span class="aki-ship-hero__price-amount">' + formatClp(homeCost) + '</span>';

		var detailsHtml = [
			'<div class="aki-ship-hero__details">',
			'  <span class="aki-ship-hero__detail">' + iconSvg('check') + ' Seguimiento en línea</span>',
			'  <span class="aki-ship-hero__detail">' + iconSvg('check') + ' Cobertura nacional</span>',
			'  <span class="aki-ship-hero__detail">' + iconSvg('check') + ' Empaque con protección</span>',
			'</div>'
		].join('');

		var upsellHtml = '';

		var badgeHtml = free
			? '<span class="aki-ship-hero__badge">Recomendado</span>'
			: '';

		return [
			'<div class="aki-ship-hero' + (free ? ' aki-ship-hero--free' : ' aki-ship-hero--paid') + '">',
			  badgeHtml,
			'  <div class="aki-ship-hero__row">',
			'    <div class="aki-ship-hero__icon">' + iconSvg('truck') + '</div>',
			'    <div class="aki-ship-hero__body">',
			'      <div class="aki-ship-hero__title">',
			'        <span>Envío Blue Express</span>',
			'        <span class="aki-ship-hero__price">' + priceHtml + '</span>',
			'      </div>',
			'      <div class="aki-ship-hero__eta">' + etaText + ' a tu domicilio</div>',
			         detailsHtml,
			'    </div>',
			'  </div>',
			   upsellHtml,
			'</div>'
		].join('');
	}

	// ═══════════════════════════════════════════════════════════════
	// Flat grid builder (Variante A — Sprint G6 reactivado 2026-04-20)
	//
	// Construye 3 cards uniformes visibles simultáneamente, sin accordion:
	//   1. Metro San Miguel (GRATIS) — first si RM · conversion anchor
	//   2. Retiro punto Blue Express — ahorro intermedio
	//   3. Envío Blue Express domicilio — default selected
	//
	// Patrón referencia: Falabella CL, Paris CL, MercadoLibre CL, Nike.
	// Motivo del refactor:
	//   - AUDIT_PROGRESS.md CO-14: usuarios RM no descubrían Metro gratis en accordion.
	//   - docs/skills/ux.md:64-67 pide shipping options visibles con precio+ETA.
	//   - docs/skills/branding.md:177 lista "Retira gratis en metro San Miguel"
	//     como microcopy canónico que debe ser visible, no escondido.
	// ═══════════════════════════════════════════════════════════════

	// Elige el modo a destacar como "Recomendado":
	// 1) Si hay envío gratis (threshold cumplido) → home (default gana y es gratis).
	// 2) Si Metro RM disponible → metro (gratis sobre resto).
	// 3) Si PUDO más barato que home → pudo (ahorra).
	// 4) Fallback → home.
	function pickRecommended(homeCost, pudoCost, hasMetro, hasPudo) {
		if (hasPudo === undefined) hasPudo = true;
		if (isFreeShipping()) return 'home';
		if (hasMetro) return 'metro';
		if (hasPudo && !isNaN(pudoCost) && !isNaN(homeCost) && pudoCost > 0 && homeCost > pudoCost) return 'pudo';
		return 'home';
	}

	// Construye atributos para una card del grid plano.
	// `extraClass` añade modificadores (ej. aki-ship-alt__card--recommended).
	// `badgeHtml` se posiciona absoluto sobre la card (slot flotante).
	function altCardHtml(mode, selected, iconHtml, name, sub, priceHtml, savingsHtml, extraClass, badgeHtml, priority) {
		var classes = 'aki-ship-alt__card';
		if (selected)     classes += ' is-selected';
		if (extraClass)   classes += ' ' + extraClass;

		return [
			'<div class="' + classes + '"',
			'     data-alt-mode="' + mode + '"',
			'     data-priority="' + (priority || 9) + '"',
			'     tabindex="0" role="radio" aria-checked="' + (selected ? 'true' : 'false') + '">',
			  badgeHtml || '',
			'  <span class="aki-ship-alt__radio" aria-hidden="true"></span>',
			'  <div class="aki-ship-alt__icon' + (mode === 'metro' ? ' aki-ship-alt__icon--metro' : (mode === 'pudo' ? ' aki-ship-alt__icon--pudo' : '')) + '">' + iconHtml + '</div>',
			'  <div class="aki-ship-alt__body">',
			'    <span class="aki-ship-alt__name">' + name + '</span>',
			'    <span class="aki-ship-alt__sub">' + sub + '</span>',
			'  </div>',
			'  <div class="aki-ship-alt__price">' + priceHtml + (savingsHtml || '') + '</div>',
			'</div>'
		].join('');
	}

	// homeInfo (opcional): { label, sub, iconKey, iconSvgRaw, badge } — si viene, la card home
	// refleja ese courier en vez del default BlueX. Permite que el hero layout siga
	// funcionando cuando el rate principal NO es BlueX (ej: 12 Horas en local, o fallback
	// en prod si la cotización BlueX falla).
	// hasPudo: si false, la card de Retiro en punto Blue Express se omite (PUDO es exclusivo BlueX).
	function buildShippingCardsHtml(homeCost, pudoCost, hasMetro, currentMode, homeInfo, hasPudo, sameDayInfo, sameDayCost) {
		if (hasPudo === undefined) hasPudo = true;
		var free        = isFreeShipping();
		var recommended = pickRecommended(homeCost, pudoCost, hasMetro, hasPudo);

		// Badge contextual — explica *por qué* esa opción es la recomendada
		// en vez de un genérico "Recomendado" que genera desconfianza.
		function makeBadge(mode) {
			var labels = {
				'metro': 'Más rápido y gratis',
				'pudo' : (!isNaN(pudoCost) && !isNaN(homeCost) && homeCost > pudoCost && pudoCost > 0)
					? 'Ahorras ' + formatClp(Math.round(homeCost - pudoCost))
					: 'Retiro flexible',
				'home' : free ? 'Envío gratis ganado' : 'Más cómodo'
			};
			return '<span class="aki-ship-alt__badge">' + (labels[mode] || 'Recomendado') + '</span>';
		}

		var cards = [];

		// ── Card 1: Metro San Miguel (solo RM) ──
		if (hasMetro) {
			cards.push(altCardHtml(
				'metro',
				currentMode === 'metro',
				iconSvg('store'),
				'Retiro gratis en Metro San Miguel',
				'Coordinamos por WhatsApp · Listo en 24h',
				'<span class="aki-ship-alt__price-val aki-ship-alt__price-val--free">GRATIS</span>',
				'', // savings extra: el "GRATIS" ya dice todo, evitamos redundancia "Ahorras 100%"
				recommended === 'metro' ? 'aki-ship-alt__card--recommended' : '',
				recommended === 'metro' ? makeBadge('metro') : '',
				0
			));
		}

		// ── Card 2: Retiro en punto Blue Express (PUDO) — solo si hay BlueX ──
		if (hasPudo) {
			var pudoPriceHtml, pudoSavingsHtml = '';
			if (free || pudoCost <= 0) {
				pudoPriceHtml   = '<span class="aki-ship-alt__price-val aki-ship-alt__price-val--free">GRATIS</span>';
				pudoSavingsHtml = '<span class="aki-ship-alt__savings">Retiro flexible</span>';
			} else if (!isNaN(pudoCost) && pudoCost > 0) {
				pudoPriceHtml = '<span class="aki-ship-alt__price-val">' + formatClp(pudoCost) + '</span>';
				if (!isNaN(homeCost) && homeCost > pudoCost) {
					var savings = Math.round(homeCost - pudoCost);
					pudoSavingsHtml = '<span class="aki-ship-alt__savings aki-ship-alt__savings--pulse">Ahorras ' + formatClp(savings) + '</span>';
				}
			} else {
				pudoPriceHtml = '<span class="aki-ship-alt__price-val">—</span>';
			}

			cards.push(altCardHtml(
				'pudo',
				currentMode === 'pudo',
				iconSvg('pin'),
				'Retiro en punto Blue Express',
				'+3.200 puntos en Chile · Lockers 24/7 con QR',
				pudoPriceHtml,
				pudoSavingsHtml,
				recommended === 'pudo' ? 'aki-ship-alt__card--recommended' : '',
				recommended === 'pudo' ? makeBadge('pudo') : '',
				2
			));
		}

		// ── Card 3: 12 Horas Same Day (solo RM + L-S antes 13:30) ──
		// Entre pudo y home. El badge "SAME DAY" + sub "Entrega hoy" es el
		// gancho de urgencia que diferencia de BlueX (2-3 días).
		if (sameDayInfo) {
			var sdCost = isNaN(sameDayCost) ? 0 : sameDayCost;
			var sdPriceHtml = free || sdCost <= 0
				? '<span class="aki-ship-alt__price-val aki-ship-alt__price-val--free">GRATIS</span>'
				: '<span class="aki-ship-alt__price-val">' + formatClp(sdCost) + '</span>';
			var sdSavingsHtml = '<span class="aki-ship-alt__savings aki-ship-alt__savings--pulse">' + (sameDayInfo.badge || 'LLEGA HOY') + '</span>';

			cards.push(altCardHtml(
				'sameday',
				currentMode === 'sameday',
				sameDayInfo.iconSvgRaw || iconSvg('truck'),
				sameDayInfo.label || '12 Horas Envíos',
				sameDayInfo.sub   || 'Entrega hoy · Santiago',
				sdPriceHtml,
				sdSavingsHtml,
				'',
				'',
				1
			));
		}

		// ── Card 4: Home (BlueX por default, fallback a courier disponible) ──
		var homePriceHtml = free
			? '<span class="aki-ship-alt__price-val aki-ship-alt__price-val--free">GRATIS</span>'
			: '<span class="aki-ship-alt__price-val">' + formatClp(homeCost) + '</span>';
		var homeSavingsHtml = free
			? '<span class="aki-ship-alt__savings">Incluido en tu pedido</span>'
			: '';
		var homeSel = currentMode === 'home' || !currentMode;

		var defaultHomeInfo = {
			label: 'Envío Blue Express a domicilio',
			sub:   etaFor('bluex-home') + ' · Seguimiento en línea',
			iconSvgRaw: iconSvg('truck')
		};
		var resolvedHomeInfo = homeInfo || defaultHomeInfo;
		var homeIcon = resolvedHomeInfo.iconSvgRaw
			|| (resolvedHomeInfo.iconKey ? iconSvg(resolvedHomeInfo.iconKey) : iconSvg('truck'));

		cards.push(altCardHtml(
			'home',
			homeSel,
			homeIcon,
			resolvedHomeInfo.label,
			resolvedHomeInfo.sub,
			homePriceHtml,
			homeSavingsHtml,
			recommended === 'home' ? 'aki-ship-alt__card--recommended' : '',
			recommended === 'home' ? makeBadge('home') : '',
			1
		));

		return '<div class="aki-ship-flat" role="radiogroup" aria-label="Método de envío">' + cards.join('') + '</div>';
	}

	// ═══════════════════════════════════════════════════════════════
	// Main layout builder
	// ═══════════════════════════════════════════════════════════════

	function buildHeroLayout($container, $list) {
		// Extract data from the WC shipping list
		var $bluexLi = $list.children('li').filter(function () {
			return detectRateType($(this).find('input.shipping_method').attr('value') || '') === 'bluex';
		}).first();
		var $pickupLi = $list.children('li').filter(function () {
			return detectRateType($(this).find('input.shipping_method').attr('value') || '') === 'pickup';
		}).first();
		var $otherLi = $list.children('li').filter(function () {
			return detectRateType($(this).find('input.shipping_method').attr('value') || '') === 'other';
		}).first();
		var $sameDayLi = $list.children('li').filter(function () {
			return detectRateType($(this).find('input.shipping_method').attr('value') || '') === 'sameday';
		}).first();

		// Preferencia: BlueX (prod canónico) → primer courier "other" (local/fallback)
		// → solo pickup. Si ninguno, delegar a WC (return false).
		var hasBluex = $bluexLi.length > 0;
		var $homeLi  = hasBluex ? $bluexLi : $otherLi;
		var hasMetro = $pickupLi.length > 0;

		if (!$homeLi.length && !hasMetro) return false; // Nada que pintar

		var homeCost = NaN, pudoCost = NaN, homeInfo = null;

		if ($homeLi.length) {
			homeCost = parseFloat($homeLi.attr('data-home-cost'));
			if (hasBluex) {
				pudoCost = parseFloat($homeLi.attr('data-pudo-cost'));
			}

			// Fallback: leer costo del label si no viene en data-*
			if (isNaN(homeCost)) {
				var $priceEl = $homeLi.find('.woocommerce-Price-amount').first();
				if ($priceEl.length) {
					homeCost = parseFloat($priceEl.text().replace(/[^0-9]/g, ''));
				}
			}
			if (hasBluex && isNaN(pudoCost)) {
				pudoCost = isNaN(homeCost) ? 0 : Math.max(0, homeCost - 500);
			}

			// Cuando el home no es BlueX, pedimos metadata al registro de couriers
			// del theme (akb_get_couriers_ui_metadata) para pintar label/icono
			// coherentes con el courier real (ej: "12 Horas Envíos (Same Day RM)").
			if (!hasBluex) {
				var methodValue = $homeLi.find('input.shipping_method').attr('value') || '';
				var meta = findCourierMetadata(methodValue);
				var etaText = (meta && meta.eta) ? meta.eta : 'Despacho same-day';
				homeInfo = {
					label: (meta && meta.label) ? meta.label : 'Envío a domicilio',
					sub:   (meta && meta.tagline) ? meta.tagline : etaText,
					iconSvgRaw: (meta && meta.iconSvg) ? meta.iconSvg : iconSvg('truck')
				};
			}
		} else {
			homeCost = 0;
		}

		if (isFreeShipping()) {
			homeCost = 0;
			pudoCost = 0;
		}

		// ── Same Day (12 Horas) ───────────────────────────────────────
		// Muestra el rate si existe. Pre-cutoff → "Llega hoy". Post-cutoff
		// (o domingo) → "Llega mañana" con badge distinto. Alineado con
		// MercadoLibre / Falabella: nunca se esconde, solo cambia la promesa.
		var sameDayInfo = null, sameDayCost = NaN;
		if ($sameDayLi.length) {
			var sdMethodValue = $sameDayLi.find('input.shipping_method').attr('value') || '';
			var sdMeta        = findCourierMetadata(sdMethodValue);
			var $sdPrice      = $sameDayLi.find('.woocommerce-Price-amount').first();
			sameDayCost = $sdPrice.length ? parseFloat($sdPrice.text().replace(/[^0-9]/g, '')) : NaN;
			var isToday       = isSameDayAvailable();
			sameDayInfo = {
				label:      (sdMeta && sdMeta.label) ? sdMeta.label : '12 Horas Envíos',
				sub:        isToday
					? 'Llega hoy · Pide antes de las 13:30'
					: 'Llega mañana · Despacho prioritario',
				iconSvgRaw: (sdMeta && sdMeta.iconSvg) ? sdMeta.iconSvg : iconSvg('truck'),
				badge:      isToday ? ((sdMeta && sdMeta.badge) ? sdMeta.badge : 'LLEGA HOY') : 'MAÑANA'
			};
		}

		// Build or update wrapper (mantenemos `.aki-ship-hero-wrap` como nombre
		// histórico del contenedor para no romper selectores externos — el
		// contenido es la nueva grilla plana `.aki-ship-flat`).
		var $wrap = $container.find('.aki-ship-hero-wrap');
		if (!$wrap.length) {
			$wrap = $('<div class="aki-ship-hero-wrap"></div>');
			$container.prepend($wrap);
		}

		var mode = getCurrentMode();
		$wrap.html(buildShippingCardsHtml(homeCost, pudoCost, hasMetro, mode, homeInfo, hasBluex, sameDayInfo, sameDayCost));

		// Hide the original WC shipping list (keep it in DOM for radio sync)
		$list.addClass('aki-ship-grid--hidden');

		return true;
	}

	// ═══════════════════════════════════════════════════════════════
	// Selection sync (hero/accordion ↔ WC radios ↔ delivery mode)
	// ═══════════════════════════════════════════════════════════════

	function getCurrentMode() {
		var m = $('input[name="akibara_delivery_mode"]:checked').val();
		return m || 'home';
	}

	function setDeliveryMode(mode) {
		var $r = $('input[name="akibara_delivery_mode"][value="' + mode + '"]');
		if (!$r.length) return;
		if ($r.prop('checked')) {
			$r.trigger('change');
		} else {
			$r.prop('checked', true).trigger('change');
		}
	}

	// Selección unificada (Variante A): home, pudo y metro son cards del
	// mismo tipo `.aki-ship-alt__card` con `data-alt-mode`. Una sola función
	// cubre los 3 casos. `selectHero()` queda como wrapper backward-compat.
	function selectAltCard(mode) {
		var $container = getContainer();
		var $wrap      = $container.find('.aki-ship-hero-wrap');

		$wrap.find('.aki-ship-alt__card').removeClass('is-selected').attr('aria-checked', 'false');
		$wrap.find('.aki-ship-alt__card[data-alt-mode="' + mode + '"]').addClass('is-selected').attr('aria-checked', 'true');

		setDeliveryMode(mode);

		var $list = $container.find('#shipping_method').first();
		if (mode === 'metro') {
			var $pickupRadio = $list.find('input.shipping_method[value^="local_pickup"]').first();
			if ($pickupRadio.length && !$pickupRadio.prop('checked')) {
				$pickupRadio.prop('checked', true).trigger('change');
			}
		} else if (mode === 'sameday') {
			var $sameDayRadio = $list.find('input.shipping_method[value^="12horas"]').first();
			if ($sameDayRadio.length && !$sameDayRadio.prop('checked')) {
				$sameDayRadio.prop('checked', true).trigger('change');
			}
		} else {
			// home y pudo comparten el rate BlueX cuando existe (data-mode distingue en PHP).
			// Si no hay BlueX (local/fallback), el home mode selecciona el primer rate no-pickup
			// que el hero layout usó como anchor.
			var $bluexRadio = $list.find('input.shipping_method[value^="bluex-ex"]').first();
			if ($bluexRadio.length) {
				if (!$bluexRadio.prop('checked')) {
					$bluexRadio.prop('checked', true).trigger('change');
				}
			} else {
				var $otherRadio = $list.find('li').filter(function () {
					return detectRateType($(this).find('input.shipping_method').attr('value') || '') === 'other';
				}).first().find('input.shipping_method').first();
				if ($otherRadio.length && !$otherRadio.prop('checked')) {
					$otherRadio.prop('checked', true).trigger('change');
				}
			}
		}

		var courier = mode === 'metro' ? 'pickup' : (mode === 'pudo' ? 'bluex-pudo' : 'bluex-home');
		if (S.tracking) S.tracking.trackShipping(courier, mode);
	}

	// Backward-compat: algunos callers externos pueden invocar selectHero.
	function selectHero() {
		selectAltCard('home');
	}

	// ═══════════════════════════════════════════════════════════════
	// Main refresh
	// ═══════════════════════════════════════════════════════════════

	function finalizeRefresh($container) {
		window.requestAnimationFrame(function () {
			$container.removeClass('is-updating');
		});
	}

	function refresh() {
		var $container = getContainer();
		if (!$container.length) return;

		if (!isAddressComplete()) {
			renderPlaceholder($container);
			finalizeRefresh($container);
			return;
		}
		clearPlaceholder($container);

		var $list = $container.find('#shipping_method, .woocommerce-shipping-methods').first();
		if (!$list.length) {
			renderPlaceholder($container);
			finalizeRefresh($container);
			return;
		}

		// Tag original <li> elements with data attributes
		$list.children('li').each(function () {
			var $li = $(this);
			var $input = $li.find('input[type="radio"]');
			var val = $input.attr('value') || '';
			var type = detectRateType(val);
			var key = type === 'bluex' ? 'bluex-home' : type === 'pickup' ? 'pickup' : 'other';
			$li.attr('data-courier', key);
			$li.attr('data-mode', cardDefinition(key, val).mode);
			$li.attr('data-method-value', val);
		});

		if (UNIFIED_GRID) {
			buildHeroLayout($container, $list);
		}

		$container.find('.aki-ship-regional').remove();
		if (S.freeProgress) { S.freeProgress.update(); S.freeProgress.updateSidebar(); }
		if (S.pudo) S.pudo.moveAuxPanels();
		finalizeRefresh($container);
	}

	// ═══════════════════════════════════════════════════════════════
	// Bindings
	// ═══════════════════════════════════════════════════════════════

	$(document).on('change', '#billing_state, #billing_city, #billing_address_1, #shipping_state, #shipping_city, #shipping_address_1', function () {
		setTimeout(refresh, 150);
	});

	// Input en tiempo real: feedback inmediato al borrar/escribir calle sin esperar blur.
	// WC solo dispara update_checkout en change; sin este handler los cards quedan stale
	// visualmente cuando el usuario borra la dirección y sigue con foco en el campo.
	var inputRefreshTimer = null;
	$(document).on('input', '#billing_address_1, #shipping_address_1, #billing_city, #shipping_city', function () {
		clearTimeout(inputRefreshTimer);
		inputRefreshTimer = setTimeout(refresh, 250);
	});

	// Card click (home, pudo o metro — unificado en flat grid)
	$(document).on('click', '.aki-ship-alt__card', function (e) {
		e.preventDefault();
		var mode = $(this).attr('data-alt-mode');
		if (!mode) return;
		selectAltCard(mode);
		if (S.pudo) S.pudo.moveAuxPanels();
	});

	// Keyboard navigation on alt cards
	$(document).on('keydown', '.aki-ship-alt__card', function (e) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			$(this).trigger('click');
		}
	});

	// When delivery mode changes externally (e.g. from checkout-pudo.js)
	$(document).on('change', 'input[name="akibara_delivery_mode"]', function () {
		setTimeout(refresh, 80);
	});

	$(document).on('change', 'input.shipping_method', function () {
		setTimeout(function () {
			if (S.freeProgress) S.freeProgress.updateSidebar();
		}, 80);
	});

	// ═══════════════════════════════════════════════════════════════
	// Public API
	// ═══════════════════════════════════════════════════════════════

	S.grid = {
		refresh:           refresh,
		getContainer:      getContainer,
		renderPlaceholder: renderPlaceholder,
		clearPlaceholder:  clearPlaceholder,
		buildHeroLayout:   buildHeroLayout,
		isAddressComplete: isAddressComplete,
		getCurrentMode:    getCurrentMode,
		setDeliveryMode:   setDeliveryMode,
		selectHero:        selectHero,
		selectAltCard:     selectAltCard,
		detectRateType:    detectRateType,
		cardDefinition:    cardDefinition,
		pickRecommended:   pickRecommended,
		etaFor:            etaFor,
		formatClp:         formatClp,
		getCartSubtotal:   getCartSubtotal,
		isFreeShipping:    isFreeShipping,
		iconSvg:           iconSvg,
		finalizeRefresh:   finalizeRefresh
	};

})(jQuery);
