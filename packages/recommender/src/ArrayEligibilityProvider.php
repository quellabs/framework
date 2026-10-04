<?php

namespace Quellabs\Recommender;

/** In-memory adapter for catalogs small enough to supply every eligible ID. */
readonly class ArrayEligibilityProvider implements EligibilityProvider {
    /** @var array<int, true> */
    private array $eligible;

    /** @param array<int, int> $eligibleIds All eligible catalog IDs. */
    public function __construct(array $eligibleIds) {
        $map = [];
        foreach ($eligibleIds as $id) {
            if (!is_int($id) || $id < 0 || $id > 4294967295) {
                throw new \InvalidArgumentException('Eligible IDs must be unsigned 32-bit integers.');
            }
            $map[$id] = true;
        }
        $this->eligible = $map;
    }

    /** @inheritDoc */
    public function filterEligible(array $candidateIds): array {
        return array_values(array_filter($candidateIds, fn($id) => isset($this->eligible[$id])));
    }
}
