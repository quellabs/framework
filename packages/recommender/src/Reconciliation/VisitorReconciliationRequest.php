<?php

	namespace Quellabs\Recommender\Reconciliation;

	use Quellabs\Recommender\EligibilityProvider;
	use Quellabs\Recommender\RecommendationSource;

	/** Validated inputs for one reconciliation request for an anonymous visitor. */
	readonly class VisitorReconciliationRequest {

		/** @var ReconciliationRequest The validated request the reconciler ranks with */
		public ReconciliationRequest $request;

		/**
		 * Validate the request inputs and build the shared request they map to.
		 * @param EligibilityProvider $eligibility Application eligibility check
		 * @param array<int, VisitorSource> $sources Enabled visitor source set
		 * @param int $limit Maximum selectable items, from 1 to 100
		 * @param string $placement Display surface, a printable ASCII key up to 64 bytes
		 * @param array<int, int> $newProductIds Ordered new-product suggestions
		 * @param array<int, int> $additionalCandidateIds Application exploration candidates
		 * @param int|null $category Category override
		 * @param string|null $contextKey Model and logging partition, a printable ASCII key up to 128 bytes
		 * @param ReconciliationTuning|null $tuning Threshold and source limit overrides, defaults when null
		 * @throws \InvalidArgumentException When an input is outside its allowed range
		 */
		public function __construct(
			EligibilityProvider   $eligibility,
			array                 $sources,
			int                   $limit,
			string                $placement,
			array                 $newProductIds = [],
			array                 $additionalCandidateIds = [],
			?int                  $category = null,
			?string               $contextKey = null,
			?ReconciliationTuning $tuning = null
		) {
			$this->request = new ReconciliationRequest(
				$eligibility,
				array_map(fn(VisitorSource $source): RecommendationSource => $source->recommendationSource(), $sources),
				$limit,
				$placement,
				$newProductIds,
				$additionalCandidateIds,
				$category,
				$contextKey,
				$tuning
			);
		}
	}
