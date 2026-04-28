<?php
/**
 * Akibara Core — Módulo Product Badges
 *
 * Set definido 2026-04 tras auditoría UX/CRO/A11Y/Competitiva:
 *   - Estado (top-left, exclusivo): Preventa · Agotado · Disponible
 *   - Comercial (top-right, cuando aplica): Ahorra X%
 *
 * Migrado desde akibara/modules/product-badges/module.php (Polish #1 2026-04-26).
 * Group-wrap pattern + sentinel per HANDOFF §8 (REDESIGN.md §9).
 *
 * @package    Akibara\Core
 * @subpackage ProductBadges
 * @version    1.1.0
 */

defined( 'ABSPATH' ) || exit;

// ─── File-level guard ───────────────────────────────────────────────────────
if ( defined( 'AKB_CORE_PRODUCT_BADGES_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_PRODUCT_BADGES_LOADED', '1.1.0' );

// Backward-compat: si el legacy definió AKB_PRODUCT_BADGES_LOADED, no redeclares.
if ( defined( 'AKB_PRODUCT_BADGES_LOADED' ) ) {
	return;
}

// Constant signal per ModuleRegistry pattern.
if ( ! defined( 'AKB_CORE_MODULE_PRODUCT_BADGES_LOADED' ) ) {
	define( 'AKB_CORE_MODULE_PRODUCT_BADGES_LOADED', '1.1.0' );
}

// ─── Group wrap (REDESIGN.md §9) ────────────────────────────────────────────
if ( ! function_exists( 'akb_plugin_render_badges' ) ) {

	/**
	 * Render product badges HTML.
	 *
	 * Estado → top-left, exclusivo (máx 1): Preventa > Agotado > Disponible.
	 * Comercial → top-right, independiente: Ahorra X% si hay sale real.
	 *
	 * @param WC_Product $product
	 * @return void
	 */
	function akb_plugin_render_badges( $product ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$product_id = $product->get_id();
		$is_reserva = get_post_meta( $product_id, '_akb_reserva', true ) === 'yes';

		// ── Comercial: Ahorra X% (top-right) ────────────────────────
		$discount_badge = '';
		if ( $product->is_on_sale() && $product->is_type( 'simple' ) ) {
			$regular = (float) $product->get_regular_price();
			$sale    = (float) $product->get_sale_price();
			if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
				$pct = (int) round( ( ( $regular - $sale ) / $regular ) * 100 );
				if ( $pct > 0 ) {
					$discount_badge = '<span class="badge badge--sale"><span>Ahorra ' . $pct . '%</span></span>';
				}
			}
		}

		// ── Estado: Preventa > Agotado > Disponible (top-left, uno solo) ──
		$status_badge = '';
		if ( $is_reserva ) {
			$status_badge = '<span class="badge badge--preorder"><span>Preventa</span></span>';
		} elseif ( ! $product->is_in_stock() ) {
			$status_badge = '<span class="badge badge--out"><span>Agotado</span></span>';
		} else {
			$status_badge = '<span class="badge badge--stock"><span>Disponible</span></span>';
		}

		if ( $discount_badge ) {
			echo '<div class="product-card__badge-discount">' . $discount_badge . '</div>';
		}
		if ( $status_badge ) {
			echo '<div class="product-card__badges">' . $status_badge . '</div>';
		}
	}

} // end group wrap
