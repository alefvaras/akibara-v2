<?php
/**
 * Akibara Core — Módulo Checkout Validation (Calidad de datos)
 *
 * Implementa 3 mejoras priorizadas para evitar pedidos con datos sucios:
 *
 *  A. Email typo-fix: sugiere corrección si el dominio tiene typo común
 *     (gmial→gmail, hotmial→hotmail, yahho→yahoo, etc.).
 *
 *  B. Nombres: minlength=2, regex letras/espacios/apóstrofe/guión,
 *     anti-fraude (rechaza chars repetidos como "aaaa").
 *
 *  C. Dirección: minlength=5, debe contener al menos un dígito
 *     (número de calle), anti-fraude. Skip en modo Metro (retiro gratis).
 *
 * Validación tanto frontend (JS) para feedback inmediato, como backend
 * (PHP) para seguridad (no se puede saltar editando JS).
 *
 * Migrado desde akibara/modules/checkout-validation/module.php (Polish #1 2026-04-26).
 * Group-wrap pattern + sentinel per HANDOFF §8 (REDESIGN.md §9).
 *
 * @package    Akibara\Core
 * @subpackage CheckoutValidation
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

// ─── File-level guard ───────────────────────────────────────────────────────
if ( defined( 'AKB_CORE_CHECKOUT_VALIDATION_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_CHECKOUT_VALIDATION_LOADED', '1.0.0' );

// Backward-compat: si el legacy definió AKIBARA_CHECKOUT_VALIDATION_LOADED, no redeclares.
if ( defined( 'AKIBARA_CHECKOUT_VALIDATION_LOADED' ) ) {
	return;
}

// Constant signal per ModuleRegistry pattern.
if ( ! defined( 'AKB_CORE_MODULE_CHECKOUT_VALIDATION_LOADED' ) ) {
	define( 'AKB_CORE_MODULE_CHECKOUT_VALIDATION_LOADED', '1.0.0' );
}

// ─── Group wrap (REDESIGN.md §9) ────────────────────────────────────────────
if ( ! function_exists( 'akibara_validation_get_mode' ) ) {

	// ══════════════════════════════════════════════════════════════════
	// HELPERS
	// ══════════════════════════════════════════════════════════════════

	/**
	 * Detecta el modo de entrega seleccionado en el checkout actual,
	 * leyendo POST data tanto en submit final como en AJAX update.
	 *
	 * @return string 'home' | 'pudo' | 'metro'
	 */
	function akibara_validation_get_mode(): string {
		$post_data = array();

		// Lee POST sin nonce porque corre en hooks WC (woocommerce_update_order_review/checkout_process)
		// que ya validan su propio nonce 'update-order-review' / 'woocommerce-process_checkout'.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['post_data'] ) ) {
			parse_str( sanitize_text_field( wp_unslash( $_POST['post_data'] ) ), $post_data );
		}

		if ( isset( $_POST['akibara_delivery_mode'] ) ) {
			$post_data['akibara_delivery_mode'] = sanitize_text_field( wp_unslash( $_POST['akibara_delivery_mode'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$mode = isset( $post_data['akibara_delivery_mode'] )
			? sanitize_key( (string) $post_data['akibara_delivery_mode'] )
			: 'home';

		return in_array( $mode, array( 'home', 'pudo', 'metro' ), true ) ? $mode : 'home';
	}

	// ══════════════════════════════════════════════════════════════════
	// B. VALIDACIÓN DE NOMBRES (PHP)
	// ══════════════════════════════════════════════════════════════════

	/**
	 * Valida un nombre/apellido humano.
	 *
	 * @return string|true true si válido, mensaje de error si inválido
	 */
	function akibara_validation_check_name( string $value, string $field_label ) {
		$value = trim( $value );

		if ( $value === '' ) {
			return sprintf( '%s es obligatorio.', $field_label );
		}

		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( $len < 2 ) {
			return sprintf( '%s debe tener al menos 2 caracteres.', $field_label );
		}

		if ( $len > 50 ) {
			return sprintf( '%s es demasiado largo (máx. 50 caracteres).', $field_label );
		}

		// Sólo letras (con tildes/ñ), espacios, apóstrofe y guión.
		if ( ! preg_match( '/^[a-zA-ZáéíóúüñÁÉÍÓÚÜÑ\s\'\-]+$/u', $value ) ) {
			return sprintf( '%s sólo puede contener letras, espacios, apóstrofes y guiones.', $field_label );
		}

		// Anti-fraude: rechazar caracteres repetidos como "aaa", "xxxxx".
		$normalized = preg_replace( '/\s+/u', '', $value );
		if ( $normalized && preg_match( '/^(.)\1+$/u', $normalized ) ) {
			return sprintf( '%s no parece válido.', $field_label );
		}

		return true;
	}

	add_action( 'woocommerce_checkout_process', 'akibara_validation_validate_names' );

	function akibara_validation_validate_names(): void {
		// Nonce 'woocommerce-process_checkout' validado por WC core antes del hook.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$first = isset( $_POST['billing_first_name'] )
			? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) )
			: '';
		$last  = isset( $_POST['billing_last_name'] )
			? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$check_first = akibara_validation_check_name( $first, 'El nombre' );
		if ( $check_first !== true ) {
			wc_add_notice( $check_first, 'error' );
		}

		$check_last = akibara_validation_check_name( $last, 'El apellido' );
		if ( $check_last !== true ) {
			wc_add_notice( $check_last, 'error' );
		}
	}

	// ══════════════════════════════════════════════════════════════════
	// C. VALIDACIÓN DE DIRECCIÓN (PHP)
	// ══════════════════════════════════════════════════════════════════

	/**
	 * Valida que la dirección tenga formato razonable: longitud mínima,
	 * contiene un número (de calle) y no es chatarra repetitiva.
	 *
	 * @return string|true
	 */
	function akibara_validation_check_address( string $value ) {
		$value = trim( $value );

		if ( $value === '' ) {
			return 'La dirección es obligatoria.';
		}

		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( $len < 5 ) {
			return 'La dirección parece incompleta. Incluye calle y número.';
		}

		if ( $len > 100 ) {
			return 'La dirección es demasiado larga (máx. 100 caracteres).';
		}

		// Debe contener al menos un dígito (número de calle).
		if ( ! preg_match( '/\d/u', $value ) ) {
			return 'La dirección debe incluir el número de la calle. Ej: Av. Providencia 1234.';
		}

		// Anti-fraude: rechazar repeticiones tipo "aaaaaa" o "1111 1111".
		$normalized = preg_replace( '/\s+/u', '', $value );
		if ( $normalized && preg_match( '/^(.)\1+$/u', $normalized ) ) {
			return 'La dirección no parece válida.';
		}

		return true;
	}

	add_action( 'woocommerce_checkout_process', 'akibara_validation_validate_address' );

	function akibara_validation_validate_address(): void {
		// En modo Metro la dirección es opcional (retiro en San Miguel).
		if ( 'metro' === akibara_validation_get_mode() ) {
			return;
		}

		// Nonce 'woocommerce-process_checkout' validado por WC core antes del hook.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$address = isset( $_POST['billing_address_1'] )
			? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$check = akibara_validation_check_address( $address );
		if ( $check !== true ) {
			wc_add_notice( $check, 'error' );
		}
	}

	// ══════════════════════════════════════════════════════════════════
	// JAVASCRIPT — Email typo-fix + real-time validation
	// ══════════════════════════════════════════════════════════════════

	add_action( 'wp_enqueue_scripts', 'akibara_validation_enqueue_js' );

	function akibara_validation_enqueue_js(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$js_path  = __DIR__ . '/checkout-validation.js';
		$css_path = __DIR__ . '/checkout-validation.css';
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : AKB_CORE_CHECKOUT_VALIDATION_LOADED;
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : AKB_CORE_CHECKOUT_VALIDATION_LOADED;

		wp_enqueue_script(
			'akibara-checkout-validation',
			plugin_dir_url( __FILE__ ) . 'checkout-validation.js',
			array( 'jquery' ),
			$js_ver,
			true
		);

		wp_enqueue_style(
			'akibara-checkout-validation',
			plugin_dir_url( __FILE__ ) . 'checkout-validation.css',
			array(),
			$css_ver
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
			$exc[] = 'akibara-checkout-validation';
			$exc[] = 'checkout-validation.js';
			return array_values( array_unique( array_filter( array_map( 'trim', $exc ) ) ) );
		}
	);

} // end group wrap
