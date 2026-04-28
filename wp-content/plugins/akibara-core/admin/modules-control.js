/**
 * Akibara Core — Modules Control toggle handler.
 *
 * AJAX update on toggle change. Confirmación para módulos críticos.
 * Vanilla JS, no jQuery dependency.
 */
(function () {
	'use strict';

	if ( ! window.akibaraModules ) {
		return;
	}

	const cfg = window.akibaraModules;
	const inputs = document.querySelectorAll( '.akb-toggle__input' );

	inputs.forEach( ( input ) => {
		input.addEventListener( 'change', async ( e ) => {
			const checkbox = e.currentTarget;
			const module = checkbox.dataset.module;
			const isCritical = checkbox.dataset.critical === '1';
			const newState = checkbox.checked;

			// Confirmación para módulos críticos al desactivar.
			if ( isCritical && ! newState ) {
				if ( ! confirm( cfg.strings.confirmCritical ) ) {
					checkbox.checked = true; // revertir
					return;
				}
			}

			// Optimistic UI update.
			const row = checkbox.closest( '.akb-module-row' );
			const labelEl = checkbox.parentElement.querySelector( '.akb-toggle__label' );

			if ( row ) {
				row.classList.toggle( 'is-enabled', newState );
				row.classList.toggle( 'is-disabled', ! newState );
			}
			if ( labelEl ) {
				labelEl.textContent = newState ? cfg.strings.enabled : cfg.strings.disabled;
			}

			// AJAX call.
			try {
				const formData = new FormData();
				formData.append( 'action', 'akibara_toggle_module' );
				formData.append( 'module', module );
				formData.append( 'enabled', newState ? '1' : '0' );
				formData.append( 'nonce', cfg.nonce );

				const response = await fetch( cfg.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
				} );

				const data = await response.json();

				if ( ! data.success ) {
					throw new Error( ( data.data && data.data.message ) || 'unknown' );
				}

				showToast( newState ? cfg.strings.savedOn : cfg.strings.savedOff, 'success' );
			} catch ( err ) {
				// Revert UI on error.
				checkbox.checked = ! newState;
				if ( row ) {
					row.classList.toggle( 'is-enabled', ! newState );
					row.classList.toggle( 'is-disabled', newState );
				}
				if ( labelEl ) {
					labelEl.textContent = ! newState ? cfg.strings.enabled : cfg.strings.disabled;
				}
				showToast( cfg.strings.errorSave, 'error' );
				// eslint-disable-next-line no-console
				console.error( '[akibara-modules]', err );
			}
		} );
	} );

	/**
	 * Toast notification (top-right, auto-dismiss).
	 */
	function showToast( message, type ) {
		const toast = document.createElement( 'div' );
		toast.className = 'akb-toast akb-toast--' + ( type || 'success' );
		toast.setAttribute( 'role', 'status' );
		toast.setAttribute( 'aria-live', 'polite' );
		toast.textContent = message;

		document.body.appendChild( toast );

		// Animate in.
		requestAnimationFrame( () => toast.classList.add( 'akb-toast--visible' ) );

		// Auto-dismiss.
		setTimeout( () => {
			toast.classList.remove( 'akb-toast--visible' );
			setTimeout( () => toast.remove(), 300 );
		}, 2400 );
	}
})();
