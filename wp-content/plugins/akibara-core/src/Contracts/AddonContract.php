<?php
/**
 * Akibara Core — AddonContract interface.
 *
 * Implementado por cada plugin addon de Akibara (akibara-preventas,
 * akibara-marketing, akibara-inventario, etc.) para registrarse de forma
 * type-safe con el core Bootstrap.
 *
 * @package Akibara\Core\Contracts
 * @since   2.0.0 (post-INCIDENT-01)
 */

namespace Akibara\Core\Contracts;

use Akibara\Core\Bootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * Contract that every Akibara addon plugin must implement.
 *
 * Addon entry-point file (e.g., `akibara-preventas/akibara-preventas.php`)
 * instantiates a class implementing this interface and passes it to
 * `Bootstrap::register_addon()`. The Bootstrap wraps init() in a per-addon
 * try/catch — a single addon's failure cannot crash the site (auto-disable
 * + persisted record + WP fallback).
 *
 * Example:
 *   add_action( 'plugins_loaded', static function (): void {
 *       if ( class_exists( '\Akibara\Core\Bootstrap' ) ) {
 *           \Akibara\Core\Bootstrap::instance()->register_addon(
 *               new \Akibara\Preventas\Plugin()
 *           );
 *       }
 *   }, 10 );
 *
 * Plus the legacy do_action('akibara_core_init', $bootstrap) hook is preserved
 * for external/non-Akibara addons that may already exist.
 */
interface AddonContract {

	/**
	 * Initialize the addon: register services, declare modules, hook handlers.
	 *
	 * Called once by Bootstrap after manifest validation. Wrapped in try/catch
	 * by the caller — throwing an Error or Exception causes the addon to be
	 * deactivated (persisted) and disabled for subsequent requests.
	 *
	 * @param Bootstrap $bootstrap Core bootstrap (facade for services + modules + future APIs).
	 */
	public function init( Bootstrap $bootstrap ): void;

	/**
	 * Return the addon manifest (slug, version, type, dependencies).
	 *
	 * Called by Bootstrap before init() to declare the module in the registry.
	 * Should be a pure function of the addon's static identity — must not
	 * read database, network, or filesystem.
	 */
	public function manifest(): AddonManifest;
}
