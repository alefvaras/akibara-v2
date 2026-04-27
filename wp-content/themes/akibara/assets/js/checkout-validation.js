/**
 * Akibara Checkout Field Validation — A4 (a11y)
 *
 * Populates the .checkout-field-hint container that is injected by
 * akibara_checkout_field_aria_hint() (filter woocommerce_form_field).
 * Each input/select/textarea has aria-describedby="{key}_description"
 * pointing to a sibling <p id="{key}_description"> with role="alert" +
 * aria-live="polite". When this script writes a message into that node,
 * screen readers announce it automatically.
 *
 * Triggers:
 *   - focusout / change : evaluate, populate hint if invalid, clear if valid.
 *   - input             : if user is typing in an already-flagged field,
 *                         re-evaluate (so the message disappears as soon as
 *                         they fix it).
 *
 * WooCommerce core's checkout.min.js sets `aria-invalid="true"` on focusout
 * already, but does NOT create the inline error node nor wire aria-describedby
 * (only does that on submit via show_inline_errors). This script fills that
 * gap so SR users hear errors at focusout, matching the visual experience.
 *
 * Keep messages short & action-oriented, no Argentine voseo (R5).
 *
 * @package Akibara
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) return;

	// ── Patrones de validación (alineados con backend WC + módulos custom) ──
	var EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	// Phone CL: +56 9 XXXX XXXX o 9XXXXXXXX (8-9 dígitos tras código país).
	// Acepta espacios y guiones, pero exige al menos 8 dígitos.
	var PHONE_REGEX = /^[+\d][\d\s\-]{7,}$/;
	// RUT chileno: 7-8 dígitos + DV (0-9 o K). Validación liviana — el módulo
	// RUT del tema corre el cálculo del DV; acá solo flag de "obviamente vacío
	// o malformado".
	var RUT_REGEX = /^\d{1,2}\.?\d{3}\.?\d{3}-?[\dKk]$/;

	// ── Mensajes en español neutro chileno (sin voseo). ──
	var MESSAGES = {
		required: 'Este campo es obligatorio',
		email:    'Ingresa un email válido',
		phone:    'Ingresa un teléfono válido',
		rut:      'Ingresa un RUT válido',
	};

	/**
	 * Resuelve el container .checkout-field-hint asociado a un input.
	 *
	 * Estrategia: aria-describedby apunta al ID del hint. Si por algún motivo
	 * el filter PHP no se aplicó (campo dinámico de plugin), buscamos por
	 * convención `{id}_description`.
	 */
	function getHintEl( inp ) {
		var describedby = inp.getAttribute( 'aria-describedby' );
		if ( describedby ) {
			// Puede haber múltiples IDs — buscamos el que corresponde a nuestro hint.
			var ids = describedby.split( /\s+/ );
			for ( var i = 0; i < ids.length; i++ ) {
				var el = document.getElementById( ids[ i ] );
				if ( el && el.classList.contains( 'checkout-field-hint' ) ) {
					return el;
				}
			}
		}
		// Fallback por convención.
		var fallback = inp.id ? document.getElementById( inp.id + '_description' ) : null;
		if ( fallback && fallback.classList.contains( 'checkout-field-hint' ) ) {
			return fallback;
		}
		return null;
	}

	/**
	 * Determina el tipo de validación para un input.
	 *
	 * Priorities:
	 *   1. Email field (type=email o id=*_email).
	 *   2. RUT field (id=billing_rut).
	 *   3. Phone field (id=*_phone, type=tel).
	 *   4. Required (default si .form-row tiene .validate-required).
	 */
	function classifyField( inp, $row ) {
		var id   = inp.id || '';
		var type = ( inp.getAttribute( 'type' ) || '' ).toLowerCase();

		if ( type === 'email' || /_email$/.test( id ) ) return 'email';
		if ( id === 'billing_rut' )                     return 'rut';
		if ( type === 'tel' || /_phone$/.test( id ) )   return 'phone';

		// Si la row está marcada como required pero no tiene tipo específico, va por required.
		if ( $row.hasClass( 'validate-required' ) ) return 'required';

		return null;
	}

	/**
	 * Evalúa un input y retorna un mensaje de error (o '' si está OK).
	 */
	function evaluate( inp, kind ) {
		var v = ( inp.value || '' ).trim();

		// Required check primero (aplica a todos los kinds salvo si row no es required).
		var $row = $( inp ).closest( '.form-row' );
		var isRequired = $row.hasClass( 'validate-required' );

		if ( ! v ) {
			return isRequired ? MESSAGES.required : '';
		}

		// Type-specific format check (solo si hay valor).
		switch ( kind ) {
			case 'email':
				return EMAIL_REGEX.test( v ) ? '' : MESSAGES.email;
			case 'phone':
				return PHONE_REGEX.test( v ) ? '' : MESSAGES.phone;
			case 'rut':
				// Si el módulo RUT marcó .woocommerce-invalid en la row, propagamos.
				// Si no, validamos shape básico.
				if ( $row.hasClass( 'woocommerce-invalid' ) ) return MESSAGES.rut;
				return RUT_REGEX.test( v ) ? '' : MESSAGES.rut;
		}
		return '';
	}

	/**
	 * Popula o limpia el hint container para un input.
	 */
	function updateHint( inp ) {
		var $row = $( inp ).closest( '.form-row' );
		// Solo en checkout — el filter PHP ya scopea pero defensivamente.
		if ( ! $row.length ) return;

		var hint = getHintEl( inp );
		if ( ! hint ) return;

		var kind = classifyField( inp, $row );
		if ( ! kind ) {
			// Sin reglas conocidas — limpiar para no dejar ruido.
			if ( hint.textContent ) hint.textContent = '';
			return;
		}

		var msg = evaluate( inp, kind );

		// Solo escribir si cambió (evita re-anuncios redundantes en SR si user
		// sigue tipeando con el mismo error pendiente).
		if ( hint.textContent !== msg ) {
			hint.textContent = msg;
		}
	}

	$( function () {
		var $form = $( 'form.woocommerce-checkout' );
		if ( ! $form.length ) return;

		// focusout cubre tab-out / click-out — momento canónico para validar.
		// change cubre <select> que no disparan focusout en algunos navegadores.
		$form.on( 'focusout change', '.input-text, select, textarea', function () {
			updateHint( this );
		} );

		// input: solo re-evaluar si el campo YA estaba marcado como inválido,
		// para no spammear al user mientras tipea por primera vez. Pattern
		// estándar UX: errors-after-blur, recovery-on-typing.
		$form.on( 'input', '.input-text, textarea', function () {
			var $row = $( this ).closest( '.form-row' );
			if ( $row.hasClass( 'woocommerce-invalid' ) || this.getAttribute( 'aria-invalid' ) === 'true' ) {
				updateHint( this );
			}
		} );

		// updated_checkout dispara cuando WC re-renderiza fragmentos del form
		// (ej: cambio de método de envío). En ese momento los hints se pueden
		// quedar con texto stale si el DOM cambió. Limpiamos hints vacíos
		// donde el campo ya está válido.
		$( document.body ).on( 'updated_checkout', function () {
			$form.find( '.checkout-field-hint' ).each( function () {
				if ( ! this.textContent ) return;
				// Buscar el input asociado por convención de ID.
				var fieldId = this.id.replace( /_description$/, '' );
				var inp     = document.getElementById( fieldId );
				if ( inp ) updateHint( inp );
			} );
		} );
	} );

} )( window.jQuery );
