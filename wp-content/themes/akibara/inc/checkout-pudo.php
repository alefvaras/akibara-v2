<?php
/**
 * Akibara — BlueX PUDO Selector (integrated design)
 *
 * Reemplaza el widget por defecto del plugin `bluex-for-woocommerce`
 * (que se inyecta con estilos inline en el sidebar del checkout) por un
 * selector propio integrado con el dark theme Akibara, colocado dentro
 * del paso "Datos de envío" del checkout.
 *
 * Estrategia:
 *  - Mantener `pudoEnable=yes` para que las APIs de pricing/webhook
 *    del plugin BlueX sigan funcionando normalmente.
 *  - Remover únicamente los hooks de render visual del plugin.
 *  - Renderizar nuestro selector via hook custom
 *    `akibara_checkout_pudo_selector` (se dispara en form-checkout.php
 *    dentro del step 2, después de los métodos de envío).
 *  - Reutilizar los nombres de inputs ocultos `agencyId` e
 *    `isPudoSelected` que el plugin espera en POST/session.
 *  - Reutilizar el iframe del widget `widget-pudo.blue.cl` (no
 *    requiere Google Maps API key).
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filtrar los shipping rates cuando el cliente está en modo PUDO
 * pero aún no eligió un punto de retiro.
 *
 * Sin este filtro, el plugin BlueX devuelve el rate de despacho a
 * domicilio ($3.100) como fallback aunque `isPudoSelected=pudoShipping`,
 * lo que confunde al cliente (ve "Retiro en punto" con precio de
 * domicilio).
 *
 * Al devolver un array vacío, WooCommerce muestra "Sin opciones de
 * envío disponibles" y el total no incluye envío hasta que el cliente
 * seleccione un punto.
 */
/**
 * Leer y normalizar request data del checkout (AJAX update_order_review
 * o submit final), manteniendo una sola fuente de verdad.
 */
function akibara_checkout_get_request_data() {
	$post_data = [];
	if ( isset( $_POST['post_data'] ) ) {
		parse_str( (string) $_POST['post_data'], $post_data );
	} elseif ( ! empty( $_POST ) ) {
		$post_data = $_POST;
	}

	return is_array( $post_data ) ? $post_data : [];
}

/**
 * Determinar si el retiro gratis Metro San Miguel está habilitado.
 * Regla: solo Región Metropolitana (CL-RM).
 */
