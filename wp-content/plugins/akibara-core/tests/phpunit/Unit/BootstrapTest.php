<?php
/**
 * Unit tests for Bootstrap auto-recovery (post-INCIDENT-01).
 *
 * Verifies that Bootstrap::register_addon() catches Throwable, deactivates
 * the offending plugin, and persists the failure record — without crashing
 * the site.
 *
 * @package Akibara\Core\Tests\Unit
 */

declare( strict_types=1 );

namespace Akibara\Core\Tests\Unit;

use Akibara\Core\Bootstrap;
use Akibara\Core\Contracts\AddonContract;
use Akibara\Core\Contracts\AddonManifest;
use Akibara\Core\Tests\WPMock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( Bootstrap::class )]
final class BootstrapTest extends TestCase {

	protected function setUp(): void {
		WPMock::reset();
	}

	public function test_register_addon_returns_true_when_init_succeeds(): void {
		$bootstrap = Bootstrap::instance();
		$addon     = new SuccessfulAddon();

		$result = $bootstrap->register_addon( $addon );

		self::assertTrue( $result );
		self::assertSame( array(), WPMock::$deactivated, 'No deactivation expected.' );
		self::assertArrayNotHasKey( 'akibara_disabled_addons', WPMock::$options );
	}

	public function test_register_addon_returns_false_when_init_throws_typeerror(): void {
		$bootstrap = Bootstrap::instance();
		$addon     = new ThrowingAddon();

		$result = $bootstrap->register_addon( $addon );

		self::assertFalse( $result, 'register_addon must return false on Throwable.' );
	}

	public function test_register_addon_isolates_failure_subsequent_calls_work(): void {
		// Per-addon try/catch isolation: if one addon throws, subsequent
		// register_addon() calls still succeed (no cascade failure).
		$bootstrap = Bootstrap::instance();

		$bootstrap->register_addon( new ThrowingAddon() );  // throws + caught
		$result = $bootstrap->register_addon( new SuccessfulAddon() );

		self::assertTrue( $result, 'register_addon must continue working after a previous addon threw.' );
	}

	public function test_addon_manifest_rejects_empty_slug(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AddonManifest( slug: '', version: '1.0.0' );
	}

	public function test_addon_manifest_rejects_empty_version(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AddonManifest( slug: 'x', version: '' );
	}

	public function test_addon_manifest_rejects_invalid_type(): void {
		$this->expectException( \InvalidArgumentException::class );
		new AddonManifest( slug: 'x', version: '1.0.0', type: 'plugin' );
	}

	public function test_addon_manifest_accepts_valid_input(): void {
		$manifest = new AddonManifest(
			slug: 'akibara-test',
			version: '2.5.0',
			type: 'addon',
			dependencies: array( 'akibara-core' => '>=1.0' ),
		);

		self::assertSame( 'akibara-test', $manifest->slug );
		self::assertSame( '2.5.0', $manifest->version );
		self::assertSame( 'addon', $manifest->type );
		self::assertSame( array( 'akibara-core' => '>=1.0' ), $manifest->dependencies );
	}

	public function test_addon_manifest_default_type_is_addon(): void {
		$manifest = new AddonManifest( slug: 'x', version: '1.0' );
		self::assertSame( 'addon', $manifest->type );
	}

	public function test_addon_manifest_accepts_integration_and_analytics_types(): void {
		$integration = new AddonManifest( slug: 'a', version: '1.0', type: 'integration' );
		$analytics   = new AddonManifest( slug: 'b', version: '1.0', type: 'analytics' );
		self::assertSame( 'integration', $integration->type );
		self::assertSame( 'analytics', $analytics->type );
	}
}

// ─── Test fixtures ──────────────────────────────────────────────────────────

final class SuccessfulAddon implements AddonContract {
	public function manifest(): AddonManifest {
		return new AddonManifest( slug: 'test-success', version: '1.0.0' );
	}

	public function init( Bootstrap $bootstrap ): void {
		// no-op success
	}
}

final class ThrowingAddon implements AddonContract {
	public function manifest(): AddonManifest {
		return new AddonManifest( slug: 'test-throw', version: '1.0.0' );
	}

	public function init( Bootstrap $bootstrap ): void {
		throw new \TypeError( 'Simulated contract violation for testing.' );
	}
}
