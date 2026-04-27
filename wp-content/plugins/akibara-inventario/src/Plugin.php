<?php
/**
 * Akibara Inventario — Plugin entry class implementing AddonContract.
 *
 * Type-safe addon registration via Bootstrap::register_addon().
 * Per-addon failure isolation: if this init() throws, only this addon is
 * disabled — akibara-core auto-recovery handles it (INCIDENT-01 lesson).
 *
 * @package Akibara\Inventario
 * @since   1.0.0
 */

namespace Akibara\Inventario;

use Akibara\Core\Bootstrap;
use Akibara\Core\Contracts\AddonContract;
use Akibara\Core\Contracts\AddonManifest;

defined( 'ABSPATH' ) || exit;

final class Plugin implements AddonContract {

	public function manifest(): AddonManifest {
		return new AddonManifest(
			slug: 'akibara-inventario',
			version: \AKB_INVENTARIO_VERSION,
			type: 'addon',
			dependencies: array(
				'akibara-core' => '>=1.0',
				'woocommerce'  => '>=6.0',
			)
		);
	}

	public function init( Bootstrap $bootstrap ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Register repositories with ServiceLocator.
		$bootstrap->services()->register(
			'inventario.stock_repo',
			new Repository\StockRepository()
		);
		$bootstrap->services()->register(
			'inventario.bis_repo',
			new Repository\BackInStockRepository()
		);

		// Declare module in registry.
		$bootstrap->modules()->declare_module( 'akibara-inventario', \AKB_INVENTARIO_VERSION, 'addon' );

		// Load modules — inventory + shipping + back-in-stock.
		$this->load_modules();
	}

	private function load_modules(): void {
		// Run DB schema install idempotently.
		Admin\Schema::maybe_install();

		// Core inventory module (Stock Central admin tab + AJAX handlers).
		require_once \AKB_INVENTARIO_DIR . 'modules/inventory/module.php';

		// Shipping orchestrator (BlueX + 12 Horas).
		require_once \AKB_INVENTARIO_DIR . 'modules/shipping/module.php';

		// Back-in-stock subscriptions.
		require_once \AKB_INVENTARIO_DIR . 'modules/back-in-stock/module.php';
	}
}
