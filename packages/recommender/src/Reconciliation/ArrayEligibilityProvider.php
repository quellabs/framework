<?php
	
	namespace Quellabs\Recommender\Reconciliation;
	
	use Quellabs\Recommender\Internal\Identifier;
	
	/** In-memory adapter for catalogs small enough to supply every eligible ID. */
	readonly class ArrayEligibilityProvider implements EligibilityProvider {
		
		/** @var array<int, true> Eligible IDs as keys */
		private array $eligible;
		
		/**
		 * Index the eligible IDs for constant-time lookup.
		 * @param array<int, int> $eligibleIds All eligible catalog IDs
		 * @throws \InvalidArgumentException When an ID is not an unsigned 32-bit integer
		 */
		public function __construct(array $eligibleIds) {
			$eligible = [];
			
			foreach ($eligibleIds as $id) {
				if (!is_int($id) || $id < 0 || $id > Identifier::MAX) {
					throw new \InvalidArgumentException('Eligible ID must be an unsigned 32-bit integer, got ' . var_export($id, true) . '.');
				}
				
				$eligible[$id] = true;
			}
			
			$this->eligible = $eligible;
		}
		
		/**
		 * Return the candidate IDs that are eligible, preserving candidate order.
		 * @param array<int, int> $candidateIds Distinct IDs in candidate order
		 * @return array<int, int> An order-preserving subset of the input
		 */
		public function filterEligible(array $candidateIds): array {
			return array_values(array_filter($candidateIds, fn($id) => isset($this->eligible[$id])));
		}
	}
