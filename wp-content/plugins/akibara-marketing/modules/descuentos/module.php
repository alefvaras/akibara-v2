<?php
/**
 * Akibara Marketing — Descuentos v11.0.0
 *
 * Motor de descuentos flexible para WooCommerce:
 *  - Descuentos por porcentaje Y monto fijo (CLP)
 *  - Reglas por categoria, etiqueta, marca o cualquier atributo
 *  - Descuentos de carrito via cupones virtuales
 *  - Herencia de taxonomias padre → hijo
 *  - Inclusiones y exclusiones de productos individuales
 *  - Fechas de inicio y fin por regla
 *  - Compatibilidad completa con variaciones de producto
 *  - Display "Ahorras $X"
 *  - Panel de administracion visual con wizard de 3 pasos
 *
 * Lifted from server-snapshot/.../modules/descuentos/module.php v11.0.0
 * Adaptations:
 *   - Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 *   - akb_is_module_enabled() guard preserved (provided by akibara-core)
 *   - AKIBARA_FILE → AKB_MARKETING_FILE in HPOS FeaturesUtil declaration
 *   - Group wrap (class_exists) added around Akibara_Descuento_Taxonomia
 *
 * @package    Akibara\Marketing
 * @subpackage Descuentos
 * @version    11.0.0
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKIBARA_DESCUENTOS_LOADED' ) ) {
	return;
}

// Feature flag — kill switch uniforme.
// - Option:    wp option update akibara_module_descuentos_enabled 0
// - Constante: define('AKIBARA_DISABLE_DESCUENTOS', true) en wp-config.php
// - Filter:    add_filter('akibara_module_descuentos_enabled', '__return_false')
if ( function_exists( 'akb_is_module_enabled' ) && ! akb_is_module_enabled( 'descuentos' ) ) {
	return;
}

define( 'AKIBARA_DESCUENTOS_LOADED', '11.0.0' );

// Cargar componentes
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/migration.php';
require_once __DIR__ . '/presets.php';
require_once __DIR__ . '/banner.php';

if ( is_admin() ) {
	require_once __DIR__ . '/admin.php';
}

// Endpoint AJAX para traer un preset ya renderizado a array de regla.
if ( function_exists( 'akb_ajax_endpoint' ) && function_exists( 'akibara_descuento_create_from_preset' ) ) {
	akb_ajax_endpoint(
		'akb_desc_get_preset',
		array(
			'nonce'      => 'akibara_descuento',
			'capability' => 'manage_woocommerce',
			'handler'    => static function ( array $post ): array {
				$key  = sanitize_key( $post['preset'] ?? '' );
				$rule = akibara_descuento_create_from_preset( $key );
				if ( ! $rule ) {
					return array( 'error' => 'Preset no encontrado' );
				}
				return array( 'rule' => $rule );
			},
		)
	);
}

// ══════════════════════════════════════════════════════════════════
// CLASE PRINCIPAL — Bootstrap
// ══════════════════════════════════════════════════════════════════

if ( ! class_exists( 'Akibara_Descuento_Taxonomia' ) ) {

	final class Akibara_Descuento_Taxonomia {

		private static $instance = null;

		const VERSION       = '11.0.0';
		const OPTION_REGLAS = 'akibara_descuento_reglas';
		const CACHE_LIMIT   = 1500;

		// Sub-componentes
		public $engine = null;
		public $cart   = null;
		public $admin  = null;

		// Cache compartido
		public $reglas      = null;
		public $rule_hashes = array();
		public $price_cache = array();
		public $processing  = false;

		public static function instance(): self {
			return self::$instance ?? ( self::$instance = new self() );
		}

		private function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		}

		public function init(): void {
			if ( ! class_exists( 'WooCommerce' ) ) {
				add_action(
					'admin_notices',
					function () {
						echo '<div class="notice notice-error"><p><strong>Akibara Descuentos:</strong> Requiere WooCommerce.</p></div>';
					}
				);
				return;
			}

			// Inicializar motor de precios
			$this->engine = new Akibara_Descuento_Engine( $this );

			// Inicializar carrito (cupones virtuales)
			$this->cart = new Akibara_Descuento_Cart( $this );

			// Admin
			if ( is_admin() && class_exists( 'Akibara_Descuento_Admin' ) ) {
				$this->admin = new Akibara_Descuento_Admin( $this );
			}

			// HPOS compatibility — use AKB_MARKETING_FILE (plugin entry point)
			add_action( 'before_woocommerce_init', array( $this, 'declare_hpos' ) );

			// Limpiar cache cuando se guarda un producto
			add_action( 'save_post_product', array( $this, 'on_product_save' ), 99 );

			// Invalidar cache de arbol de terminos cuando cambia la taxonomy.
			add_action( 'created_term', array( $this->engine, 'flush_terms_cache' ), 10, 3 );
			add_action( 'edited_term', array( $this->engine, 'flush_terms_cache' ), 10, 3 );
			add_action( 'delete_term', array( $this->engine, 'flush_terms_cache' ), 10, 3 );

			// Registrar hooks de precio si hay reglas producto activas
			if ( $this->engine->hay_reglas_activas( 'producto' ) ) {
				$this->engine->register_price_filters();
			}

			// Registrar hooks de carrito si hay reglas carrito activas
			if ( $this->engine->hay_reglas_activas( 'carrito' ) ) {
				$this->cart->register_hooks();
			}
		}

		public function on_product_save( int $product_id ): void {
			unset( $this->price_cache[ $product_id ] );
		}

		public function declare_hpos(): void {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				// Use AKB_MARKETING_FILE if defined, otherwise fallback to __FILE__ of plugin entry.
				// The constant is defined in akibara-marketing.php as plugin_basename(__FILE__).
				$plugin_file = defined( 'AKB_MARKETING_FILE' ) ? AKB_MARKETING_FILE : plugin_dir_path( __DIR__ ) . 'akibara-marketing.php';
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true );
			}
		}

		// ─── Acceso a reglas ─────────────────────────────────────────

		public function get_reglas(): array {
			if ( $this->reglas !== null ) {
				return $this->reglas;
			}

			$raw = get_option( self::OPTION_REGLAS, array() );

			// Detectar formato v11 (wrapper) vs v10 (array plano)
			if ( is_array( $raw ) && isset( $raw['_v'] ) ) {
				$rules = is_array( $raw['_rules'] ?? null ) ? $raw['_rules'] : array();
			} elseif ( is_array( $raw ) ) {
				$rules = $raw;
			} else {
				$rules = array();
			}

			// Migrar y validar cada regla
			$this->reglas = array_map( array( $this->engine, 'migrar_regla_v10' ), $rules );

			// Pre-computar hashes para cache keys
			$this->rule_hashes = array();
			foreach ( $this->reglas as $idx => $regla ) {
				$this->rule_hashes[ $idx ] = $regla['id'] ?? $idx;
			}

			return $this->reglas;
		}

		public function save_reglas( array $reglas ): void {
			$this->reglas      = $reglas;
			$this->rule_hashes = array();
			$this->price_cache = array();

			// Guardar en formato v11
			update_option(
				self::OPTION_REGLAS,
				array(
					'_v'     => 11,
					'_rules' => $reglas,
				)
			);

			$this->clear_cache();
		}

		public function clear_cache(): void {
			$this->reglas      = null;
			$this->rule_hashes = array();
			$this->price_cache = array();

			if ( class_exists( 'WC_Cache_Helper' ) ) {
				WC_Cache_Helper::get_transient_version( 'product', true );
			}

			do_action( 'litespeed_purge_all' );
		}
	}

	Akibara_Descuento_Taxonomia::instance();

} // end class_exists guard
