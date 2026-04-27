<?php
/**
 * Akibara Core — ModuleRegistry.
 *
 * Tracking de modules cargados (foundation + futuros addons).
 * Cada module se registra a sí mismo en lifecycle hook.
 *
 * Phase 1: stub que solo trackea modules. Sprint 3+ puede agregar
 * dependency graph, health checks, capability flags.
 *
 * @package Akibara\Core\Registry
 * @since   1.0.0
 */

namespace Akibara\Core\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Module registry.
 */
final class ModuleRegistry {

	/**
	 * @var array<string, array{version: string, type: string, loaded_at: float}>
	 */
	private array $modules = array();

	/**
	 * Register a module as loaded.
	 *
	 * Llamado desde cada module en su init.
	 *
	 * @param string $slug    Module slug (e.g. 'search', 'category-urls').
	 * @param string $version Module version.
	 * @param string $type    'core' (built-in) | 'addon' (external plugin).
	 */
	public function declare_module( string $slug, string $version = '1.0.0', string $type = 'core' ): void {
		$this->modules[ $slug ] = array(
			'version'   => $version,
			'type'      => $type,
			'loaded_at' => microtime( true ),
		);

		// Constant para que el theme pueda check via akb_core_module_loaded() helper
		$constant = 'AKB_CORE_MODULE_' . strtoupper( str_replace( '-', '_', $slug ) ) . '_LOADED';
		if ( ! defined( $constant ) ) {
			define( $constant, $version );
		}
	}

	/**
	 * Check si un module está cargado.
	 */
	public function has( string $slug ): bool {
		return isset( $this->modules[ $slug ] );
	}

	/**
	 * Get module info.
	 *
	 * @return array{version: string, type: string, loaded_at: float}|null
	 */
	public function get( string $slug ): ?array {
		return $this->modules[ $slug ] ?? null;
	}

	/**
	 * List all loaded modules.
	 *
	 * @return array<string, array{version: string, type: string, loaded_at: float}>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * List solo slugs (sin metadata).
	 *
	 * @return list<string>
	 */
	public function keys(): array {
		return array_keys( $this->modules );
	}
}
