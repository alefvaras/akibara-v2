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
use Akibara\Core\Contracts\AddonContract;
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
	 * Fires the `akibara_core_init` hook with the Bootstrap singleton (facade)
	 * as single argument. Wraps the dispatch in `try/catch \Throwable` so that
	 * a single addon's contract violation cannot crash the site — the offending
	 * plugin is auto-disabled (persisted via wp_option) and Bootstrap continues.
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
		 * Fires after Bootstrap initializes core services. Addons hook here to
		 * register their services and declare their modules using the Bootstrap
		 * facade ($bootstrap->services(), $bootstrap->modules(), and any future
		 * subsystems added without breaking changes).
		 *
		 * @since 1.0.0 (2-arg form: ServiceLocator + ModuleRegistry)
		 * @since 2.0.0 (1-arg form: Bootstrap facade — INCIDENT-01 refactor)
		 *
		 * @param Bootstrap $bootstrap Core bootstrap singleton (facade).
		 */
		try {
			do_action( 'akibara_core_init', $this );
		} catch ( \Throwable $e ) {
			$this->handle_addon_failure( $e );
		}

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
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}

	/**
	 * Register an Akibara addon via the typed contract.
	 *
	 * Preferred over the `akibara_core_init` hook for first-party Akibara addons:
	 * the AddonContract interface enforces the `init(Bootstrap)` signature at
	 * compile time (PHPStan + IDE) and the Bootstrap wraps init() in a per-addon
	 * try/catch — a single addon's failure cannot affect other addons or crash
	 * the site.
	 *
	 * Returns true if the addon initialized successfully, false if it threw and
	 * was auto-disabled.
	 *
	 * @param AddonContract $addon Addon plugin instance (class implements AddonContract).
	 * @return bool                True on success, false if the addon was auto-disabled.
	 */
	public function register_addon( AddonContract $addon ): bool {
		try {
			$manifest = $addon->manifest();
			$this->modules->declare_module(
				$manifest->slug,
				$manifest->version,
				$manifest->type
			);
			$addon->init( $this );
			return true;
		} catch ( \Throwable $e ) {
			$this->handle_addon_failure( $e );
			return false;
		}
	}

	/**
	 * Handle a Throwable raised by an addon's `akibara_core_init` callback.
	 *
	 * Logs the error, attempts to identify the offending plugin from the error's
	 * file path, deactivates it (persisted) so the next request boots cleanly.
	 * Site stays UP for end users — only the offending addon is offline.
	 *
	 * @internal
	 */
	private function handle_addon_failure( \Throwable $e ): void {
		// Always log — visible in Sentry + Hostinger error log.
		\error_log( \sprintf(
			'[akibara-core] Addon contract violation: %s in %s:%d',
			$e->getMessage(),
			$e->getFile(),
			$e->getLine()
		) );

		$plugin_basename = $this->plugin_basename_from_path( $e->getFile() );
		if ( null !== $plugin_basename ) {
			$this->disable_addon( $plugin_basename, $e );
		}
	}

	/**
	 * Deactivate an addon plugin and persist the failure record.
	 *
	 * Uses WP core `deactivate_plugins()` so that `active_plugins` option is
	 * updated. Stores failure metadata in `akibara_disabled_addons` for the
	 * admin notice + post-mortem analysis.
	 *
	 * @internal
	 */
	private function disable_addon( string $plugin_basename, \Throwable $e ): void {
		if ( ! \function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		\deactivate_plugins( $plugin_basename, true /* silent */ );

		$disabled                       = \get_option( 'akibara_disabled_addons', array() );
		$disabled[ $plugin_basename ]   = array(
			'reason'    => 'contract_violation',
			'message'   => $e->getMessage(),
			'file'      => $e->getFile(),
			'line'      => $e->getLine(),
			'timestamp' => \time(),
		);
		\update_option( 'akibara_disabled_addons', $disabled, false /* autoload off */ );
	}

	/**
	 * Convert an absolute file path to a WordPress plugin basename.
	 *
	 * Returns `akibara-preventas/akibara-preventas.php` for a path like
	 * `/var/www/.../wp-content/plugins/akibara-preventas/akibara-preventas.php`.
	 * Returns null if the path is not under wp-content/plugins/.
	 *
	 * @internal
	 */
	private function plugin_basename_from_path( string $file_path ): ?string {
		if ( \preg_match( '#wp-content/plugins/([^/]+/[^/]+\.php)#', $file_path, $m ) ) {
			return $m[1];
		}
		return null;
	}
}
