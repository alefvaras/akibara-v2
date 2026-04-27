<?php
/**
 * Akibara Preventas — Plugin entry class implementing AddonContract.
 *
 * Type-safe addon registration via Bootstrap::register_addon().
 *
 * @package Akibara\Preventas
 * @since   2.0.0 (post-INCIDENT-01)
 */

namespace Akibara\Preventas;

use Akibara\Core\Bootstrap;
use Akibara\Core\Contracts\AddonContract;
use Akibara\Core\Contracts\AddonManifest;

defined( 'ABSPATH' ) || exit;

final class Plugin implements AddonContract {

	public function manifest(): AddonManifest {
		return new AddonManifest(
			slug: 'akibara-preventas',
			version: \AKB_PREVENTAS_VERSION,
			type: 'addon',
			dependencies: array(
				'akibara-core' => '>=1.0',
				'woocommerce'  => '>=6.0',
			)
		);
	}

	public function init( Bootstrap $bootstrap ): void {
		if ( ! \class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Register repository with ServiceLocator (via Bootstrap facade).
		$bootstrap->services()->register(
			'preventas.repository',
			new Repository\PreorderRepository()
		);

		// Load class files (legacy + PSR-4 mix preserved from Sprint 3).
		\akb_preventas_load_classes();

		// Init subsystems.
		\Akibara_Reserva_Cart::init();
		\Akibara_Reserva_Orders::init();
		\Akibara_Reserva_Editor::init();
		\Akibara_Reserva_Frontend::init();
		\Akibara_Reserva_Admin::init();
		\Akibara_Reserva_Cron::init();
		\Akibara_Reserva_Stock::init();
		\Akibara_Reserva_MyAccount::init();
		\Akibara_Reserva_Email_Queue::init();

		// Run idempotent migration (admin only to avoid frontend overhead).
		if ( \is_admin() ) {
			\Akibara_Reserva_Migration::maybe_unify_types();
		}

		// Load modules.
		\akb_preventas_load_modules();
	}
}
