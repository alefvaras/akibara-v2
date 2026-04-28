/**
 * Akibara WhatsApp — Frontend Script v1.3.0
 * Solo boton flotante, click directo a WhatsApp
 */
(function () {
	'use strict';

	const container = document.getElementById('akibara-wa');
	if (!container) {
		return;
	}

	let settings;
	try {
		settings = JSON.parse(container.dataset.settings || '{}');
	} catch (e) {
		return;
	}
	if (!settings.phone) {
		return;
	}

	const btn = container.querySelector('.akibara-wa__btn');

	function getIsMobile() {
		return window.innerWidth <= 768;
	}

	if (getIsMobile() && !settings.mobile) {
		container.remove();
		return;
	}
	if (!getIsMobile() && !settings.desktop) {
		container.remove();
		return;
	}

	container.setAttribute('aria-hidden', 'false');
	btn.setAttribute('tabindex', '-1');

	function showBtn() {
		btn.classList.add('is-visible');
		btn.removeAttribute('tabindex');
		// Safety net: si la transición CSS no corrió (tab en background / iOS bfcache),
		// forzar estado final via inline style 800ms después (> 400ms de transición).
		setTimeout(function () {
			if (parseFloat(getComputedStyle(btn).opacity) < 0.5) {
				btn.style.opacity = '1';
				btn.style.transform = 'scale(1) translateY(0)';
			}
		}, 800);
	}

	if (document.hidden) {
		// Página cargó en background — esperar hasta que sea visible para mostrar el botón.
		document.addEventListener('visibilitychange', function onVisible() {
			if (!document.hidden) {
				document.removeEventListener('visibilitychange', onVisible);
				setTimeout(showBtn, settings.delayBtn || 1500);
			}
		});
	} else {
		setTimeout(showBtn, settings.delayBtn || 1500);
	}

	// Auto-fade cuando un section heading está en la zona inferior del viewport
	// donde vive el botón. Audit 2026-04-27 P2-02: el botón cubría headings tipo
	// "Te puede interesar" / "También te puede gustar". Graceful degradation si IO
	// no está disponible.
	function setupHeadingAutoFade() {
		if (!('IntersectionObserver' in window)) {
			return;
		}

		const headings = document.querySelectorAll(
			'.section-header__title, .product-info h2, .akb-series-hub__title, ' +
				'.related h2, .upsells h2, .cross-sells h2, .single-product__related-title'
		);
		if (!headings.length) {
			return;
		}

		const active = new Set();
		let lastDimmed = null;

		function applyDim(shouldDim) {
			if (shouldDim === lastDimmed) {
				return;
			}
			lastDimmed = shouldDim;
			if (shouldDim) {
				btn.classList.add('is-dimmed');
				btn.style.pointerEvents = 'none';
			} else {
				btn.classList.remove('is-dimmed');
				btn.style.pointerEvents = '';
			}
		}

		function recompute() {
			const alignRight =
				container.classList.contains('akibara-wa--right') ||
				!container.classList.contains('akibara-wa--left');
			const btnRect = btn.getBoundingClientRect();
			const buffer = 24;
			let collision = false;

			active.forEach(function (h) {
				const r = h.getBoundingClientRect();
				// Vertical disjoint?
				if (r.bottom < btnRect.top - buffer || r.top > btnRect.bottom + buffer) {
					return;
				}
				// Horizontal collision against the side where the button lives?
				if (alignRight) {
					if (r.right > btnRect.left - buffer) {
						collision = true;
					}
				} else if (r.left < btnRect.right + buffer) {
					collision = true;
				}
			});

			applyDim(collision);
		}

		const io = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (e) {
					if (e.isIntersecting) {
						active.add(e.target);
					} else {
						active.delete(e.target);
					}
				});
				recompute();
			},
			{ rootMargin: '0px 0px 0px 0px', threshold: 0 }
		);

		headings.forEach(function (h) {
			io.observe(h);
		});

		let ticking = false;
		window.addEventListener(
			'scroll',
			function () {
				if (ticking) {
					return;
				}
				ticking = true;
				requestAnimationFrame(function () {
					recompute();
					ticking = false;
				});
			},
			{ passive: true }
		);
		window.addEventListener('resize', recompute, { passive: true });
	}

	setTimeout(setupHeadingAutoFade, (settings.delayBtn || 1500) + 200);

	btn.addEventListener('click', function (e) {
		e.preventDefault();
		openWhatsApp();
	});

	document.addEventListener('click', function (e) {
		const trigger = e.target.closest(
			'.akibara-wa-open, a[href="#whatsapp"], a[href="#joinchat"]'
		);
		if (trigger) {
			e.preventDefault();
			openWhatsApp();
		}
	});

	function openWhatsApp() {
		fireAnalytics();
		let url;
		if (getIsMobile()) {
			url = 'https://wa.me/' + settings.phone;
			if (settings.message) {
				url += '?text=' + encodeURIComponent(settings.message);
			}
		} else {
			url =
				settings.url ||
				'https://web.whatsapp.com/send?phone=' +
					settings.phone +
					(settings.message ? '&text=' + encodeURIComponent(settings.message) : '');
		}
		window.open(url, '_blank', 'noopener');
	}

	function fireAnalytics() {
		const ctx = settings.isProduct ? 'product' : 'general';
		if (typeof gtag === 'function') {
			gtag('event', 'generate_lead', {
				event_category: 'whatsapp',
				event_label: ctx,
				value: 1,
			});
		}
		if (window.dataLayer) {
			window.dataLayer.push({ event: 'akibara_whatsapp_click', wa_context: ctx });
		}
	}
})();
