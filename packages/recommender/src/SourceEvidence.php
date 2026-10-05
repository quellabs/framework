<?php
	
	namespace Quellabs\Recommender;
	
	/** One candidate's evidence from one enabled source. */
	readonly class SourceEvidence {
		/** @param RecommendationSource $source Origin algorithm
		 * @param float|null $rawScore Native algorithm score
		 * @param int|null $sourceRank Rank after eligibility filtering
		 * @param int|null $supportCount Summed pair support or rating count
		 * @param array<int, int> $contributingItemIds Rated items behind this signal
		 * @param float|null $logOddsContribution Fitted source contribution
		 */
		public function __construct(
			public RecommendationSource $source,
			public ?float $rawScore = null,
			public ?int $sourceRank = null,
			public ?int $supportCount = null,
			public array $contributingItemIds = [],
			public ?float $logOddsContribution = null,
		) {
			if (($rawScore !== null && !is_finite($rawScore)) || ($sourceRank !== null && $sourceRank < 1)
				|| ($supportCount !== null && $supportCount < 0)
				|| ($logOddsContribution !== null && !is_finite($logOddsContribution))) {
				throw new \InvalidArgumentException('Invalid source evidence.');
			}
			foreach ($contributingItemIds as $id) {
				if (!is_int($id) || $id < 0 || $id > 4294967295) {
					throw new \InvalidArgumentException('Invalid contributing item ID.');
				}
			}
		}
	}
