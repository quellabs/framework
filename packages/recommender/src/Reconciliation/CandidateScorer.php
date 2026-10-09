<?php
	
	namespace Quellabs\Recommender\Reconciliation;
	
	/** Turns the features and source evidence of one candidate into a ranking score. */
	interface CandidateScorer {
		
		/**
		 * Score one candidate.
		 * @param array<string, float> $features Serving-time feature values
		 * @param array<int, SourceEvidence> $evidence Ranked source signals for the candidate
		 * @return ScoredCandidate Ranking score and optional source contributions
		 */
		public function score(array $features, array $evidence): ScoredCandidate;
	}
