<?php
/**
 * TopEditoriales widget — editoriales ordenadas por subscribers en Brevo.
 *
 * Consumes Akibara\Marketing\Brevo\EditorialLists::ALL (8 IDs).
 * Data source: Brevo API via SegmentationService.
 *
 * @package Akibara\Marketing\Finance\Widgets
 */

declare(strict_types=1);

namespace Akibara\Marketing\Finance\Widgets;

use Akibara\Marketing\Brevo\EditorialLists;
use Akibara\Marketing\Brevo\SegmentationService;

defined( 'ABSPATH' ) || exit;

/**
 * Returns top editoriales by Brevo subscriber count (all 8 editorial lists).
 *
 * Cache: 15 min per list (handled inside SegmentationService.count_for_list).
 */
final class TopEditoriales {

	private SegmentationService $segmentation;

	public function __construct( SegmentationService $segmentation ) {
		$this->segmentation = $segmentation;
	}

	/**
	 * Fetch editorial subscriber data.
	 *
	 * @return array<string,array{id:int,label:string,count:int}>
	 *   Keyed by editorial slug, sorted by count desc.
	 */
	public function fetch(): array {
		return $this->segmentation->editorial_counts();
	}

	/**
	 * Return the list of all 8 editorial constants for reference.
	 *
	 * @return array<string,int>
	 */
	public function all_list_ids(): array {
		return EditorialLists::ALL;
	}
}
