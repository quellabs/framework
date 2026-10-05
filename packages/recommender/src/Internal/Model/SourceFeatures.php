<?php

namespace Quellabs\Recommender\Internal\Model;

use Quellabs\Recommender\RecommendationSource;

/** Feature names that version-1 click models read for each enabled source. */
final class SourceFeatures {

	/** @var array<int, string> Feature suffixes shared by every source */
	public const SUFFIXES = ['log_depth_searched', 'present', 'reciprocal_rank', 'score', 'count'];

	/**
	 * Return the sorted feature names that the enabled sources contribute to a snapshot.
	 * @param array<int, RecommendationSource> $sources Enabled sources
	 * @return array<int, string> Sorted feature names, such as "item_links.score"
	 */
	public static function names(array $sources): array {
		$names = [];

		foreach ($sources as $source) {
			foreach (self::SUFFIXES as $suffix) {
				$names[] = $source->value . '.' . $suffix;
			}
		}

		sort($names);
		return $names;
	}
}
