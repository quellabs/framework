<?php

namespace Quellabs\Recommender;

/** Application-owned current catalog eligibility. */
interface EligibilityProvider {
    /** @param array<int, int> $candidateIds Distinct IDs in candidate order
     * @return array<int, int> An order-preserving subset of the input
     */
    public function filterEligible(array $candidateIds): array;
}
