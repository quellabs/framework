<?php

namespace Quellabs\Recommender;

/** Application-owned current catalog eligibility. */
interface EligibilityProvider {
	
	/**
	 * Return the candidate IDs that are currently eligible.
	 * @param array<int, int> $candidateIds Distinct IDs in candidate order
	 * @return array<int, int> An order-preserving subset of the input
	 */
	public function filterEligible(array $candidateIds): array;
}
