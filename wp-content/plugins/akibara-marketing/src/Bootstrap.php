<?php
/**
 * Bootstrap — akibara-marketing addon entry point.
 *
 * @package Akibara\Marketing
 */

declare(strict_types=1);

namespace Akibara\Marketing;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap singleton for akibara-marketing.
 *
 * Registers the addon with akibara-core's ServiceLocator and
 * exposes the finance-dashboard widget registry.
 */
final class Bootstrap {

	private static ?self $instance = null;

	/** @var array<string,object> */
	private array $services = array();

	private function __construct() {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init — called once from akb_marketing_sentinel's akibara_core_init callback.
	 */
	public static function init(): void {
		self::instance(); // ensure singleton exists
	}

	public function register( string $id, object $service ): void {
		$this->services[ $id ] = $service;
	}

	public function get( string $id ): ?object {
		return $this->services[ $id ] ?? null;
	}
}
