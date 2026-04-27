<?php
/**
 * TrendingSearches widget — top busquedas en la tienda.
 *
 * Data source: wp_option 'akibara_trending_searches' (written by akibara-core search).
 * Prod known values: One Piece 196k, Jujutsu 34, Berserk 9.
 *
 * @package Akibara\Marketing\Finance\Widgets
 */

declare(strict_types=1);

namespace Akibara\Marketing\Finance\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Returns top search terms from the store's search log.
 *
 * Reads 'akibara_trending_searches' option — populated by akibara-core's
 * search module (read-only here).
 */
final class TrendingSearches {

	private const OPTION_KEY = 'akibara_trending_searches';
	private const CACHE_KEY  = 'akb_fin_trending_searches';
	private const CACHE_TTL  = 10 * MINUTE_IN_SECONDS;

	/**
	 * Fetch trending searches.
	 *
	 * @param int $limit  Max items to return (default 10).
	 * @return array<int,array{term:string,count:int}>
	 */
	public function fetch( int $limit = 10 ): array {
		$cache_key = self::CACHE_KEY . '_' . $limit;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$raw    = get_option( self::OPTION_KEY, array() );
		$result = $this->normalize( (array) $raw, $limit );

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Normalize option data into a consistent array shape.
	 *
	 * The option can be stored in two formats:
	 *   a) array<string, int>  — term => count (legacy flat format)
	 *   b) array<array{term, count}> — structured format
	 *
	 * @param array<mixed> $raw
	 * @return array<int,array{term:string,count:int}>
	 */
	private function normalize( array $raw, int $limit ): array {
		$result = array();

		foreach ( $raw as $key => $value ) {
			if ( is_array( $value ) && isset( $value['term'] ) ) {
				// Structured format.
				$result[] = array(
					'term'  => (string) $value['term'],
					'count' => (int) ( $value['count'] ?? 0 ),
				);
			} elseif ( is_string( $key ) && ( is_int( $value ) || is_numeric( $value ) ) ) {
				// Flat format: term => count.
				$result[] = array(
					'term'  => $key,
					'count' => (int) $value,
				);
			}
		}

		// Sort by count descending.
		usort(
			$result,
			static fn( array $a, array $b ) => $b['count'] <=> $a['count']
		);

		return array_slice( $result, 0, $limit );
	}
}
