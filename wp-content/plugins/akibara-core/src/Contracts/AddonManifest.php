<?php
/**
 * Akibara Core — AddonManifest value object.
 *
 * Declares an addon's identity (slug, version, type) and dependencies. Used by
 * Bootstrap::register_addon() to validate compatibility before init().
 *
 * @package Akibara\Core\Contracts
 * @since   2.0.0 (post-INCIDENT-01)
 */

namespace Akibara\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable manifest describing an Akibara addon.
 *
 * Example:
 *   new AddonManifest(
 *       slug: 'akibara-preventas',
 *       version: '1.0.0',
 *       type: 'addon',
 *       dependencies: [ 'akibara-core' => '>=1.0', 'woocommerce' => '>=6.0' ],
 *   );
 */
final class AddonManifest {

	/**
	 * @param string                $slug         Plugin slug (matches plugin directory name).
	 * @param string                $version      Semver string.
	 * @param string                $type         One of: 'addon', 'integration', 'analytics'.
	 * @param array<string, string> $dependencies Map of dependency slug → semver constraint.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $version,
		public readonly string $type = 'addon',
		public readonly array $dependencies = array()
	) {
		if ( '' === $slug ) {
			throw new \InvalidArgumentException( 'AddonManifest slug must be non-empty.' );
		}
		if ( '' === $version ) {
			throw new \InvalidArgumentException( 'AddonManifest version must be non-empty.' );
		}
		if ( ! in_array( $type, array( 'addon', 'integration', 'analytics' ), true ) ) {
			throw new \InvalidArgumentException(
				"AddonManifest type must be one of 'addon'|'integration'|'analytics', got '{$type}'."
			);
		}
	}
}
