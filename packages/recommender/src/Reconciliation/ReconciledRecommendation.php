<?php
	
	namespace Quellabs\Recommender\Reconciliation;
	
	use Quellabs\Recommender\Internal\Identifier;
	
	/** A ranked candidate with its bounded source evidence. */
	readonly class ReconciledRecommendation {
		
		/** @var int Catalog ID */
		public int $itemId;
		
		/** @var float|null Rank-fusion score or reference-position probability */
		public ?float $rankingScore;
		
		/** @var array<int, SourceEvidence> Available source signals */
		public array $evidence;
		
		/** @var array<string, float> Serving-time feature values */
		public array $featureSnapshot;
		
		/** @var array<string, float> Fitted terms for all enabled sources */
		public array $sourceLogOddsContributions;
		
		/** @var array<string, int> Requested LIMIT reached per enabled source */
		public array $searchedDepths;
		
		/**
		 * Build a ranked candidate, rejecting values outside their allowed ranges.
		 * @param int $itemId Catalog ID, an unsigned 32-bit integer
		 * @param float|null $rankingScore Rank-fusion score or reference-position probability
		 * @param array<mixed> $evidence Available source signals
		 * @param array<mixed> $featureSnapshot Serving-time feature values
		 * @param array<mixed> $sourceLogOddsContributions Fitted terms for all enabled sources
		 * @param array<mixed> $searchedDepths Requested LIMIT reached per enabled source
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(
			int $itemId,
			?float $rankingScore,
			array $evidence,
			array $featureSnapshot = [],
			array $sourceLogOddsContributions = [],
			array $searchedDepths = []
		) {
			if ($itemId < 0 || $itemId > Identifier::MAX) {
				throw new \InvalidArgumentException("Item ID must be an unsigned 32-bit integer, got {$itemId}.");
			}
			
			if ($rankingScore !== null && !is_finite($rankingScore)) {
				throw new \InvalidArgumentException("Ranking score for item {$itemId} must be finite, got {$rankingScore}.");
			}
			
			$validatedEvidence = $this->validateEvidence($evidence);
			$validatedFeatures = $this->validateFeatureValues($featureSnapshot);
			$validatedContributions = self::validateSourceContributions($sourceLogOddsContributions);
			$validatedDepths = self::validateSearchedDepths($searchedDepths);
			
			$this->itemId = $itemId;
			$this->rankingScore = $rankingScore;
			$this->evidence = $validatedEvidence;
			$this->featureSnapshot = $validatedFeatures;
			$this->sourceLogOddsContributions = $validatedContributions;
			$this->searchedDepths = $validatedDepths;
		}
		
		/**
		 * Reject source log-odds contributions that are not finite floats keyed by source name.
		 * @param array<mixed> $contributions Source log-odds contributions
		 * @return array<string, float> The validated contributions
		 * @throws \InvalidArgumentException When a contribution is not a finite float
		 */
		private static function validateSourceContributions(array $contributions): array {
			$validated = [];

			foreach ($contributions as $name => $value) {
				if (!is_string($name) || !is_float($value) || !is_finite($value)) {
					throw new \InvalidArgumentException("Source contribution '{$name}' must be a finite float, got " . var_export($value, true) . '.');
				}

				$validated[$name] = $value;
			}

			return $validated;
		}
		
		/**
		 * Reject searched depths that are not positive integers keyed by source name.
		 * @param array<mixed> $depths Searched depths per source
		 * @return array<string, int> The validated depths
		 * @throws \InvalidArgumentException When a depth is not a positive integer
		 */
		private static function validateSearchedDepths(array $depths): array {
			$validated = [];

			foreach ($depths as $name => $depth) {
				if (!is_string($name) || !is_int($depth) || $depth < 1) {
					throw new \InvalidArgumentException("Searched depth for '{$name}' must be a positive integer, got " . var_export($depth, true) . '.');
				}

				$validated[$name] = $depth;
			}

			return $validated;
		}
		
		/**
		 * Reject evidence that is not a SourceEvidence or repeats a source.
		 * @param array<mixed> $evidence Source signals to check
		 * @return array<int, SourceEvidence> The validated signals
		 * @throws \InvalidArgumentException When a signal is invalid or repeats a source
		 */
		private function validateEvidence(array $evidence): array {
			$sourceValues = [];
			$validated = [];

			foreach ($evidence as $signal) {
				if (!$signal instanceof SourceEvidence) {
					throw new \InvalidArgumentException('Evidence must contain SourceEvidence instances, got ' . get_debug_type($signal) . '.');
				}

				if (isset($sourceValues[$signal->source->value])) {
					throw new \InvalidArgumentException("Evidence repeats source '{$signal->source->value}'.");
				}

				$sourceValues[$signal->source->value] = true;
				$validated[] = $signal;
			}

			return $validated;
		}
		
		/**
		 * Reject feature values that are not finite numbers keyed by name, and store them as floats.
		 * @param array<mixed> $featureSnapshot Feature values to check
		 * @return array<string, float> The validated feature values
		 * @throws \InvalidArgumentException When a name is not a string or a value is not a finite number
		 */
		private function validateFeatureValues(array $featureSnapshot): array {
			$validated = [];

			foreach ($featureSnapshot as $name => $value) {
				if (!is_string($name) || (!is_float($value) && !is_int($value)) || !is_finite((float)$value)) {
					throw new \InvalidArgumentException("Feature '{$name}' must be a finite number, got " . var_export($value, true) . '.');
				}

				$validated[$name] = (float)$value;
			}

			return $validated;
		}
	}
