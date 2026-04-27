<?php
/**
 * EditorialLists — canonical source for Brevo editorial list IDs.
 *
 * IDs are HARDCODED. They match the live Brevo account for Akibara.
 * DO NOT change values without verifying against Brevo dashboard.
 *
 * @package Akibara\Marketing\Brevo
 */

declare(strict_types=1);

namespace Akibara\Marketing\Brevo;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical Brevo editorial list constants.
 *
 * The 8 editorial lists that map purchase history → Brevo list membership.
 * Consumed by: SegmentationService, DashboardController (TopEditoriales widget).
 */
final class EditorialLists {

	// ── Editorial IDs (hardcoded — DO NOT change without Brevo dashboard audit) ──

	public const IVREA_AR   = 24;
	public const PANINI_AR  = 25;
	public const PLANETA_ES = 26;
	public const MILKY_WAY  = 27;
	public const OVNI_PRESS = 28;
	public const IVREA_ES   = 29;
	public const PANINI_ES  = 30;
	public const ARECHI     = 31;

	/**
	 * All editorial list IDs keyed by slug.
	 *
	 * @return array<string,int>
	 */
	public const ALL = array(
		'ivrea-ar'   => self::IVREA_AR,
		'panini-ar'  => self::PANINI_AR,
		'planeta-es' => self::PLANETA_ES,
		'milky-way'  => self::MILKY_WAY,
		'ovni-press' => self::OVNI_PRESS,
		'ivrea-es'   => self::IVREA_ES,
		'panini-es'  => self::PANINI_ES,
		'arechi'     => self::ARECHI,
	);

	/**
	 * Human-readable editorial labels keyed by slug.
	 *
	 * @return array<string,string>
	 */
	public const LABELS = array(
		'ivrea-ar'   => 'Ivrea Argentina',
		'panini-ar'  => 'Panini Argentina',
		'planeta-es' => 'Planeta España',
		'milky-way'  => 'Milky Way',
		'ovni-press' => 'Ovni Press',
		'ivrea-es'   => 'Ivrea España',
		'panini-es'  => 'Panini España',
		'arechi'     => 'Arechi Manga',
	);

	/**
	 * Resolve a list ID from editorial slug.
	 *
	 * Falls back to wp_option `akibara_brevo_editorial_lists` (same as legacy
	 * akibara_brevo_editorial_list_id() helper) so prod data is never lost.
	 *
	 * @param string $slug  Editorial slug as defined in ALL constant.
	 * @return int  List ID, or 0 if not found.
	 */
	public static function id_for_slug( string $slug ): int {
		if ( isset( self::ALL[ $slug ] ) ) {
			return self::ALL[ $slug ];
		}
		// Fallback: option-based override (legacy compat).
		$option = (array) get_option( 'akibara_brevo_editorial_lists', array() );
		return isset( $option[ $slug ] ) ? (int) $option[ $slug ] : 0;
	}

	/**
	 * Resolve list ID from editorial name as it appears in WooCommerce
	 * product_brand taxonomy (e.g. "Ivrea Argentina").
	 *
	 * @param string $name  Display name from product_brand.
	 * @return int  List ID or 0 if no match.
	 */
	public static function id_for_name( string $name ): int {
		$flipped = array_flip( self::LABELS );
		$slug    = $flipped[ $name ] ?? null;
		return $slug !== null ? self::id_for_slug( $slug ) : 0;
	}
}
