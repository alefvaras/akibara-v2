<?php
/**
 * Akibara Core — ServiceLocator.
 *
 * Container minimal singleton para sharing services entre core y addons.
 * Phase 1 implementation — minimal viable. Sprint 3+ puede expand a DI container.
 *
 * @package Akibara\Core\Container
 * @since   1.0.0
 */

namespace Akibara\Core\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight service locator (NOT full DI container).
 *
 * Uso:
 *   $services = ServiceLocator::instance();
 *   $services->set('order_helper', $obj);
 *   $obj = $services->get('order_helper');
 */
final class ServiceLocator {

	private static ?ServiceLocator $instance = null;

	/** @var array<string, mixed> */
	private array $services = array();

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a service by id.
	 */
	public function set( string $id, mixed $service ): void {
		$this->services[ $id ] = $service;
	}

	/**
	 * Retrieve a service by id.
	 *
	 * @throws \RuntimeException If service not found.
	 */
	public function get( string $id ): mixed {
		if ( ! isset( $this->services[ $id ] ) ) {
			throw new \RuntimeException( "Service '{$id}' not registered in Akibara\\Core\\ServiceLocator" );
		}
		return $this->services[ $id ];
	}

	/**
	 * Check if service is registered.
	 */
	public function has( string $id ): bool {
		return isset( $this->services[ $id ] );
	}

	/**
	 * List all registered service ids.
	 *
	 * @return list<string>
	 */
	public function keys(): array {
		return array_keys( $this->services );
	}
}
