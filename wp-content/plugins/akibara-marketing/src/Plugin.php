<?php
/**
 * Akibara Marketing — Plugin entry class implementing AddonContract.
 *
 * Type-safe addon registration via Bootstrap::register_addon().
 *
 * @package Akibara\Marketing
 * @since   2.0.0 (post-INCIDENT-01)
 */

namespace Akibara\Marketing;

use Akibara\Core\Bootstrap;
use Akibara\Core\Contracts\AddonContract;
use Akibara\Core\Contracts\AddonManifest;

defined( 'ABSPATH' ) || exit;

final class Plugin implements AddonContract {

	public function manifest(): AddonManifest {
		return new AddonManifest(
			slug: 'akibara-marketing',
			version: \AKB_MARKETING_VERSION,
			type: 'addon',
			dependencies: array(
				'akibara-core' => '>=1.0',
			)
		);
	}

	public function init( Bootstrap $bootstrap ): void {
		// Bootstrap::register_addon() already declared the module via manifest().
		// Run DB migrations idempotently.
		\akb_marketing_maybe_run_migrations();

		// Load modules.
		\akb_marketing_load_modules();
	}
}
