<?php
/**
 * Akibara MercadoLibre — AddonContract implementation.
 *
 * Registers this addon with akibara-core Bootstrap using the typed interface
 * introduced in Sprint 3 / INCIDENT-01 refactor. Any fatal here is isolated
 * to this addon: Bootstrap::register_addon() wraps init() in per-addon try/catch.
 *
 * @package Akibara\MercadoLibre
 * @since   1.0.0
 */

namespace Akibara\MercadoLibre;

use Akibara\Core\Bootstrap;
use Akibara\Core\Contracts\AddonContract;
use Akibara\Core\Contracts\AddonManifest;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin entry class for akibara-mercadolibre.
 *
 * Responsibilities:
 * - Declare module in Core registry via manifest()
 * - Register ML repository as a named service in ServiceLocator
 * - No business logic here: that lives in the procedural includes
 */
final class Plugin implements AddonContract {

	/**
	 * Return the addon manifest (slug, version, type, dependencies).
	 *
	 * Pure function of static identity — no DB / network / filesystem.
	 */
	public function manifest(): AddonManifest {
		return new AddonManifest(
			slug:         'akibara-mercadolibre',
			version:      '1.0.0',
			type:         'addon',
			dependencies: array( 'akibara-core' => '>=1.0' ),
		);
	}

	/**
	 * Initialize: register services in ServiceLocator.
	 *
	 * The procedural includes (class-ml-api.php, class-ml-db.php, etc.) are
	 * already loaded at plugins_loaded:9 by akb_mercadolibre_early_load().
	 * This method exposes key helpers as named services so other addons can
	 * consume them without depending on global function existence checks.
	 */
	public function init( Bootstrap $bootstrap ): void {
		// Expose ML API request helper as a named service (callable closure).
		// Consumers: $bootstrap->services()->get('ml.request')($method, $endpoint, $body)
		$bootstrap->services()->register(
			'ml.request',
			static fn() => static function ( string $method, string $endpoint, ?array $body = null ): array {
				return function_exists( 'akb_ml_request' )
					? akb_ml_request( $method, $endpoint, $body )
					: array( 'error' => 'akibara-mercadolibre not fully loaded' );
			}
		);

		// Expose seller_id helper — avoids direct transient coupling in consumers.
		$bootstrap->services()->register(
			'ml.seller_id',
			static fn() => static function (): ?int {
				return function_exists( 'akb_ml_get_seller_id' )
					? akb_ml_get_seller_id()
					: null;
			}
		);
	}
}
