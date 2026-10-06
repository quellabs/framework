<?php
	
	namespace Quellabs\Recommender\Reconciliation;
	
	use Quellabs\Recommender\RecommendationSource;
	
	/** Chooses the active scorer for a ranking partition. */
	interface ScorerResolver {
		
		/**
		 * Return the active scorer for a partition, or null to use rank fusion.
		 * @param int $category Resolved category
		 * @param string $placement Display surface
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @param string|null $contextKey Model partition
		 * @return ActiveScorer|null Active scorer, or null when none applies
		 */
		public function resolve(int $category, string $placement, array $sources, ?string $contextKey): ?ActiveScorer;
	}
