<?php
/**
 * Akibara Core — Bootstrap.
 *
 * Singleton inicializa ServiceLocator + ModuleRegistry y dispara lifecycle hooks.
 * Llamado desde akibara-core.php → plugins_loaded action priority 5.
 *
 * @package Akibara\Core
 * @since   1.0.0
 */

namespace Akibara\Core;

use Akibara\Core\Container\ServiceLocator;
use Akibara\Core\Registry\ModuleRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap singleton.
 */
final class Bootstrap {

	private static ?Bootstrap $instance = null;

	private ServiceLocator $services;
	private ModuleRegistry $modules;
	private bool $initialized = false;

	private function __construct() {
		$this->services = ServiceLocator::instance();
		$this->modules  = new ModuleRegistry();
	}

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize core services + modules.
	 *
	 * Idempotent — safe to call multiple times.
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		// Register core services en ServiceLocator (placeholders Phase 1, expandidos Sprint 3).
		$this->services->set( 'modules', $this->modules );
		$this->services->set( 'version', AKIBARA_CORE_VERSION );

		/**
		 * Action: akibara_core_init
		 *
		 * Fires después de Bootstrap init. Addons hook aquí para
		 * registrar sus services en ServiceLocator.
		 *
		 * @since 1.0.0
		 *
		 * @param ServiceLocator $services Container singleton.
		 * @param ModuleRegistry $modules  Module registry.
		 */
		do_action( 'akibara_core_init', $this->services, $this->modules );

		$this->initialized = true;
	}

	public function services(): ServiceLocator {
		return $this->services;
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	/**
	 * Check si init() ya corrió. Útil para `akb_core_initialized()` helper.
	 *
	 * @return bool
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}
}
