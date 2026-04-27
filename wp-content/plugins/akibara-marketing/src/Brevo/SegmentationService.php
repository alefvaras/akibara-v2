<?php
/**
 * SegmentationService — thin wrapper around AkibaraBrevo for list management.
 *
 * @package Akibara\Marketing\Brevo
 */

declare(strict_types=1);

namespace Akibara\Marketing\Brevo;

defined( 'ABSPATH' ) || exit;

/**
 * Provides segmentation helpers consumed by the brevo module and finance widgets.
 *
 * All real HTTP calls are delegated to the existing AkibaraBrevo class (from
 * akibara-core includes) — this class adds only the akibara-marketing-specific
 * orchestration layer.
 */
class SegmentationService {

	/**
	 * Fetch Brevo contact count for a single list ID.
	 *
	 * Uses wp_remote_get against the Brevo contacts/lists/{id} endpoint.
	 * Returns 0 on API error or missing key (graceful degradation).
	 *
	 * @param int $list_id  Brevo list ID.
	 * @return int  Contact count or 0 on failure.
	 */
	public function count_for_list( int $list_id ): int {
		if ( ! class_exists( 'AkibaraBrevo' ) ) {
			return 0;
		}

		$api_key = \AkibaraBrevo::get_api_key();
		if ( empty( $api_key ) ) {
			return 0;
		}

		$cache_key = 'akb_brevo_list_count_' . $list_id;
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return (int) $cached;
		}

		$response = wp_remote_get(
			'https://api.brevo.com/v3/contacts/lists/' . $list_id,
			array(
				'headers' => array(
					'api-key' => $api_key,
					'accept'  => 'application/json',
				),
				'timeout' => 8,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return 0;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$count = (int) ( $body['totalSubscribers'] ?? 0 );

		set_transient( $cache_key, $count, 15 * MINUTE_IN_SECONDS );
		return $count;
	}

	/**
	 * Fetch subscriber counts for all 8 editorial lists.
	 *
	 * @return array<string,array{id:int,label:string,count:int}>
	 */
	public function editorial_counts(): array {
		$result = array();
		foreach ( EditorialLists::ALL as $slug => $list_id ) {
			$result[ $slug ] = array(
				'id'    => $list_id,
				'label' => EditorialLists::LABELS[ $slug ] ?? $slug,
				'count' => $this->count_for_list( $list_id ),
			);
		}
		// Sort by count descending.
		uasort(
			$result,
			static fn( array $a, array $b ) => $b['count'] <=> $a['count']
		);
		return $result;
	}
}
