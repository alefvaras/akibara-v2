<?php
/**
 * Akibara Core — Módulo Teléfono Chile para Checkout
 *
 * Validación y auto-formato estilo Falabella/Paris:
 *  - Chip visual "+56 9" fijo a la izquierda del input (CSS pseudo).
 *  - Input acepta 8 dígitos (móvil chileno), auto-formato "1234 5678".
 *  - Validación frontend (JS) + backend (PHP).
 *  - Acepta también legacy "9XXXXXXXX" o "+56 9 XXXX XXXX" (pre-fill).
 *  - Helper text explicativo (Baymard: reduce abandono 14%).
 *  - Storage: normaliza a "+56 9 XXXX XXXX" antes de guardar.
 *
 * Migrado desde akibara/modules/phone/module.php (Polish #1 2026-04-26).
 * Group-wrap pattern + sentinel per HANDOFF §8 (REDESIGN.md §9).
 *
 * @package    Akibara\Core
 * @subpackage Phone
 * @version    2.0.0
 */

defined( 'ABSPATH' ) || exit;

// ─── File-level guard ───────────────────────────────────────────────────────
if ( defined( 'AKB_CORE_PHONE_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_PHONE_LOADED', '2.0.0' );

// Backward-compat: si el legacy ya cargó AKIBARA_PHONE_LOADED, no redeclares.
if ( defined( 'AKIBARA_PHONE_LOADED' ) ) {
	return;
}

// Constant signal per ModuleRegistry pattern.
if ( ! defined( 'AKB_CORE_MODULE_PHONE_LOADED' ) ) {
	define( 'AKB_CORE_MODULE_PHONE_LOADED', '2.0.0' );
}

// ─── Group wrap (REDESIGN.md §9) ────────────────────────────────────────────
if ( ! function_exists( 'akibara_phone_modify_field' ) ) {

	// ══════════════════════════════════════════════════════════════════
	// 1. MODIFICAR CAMPO DEL CHECKOUT
	// ══════════════════════════════════════════════════════════════════

	add_filter( 'woocommerce_checkout_fields', 'akibara_phone_modify_field' );

	function akibara_phone_modify_field( array $fields ): array {
		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['label']             = 'Celular';
			$fields['billing']['billing_phone']['placeholder']       = '1234 5678';
			$fields['billing']['billing_phone']['description']       = 'Te avisaremos del estado de tu envío por WhatsApp o SMS.';
			$fields['billing']['billing_phone']['class'][]           = 'akb-phone-field';
			$fields['billing']['billing_phone']['custom_attributes'] = array_merge(
				$fields['billing']['billing_phone']['custom_attributes'] ?? array(),
				array(
					'inputmode'    => 'numeric',
					'maxlength'    => 9,  // "XXXX XXXX" = 9 chars con espacio
					'autocomplete' => 'tel-national',
				)
			);
		}
		return $fields;
	}

	// ══════════════════════════════════════════════════════════════════
	// 2. VALIDACIÓN BACKEND
	// ══════════════════════════════════════════════════════════════════

	add_action( 'woocommerce_checkout_process', 'akibara_phone_validate' );

	function akibara_phone_validate(): void {
		// Nonce 'woocommerce-process_checkout' validado por WC core antes del hook.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$phone_raw = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $phone_raw ) ) {
			wc_add_notice( 'El celular es obligatorio.', 'error' );
			return;
		}

		$digits = akibara_phone_extract_mobile_digits( $phone_raw );

		if ( strlen( $digits ) !== 8 ) {
			wc_add_notice(
				'El celular debe tener 8 dígitos. Ejemplo: 1234 5678.',
				'error'
			);
			return;
		}

		// Anti-fraude: rechazar números obviamente falsos.
		if ( preg_match( '/^(\d)\1+$/', $digits ) ) {
			wc_add_notice( 'El celular ingresado no parece válido.', 'error' );
			return;
		}

		// Los primeros dígitos 0 y 1 no son móviles asignados en Chile.
		if ( preg_match( '/^[01]/', $digits ) ) {
			wc_add_notice( 'El celular no parece válido. Revisa el número.', 'error' );
			return;
		}
	}

	/**
	 * Extrae los 8 dígitos "nacionales" de un móvil chileno, descartando
	 * prefijos +56 / 56 / 9 iniciales y cualquier formato (espacios, guiones).
	 *
	 * Ejemplos:
	 *   "1234 5678"            → "12345678"
	 *   "+56 9 1234 5678"      → "12345678"
	 *   "912345678"            → "12345678"
	 *   "+56912345678"         → "12345678"
	 *   "56912345678"          → "12345678"
	 *
	 * Si el input no calza con ninguna variante → string vacío.
	 */
	function akibara_phone_extract_mobile_digits( string $phone ): string {
		$clean = preg_replace( '/\D/', '', $phone );

		if ( $clean === '' ) {
			return '';
		}

		$len = strlen( $clean );

		if ( $len === 8 ) {
			return $clean;
		}

		if ( $len === 9 && $clean[0] === '9' ) {
			return substr( $clean, 1 );
		}

		if ( $len === 11 && str_starts_with( $clean, '569' ) ) {
			return substr( $clean, 3 );
		}

		return '';
	}

	// ══════════════════════════════════════════════════════════════════
	// 3. NORMALIZAR ANTES DE GUARDAR
	// ══════════════════════════════════════════════════════════════════

	add_action( 'woocommerce_checkout_update_order_meta', 'akibara_phone_normalize_order', 5, 2 );

	function akibara_phone_normalize_order( int $order_id, array $data ): void {
		// Nonce validado por WC core antes del hook.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$phone_raw = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( empty( $phone_raw ) ) {
			return;
		}

		$normalized = akibara_phone_format( $phone_raw );

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->set_billing_phone( $normalized );
			$order->save();
		}
	}

	/**
	 * Normaliza un teléfono chileno a formato "+56 9 XXXX XXXX".
	 *
	 * Si no puede extraer 8 dígitos válidos, retorna el input original
	 * (fail-safe para no perder el dato en edge cases raros).
	 */
	function akibara_phone_format( string $phone ): string {
		$digits = akibara_phone_extract_mobile_digits( $phone );

		if ( strlen( $digits ) !== 8 ) {
			return $phone;
		}

		return '+56 9 ' . substr( $digits, 0, 4 ) . ' ' . substr( $digits, 4, 4 );
	}

	// ══════════════════════════════════════════════════════════════════
	// 4. ASSETS — JS externo + CSS para el chip "+56 9"
	// ══════════════════════════════════════════════════════════════════

	add_action( 'wp_enqueue_scripts', 'akibara_phone_enqueue_assets' );

	function akibara_phone_enqueue_assets(): void {
		if ( ! is_checkout() ) {
			return;
		}

		// Versión basada en la constante de este módulo core.
		$ver = AKB_CORE_PHONE_LOADED . '.5';

		wp_enqueue_script(
			'akibara-phone',
			plugin_dir_url( __FILE__ ) . 'phone.js',
			array( 'jquery' ),
			$ver,
			true
		);

		wp_enqueue_style(
			'akibara-phone',
			plugin_dir_url( __FILE__ ) . 'phone.css',
			array(),
			$ver
		);
	}

	/**
	 * Excluir nuestros assets de defer/delay/combine de LiteSpeed.
	 */
	add_filter(
		'litespeed_optm_js_defer_exc',
		function ( $exc ) {
			if ( ! is_array( $exc ) ) {
				$exc = preg_split( '/[\r\n]+/', (string) $exc ) ?: array();
			}
			$exc[] = 'akibara-phone';
			$exc[] = 'phone.js';
			return array_values( array_unique( array_filter( array_map( 'trim', $exc ) ) ) );
		}
	);

	/**
	 * Pre-filtrar el valor del campo al renderear el checkout:
	 * si el usuario tiene guardado "+56 9 XXXX XXXX" (o legacy "9XXXXXXXX"),
	 * mostrar solo los 8 dígitos "XXXX XXXX".
	 */
	add_filter(
		'woocommerce_checkout_get_value',
		function ( $value, $input ) {
			if ( 'billing_phone' !== $input ) {
				return $value;
			}

			$digits = akibara_phone_extract_mobile_digits( (string) $value );
			if ( strlen( $digits ) !== 8 ) {
				return $value;
			}

			return substr( $digits, 0, 4 ) . ' ' . substr( $digits, 4, 4 );
		},
		10,
		2
	);

} // end group wrap
