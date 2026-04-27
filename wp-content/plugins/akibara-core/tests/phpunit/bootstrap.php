<?php
/**
 * PHPUnit bootstrap for akibara-core unit tests.
 *
 * Loads PSR-4 autoloader from composer + stubs minimal WordPress functions
 * needed by Bootstrap.php (deactivate_plugins, get_option, update_option,
 * error_log) so unit tests run without a full WP boot.
 *
 * @package Akibara\Core\Tests
 */

declare( strict_types=1 );

// Constants normally defined by WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'AKIBARA_CORE_VERSION' ) ) {
	define( 'AKIBARA_CORE_VERSION', '1.0.0-test' );
}

// Composer autoloader (loads Akibara\Core\* and Akibara\Core\Tests\* via PSR-4).
$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	throw new RuntimeException(
		"Composer autoload not found at {$autoload}. Run `composer install` in akibara-core/."
	);
}
require_once $autoload;

// Load the WPMock helper class explicitly (composer-dev autoload covers it,
// but we ensure availability before stubs reference it).
require_once __DIR__ . '/Helpers/WPMock.php';

// ─── WordPress function stubs (global namespace) ───────────────────────────
// Tests can override behavior via Akibara\Core\Tests\WPMock static state.

if ( ! function_exists( 'deactivate_plugins' ) ) {
	function deactivate_plugins( string $plugin_basename, bool $silent = false ): void {
		\Akibara\Core\Tests\WPMock::$deactivated[ $plugin_basename ] = true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed {
		return \Akibara\Core\Tests\WPMock::$options[ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, bool|string $autoload = '' ): bool {
		\Akibara\Core\Tests\WPMock::$options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	// Trivial stub — Bootstrap test does not exercise the WP hook system via register_addon().
	function do_action( string $hook, mixed ...$args ): void {
		// Deliberate no-op.
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}