function akibara_is_metro_rm_eligible( array $request_data = [] ) {
	$request_states = [];

	if ( ! empty( $request_data ) ) {
		$request_billing_state  = isset( $request_data['billing_state'] ) ? strtoupper( trim( (string) $request_data['billing_state'] ) ) : '';
		$request_shipping_state = isset( $request_data['shipping_state'] ) ? strtoupper( trim( (string) $request_data['shipping_state'] ) ) : '';

		if ( '' !== $request_billing_state ) {
			$request_states[] = $request_billing_state;
		}

		if ( '' !== $request_shipping_state ) {
			$request_states[] = $request_shipping_state;
		}
	}

	if ( ! empty( $request_states ) ) {
		foreach ( $request_states as $state ) {
			if ( in_array( $state, [ 'CL-RM', 'RM' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	$customer_states = [];
	if ( function_exists( 'WC' ) && WC()->customer ) {
		$customer_states[] = strtoupper( trim( (string) WC()->customer->get_billing_state() ) );
		$customer_states[] = strtoupper( trim( (string) WC()->customer->get_shipping_state() ) );
	}

	foreach ( $customer_states as $state ) {
		if ( in_array( $state, [ 'CL-RM', 'RM' ], true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Obtener modo de entrega seleccionado desde request actual.
 */
function akibara_checkout_get_selected_mode( array $request_data = [] ) {
	if ( empty( $request_data ) ) {
		$request_data = akibara_checkout_get_request_data();
	}

	$mode = isset( $request_data['akibara_delivery_mode'] )
		? sanitize_key( (string) $request_data['akibara_delivery_mode'] )
		: 'home';

	return in_array( $mode, [ 'home', 'pudo', 'metro' ], true ) ? $mode : 'home';
}

/**
 * En modo Metro, domicilio/comuna se vuelven opcionales para no forzar
 * datos de despacho cuando el cliente retira gratis.
 */
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	$mode = akibara_checkout_get_selected_mode();
	if ( 'metro' !== $mode ) {
		return $fields;
	}

	$optional_keys = [
		'billing_address_1',
		'billing_city',
		'billing_address_2',
	];

	foreach ( $optional_keys as $key ) {
		if ( ! isset( $fields['billing'][ $key ] ) ) {
			continue;
		}

		$fields['billing'][ $key ]['required'] = false;

		if ( isset( $fields['billing'][ $key ]['validate'] ) && is_array( $fields['billing'][ $key ]['validate'] ) ) {
			$fields['billing'][ $key ]['validate'] = array_values(
				array_diff( $fields['billing'][ $key ]['validate'], [ 'required' ] )
			);
		}
	}

	return $fields;
}, 999 );

/**
 * Helper: leer `isPudoSelected` y `agencyId` desde el POST del AJAX
 * `update_order_review` o del submit del checkout.
 *
 * @return array [is_pudo => bool, agency_id => string]
 */
function akibara_pudo_get_post_state() {
	$post_data = akibara_checkout_get_request_data();
	$is_pudo   = ! empty( $post_data['isPudoSelected'] )
		&& 'pudoShipping' === $post_data['isPudoSelected'];
	$agency_id = isset( $post_data['agencyId'] )
		? trim( (string) $post_data['agencyId'] )
		: '';
	return [ 'is_pudo' => $is_pudo, 'agency_id' => $agency_id ];
}

/**
 * Agregar el estado PUDO al shipping package. WooCommerce calcula un
 * hash del package para cachear los rates; al incluir estos campos,
 * cualquier cambio en el selector PUDO invalida el cache y WC llama
 * de nuevo a los shipping methods + filters.
 */
add_filter( 'woocommerce_cart_shipping_packages', function ( $packages ) {
	$state = akibara_pudo_get_post_state();
	foreach ( $packages as $i => $package ) {
		$packages[ $i ]['aki_pudo_mode']   = $state['is_pudo'] ? 'pudo' : 'home';
		$packages[ $i ]['aki_pudo_agency'] = $state['agency_id'];
	}
	return $packages;
} );

/**
 * Forzar la invalidación del cache de shipping rates cuando cambia el
 * modo PUDO. El filter anterior (cart_shipping_packages) invalida el
 * hash solo para rates calculados de novo, pero WooCommerce mantiene
 * rates cacheados en `WC()->session` de sesiones anteriores.
 *
 * Este hook detecta cuando el cliente cambia el modo PUDO (comparando
 * con el estado previo en sesión) y fuerza reset_shipping() para que
 * WC recalcule los rates aplicando nuestro filter.
 */
add_action( 'woocommerce_checkout_update_order_review', function ( $post_data ) {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	parse_str( (string) $post_data, $parsed );
	$current_pudo   = ! empty( $parsed['isPudoSelected'] ) && 'pudoShipping' === $parsed['isPudoSelected'];
	$current_agency = isset( $parsed['agencyId'] ) ? trim( (string) $parsed['agencyId'] ) : '';
	$current_key    = ( $current_pudo ? 'pudo' : 'home' ) . '|' . $current_agency;

	$previous_key = WC()->session->get( 'aki_pudo_state_key' );

	if ( $previous_key !== $current_key ) {
		WC()->session->set( 'aki_pudo_state_key', $current_key );
		// Limpiar rates cacheados para forzar recálculo.
		WC()->shipping()->reset_shipping();
		// Eliminar el array de shipping_for_package en sesión.
		$packages = WC()->session->get( 'shipping_for_package_0' );
		if ( $packages ) {
			WC()->session->set( 'shipping_for_package_0', null );
		}
	}
} );

/**
 * Filtrar los shipping rates en checkout:
 *
 * 1. Metro San Miguel: solo visible si la región es RM.
 *
 * NOTA: el filter anterior que removía TODOS los rates bluex cuando
 * el cliente estaba en modo PUDO sin agency_id fue removido porque
 * rompía el Unified Grid — el enhancer necesita `bluex-ex-home` en
 * el grid para renderear la card virtual "Retiro en punto Blue
 * Express" (comparte `data-method-value` con bluex-home y solo
 * cambia `data-mode=pudo`). Sin bluex-home, la card desaparece tras
 * el primer update_checkout → el mapa se destruye y el user ve
 * "abrir-cerrar" en 2 segundos.
 *
 * La validación del agency_id ya está garantizada en
 * `woocommerce_checkout_process` (submit final bloqueado sin punto
 * elegido).
 */
add_filter( 'woocommerce_package_rates', function ( $rates, $package ) {
	$metro_enabled = akibara_is_metro_rm_eligible( akibara_checkout_get_request_data() );

	if ( ! $metro_enabled ) {
		foreach ( $rates as $rate_id => $rate ) {
			$method_id   = method_exists( $rate, 'get_method_id' ) ? $rate->get_method_id() : '';
			$instance_id = method_exists( $rate, 'get_instance_id' ) ? (int) $rate->get_instance_id() : 0;
			$label       = method_exists( $rate, 'get_label' ) ? (string) $rate->get_label() : '';

			if ( 'local_pickup' === $method_id && ( 70 === $instance_id || false !== stripos( $label, 'San Miguel' ) ) ) {
				unset( $rates[ $rate_id ] );
			}
		}
	}

	return $rates;
}, 99, 2 );

/**
 * Validar en el checkout submit que el cliente haya elegido un punto
 * PUDO si activó el modo "Retiro en punto Blue Express".
 */
add_action( 'woocommerce_checkout_process', function () {
	$request_data = akibara_checkout_get_request_data();
	$is_pudo      = ! empty( $request_data['isPudoSelected'] ) && 'pudoShipping' === $request_data['isPudoSelected'];
	$agency_id    = isset( $request_data['agencyId'] ) ? trim( (string) $request_data['agencyId'] ) : '';
	$mode         = akibara_checkout_get_selected_mode( $request_data );

	if ( 'metro' === $mode && ! akibara_is_metro_rm_eligible( $request_data ) ) {
		wc_add_notice(
			'El retiro gratis en metro San Miguel está disponible solo para direcciones de la Región Metropolitana.',
			'error'
		);
	}

	if ( $is_pudo && '' === $agency_id ) {
		wc_add_notice(
			'Por favor selecciona un punto de retiro Blue Express en el mapa antes de continuar.',
			'error'
		);
	}
} );

/**
 * Capa extra anti-POST manual: si el modo es Metro, ignorar errores
 * de dirección/comuna en validación final.
 */
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( ! ( $errors instanceof WP_Error ) ) {
		return;
	}

	if ( 'metro' !== akibara_checkout_get_selected_mode() ) {
		return;
	}

	foreach ( $errors->get_error_codes() as $code ) {
		if (
			0 === strpos( (string) $code, 'billing_address_1' ) ||
			0 === strpos( (string) $code, 'billing_city' ) ||
			0 === strpos( (string) $code, 'billing_address_2' )
		) {
			$errors->remove( $code );
		}
	}
}, 20, 2 );

/**
 * Remover los hooks de render visual del widget BlueX.
 *
 * Se ejecuta tarde (prio 999 en `wp_loaded`) para garantizar que la
 * instancia del plugin ya registró sus hooks en `init`.
 */
add_action( 'wp_loaded', function () {
	global $wp_filter;

	$hooks_to_clean = [
		'woocommerce_review_order_after_order_total',
		'woocommerce_checkout_after_order_review',
		'woocommerce_checkout_process',
		'wp_footer',
	];

	foreach ( $hooks_to_clean as $hook ) {
		if ( empty( $wp_filter[ $hook ] ) ) {
			continue;
		}
		$wp_hook = $wp_filter[ $hook ];
		foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn = $cb['function'] ?? null;
				if ( is_array( $fn ) && is_object( $fn[0] ) && $fn[0] instanceof WC_Correios_PudosMap ) {
					remove_action( $hook, $fn, $priority );
				}
			}
		}
	}
}, 999 );

/**
 * Short-circuit del endpoint `clear_shipping_cache` del plugin BlueX.
 *
 * El cliente de `custom-checkout-map.min.js` dispara este fetch en cada
 * DOMContentLoaded y tras cada `updated_checkout`. En prod Hostinger la
 * respuesta llega como HTML de error (WAF/500/timeout), rompiendo el
 * `response.json()` con SyntaxError ruidoso en consola. Akibara gestiona
 * el shipping cache vía `ship-grid.js`, así que el call es redundante.
 * Priority 1 se ejecuta antes del handler del plugin (priority 10) y
 * `wp_send_json_success` termina el request con wp_die.
 */
$akibara_bluex_clear_cache_noop = function () {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'bluex_checkout_nonce' ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}
	wp_send_json_success( [ 'noop' => true ] );
};
add_action( 'wp_ajax_clear_shipping_cache', $akibara_bluex_clear_cache_noop, 1 );
add_action( 'wp_ajax_nopriv_clear_shipping_cache', $akibara_bluex_clear_cache_noop, 1 );
unset( $akibara_bluex_clear_cache_noop );

/**
 * Render del selector PUDO integrado.
 *
 * Se dispara desde `form-checkout.php` (template override del tema)
 * dentro del step 2, justo después de `#aki-shipping-methods`.
 */
add_action( 'akibara_checkout_pudo_selector', 'akibara_render_pudo_selector' );

function akibara_render_pudo_selector() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}

	// Solo renderizar si el plugin está activo con PUDO habilitado.
	$settings = get_option( 'woocommerce_correios-integration_settings', [] );
	if ( empty( $settings['pudoEnable'] ) || $settings['pudoEnable'] !== 'yes' ) {
		return;
	}

	// Default UX: partir siempre en domicilio y NO preseleccionar
	// retiro/punto desde sesiones anteriores sin acción explícita.
	$mode         = 'home';
	$agency_id    = '';
	$request_data = akibara_checkout_get_request_data();
	$metro_enabled = akibara_is_metro_rm_eligible( $request_data );

	$request_mode = isset( $request_data['akibara_delivery_mode'] )
		? sanitize_key( (string) $request_data['akibara_delivery_mode'] )
		: '';

	if ( in_array( $request_mode, [ 'home', 'pudo', 'metro' ], true ) ) {
		$mode = $request_mode;
	}

	if ( 'metro' === $mode && ! $metro_enabled ) {
		$mode = 'home';
	}

	if ( 'pudo' === $mode ) {
		$agency_id = isset( $request_data['agencyId'] )
			? trim( (string) $request_data['agencyId'] )
			: '';
	}

	if ( 'pudo' !== $mode && WC()->session ) {
		WC()->session->set( 'bluex_pudo_selected', false );
		WC()->session->__unset( 'bluex_agency_id' );
	}

	$is_pudo_selected = ( 'pudo' === $mode ) && '' !== $agency_id;

	// URL del widget (iframe) — tomar del plugin si está disponible,
	// con fallback al endpoint público.
	$widget_base = 'https://widget-pudo.blue.cl';
	$widget_url  = $widget_base . ( $agency_id ? '?id=' . rawurlencode( $agency_id ) : '' );
	?>
	<div class="aki-pudo" id="aki-pudo" data-delivery-mode="<?php echo esc_attr( $mode ); ?>">
		<?php
		/*
		 * State carriers: 3 radios ocultos que actúan como fuente de
		 * verdad de `akibara_delivery_mode`. La UI visible del selector
		 * vive en el unified grid (ship-grid.js hero + accordion).
		 *
		 * Consumidores del contract (no remover sin migrar a todos):
		 *  - ship-grid.js (setDeliveryMode / getCurrentMode)
		 *  - checkout-steps.js (validación step 2)
		 *  - checkout-validation/module.php + checkout-validation.js
		 *  - checkout-accordion.php (detección PUDO en AJAX update)
		 *  - checkout-pudo.js (onModeChange, refreshMetroEligibility)
		 */
		?>
		<div class="aki-pudo__mode-state" hidden aria-hidden="true">
			<input type="radio" name="akibara_delivery_mode" value="home" <?php checked( $mode === 'home' ); ?>>
			<input type="radio" name="akibara_delivery_mode" value="pudo" <?php checked( $mode === 'pudo' ); ?>>
			<input type="radio" name="akibara_delivery_mode" value="metro" <?php checked( $mode === 'metro' ); ?> <?php disabled( ! $metro_enabled ); ?>>
			<input type="radio" name="akibara_delivery_mode" value="sameday" <?php checked( $mode === 'sameday' ); ?>>
		</div>

		<!-- Confirmación pre-pago para retiro Metro San Miguel.
		     No incluimos CTA externo: la coordinación se hace post-pago
		     vía email/WhatsApp transaccional (ver email-customer-processing-order). -->
		<div class="aki-pudo__metro<?php echo $mode === 'metro' ? ' is-open' : ''; ?>" id="aki-pudo-metro">
			<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
			<div class="aki-pudo__metro-body">
				<strong>Retiro gratis confirmado</strong>
				<p>Te enviaremos por email y WhatsApp los detalles del retiro en metro San Miguel una vez completes tu pago. Coordinamos día y hora contigo, sin costo.</p>
			</div>
		</div>

		<div class="aki-pudo__map<?php echo $mode === 'pudo' ? ' is-open' : ''; ?>" id="aki-pudo-map">
			<?php if ( $is_pudo_selected && $agency_id ) : ?>
				<div class="aki-pudo__selected" id="aki-pudo-selected">
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
					<span>Punto seleccionado: <strong><?php echo esc_html( $agency_id ); ?></strong></span>
					<button type="button" class="aki-pudo__change" id="aki-pudo-change">Cambiar</button>
				</div>
			<?php endif; ?>

			<!-- Value prop panel: precio desde, beneficios y plazos.
			     Se muestra arriba del mapa cuando PUDO está abierto.
			     Se oculta automáticamente cuando ya hay un punto seleccionado
			     (ya convencimos al cliente). Re-aparece si hace click en "Cambiar". -->
			<div class="aki-pudo__features"<?php echo ( $is_pudo_selected && $agency_id ) ? ' hidden' : ''; ?>>
				<div class="aki-pudo__features-price">
					<strong>Desde $2.600</strong>
					<span>zona local · IVA incluido</span>
					<span class="aki-pudo__features-price-tag">Ahorra hasta 40%</span>
				</div>
				<ul class="aki-pudo__features-list">
					<li>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
						<span><strong>+3.200 puntos</strong> en Chile · incluye <strong>Copec 24/7</strong></span>
					</li>
					<li>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
						<span>Retira cuando quieras, <strong>hasta 5 días hábiles</strong></span>
					</li>
					<li>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
						<span>Lockers autogestionados con <strong>QR o PIN</strong> — sin filas</span>
					</li>
					<li>
						<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
						<span>No dependes de estar en casa ni del conserje</span>
					</li>
				</ul>
				<p class="aki-pudo__features-note">
					<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
					Plazo RM: 2–7 días hábiles · zonas extremas: 10–15 días
				</p>
			</div>

			<div class="aki-pudo__iframe-wrap" id="aki-pudo-iframe-wrap">
				<iframe
					id="aki-pudo-iframe"
					src="<?php echo $mode === 'pudo' ? esc_url( $widget_url ) : 'about:blank'; ?>"
					data-widget-url="<?php echo esc_url( $widget_url ); ?>"
					title="Selector de punto Blue Express"
					loading="lazy"
					allow="geolocation 'self' https://widget-pudo.blue.cl; clipboard-read; clipboard-write"
					referrerpolicy="no-referrer-when-downgrade">
				</iframe>
				<p class="aki-pudo__hint">
					<?php if ( $is_pudo_selected ) : ?>
						<span>Puedes elegir otro punto en el mapa cuando quieras.</span>
					<?php else : ?>
						<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5"/><polyline points="5 12 12 5 19 12"/></svg>
						<span>Elige un punto en el mapa para confirmar tu retiro.</span>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<!-- Hidden inputs que el plugin BlueX lee para calcular pricing
		     y persistir la selección en la orden. -->
		<input type="hidden" name="isPudoSelected" id="isPudoSelected" value="<?php echo $mode === 'pudo' ? 'pudoShipping' : ''; ?>">
		<input type="hidden" name="agencyId" id="agencyId" value="<?php echo esc_attr( $agency_id ); ?>">
		<input type="hidden" name="shippingBlue" id="shippingBlue" value="<?php echo $mode === 'pudo' ? 'pudoShipping' : 'normalShipping'; ?>">
	</div>
	<?php
}

/**
 * Encolar el JS que maneja la interacción del selector PUDO.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_checkout() ) {
		return;
	}
	$settings = get_option( 'woocommerce_correios-integration_settings', [] );
	if ( empty( $settings['pudoEnable'] ) || $settings['pudoEnable'] !== 'yes' ) {
		return;
	}
	wp_enqueue_script(
		'akibara-checkout-pudo',
		AKIBARA_THEME_URI . '/assets/js/checkout-pudo.js',
		[ 'jquery', 'wc-checkout' ],
		// .82 — A6 a11y Round 3: Escape handler + role=dialog wrapper iframe.
		AKIBARA_THEME_VERSION . '.82',
		true
	);
} );

/**
 * Excluir este JS de defer/delay/combine en LiteSpeed.
 */
add_filter( 'litespeed_optm_js_defer_exc', function ( $exc ) {
	if ( ! is_array( $exc ) ) {
		$exc = preg_split( '/[\r\n]+/', (string) $exc ) ?: [];
	}
	$exc[] = 'akibara-checkout-pudo';
	$exc[] = 'checkout-pudo.js';
	return array_values( array_unique( array_filter( array_map( 'trim', $exc ) ) ) );
} );
