<?php

	namespace Quellabs\Recommender\Reconciliation;

	/** Serving-time features and search depths that explain a reconciled recommendation. */
	readonly class ReconciliationDiagnostics {

		/** @var array<string, float> Serving-time feature values */
		public array $featureSnapshot;

		/** @var array<string, float> Fitted terms for all enabled sources */
		public array $sourceLogOddsContributions;

		/** @var array<string, int> Requested LIMIT reached per enabled source */
		public array $searchedDepths;

		/**
		 * Validate the diagnostics and store them in canonical form.
		 * @param array<mixed> $featureSnapshot Serving-time feature values
		 * @param array<mixed> $sourceLogOddsContributions Fitted terms for all enabled sources
		 * @param array<mixed> $searchedDepths Requested LIMIT reached per enabled source
		 * @throws \InvalidArgumentException When a value is outside its allowed range
		 */
		public function __construct(array $featureSnapshot = [], array $sourceLogOddsContributions = [], array $searchedDepths = []) {
			$this->featureSnapshot = $this->validateFeatureValues($featureSnapshot);
			$this->sourceLogOddsContributions = self::validateSourceContributions($sourceLogOddsContributions);
			$this->searchedDepths = self::validateSearchedDepths($searchedDepths);
		}

		/**
		 * Reject feature values that are not finite numbers keyed by name, and store them as floats.
		 * @param array<mixed> $featureSnapshot Feature values to check
		 * @return array<string, float> The validated feature values
		 * @throws \InvalidArgumentException When a name is not a string or a value is not a finite number
		 */
		private function validateFeatureValues(array $featureSnapshot): array {
			$validated = [];

			foreach ($featureSnapshot as $name => $value) {
				if (!is_string($name) || (!is_float($value) && !is_int($value)) || !is_finite((float)$value)) {
					throw new \InvalidArgumentException("Feature '{$name}' must be a finite number, got " . var_export($value, true) . '.');
				}

				$validated[$name] = (float)$value;
			}

			return $validated;
		}

		/**
		 * Reject source log-odds contributions that are not finite floats keyed by source name.
		 * @param array<mixed> $contributions Source log-odds contributions
		 * @return array<string, float> The validated contributions
		 * @throws \InvalidArgumentException When a contribution is not a finite float
		 */
		private static function validateSourceContributions(array $contributions): array {
			$validated = [];

			foreach ($contributions as $name => $value) {
				if (!is_string($name) || !is_float($value) || !is_finite($value)) {
					throw new \InvalidArgumentException("Source contribution '{$name}' must be a finite float, got " . var_export($value, true) . '.');
				}

				$validated[$name] = $value;
			}

			return $validated;
		}

		/**
		 * Reject searched depths that are not positive integers keyed by source name.
		 * @param array<mixed> $depths Searched depths per source
		 * @return array<string, int> The validated depths
		 * @throws \InvalidArgumentException When a depth is not a positive integer
		 */
		private static function validateSearchedDepths(array $depths): array {
			$validated = [];

			foreach ($depths as $name => $depth) {
				if (!is_string($name) || !is_int($depth) || $depth < 1) {
					throw new \InvalidArgumentException("Searched depth for '{$name}' must be a positive integer, got " . var_export($depth, true) . '.');
				}

				$validated[$name] = $depth;
			}

			return $validated;
		}
	}
