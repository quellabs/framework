<?php
	
	namespace Quellabs\Recommender\Reconciliation;
	
	use Quellabs\Recommender\Internal\Identifier;
	
	
	use Quellabs\Recommender\RecommendationSource;
	/** One candidate's evidence from one enabled source. */
	readonly class SourceEvidence {
		
		/** @var RecommendationSource Origin algorithm */
		public RecommendationSource $source;
		
		/** @var float|null Native algorithm score */
		public ?float $rawScore;
		
		/** @var int|null Rank after eligibility filtering */
		public ?int $sourceRank;
		
		/** @var int|null Summed pair support or rating count */
		public ?int $supportCount;
		
		/** @var array<int, int> Rated items behind this signal */
		public array $contributingProductIds;
		
		/** @var float|null Fitted source contribution */
		public ?float $logOddsContribution;
		
		/**
		 * Build the evidence for one source, rejecting invalid values.
		 * @param RecommendationSource $source Origin algorithm
		 * @param float|null $rawScore Native algorithm score
		 * @param int|null $sourceRank Rank after eligibility filtering, at least 1
		 * @param int|null $supportCount Summed pair support or rating count, at least 0
		 * @param array<int, int> $contributingProductIds Rated items behind this signal
		 * @param float|null $logOddsContribution Fitted source contribution
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(
			RecommendationSource $source,
			?float               $rawScore = null,
			?int                 $sourceRank = null,
			?int                 $supportCount = null,
			array                $contributingProductIds = [],
			?float               $logOddsContribution = null
		) {
			if ($rawScore !== null && !is_finite($rawScore)) {
				throw new \InvalidArgumentException("Raw score must be finite, got {$rawScore}.");
			}
			
			if ($sourceRank !== null && $sourceRank < 1) {
				throw new \InvalidArgumentException("Source rank must be at least 1, got {$sourceRank}.");
			}
			
			if ($supportCount !== null && $supportCount < 0) {
				throw new \InvalidArgumentException("Support count must not be negative, got {$supportCount}.");
			}
			
			if ($logOddsContribution !== null && !is_finite($logOddsContribution)) {
				throw new \InvalidArgumentException("Log-odds contribution must be finite, got {$logOddsContribution}.");
			}
			
			self::assertContributingProductIds($contributingProductIds);

			$this->source = $source;
			$this->rawScore = $rawScore;
			$this->sourceRank = $sourceRank;
			$this->supportCount = $supportCount;
			$this->contributingProductIds = $contributingProductIds;
			$this->logOddsContribution = $logOddsContribution;
		}

		/**
		 * Reject contributing item IDs outside the unsigned 32-bit range.
		 * @param array<int, mixed> $ids Contributing item IDs
		 * @return void
		 * @throws \InvalidArgumentException When an ID is not an unsigned 32-bit integer
		 */
		private static function assertContributingProductIds(array $ids): void {
			foreach ($ids as $id) {
				if (!is_int($id) || $id < 0 || $id > Identifier::MAX) {
					throw new \InvalidArgumentException('Contributing item ID must be an unsigned 32-bit integer, got ' . var_export($id, true) . '.');
				}
			}
		}
	}
