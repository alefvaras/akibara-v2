<?php
/**
 * Akibara Marketing — Descuentos Tramos (Volume Tiers)
 *
 * NOTE: This module has NO legacy source in the server-snapshot
 * (server-snapshot/public_html/wp-content/plugins/akibara/modules/descuentos-tramos/
 * is empty). The tramos (volume tier) functionality is already implemented
 * inside the main descuentos module (modules/descuentos/cart.php v11.1 and
 * engine.php), as the `tramos` field on carrito-type rules.
 *
 * This module exists as a named entry point that currently:
 *   1. Optionally auto-creates the default volume rule (via tramos-setup.php
 *      which is already included by modules/descuentos/tramos-setup.php).
 *   2. Provides a future hook point for standalone tramo management UI.
 *
 * Status: PASS-THROUGH — all real logic lives in modules/descuentos/.
 * No additional hooks registered here to avoid duplicates.
 *
 * @package    Akibara\Marketing
 * @subpackage DescuentosTramos
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_MARKETING_TRAMOS_LOADED' ) ) {
	return;
}
define( 'AKB_MARKETING_TRAMOS_LOADED', '1.0.0' );

// ── Group wrap ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_marketing_tramos_sentinel' ) ) {

	function akb_marketing_tramos_sentinel(): bool {
		return defined( 'AKB_MARKETING_TRAMOS_LOADED' );
	}

	// Tramos logic is provided by the descuentos module (cart.php + engine.php).
	// This module is intentionally thin — it only loads the setup routine if the
	// descuentos module is active and the rule hasn't been created yet.
	add_action(
		'plugins_loaded',
		static function (): void {
			if ( ! defined( 'AKIBARA_DESCUENTOS_LOADED' ) ) {
				return;
			}
			// tramos-setup.php is already loaded by modules/descuentos/module.php
			// via the tramos-setup.php file in that directory.
			// No duplicate load needed here.
		},
		30
	);

} // end group wrap
