<?php

	namespace Quellabs\Recommender\Reconciliation;

	/** Scores a candidate as the sum of its reciprocal source ranks. */
	readonly class RankFusionScorer implements CandidateScorer {

		/** Rank offset that damps the weight of top positions */
		private const RANK_OFFSET = 60;

		/**
		 * Return the reciprocal-rank weight of one source position.
		 * @param int $rank One-based position within a source
		 * @return float Reciprocal-rank weight
		 */
		public static function reciprocalRank(int $rank): float {
			return 1 / (self::RANK_OFFSET + $rank);
		}

		/**
		 * Sum the reciprocal ranks of the candidate's ranked signals.
		 * @param array<string, float> $features Unused; rank fusion reads the evidence only
		 * @param array<int, SourceEvidence> $evidence Ranked source signals for the candidate
		 * @return ScoredCandidate Fused score without contributions
		 */
		public function score(array $features, array $evidence): ScoredCandidate {
			$score = 0.0;

			foreach ($evidence as $signal) {
				if ($signal->sourceRank !== null) {
					$score += self::reciprocalRank($signal->sourceRank);
				}
			}

			return new ScoredCandidate($score, null);
		}
	}
