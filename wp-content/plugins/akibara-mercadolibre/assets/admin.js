/**
 * Akibara MercadoLibre — Admin panel script
 * Extraído de module.php para cacheo HTTP y debugging (DT-11).
 * Depende de: jQuery, window.AkibaraMlData (nonce, ajaxUrl).
 */
(function ($) {
	'use strict';

	var data  = window.AkibaraMlData || {};
	var nonce = data.nonce || '';
	var ajaxUrl = data.ajaxUrl || window.ajaxurl;
	var currentPage = 1;
	var totalPages  = 1;
	var totalItems  = 0;

	function mlNotify(message, type) {
		if (window.AkibaraAdmin && typeof window.AkibaraAdmin.toast === 'function') {
			window.AkibaraAdmin.toast(message, type || 'success');
			return;
		}
		window.alert(message);
	}

	function mlConfirm(message, onConfirm) {
		if (window.AkibaraAdmin && typeof window.AkibaraAdmin.confirm === 'function') {
			window.AkibaraAdmin.confirm(message, onConfirm);
			return;
		}
		if (window.confirm(message)) {
onConfirm();
		}
	}

	function mlPrompt(message, defaultValue, onSubmit) {
		if (window.AkibaraAdmin && typeof window.AkibaraAdmin.prompt === 'function') {
			window.AkibaraAdmin.prompt(message, defaultValue, onSubmit);
			return;
		}
		var fallback = window.prompt(message, defaultValue || '');
		if (fallback !== null) {
onSubmit(fallback);
		}
	}

	function fmtCLP(n) {
return '$' + Math.round(n).toLocaleString('es-CL'); }

	function applyRounding(price, mode) {
		if (mode === 'none' || price < 1100) {
return price;
		}
		var thousands = Math.floor(price / 1000);
		var remainder = price % 1000;
		var target = mode === '990' ? 990 : (mode === '900' ? 900 : null);
		if (target === null) {
return price;
		}
		if (remainder === target) {
return price;
		}
		if (remainder > target) {
return (thousands + 1) * 1000 + target;
		}
		return thousands * 1000 + target;
	}

	var AKB_FREE_SHIPPING_THRESHOLD = 19990;

	function updatePricePreview() {
		var comm  = parseFloat($('#akb-ml-commission').val()) || 0;
		var extra = parseFloat($('#akb-ml-extra').val()) || 0;
		var ship  = parseInt($('#akb-ml-shipping').val()) || 0;
		var mode  = $('#akb-ml-price-rounding').val() || 'none';
		var total = comm + extra;
		if (total >= 100) {
return;
		}

		$('#akb-ml-total-pct').text(total.toFixed(1));
		$('#akb-ml-ship-preview').text(ship.toLocaleString('es-CL'));

		// Ejemplo bajo umbral: WC=$10.000 NO cruza, sin envío
		var lowRaw  = Math.ceil(10000 / (1 - total / 100));
		var lowEx   = applyRounding(lowRaw, mode);
		$('#akb-ml-example-low').text(lowEx.toLocaleString('es-CL'));

		// Ejemplo sobre umbral: WC=$20.000 cruza, absorbe envío
		var highBase = 20000;
		var highNoShip = Math.ceil(highBase / (1 - total / 100));
		var highRaw = (highNoShip >= AKB_FREE_SHIPPING_THRESHOLD && ship > 0)
			? Math.ceil((highBase + ship) / (1 - total / 100))
			: highNoShip;
		var highEx = applyRounding(highRaw, mode);
		$('#akb-ml-example-high').text(highEx.toLocaleString('es-CL'));
	}

	$('#akb-ml-commission,#akb-ml-extra,#akb-ml-shipping').on('input', updatePricePreview);
	$('#akb-ml-price-rounding').on('change', updatePricePreview);

	// Toggle visibilidad del token
	$('#akb-ml-token-toggle').on('click', function () {
		var input = $('#akb-ml-token');
		var visible = input.attr('type') === 'text';
		input.attr('type', visible ? 'password' : 'text');
		$(this).text(visible ? '👁' : '🙈');
	});

	function statusBadge(p) {
		if (p.ml_status === 'active') {
return '<span class="akb-badge akb-badge--active">Activo</span>';
		}
		if (p.ml_status === 'paused') {
return '<span class="akb-badge akb-badge--inactive">Pausado</span>';
		}
		if (p.ml_status === 'error') {
return '<span class="akb-badge akb-badge--error" title="' + escHtml(p.error_msg) + '">Error</span>';
		}
		return '<span class="akb-badge">No publicado</span>';
	}

	function escHtml(s) {
		return String(s || '').replace(/[&<>"]/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
		});
	}

	function renderRow(p) {
		var mlUrl = p.permalink || ('https://articulo.mercadolibre.cl/' + p.ml_item_id.replace(/^(MLC)(\d)/, '$1-$2'));
		var mlLink = p.ml_item_id
			? '<br><a href="' + mlUrl + '" target="_blank" class="akb-ml-link-id">' + p.ml_item_id + '</a>'
			: '';
		var priceClass = p.override > 0 ? 'akb-ml-price-edit akb-ml-price-edit--override' : 'akb-ml-price-edit akb-ml-price-edit--calc';
		var actions = '';
		if (p.ml_status === 'error') {
			actions += '<button class="akb-btn akb-btn--primary akb-ml-pub-btn akb-ml-action-btn" data-id="' + p.id + '">Republicar</button> ';
			actions += '<button class="akb-btn akb-btn--secondary akb-ml-clear-err-btn akb-ml-action-btn" data-id="' + p.id + '" title="' + escHtml(p.error_msg) + '">✕ Limpiar</button>';
		} else if ( ! p.ml_item_id || p.ml_status === '') {
			actions += '<button class="akb-btn akb-btn--primary akb-ml-pub-btn akb-ml-action-btn" data-id="' + p.id + '">Publicar</button>';
		} else if (p.ml_status === 'active') {
			actions += '<button class="akb-btn akb-btn--primary akb-ml-pub-btn akb-ml-action-btn" data-id="' + p.id + '">Actualizar</button> ';
			actions += '<button class="akb-btn akb-btn--secondary akb-ml-tog-btn akb-ml-action-btn" data-id="' + p.id + '" data-action="pause">Pausar</button>';
		} else if (p.ml_status === 'paused') {
			actions += '<button class="akb-btn akb-btn--secondary akb-ml-tog-btn akb-ml-action-btn" data-id="' + p.id + '" data-action="activate">Activar</button>';
		}

		return '<tr class="akb-ml-row">'
			+ '<td><input type="checkbox" class="akb-ml-chk" value="' + p.id + '"></td>'
			+ '<td><div class="akb-ml-row__product">'
			+ (p.thumb ? '<img src="' + p.thumb + '" class="akb-ml-row__thumb">' : '')
			+ '<span>' + escHtml(p.title) + '</span></div></td>'
			+ '<td class="akb-ml-row__num">' + p.wc_stock + '</td>'
			+ '<td class="akb-ml-row__price">' + fmtCLP(p.wc_price) + '</td>'
			+ '<td class="akb-ml-row__calc">'
			+ '<span class="' + priceClass + '" data-id="' + p.id + '" data-calc="' + p.ml_calc + '" data-override="' + (p.override || 0) + '" '
			+ 'title="' + (p.override > 0 ? 'Precio manual — clic para editar' : 'Precio calculado — clic para fijar manual') + '">'
			+ fmtCLP(p.ml_calc) + (p.override > 0 ? ' ✏️' : '')
			+ '</span></td>'
			+ '<td>' + statusBadge(p) + mlLink + '</td>'
			+ '<td class="akb-ml-row__sync">' + (p.synced_at ? p.synced_at.substring(0, 16) : '—') + '</td>'
			+ '<td>' + actions + '</td>'
			+ '</tr>';
	}

	function loadProducts(page) {
		currentPage = page || 1;
		var perPage   = parseInt($('#akb-ml-per-page').val()) || 25;
		var search    = $('#akb-ml-search').val();
		var filter    = $('#akb-ml-filter').val();
		var editorial = $('#akb-ml-editorial').val();
		var serie     = $('#akb-ml-serie').val();
		$('#akb-ml-tbody').html('<tr><td colspan="8" class="akb-ml-products-loading"><em>Cargando...</em></td></tr>');
		$('#akb-ml-pagination').html('');
		$('#akb-ml-results-info').text('Cargando…');
		$.post(ajaxUrl, {
			action: 'akb_ml_get_products',
			nonce: nonce,
			page: currentPage,
			per_page: perPage,
			search: search,
			filter: filter,
			editorial: editorial,
			serie: serie
		}, function (res) {
			if ( ! res.success) {
return;
			}
			var items = res.data.items;
			totalPages = res.data.pages;
			totalItems = res.data.total;

			var html = items.length
				? items.map(renderRow).join('')
				: '<tr><td colspan="8" class="akb-ml-products-empty">Sin resultados para este filtro.</td></tr>';
			$('#akb-ml-tbody').html(html);

			if (totalItems > 0) {
				var from = (currentPage - 1) * perPage + 1;
				var to   = Math.min(currentPage * perPage, totalItems);
				$('#akb-ml-results-info').text(from + '–' + to + ' de ' + totalItems + ' productos');
			} else {
				$('#akb-ml-results-info').text('0 productos');
			}

			renderPagination(currentPage, totalPages);
		});
	}

	function renderPagination(current, total) {
		if (total <= 1) {
$('#akb-ml-pagination').html(''); return; }

		var ellipsis = '<span class="akb-ml-page-ellipsis">…</span>';

		function pageBtn(n, label) {
			var cls = 'akb-ml-page-btn' + (n === current ? ' akb-ml-page-btn--active' : '');
			return '<button class="' + cls + '" data-page="' + n + '">' + (label || n) + '</button>';
		}

		var h = '';
		h += current > 1
			? '<button class="akb-ml-page-btn" data-page="' + (current - 1) + '">‹</button>'
			: '<button class="akb-ml-page-btn akb-ml-page-btn--disabled" disabled>‹</button>';

		var pages = [];
		var range = 2;
		for (var i = 1; i <= total; i++) {
			if (i === 1 || i === total || (i >= current - range && i <= current + range)) {
				pages.push(i);
			}
		}
		var prev = null;
		for (var j = 0; j < pages.length; j++) {
			if (prev !== null && pages[j] - prev > 1) {
h += ellipsis;
			}
			h += pageBtn(pages[j]);
			prev = pages[j];
		}

		h += current < total
			? '<button class="akb-ml-page-btn" data-page="' + (current + 1) + '">›</button>'
			: '<button class="akb-ml-page-btn akb-ml-page-btn--disabled" disabled>›</button>';

		$('#akb-ml-pagination').html(h);
	}

	loadProducts(1);

	$('#akb-ml-load-btn').on('click', function () {
loadProducts(1); });
	$('#akb-ml-search').on('keypress', function (e) {
if (e.which === 13) {
loadProducts(1);
	} });
	$('#akb-ml-filter').on('change', function () {
loadProducts(1); });
	$('#akb-ml-per-page').on('change', function () {
loadProducts(1); });
	$(document).on('click', '.akb-ml-page-btn', function () {
loadProducts(parseInt($(this).data('page'))); });

	$('#akb-ml-chk-all').on('change', function () {
		$('.akb-ml-chk').prop('checked', this.checked);
		toggleBulk();
	});
	$(document).on('change', '.akb-ml-chk', toggleBulk);

	function toggleBulk() {
		var n = $('.akb-ml-chk:checked').length;
		$('#akb-ml-bulk-btn').toggle(n > 0).text('📤 Publicar seleccionados (' + n + ')');
	}

	$('#akb-ml-select-all-btn').on('click', function () {
		$('.akb-ml-chk').prop('checked', true);
		toggleBulk();
	});

	$(document).on('click', '.akb-ml-pub-btn', function () {
		var btn = $(this), id = btn.data('id');
		btn.text('…').prop('disabled', true);
		$.post(ajaxUrl, { action: 'akb_ml_publish', nonce: nonce, product_id: id }, function (res) {
			if (res.success) {
				loadProducts(currentPage);
			} else {
				mlNotify('Error: ' + res.data.message, 'error');
				btn.text('Publicar').prop('disabled', false);
			}
		});
	});

	$(document).on('click', '.akb-ml-tog-btn', function () {
		var btn = $(this), id = btn.data('id'), act = btn.data('action');
		btn.text('…').prop('disabled', true);
		$.post(ajaxUrl, { action: 'akb_ml_toggle_status', nonce: nonce, product_id: id, ml_action: act }, function (res) {
			if (res.success) {
				loadProducts(currentPage);
			} else {
				mlNotify('Error: ' + res.data.message, 'error');
				btn.prop('disabled', false);
			}
		});
	});

	$(document).on('click', '.akb-ml-clear-err-btn', function () {
		var btn = $(this), id = btn.data('id');
		var errorMsg = btn.attr('title') || 'Error desconocido';
		mlConfirm('Limpiar error para este producto?\n\n' + errorMsg + '\n\nEl producto quedará disponible para republicar.', function () {
			btn.text('…').prop('disabled', true);
			$.post(ajaxUrl, { action: 'akb_ml_clear_error', nonce: nonce, product_id: id }, function (res) {
				if (res.success) {
					mlNotify(res.data.message, 'success');
					loadProducts(currentPage);
				} else {
					mlNotify('Error: ' + res.data.message, 'error');
					btn.text('✕ Limpiar').prop('disabled', false);
				}
			});
		});
	});

	// ── Polling de progreso bulk ────────────────────────────────
	function pollBulkProgress($btn, $prog, originalLabel, jobId) {
		var $bar = $prog.find('.js-akb-ml-prog-bar');
		var $txt = $prog.find('.js-akb-ml-prog-txt');
		var attempts = 0;
		var maxAttempts = 300;

		var pollTimer = setInterval(function () {
			attempts++;
			$.post(ajaxUrl, { action: 'akb_ml_bulk_progress', nonce: nonce, job_id: jobId }, function (res) {
				if ( ! res.success || ! res.data) {
return;
				}
				var d = res.data;

				if (d.waiting) {
					$txt.text('Esperando inicio del job…');
					return;
				}
				if (jobId && d.job_id && d.job_id !== jobId) {
return;
				}
				if ( ! d.total) {
return;
				}

				var processed = (d.ok || 0) + (d.errors || 0);
				var pct = d.total > 0 ? Math.round(processed / d.total * 100) : 0;
				$bar.css('width', pct + '%').text(pct + '%');
				$txt.text('Publicando… ' + processed + ' de ' + d.total + ' (' + d.ok + ' OK, ' + d.errors + ' errores)');

				if (d.done) {
					clearInterval(pollTimer);
					$bar.css('width', '100%').text('100%');
					var msg = '✓ ' + d.ok + ' publicados. ' + d.errors + ' errores.';
					if (d.messages && d.messages.length) {
msg += '\n\nDetalles:\n' + d.messages.slice(0, 10).join('\n');
					}
					$txt.text('Completado: ' + d.ok + ' OK, ' + d.errors + ' errores.');
					setTimeout(function () {
$prog.remove(); }, 5000);
					mlNotify(msg, d.errors > 0 ? 'warning' : 'success');
					$btn.text(originalLabel).prop('disabled', false);
					loadProducts(currentPage);
				}
			}).fail(function () {
				if (attempts % 5 === 0) {
					$txt.text('Reintentando consulta de progreso…');
				}
			});

			if (attempts >= maxAttempts) {
				clearInterval(pollTimer);
				$txt.text('No se pudo confirmar el estado final. Revisa Action Scheduler.');
				mlNotify('No se pudo confirmar el estado final del bulk. Revisa Action Scheduler.', 'warning');
				$btn.text(originalLabel).prop('disabled', false);
			}
		}, 2000);
	}

	function showProgressBar($anchor) {
		var $prog = $('<div class="akb-ml-progress"><div class="akb-ml-progress__track">' +
			'<div class="akb-ml-progress__bar js-akb-ml-prog-bar"></div>' +
			'</div><div class="akb-ml-progress__text js-akb-ml-prog-txt">Encolando tareas…</div></div>');
		$anchor.closest('.akb-card--section').append($prog);
		return $prog;
	}

	$('#akb-ml-bulk-btn').on('click', function () {
		var ids = $('.akb-ml-chk:checked').map(function () {
return $(this).val(); }).get();
		if ( ! ids.length) {
return;
		}
		var btn = $(this);
		mlConfirm('¿Publicar ' + ids.length + ' producto(s) en MercadoLibre Chile?', function () {
			btn.text('Encolando…').prop('disabled', true);
			var $prog = showProgressBar(btn);
			$.post(ajaxUrl, { action: 'akb_ml_bulk_publish', nonce: nonce, ids: ids }, function (res) {
				if (res.success && res.data && res.data.enqueued && res.data.job_id) {
					$prog.find('.js-akb-ml-prog-txt').text('Procesando ' + res.data.total + ' productos en segundo plano…');
					pollBulkProgress(btn, $prog, '📤 Publicar seleccionados', res.data.job_id);
				} else {
					var msg = (res && res.data && res.data.message) ? res.data.message : 'respuesta inválida del servidor';
					mlNotify('Error: ' + msg, 'error');
					btn.text('📤 Publicar seleccionados').prop('disabled', false);
					$prog.remove();
				}
			});
		});
	});

	// Publicar TODOS los productos con stock disponible (async via Action Scheduler)
	$('#akb-ml-publish-available-btn').on('click', function () {
		var btn = $(this);

		$.post(ajaxUrl, {
			action: 'akb_ml_get_products',
			nonce: nonce,
			page: 1,
			filter: 'available',
			search: ''
		}, function (res) {
			if ( ! res.success) {
return;
			}
			var total = res.data.total;
			if (total === 0) {
				mlNotify('✅ No hay productos con stock pendientes de publicar.', 'info');
				return;
			}
			mlConfirm('🚀 Se publicarán ' + total + ' productos con stock en MercadoLibre Chile.\n\nSe procesan en segundo plano — puedes cerrar esta página.\n\n¿Continuar?', function () {
				btn.prop('disabled', true).text('Encolando…');
				var $prog = showProgressBar(btn);

				$.post(ajaxUrl, {
					action: 'akb_ml_publish_all_available',
					nonce: nonce
				}, function (res) {
					if (res.success && res.data && res.data.enqueued && res.data.job_id) {
						$prog.find('.js-akb-ml-prog-txt').text('Procesando ' + res.data.total + ' productos en segundo plano…');
						pollBulkProgress(btn, $prog, '🚀 Publicar TODOS con stock', res.data.job_id);
					} else if (res.success && res.data && ! res.data.enqueued) {
						mlNotify('✅ No hay productos con stock pendientes.', 'info');
						btn.text('🚀 Publicar TODOS con stock').prop('disabled', false);
						$prog.remove();
					} else {
						var msg = (res && res.data && res.data.message) ? res.data.message : 'respuesta inválida del servidor';
						mlNotify('Error: ' + msg, 'error');
						btn.text('🚀 Publicar TODOS con stock').prop('disabled', false);
						$prog.remove();
					}
				});
			});
		});
	});

	$('#akb-ml-save-btn').on('click', function () {
		var btn = $(this);
		btn.text('Guardando…').prop('disabled', true);
		$.post(ajaxUrl, {
			action: 'akb_ml_save_settings',
			nonce: nonce,
			client_id:              $('#akb-ml-client-id').val(),
			client_secret:          $('#akb-ml-client-secret').val(),
			access_token:           $('#akb-ml-token').val(),
			listing_type:           $('#akb-ml-listing-type').val(),
			commission_pct:         $('#akb-ml-commission').val(),
			extra_margin_pct:       $('#akb-ml-extra').val(),
			shipping_cost_estimate: $('#akb-ml-shipping').val(),
			price_rounding:         $('#akb-ml-price-rounding').val(),
			default_category:       $('#akb-ml-category').val(),
			auto_sync_stock:        $('#akb-ml-auto-stock').is(':checked') ? 1 : '',
			auto_publish_available: $('#akb-ml-auto-pub').is(':checked') ? 1 : '',
			disabled:               $('#akb-ml-disabled').is(':checked') ? 1 : ''
		}, function (res) {
			btn.text(res.success ? '✓ Guardado' : 'Guardar configuración').prop('disabled', false);
			if (res.success) {
setTimeout(function () {
btn.text('Guardar configuración'); }, 2500);
			}
		});
	});

	$('#akb-ml-test-btn').on('click', function () {
		var btn = $(this);
		btn.text('Probando…').prop('disabled', true);
		$('#akb-ml-test-result').hide();
		$.post(ajaxUrl, { action: 'akb_ml_test_connection', nonce: nonce }, function (res) {
			btn.text('🔌 Probar conexión').prop('disabled', false);
			var el = $('#akb-ml-test-result');
			if (res.success) {
				el.html('<div class="akb-notice akb-notice--success">✓ Conectado como <strong>' + res.data.nickname + '</strong> — Seller ID: ' + res.data.seller_id + ' | Site: ' + res.data.site_id + '</div>');
			} else {
				el.html('<div class="akb-notice akb-notice--warning">✗ ' + res.data.message + '</div>');
			}
			el.show();
		});
	});

	// ── PREGUNTAS ML ────────────────────────────────────────────
	function loadQuestions() {
		var status = $('#akb-ml-q-filter').val();
		$('#akb-ml-q-list').html('<div class="akb-ml-q-loading"><em>Cargando preguntas…</em></div>');
		$('#akb-ml-q-empty').hide();
		$.post(ajaxUrl, {
			action: 'akb_ml_get_questions',
			nonce: nonce,
			status: status,
			limit: 50
		}, function (res) {
			if ( ! res.success) {
				$('#akb-ml-q-list').html('<div class="akb-notice akb-notice--warning">' + escHtml(res.data.message) + '</div>');
				return;
			}
			var qs = res.data.questions;
			var total = res.data.total;

			if (status === 'UNANSWERED' && total > 0) {
				$('#akb-ml-q-badge').text(total).show();
			} else {
				$('#akb-ml-q-badge').hide();
			}

			if ( ! qs.length) {
				$('#akb-ml-q-list').html('');
				$('#akb-ml-q-empty').show();
				return;
			}

			var html = '';
			$.each(qs, function (i, q) {
				var answered = q.status === 'ANSWERED';
				var answerBlock = '';
				if (answered && q.answer_text) {
					answerBlock = '<div class="akb-ml-q-answer">'
						+ '<strong>Tu respuesta</strong> <span class="akb-ml-q-answer-time">· ' + (q.answer_date || '') + '</span><br>'
						+ '<span>' + escHtml(q.answer_text) + '</span></div>';
				} else if ( ! answered) {
					answerBlock = '<div class="akb-ml-q-compose" data-qid="' + q.id + '">'
						+ '<textarea class="akb-ml-q-textarea" rows="2" placeholder="Escribe tu respuesta…"></textarea>'
						+ '<div class="akb-ml-q-compose-actions">'
						+ '<button class="akb-btn akb-btn--primary akb-ml-q-send" data-qid="' + q.id + '">✓ Responder</button>'
						+ '<button class="akb-btn akb-btn--secondary akb-ml-q-tpl akb-ml-q-template" data-tpl="es_espanol" data-qid="' + q.id + '">🇨🇱 En español</button>'
						+ '<button class="akb-btn akb-btn--secondary akb-ml-q-tpl akb-ml-q-template" data-tpl="nuevo" data-qid="' + q.id + '">✨ Nuevo</button>'
						+ '<button class="akb-btn akb-btn--secondary akb-ml-q-tpl akb-ml-q-template" data-tpl="despacho" data-qid="' + q.id + '">📦 Despacho</button>'
						+ '</div></div>';
				}

				html += '<div class="akb-ml-q-card ' + (answered ? 'akb-ml-q-card--answered' : 'akb-ml-q-card--unanswered') + '">'
					+ '<div class="akb-ml-q-meta">'
					+ '<span class="akb-ml-q-meta-left">🛍️ <strong>' + escHtml(q.buyer) + '</strong> · ' + q.date + '</span>'
					+ '<a href="https://articulo.mercadolibre.cl/' + escHtml(q.item_id).replace(/^(MLC)(\d)/, '$1-$2') + '" target="_blank" class="akb-ml-q-item-link" title="' + escHtml(q.item_title) + '">📖 ' + escHtml(q.item_title.substring(0, 50)) + (q.item_title.length > 50 ? '…' : '') + '</a>'
					+ '</div>'
					+ '<p class="akb-ml-q-text">' + escHtml(q.text) + '</p>'
					+ answerBlock
					+ '</div>';
			});
			$('#akb-ml-q-list').html(html);
		});
	}

	var qTemplates = {
		es_espanol: 'Sí, el libro está en español. ¡Saludos!',
		nuevo: 'Sí, el producto es completamente nuevo, en perfecto estado.',
		despacho: 'Despachamos en 1-2 días hábiles por Blue Express a todo Chile. ¡Gracias por tu preferencia!'
	};

	$(document).on('click', '.akb-ml-q-tpl', function () {
		var tpl = $(this).data('tpl');
		var qid = $(this).data('qid');
		$('[data-qid="' + qid + '"] .akb-ml-q-textarea').val(qTemplates[tpl] || '');
	});

	$(document).on('click', '.akb-ml-q-send', function () {
		var btn  = $(this);
		var qid  = btn.data('qid');
		var text = $('[data-qid="' + qid + '"] .akb-ml-q-textarea').val().trim();
		if ( ! text) {
mlNotify('Escribe una respuesta primero.', 'warning'); return; }
		btn.text('Enviando…').prop('disabled', true);
		$.post(ajaxUrl, {
			action: 'akb_ml_answer_question',
			nonce: nonce,
			question_id: qid,
			text: text
		}, function (res) {
			if (res.success) {
				var card = btn.closest('.akb-ml-q-card');
				card.removeClass('akb-ml-q-card--unanswered').addClass('akb-ml-q-card--answered');
				card.find('[data-qid]').html(
					'<div class="akb-ml-q-answer-sent">'
					+ '✓ Respuesta enviada: <em>' + escHtml(text) + '</em></div>'
				);
				var badge = $('#akb-ml-q-badge');
				var n = parseInt(badge.text()) - 1;
				if (n <= 0) {
badge.hide(); } else {
badge.text(n);
				}
			} else {
				mlNotify('Error: ' + res.data.message, 'error');
				btn.text('✓ Responder').prop('disabled', false);
			}
		});
	});

	$('#akb-ml-q-refresh').on('click', loadQuestions);
	$('#akb-ml-q-filter').on('change', loadQuestions);

	// ── Price override click handler ────────────────────────────
	$(document).on('click', '.akb-ml-price-edit', function () {
		var $el = $(this);
		var pid = $el.data('id');
		var currentOverride = $el.data('override') || 0;
		var calcPrice = $el.data('calc');
		var msg = currentOverride > 0
			? 'Precio manual actual: $' + currentOverride.toLocaleString('es-CL') + '\n\nIngresa nuevo precio ML (0 = volver a fórmula automática):'
			: 'Precio calculado: $' + calcPrice.toLocaleString('es-CL') + '\n\nIngresa precio ML manual (0 = mantener fórmula):';
		mlPrompt(msg, currentOverride || '', function (input) {
			var newPrice = parseInt(input) || 0;

			$.post(ajaxUrl, {
				action: 'akb_ml_set_price_override',
				nonce: nonce,
				product_id: pid,
				override: newPrice
			}, function (res) {
				if (res.success) {
					var isOverride = res.data.override > 0;
					$el.data('override', res.data.override);
					$el.data('calc', res.data.ml_calc);
					$el.toggleClass('akb-ml-price-edit--override', isOverride);
					$el.toggleClass('akb-ml-price-edit--calc', ! isOverride);
					$el.attr('title', isOverride ? 'Precio manual \u2014 clic para editar' : 'Precio calculado \u2014 clic para fijar manual');
					$el.html(fmtCLP(res.data.ml_calc) + (isOverride ? ' \u270f\ufe0f' : ''));
				} else {
					mlNotify('Error: ' + (res.data.message || 'desconocido'), 'error');
				}
			});
		});
	});

	// Auto-cargar preguntas al abrir el tab
	loadQuestions();

	// ── Stats dashboard ─────────────────────────────────────────
	$.post(ajaxUrl, { action: 'akb_ml_get_stats', nonce: nonce }, function (res) {
		if ( ! res.success) {
return;
		}
		var d = res.data;
		$('#akb-ml-stat-orders').text(d.orders_month);
		$('#akb-ml-stat-revenue').text(
			d.revenue_month > 0
				? '$' + Math.round(d.revenue_month).toLocaleString('es-CL')
				: '$0'
		);
	});

	// ── Filtros editorial/serie ─────────────────────────────────
	$.post(ajaxUrl, { action: 'akb_ml_get_filter_options', nonce: nonce }, function (res) {
		if ( ! res.success) {
return;
		}
		var d = res.data;
		var $ed = $('#akb-ml-editorial');
		$.each(d.editoriales, function (i, name) {
			$ed.append('<option value="' + escHtml(name) + '">' + escHtml(name) + '</option>');
		});
		var $sr = $('#akb-ml-serie');
		$.each(d.series, function (i, s) {
			$sr.append('<option value="' + s.id + '">' + escHtml(s.name) + '</option>');
		});
	});

	$('#akb-ml-editorial, #akb-ml-serie').on('change', function () {
loadProducts(1); });

}(jQuery));
