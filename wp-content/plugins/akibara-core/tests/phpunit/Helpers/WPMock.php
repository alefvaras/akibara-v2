<?php
/**
 * Mock state holder for WordPress function stubs.
 *
 * @package Akibara\Core\Tests
 */

declare( strict_types=1 );

namespace Akibara\Core\Tests;

/**
 * Static state holder — tests reset this in setUp().
 */
final class WPMock {
	/** @var array<string, mixed> Captured wp_option calls. */
	public static array $options = array();

	/** @var array<string, true> Captured deactivate_plugins() calls. */
	public static array $deactivated = array();

	/** @var list<string> Captured error_log() messages. */
	public static array $logs = array();

	public static function reset(): void {
		self::$options     = array();
		self::$deactivated = array();
		self::$logs        = array();
	}
}
