/**
 * Akibara Reservas — Product editor conditional fields.
 */
(function($) {
	'use strict';

	function toggleFields() {
		var enabled   = $('#_akb_reserva').is(':checked');
		var tipo      = $('#_akb_reserva_tipo').val();
		var fechaModo = $('#_akb_reserva_fecha_modo').val();

		// Mostrar/ocultar campos de reserva
		$('.akb-reserva-fields').toggle(enabled);

		// Descuento solo visible en preventa
		$('#_akb_reserva_descuento').closest('.form-field').toggle(tipo === 'preventa');

		// Fecha solo visible si hay modo con fecha
		$('#_akb_reserva_fecha').closest('.form-field').toggle(fechaModo !== 'sin_fecha');
	}

	$(document).ready(function() {
		toggleFields();

		$('#_akb_reserva').on('change', toggleFields);
		$('#_akb_reserva_tipo').on('change', toggleFields);
		$('#_akb_reserva_fecha_modo').on('change', toggleFields);
	});

	// Soporte para variaciones (cargadas via AJAX)
	$(document).on('woocommerce_variations_loaded', function() {
		$('.akb-reserva-variation').each(function() {
			var $wrap = $(this);
			var $chk  = $wrap.find('[id^="_akb_reserva_"]').filter(':checkbox');
			var $tipo = $wrap.find('[id^="_akb_reserva_tipo_"]');
			var $modo = $wrap.find('[id^="_akb_reserva_fecha_modo_"]');
			var $desc = $wrap.find('[id^="_akb_reserva_descuento_"]');
			var $fech = $wrap.find('[id^="_akb_reserva_fecha_"]').filter('[type="date"]');

			function toggleVar() {
				var on = $chk.is(':checked');
				$wrap.find('.form-row').not($chk.closest('.form-row')).toggle(on);
				$desc.closest('.form-row').toggle($tipo.val() === 'preventa');
				$fech.closest('.form-row').toggle($modo.val() !== 'sin_fecha');
			}

			toggleVar();
			$chk.on('change', toggleVar);
			$tipo.on('change', toggleVar);
			$modo.on('change', toggleVar);
		});
	});

})(jQuery);
