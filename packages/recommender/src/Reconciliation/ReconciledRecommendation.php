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

		/** @var ReconciliationDiagnostics Features and depths behind the ranking */
		public ReconciliationDiagnostics $diagnostics;

		/**
		 * Build a ranked candidate, rejecting values outside their allowed ranges.
		 * @param int $itemId Catalog ID, an unsigned 32-bit integer
		 * @param float|null $rankingScore Rank-fusion score or reference-position probability
		 * @param array<mixed> $evidence Available source signals
		 * @param ReconciliationDiagnostics|null $diagnostics Features and depths, empty when null
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(
			int $itemId,
			?float $rankingScore,
			array $evidence,
			?ReconciliationDiagnostics $diagnostics = null
		) {
			if ($itemId < 0 || $itemId > Identifier::MAX) {
				throw new \InvalidArgumentException("Item ID must be an unsigned 32-bit integer, got {$itemId}.");
			}

			if ($rankingScore !== null && !is_finite($rankingScore)) {
				throw new \InvalidArgumentException("Ranking score for item {$itemId} must be finite, got {$rankingScore}.");
			}

			$this->itemId = $itemId;
			$this->rankingScore = $rankingScore;
			$this->evidence = $this->validateEvidence($evidence);
			$this->diagnostics = $diagnostics ?? new ReconciliationDiagnostics();
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
	}
