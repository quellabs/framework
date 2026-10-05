<?php
	
	namespace Quellabs\Recommender\Internal\Model;
	
	/** Immutable version-1 standardized logistic click model. */
	readonly class ClickModel {
		
		/** @var array<string, mixed> Validated stored model artifact */
		public array $artifact;
		
		/** @var array<string, float> Fitted coefficient per feature */
		private array $coefficients;
		
		/** @var array<string, float> Feature means used for standardization */
		private array $means;
		
		/** @var array<string, float> Feature scales used for standardization */
		private array $scales;
		
		/** @var float Fitted intercept */
		private float $intercept;
		
		/**
		 * Validate a stored artifact and load its coefficients.
		 * @param array<string, mixed> $artifact Validated stored model artifact
		 * @throws \UnexpectedValueException When the artifact or a coefficient is invalid
		 */
		public function __construct(array $artifact) {
			[$intercept, $rawCoefficients, $rawMeans, $rawScales] = self::readArtifactShape($artifact);

			$coefficients = [];
			$means = [];
			$scales = [];

			foreach ($rawCoefficients as $name => $coefficient) {
				[$coefficients[$name], $means[$name], $scales[$name]] = self::readCoefficient($name, $coefficient, $rawMeans, $rawScales);
			}

			$this->artifact = $artifact;
			$this->intercept = $intercept;
			$this->coefficients = $coefficients;
			$this->means = $means;
			$this->scales = $scales;
		}

		/**
		 * Validate the version-1 top-level fields and return the typed values the constructor needs.
		 * @param array<string, mixed> $artifact Stored model artifact
		 * @return array{float, array<mixed>, array<mixed>, array<mixed>} Intercept, coefficients, means and scales
		 * @throws \UnexpectedValueException When a required field is missing or malformed
		 */
		private static function readArtifactShape(array $artifact): array {
			if (
				($artifact['feature_schema_version'] ?? null) !== 1 ||
				!isset($artifact['intercept'], $artifact['coefficients'], $artifact['means'], $artifact['scales']) ||
				!is_numeric($artifact['intercept']) ||
				!is_finite((float)$artifact['intercept']) ||
				!is_array($artifact['coefficients']) ||
				!is_array($artifact['means']) ||
				!is_array($artifact['scales'])
			) {
				throw new \UnexpectedValueException('Incompatible click model artifact.');
			}

			return [(float)$artifact['intercept'], $artifact['coefficients'], $artifact['means'], $artifact['scales']];
		}

		/**
		 * Validate one coefficient with its mean and scale and return them as floats.
		 * @param int|string $name Feature name
		 * @param mixed $coefficient Stored coefficient
		 * @param array<mixed> $means Stored feature means
		 * @param array<mixed> $scales Stored feature scales
		 * @return array{float, float, float} Coefficient, mean and scale
		 * @throws \UnexpectedValueException When the coefficient or its standardization values are invalid
		 */
		private static function readCoefficient(int|string $name, mixed $coefficient, array $means, array $scales): array {
			if (
				!is_string($name) ||
				!is_numeric($coefficient) ||
				!isset($means[$name], $scales[$name]) ||
				!is_numeric($means[$name]) ||
				!is_numeric($scales[$name]) ||
				!is_finite((float)$coefficient) ||
				!is_finite((float)$means[$name]) ||
				!is_finite((float)$scales[$name]) ||
				(float)$scales[$name] <= 0
			) {
				throw new \UnexpectedValueException("Invalid click model coefficient for feature '{$name}'.");
			}

			return [(float)$coefficient, (float)$means[$name], (float)$scales[$name]];
		}
		
		/**
		 * Decode and validate a stored JSON artifact.
		 * @param string $json Stored model artifact
		 * @return self Validated model
		 * @throws \UnexpectedValueException When the JSON is not an object of string keys
		 */
		public static function fromJson(string $json): self {
			$artifact = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
			
			if (!is_array($artifact)) {
				throw new \UnexpectedValueException('Click model artifact JSON must decode to an array.');
			}
			
			$fields = [];
			
			foreach ($artifact as $name => $value) {
				if (!is_string($name)) {
					throw new \UnexpectedValueException('Click model artifact keys must be strings, got key ' . var_export($name, true) . '.');
				}
				
				$fields[$name] = $value;
			}
			
			return new self($fields);
		}
		
		/**
		 * Return the fitted feature names in sorted order.
		 * @return array<int, string> Sorted fitted feature names
		 */
		public function featureNames(): array {
			$names = array_keys($this->coefficients);
			sort($names);
			return $names;
		}
		
		/**
		 * Return the estimated click probability for a display position.
		 * @param array<string, float> $features Serving-time source features
		 * @param int $position Actual or reference display position
		 * @return float Estimated click probability
		 */
		public function probability(array $features, int $position): float {
			$logOdds = $this->logOdds($features, $position);
			return $logOdds >= 0 ? 1 / (1 + exp(-$logOdds)) : exp($logOdds) / (1 + exp($logOdds));
		}
		
		/**
		 * Return the model log odds for a display position.
		 * @param array<string, float> $features Serving-time source features
		 * @param int $position Display position, at least 1
		 * @return float Model log odds
		 * @throws \InvalidArgumentException When the position is less than 1
		 */
		public function logOdds(array $features, int $position): float {
			if ($position < 1) {
				throw new \InvalidArgumentException("Display position must be positive, got {$position}.");
			}
			
			$features['log_position'] = log($position);
			$sum = $this->intercept;
			
			foreach ($this->coefficients as $name => $coefficient) {
				$value = $features[$name] ?? 0.0;
				$sum += $coefficient * ($value - $this->means[$name]) / $this->scales[$name];
			}
			
			return $sum;
		}
		
		/**
		 * Return each source's fitted log-odds contribution, summed over its features.
		 * @param array<string, float> $features Serving-time source features
		 * @return array<string, float> Source-level fitted log-odds contributions
		 */
		public function sourceContributions(array $features): array {
			$result = [];
			
			foreach ($this->coefficients as $name => $coefficient) {
				if ($name === 'log_position') {
					continue;
				}
				
				$source = explode('.', $name, 2)[0];
				$result[$source] = ($result[$source] ?? 0.0)
					+ $coefficient * (($features[$name] ?? 0.0) - $this->means[$name]) / $this->scales[$name];
			}
			
			return $result;
		}
	}
