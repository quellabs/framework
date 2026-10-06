<?php

	namespace Quellabs\Recommender\Internal\Eligibility;

	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\EligibilityProvider;

	/**
	 * Applies an application eligibility provider to ranked rows, calling it in batches.
	 *
	 * Throws when a provider answer is not an ordered subset of the IDs it was given.
	 */
	final class EligibilityFilter {

		/** @var RecommendationConfig Batch size and depth cap defaults */
		private RecommendationConfig $config;

		/**
		 * Build the filter.
		 * @param RecommendationConfig $config Recommender settings
		 */
		public function __construct(RecommendationConfig $config) {
			$this->config = $config;
		}

		/**
		 * Check eligibility for IDs in batches and return the eligible ones in candidate order.
		 * @param EligibilityProvider $eligibility Application eligibility check
		 * @param array<int, int> $ids Distinct candidate IDs in candidate order
		 * @param int<1, max> $batchSize Maximum IDs per provider call
		 * @return array<int, int> Eligible IDs in candidate order
		 * @throws \UnexpectedValueException When a provider answer is not an ordered subset of its batch
		 */
		public function check(EligibilityProvider $eligibility, array $ids, int $batchSize): array {
			$eligible = [];

			foreach (array_chunk($ids, $batchSize) as $batch) {
				$response = $eligibility->filterEligible($batch);
				$this->validateResponse($batch, $response);

				foreach ($response as $id) {
					$eligible[] = $id;
				}
			}

			return $eligible;
		}

		/**
		 * Run a ranked query with an optional eligibility provider applied.
		 * Rows are checked in batches, fetching deeper until the limit is met or the depth cap is reached.
		 * @template T
		 * @param EligibilityProvider|null $eligibility Eligibility provider, or null for no restriction
		 * @param int $limit Maximum results, or zero for all
		 * @param callable(int): array<int, T> $query Receives a depth (zero for all), returns ranked rows
		 * @param callable(T): int $idOf Returns the product ID of a row
		 * @return array<int, T> Ranked rows that pass eligibility
		 */
		public function withEligibility(?EligibilityProvider $eligibility, int $limit, callable $query, callable $idOf): array {
			if ($eligibility === null) {
				return $query($limit);
			}

			return $this->fetchUntilFilled($limit, $eligibility, $query, $idOf);
		}

		/**
		 * Fetch ranked rows at growing depth until enough pass eligibility or the depth cap is reached.
		 * @template T
		 * @param int $limit Maximum results, or zero for all
		 * @param EligibilityProvider $eligibility Application eligibility check
		 * @param callable(int): array<int, T> $fetch Returns the top rows for a depth, zero meaning all
		 * @param callable(T): int $idOf Returns the product ID of a row
		 * @return array<int, T> Ranked rows that pass eligibility
		 */
		private function fetchUntilFilled(int $limit, EligibilityProvider $eligibility, callable $fetch, callable $idOf): array {
			$verdicts = [];

			if ($limit === 0) {
				return $this->takeEligible($fetch(0), 0, $eligibility, $idOf, $verdicts);
			}

			$maxDepth = max($limit, $this->config->maxCandidateDepth());
			$depth = $limit;

			while (true) {
				$rows = $fetch($depth);
				$taken = $this->takeEligible($rows, $limit, $eligibility, $idOf, $verdicts);

				if (count($taken) >= $limit || count($rows) < $depth || $depth >= $maxDepth) {
					return $taken;
				}

				$depth = min($maxDepth, 2 * $depth);
			}
		}

		/**
		 * Return the first eligible rows in ranked order, checking unseen IDs in batches and stopping at the limit.
		 * @template T
		 * @param array<int, T> $rows Rows in ranked order
		 * @param int $limit Maximum rows, or zero for all
		 * @param EligibilityProvider $eligibility Application eligibility check
		 * @param callable(T): int $idOf Returns the product ID of a row
		 * @param array<int, bool> $verdicts Eligibility per ID already checked, updated in place
		 * @return array<int, T> Eligible rows in ranked order
		 */
		private function takeEligible(array $rows, int $limit, EligibilityProvider $eligibility, callable $idOf, array &$verdicts): array {
			$batchSize = max(1, $this->config->maxEligibilityBatchSize());
			$taken = [];

			foreach (array_chunk($rows, $batchSize) as $chunk) {
				$unknown = [];

				foreach ($chunk as $row) {
					$id = $idOf($row);

					if (!array_key_exists($id, $verdicts)) {
						$verdicts[$id] = false;
						$unknown[] = $id;
					}
				}

				foreach ($this->check($eligibility, $unknown, $batchSize) as $id) {
					$verdicts[$id] = true;
				}

				foreach ($chunk as $row) {
					if (!$verdicts[$idOf($row)]) {
						continue;
					}

					$taken[] = $row;

					if ($limit > 0 && count($taken) >= $limit) {
						return $taken;
					}
				}
			}

			return $taken;
		}

		/**
		 * Check that the provider answer is an ordered subset of the submitted batch.
		 * @param array<int, int> $submitted Submitted batch
		 * @param array<mixed> $response Provider response
		 * @return void
		 * @throws \UnexpectedValueException When the response has a non-integer ID or is out of order
		 */
		private function validateResponse(array $submitted, array $response): void {
			$cursor = 0;

			foreach ($response as $id) {
				if (!is_int($id)) {
					throw new \UnexpectedValueException('Eligibility response contains a non-integer ID: ' . var_export($id, true) . '.');
				}

				while ($cursor < count($submitted) && $submitted[$cursor] !== $id) {
					$cursor++;
				}

				if ($cursor === count($submitted)) {
					throw new \UnexpectedValueException("Eligibility response ID {$id} is not an ordered subset of the submitted IDs.");
				}

				$cursor++;
			}
		}
	}
