<?php
/**
 * Akibara — Google Places Address Autocomplete
 *
 * Integra Google Places Autocomplete en:
 *  - Checkout (billing + shipping address_1)
 *  - Formulario de edición de dirección (customer-edit-address)
 *
 * Restringe resultados a Chile (CL) y autocompleta ciudad, región
 * y código postal a partir de la selección.
 *
 * Requiere:
 *   define( 'AKB_GOOGLE_MAPS_API_KEY', '...' ); en wp-config.php
 *
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Guard: cargar SOLO si plugin akibara legacy (V10) o akibara-core están active.
// Sprint 2 Cell Core Phase 1.
if ( ! defined( 'AKIBARA_V10_LOADED' ) && ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	return;
}

// Idempotent: skip si module ya loaded por otro path.
if ( defined( 'AKB_CORE_PLACES_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_PLACES_LOADED', '1.0.0' );

// F-pivot defensive layer (REDESIGN.md 2026-04-27 + staging deploy postmortem):
// Group wrap todas las top-level function declarations + hook registrations
// dentro de `if ( ! function_exists( 'akb_places_is_enabled' ) )`. PHP NO
// hoistea functions dentro de un if block. Belt-and-suspenders symbol-level.
if ( ! function_exists( 'akb_places_is_enabled' ) ) {

/**
 * ¿Está configurada la API key?
 */
function akb_places_is_enabled(): bool {
	return defined( 'AKB_GOOGLE_MAPS_API_KEY' ) && ! empty( AKB_GOOGLE_MAPS_API_KEY );
}

/**
 * Devuelve la API key.
 */
function akb_places_get_key(): string {
	return akb_places_is_enabled() ? (string) AKB_GOOGLE_MAPS_API_KEY : '';
}


/**
 * Mapa de administrative_area_level_1 (Google) a códigos ISO (WC).
 * WC usa códigos CL-RM, CL-BI, etc. Google devuelve nombres + short_name.
 */
function akb_places_region_map(): array {
	return array(
		// short_name (Google) => código WC
		'RM' => 'CL-RM', // Región Metropolitana
		'AP' => 'CL-AP', // Arica y Parinacota
		'TA' => 'CL-TA', // Tarapacá
		'AN' => 'CL-AN', // Antofagasta
		'AT' => 'CL-AT', // Atacama
		'CO' => 'CL-CO', // Coquimbo
		'VS' => 'CL-VS', // Valparaíso
		'LI' => 'CL-LI', // O'Higgins
		'ML' => 'CL-ML', // Maule
		'NB' => 'CL-NB', // Ñuble
		'BI' => 'CL-BI', // Biobío
		'AR' => 'CL-AR', // La Araucanía
		'LR' => 'CL-LR', // Los Ríos
		'LL' => 'CL-LL', // Los Lagos
		'AI' => 'CL-AI', // Aysén
		'MA' => 'CL-MA', // Magallanes
	);
}

/**
 * Determina si la página actual debe cargar autocomplete.
 */
function akb_places_should_enqueue(): bool {
	if ( ! akb_places_is_enabled() ) {
		return false;
	}

	// Checkout (no order-received, ahí se usa otro flujo)
	if ( is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
		return true;
	}

	// Order-received / Thank you (donde el cliente guest ve y edita dirección)
	if ( is_wc_endpoint_url( 'order-received' ) ) {
		return true;
	}

	// Mi Cuenta → Ver pedido
	if ( is_wc_endpoint_url( 'view-order' ) ) {
		return true;
	}

	// Mi Cuenta → Direcciones
	if ( is_wc_endpoint_url( 'edit-address' ) ) {
		return true;
	}

	return false;
}

/**
 * Encolar scripts.
 */
add_action(
	'wp_enqueue_scripts',
	function (): void {
		if ( ! akb_places_should_enqueue() ) {
			return;
		}

		$key = akb_places_get_key();

		// Lazy-load: la Google Maps JS API (~500KB) NO se carga automáticamente.
		// El JS v2 la inyecta dinámicamente solo cuando el usuario hace foco en el
		// input de dirección, reduciendo peso de la carga inicial del checkout.
		// La URL se pasa vía inline config abajo (no via wp_enqueue_script).
		$maps_url = add_query_arg(
			array(
				'key'       => $key,
				'libraries' => 'places',
				'loading'   => 'async',
				'callback'  => 'akbPlacesInit',
				'v'         => 'weekly',
				'language'  => 'es',
				'region'    => 'CL',
			),
			'https://maps.googleapis.com/maps/api/js'
		);

		// Nuestro inicializador: API New (`PlaceAutocompleteElement`).
		wp_enqueue_script(
			'akb-places-autocomplete',
			plugins_url( 'address-autocomplete.js', __FILE__ ),
			array(),
			'2.1.0',
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		// Datos que necesita el JS (incluye URL del loader para lazy-load).
		wp_add_inline_script(
			'akb-places-autocomplete',
			'window.akbPlaces = ' . wp_json_encode(
				array(
					'loaderUrl' => $maps_url,
					'regionMap' => akb_places_region_map(),
					'country'   => array( 'cl' ),
					'fields'    => array(
						// selector input => prefijo de campos asociados (city/state/postcode)
						'#billing_address_1'               => 'billing',
						'#shipping_address_1'              => 'shipping',
						'input[name="shipping_address_1"]' => 'shipping',
						'input[name="billing_address_1"]'  => 'billing',
						'input[name="akb_cea_shipping_address_1"]' => 'akb_cea_shipping',
					),
				)
			) . ';',
			'before'
		);
	}
);

/**
 * CO-9 (Sprint 1): Admin notice cuando AKB_GOOGLE_MAPS_API_KEY no está definida.
 *
 * Antes: la feature quedaba silenciosamente desactivada y el admin no sabía que
 * faltaba configurarla. Ahora: aviso dismissible en WooCommerce admin con pasos.
 */
add_action(
	'admin_notices',
	function (): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( akb_places_is_enabled() ) {
			return; // API key configurada correctamente, nada que avisar.
		}
		// Solo en pantallas relevantes (WC settings, plugins, dashboard) para no saturar.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins', 'woocommerce_page_wc-settings', 'toplevel_page_woocommerce' ), true ) ) {
			return;
		}
		// Dismissible por sesión (WP lo maneja con cookie via `is-dismissible`).
		?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<strong>Akibara — Autocompletar de direcciones desactivado.</strong>
			La constante <code>AKB_GOOGLE_MAPS_API_KEY</code> no está definida en <code>wp-config.php</code>.
			Los clientes tendrán que ingresar ciudad y región manualmente en checkout.
		</p>
		<p>
			<a href="https://developers.google.com/maps/documentation/javascript/get-api-key" target="_blank" rel="noopener">Obtener una API key</a>
			y agregar al <code>wp-config.php</code>:
			<br>
			<code>define( 'AKB_GOOGLE_MAPS_API_KEY', 'tu_key_aqui' );</code>
		</p>
	</div>
		<?php
	}
);

} // end if ( ! function_exists( 'akb_places_is_enabled' ) )
