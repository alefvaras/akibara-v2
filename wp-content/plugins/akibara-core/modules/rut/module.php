<?php
/**
 * Akibara Core — Módulo RUT Chile para Checkout
 *
 * Campo de RUT en el checkout de WooCommerce:
 *  - Validación Módulo 11 (dígito verificador)
 *  - Auto-formato con puntos y guión (XX.XXX.XXX-X)
 *  - Validación frontend (JS) + backend (PHP)
 *  - Guardado en order meta + user meta
 *  - Visible en admin order detail
 *  - Compatible con HPOS
 *  - Patrón anti-fraude (rechaza RUTs repetitivos)
 *
 * Migrado desde akibara/modules/rut/module.php (Polish #1 2026-04-26).
 * Group-wrap pattern + sentinel per HANDOFF §8 (REDESIGN.md §9).
 *
 * @package    Akibara\Core
 * @subpackage RUT
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ─── File-level guard ───────────────────────────────────────────────────────
if ( defined( 'AKB_CORE_RUT_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_RUT_LOADED', '1.0.0' );

// Backward-compat: módulo legacy define AKIBARA_RUT_LOADED — si el legacy
// cargó primero (migración parcial), no redeclares.
if ( defined( 'AKIBARA_RUT_LOADED' ) ) {
	return;
}

// Constant signal per ModuleRegistry pattern.
if ( ! defined( 'AKB_CORE_MODULE_RUT_LOADED' ) ) {
	define( 'AKB_CORE_MODULE_RUT_LOADED', '1.0.0' );
}

// ─── Group wrap (functions inside NO se hoistean — REDESIGN.md §9) ─────────
if ( ! function_exists( 'akibara_rut_checkout_field' ) ) {

	// ══════════════════════════════════════════════════════════════════
	// 1. AGREGAR CAMPO AL CHECKOUT
	// ══════════════════════════════════════════════════════════════════

	add_filter( 'woocommerce_checkout_fields', 'akibara_rut_checkout_field' );

	function akibara_rut_checkout_field( array $fields ): array {
		$fields['billing']['billing_rut'] = array(
			'type'              => 'text',
			'label'             => 'RUT',
			'placeholder'       => 'Ej: 12.345.678-5',
			'required'          => true,
			'class'             => array( 'form-row-wide', 'akb-rut-field' ),
			'priority'          => 25, // After name, before company
			'maxlength'         => 12,
			'custom_attributes' => array(
				'inputmode'    => 'text',
				'autocomplete' => 'off',
				'pattern'      => '[0-9]{1,2}\\.?[0-9]{3}\\.?[0-9]{3}-?[0-9kK]',
			),
		);

		return $fields;
	}

	// Pre-fill if user has RUT saved
	add_filter( 'woocommerce_checkout_get_value', 'akibara_rut_prefill', 10, 2 );

	function akibara_rut_prefill( $value, string $input ) {
		if ( 'billing_rut' !== $input ) {
			return $value;
		}

		if ( is_user_logged_in() ) {
			$saved = get_user_meta( get_current_user_id(), 'billing_rut', true );
			if ( ! empty( $saved ) ) {
				return $saved;
			}
		}

		return $value;
	}

	// ══════════════════════════════════════════════════════════════════
	// 2. VALIDACIÓN BACKEND (Módulo 11)
	// ══════════════════════════════════════════════════════════════════

	add_action( 'woocommerce_checkout_process', 'akibara_rut_validate' );

	function akibara_rut_validate(): void {
		// Nonce 'woocommerce-process_checkout' validado por WC core antes del hook.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$rut_raw = sanitize_text_field( wp_unslash( $_POST['billing_rut'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $rut_raw ) ) {
			wc_add_notice( 'El RUT es obligatorio.', 'error' );
			return;
		}

		// Clean: remove dots and dashes
		$rut_clean = strtoupper( preg_replace( '/[^0-9kK]/', '', $rut_raw ) );

		if ( strlen( $rut_clean ) < 8 || strlen( $rut_clean ) > 9 ) {
			wc_add_notice( 'El RUT ingresado no tiene un formato válido.', 'error' );
			return;
		}

		// Split number and verifier
		$dv     = substr( $rut_clean, -1 );
		$numero = substr( $rut_clean, 0, -1 );

		// Anti-fraud: reject repetitive patterns
		if ( preg_match( '/^(\d)\1+$/', $numero ) ) {
			wc_add_notice( 'El RUT ingresado no es válido.', 'error' );
			return;
		}

		// Módulo 11
		$calculated_dv = akibara_rut_calculate_dv( $numero );

		if ( strtoupper( $dv ) !== strtoupper( $calculated_dv ) ) {
			wc_add_notice( 'El RUT ingresado no es válido. Revisa el dígito verificador.', 'error' );
			return;
		}
	}

	/**
	 * Calculate dígito verificador using Módulo 11.
	 */
	function akibara_rut_calculate_dv( string $numero ): string {
		$sum = 0;
		$mul = 2;

		for ( $i = strlen( $numero ) - 1; $i >= 0; $i-- ) {
			$sum += (int) $numero[ $i ] * $mul;
			$mul  = $mul === 7 ? 2 : $mul + 1;
		}

		$remainder = $sum % 11;

		if ( $remainder === 0 ) {
			return '0';
		}
		if ( $remainder === 1 ) {
			return 'K';
		}

		return (string) ( 11 - $remainder );
	}

	/**
	 * Format RUT: 12345678-5 → 12.345.678-5
	 */
	function akibara_rut_format( string $rut ): string {
		$clean = strtoupper( preg_replace( '/[^0-9kK]/', '', $rut ) );
		if ( strlen( $clean ) < 2 ) {
			return $rut;
		}

		$dv     = substr( $clean, -1 );
		$numero = substr( $clean, 0, -1 );

		return number_format( (int) $numero, 0, '', '.' ) . '-' . $dv;
	}

	// ══════════════════════════════════════════════════════════════════
	// 3. GUARDAR EN ORDER + USER META
	// ══════════════════════════════════════════════════════════════════

	add_action( 'woocommerce_checkout_order_created', 'akibara_rut_save_order' );

	function akibara_rut_save_order( $order ): void {
		// Nonce validado por WC core antes del hook.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$rut_raw = sanitize_text_field( wp_unslash( $_POST['billing_rut'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( empty( $rut_raw ) ) {
			return;
		}

		$rut_formatted = akibara_rut_format( $rut_raw );

		// Save to order
		if ( $order ) {
			$order->update_meta_data( '_billing_rut', $rut_formatted );
			$order->save();
		}

		// Save to user meta for auto-fill
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), 'billing_rut', $rut_formatted );
		}
	}

	// ══════════════════════════════════════════════════════════════════
	// 4. MOSTRAR EN ADMIN ORDER
	// ══════════════════════════════════════════════════════════════════

	add_filter( 'woocommerce_admin_billing_fields', 'akibara_rut_admin_field' );

	function akibara_rut_admin_field( array $fields ): array {
		$fields['rut'] = array(
			'label' => 'RUT',
			'show'  => true,
		);
		return $fields;
	}

	// Show in order emails
	add_filter( 'woocommerce_email_order_meta_fields', 'akibara_rut_email_field', 10, 3 );

	function akibara_rut_email_field( array $fields, bool $sent_to_admin, $order ): array {
		$rut = $order->get_meta( '_billing_rut' );
		if ( ! empty( $rut ) ) {
			$fields['billing_rut'] = array(
				'label' => 'RUT',
				'value' => $rut,
			);
		}
		return $fields;
	}

	// Show in My Account → Order details
	add_action( 'woocommerce_order_details_after_customer_details', 'akibara_rut_order_details' );

	function akibara_rut_order_details( $order ): void {
		$rut = $order->get_meta( '_billing_rut' );
		if ( ! empty( $rut ) ) {
			echo '<tr><th>RUT:</th><td>' . esc_html( $rut ) . '</td></tr>';
		}
	}

	// ══════════════════════════════════════════════════════════════════
	// 5. JAVASCRIPT — Auto-format + real-time validation
	// ══════════════════════════════════════════════════════════════════

	add_action( 'wp_footer', 'akibara_rut_js', 50 );

	function akibara_rut_js(): void {
		if ( ! is_checkout() ) {
			return;
		}
		?>
		<script>
		(function(){
			'use strict';

			function calcDV(num) {
				var sum = 0, mul = 2;
				for (var i = num.length - 1; i >= 0; i--) {
					sum += parseInt(num.charAt(i)) * mul;
					mul = mul === 7 ? 2 : mul + 1;
				}
				var r = sum % 11;
				if (r === 0) return '0';
				if (r === 1) return 'K';
				return String(11 - r);
			}

			function formatRut(value) {
				var clean = value.replace(/[^0-9kK]/g, '').toUpperCase();
				if (clean.length < 2) return clean;

				var dv = clean.slice(-1);
				var num = clean.slice(0, -1);

				// Add dots
				var formatted = '';
				for (var i = num.length - 1, count = 0; i >= 0; i--, count++) {
					if (count > 0 && count % 3 === 0) formatted = '.' + formatted;
					formatted = num.charAt(i) + formatted;
				}

				return formatted + '-' + dv;
			}

			function validateRut(value) {
				var clean = value.replace(/[^0-9kK]/g, '').toUpperCase();
				if (clean.length < 8 || clean.length > 9) return false;

				var dv = clean.slice(-1);
				var num = clean.slice(0, -1);

				// Anti-fraud
				if (/^(\d)\1+$/.test(num)) return false;

				return dv === calcDV(num);
			}

			// Wait for WooCommerce to render the field
			function init() {
				var field = document.getElementById('billing_rut');
				if (!field) {
					setTimeout(init, 500);
					return;
				}

				var wrapper = field.closest('.akb-rut-field') || field.parentElement;
				var feedback = document.createElement('span');
				feedback.className = 'akb-rut-feedback';
				feedback.style.cssText = 'font-size:12px;margin-top:4px;display:block;transition:color .2s';
				wrapper.appendChild(feedback);

				// Auto-format on input
				field.addEventListener('input', function() {
					var pos = this.selectionStart;
					var oldLen = this.value.length;

					this.value = formatRut(this.value);

					var newLen = this.value.length;
					var newPos = pos + (newLen - oldLen);
					this.setSelectionRange(newPos, newPos);

					// Real-time validation
					var clean = this.value.replace(/[^0-9kK]/g, '');
					var row = field.closest('.form-row');
					if (clean.length >= 8) {
						if (validateRut(this.value)) {
							feedback.textContent = 'RUT valido';
							feedback.style.color = '#00c853';
							field.style.borderColor = '#00c853';
							if (row) { row.classList.remove('woocommerce-invalid', 'woocommerce-invalid-required-field'); row.classList.add('woocommerce-validated'); }
						} else {
							feedback.textContent = 'RUT invalido';
							feedback.style.color = '#D90010';
							field.style.borderColor = '#D90010';
							if (row) { row.classList.remove('woocommerce-validated'); row.classList.add('woocommerce-invalid'); }
						}
					} else {
						feedback.textContent = '';
						field.style.borderColor = '';
						if (row) { row.classList.remove('woocommerce-validated', 'woocommerce-invalid'); }
					}
				});

				// Format on blur
				field.addEventListener('blur', function() {
					if (this.value) {
						this.value = formatRut(this.value);
					}
				});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>
		<style>
		.akb-rut-field input { text-transform: uppercase; }
		.akb-rut-feedback { min-height: 18px; }
		</style>
		<?php
	}

} // end group wrap
