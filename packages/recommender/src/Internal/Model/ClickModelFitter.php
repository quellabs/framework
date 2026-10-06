<?php
	
	namespace Quellabs\Recommender\Internal\Model;
	
	/**
	 * Deterministic full-batch logistic fitting and chronological holdout checks.
	 *
	 * @phpstan-type LabeledSample array{features: array<string, float>, label: int}
	 * @phpstan-type ScoredSample array{probability: float, label: int}
	 */
	final class ClickModelFitter {
		
		/**
		 * Fit the model on the training items and evaluate it on the holdout items.
		 * @param array<int, LabeledSample> $training Mature training items
		 * @param array<int, LabeledSample> $holdout Later mature items
		 * @return array<string, mixed> Versioned model artifact and validation metrics
		 * @throws \InvalidArgumentException When the training or holdout set is empty
		 * @throws \RuntimeException When fitting does not converge or reaches its iteration limit
		 */
		public function fit(array $training, array $holdout): array {
			if ($training === [] || $holdout === []) {
				throw new \InvalidArgumentException('Training and holdout must be nonempty.');
			}
			
			$names = array_keys($training[0]['features']);
			sort($names);
			[$means, $scales] = $this->standardization($training, $names);
			
			$trainingRate = array_sum(array_column($training, 'label')) / count($training);
			$rate = min(1 - 1e-9, max(1e-9, $trainingRate));
			$intercept = log($rate / (1 - $rate));
			$coefficients = array_fill_keys($names, 0.0);
			
			$fitted = $this->optimize($training, $names, $intercept, $coefficients, $means, $scales);
			$holdoutReport = $this->evaluateHoldout($holdout, $fitted['intercept'], $fitted['coefficients'], $means, $scales, $rate);
			
			return [
				'feature_schema_version' => 1,
				'intercept' => $fitted['intercept'],
				'coefficients' => $fitted['coefficients'],
				'means' => $means,
				'scales' => $scales,
				'training_rate' => $trainingRate,
				'training_items' => count($training),
				'holdout_items' => count($holdout),
				'model_metrics' => $holdoutReport['model_metrics'],
				'baseline_metrics' => $holdoutReport['baseline_metrics'],
				'validated' => $holdoutReport['validated'],
			];
		}
		
		/**
		 * Compute the mean and standard deviation of each feature over the training items.
		 * @param array<int, LabeledSample> $training Training items
		 * @param array<int, string> $names Sorted feature names
		 * @return array{0: array<string, float>, 1: array<string, float>} Means and scales per feature
		 */
		private function standardization(array $training, array $names): array {
			$means = [];
			$scales = [];
			
			foreach ($names as $name) {
				$values = array_map(fn($sample) => (float)($sample['features'][$name] ?? 0.0), $training);
				$mean = array_sum($values) / count($values);
				$variance = array_sum(array_map(fn($value) => ($value - $mean) ** 2, $values)) / count($values);
				$means[$name] = $mean;
				$scales[$name] = $variance > 0 ? sqrt($variance) : 1.0;
			}
			
			return [$means, $scales];
		}
		
		/**
		 * Run full-batch gradient descent until the loss stops improving.
		 * @param array<int, LabeledSample> $training Training items
		 * @param array<int, string> $names Sorted feature names
		 * @param float $intercept Starting intercept
		 * @param array<string, float> $coefficients Starting coefficients
		 * @param array<string, float> $means Feature means
		 * @param array<string, float> $scales Feature scales
		 * @return array{intercept: float, coefficients: array<string, float>} Fitted parameters
		 * @throws \RuntimeException When the loss increases or the iteration limit is reached
		 */
		private function optimize(array $training, array $names, float $intercept, array $coefficients, array $means, array $scales): array {
			$loss = $this->loss($training, $intercept, $coefficients, $means, $scales);
			$smallSteps = 0;
			
			for ($iteration = 0; $iteration < 1000; $iteration++) {
				[$interceptGradient, $gradient] = $this->gradient($training, $names, $intercept, $coefficients, $means, $scales);
				$candidate = $this->lineSearch($training, $names, $loss, $intercept, $coefficients,
					$interceptGradient, $gradient, $means, $scales);
					
				if ($candidate['loss'] > $loss || !is_finite($candidate['loss'])) {
					throw new \RuntimeException('Click model fitting did not converge.');
				}
				
				$improvement = ($loss - $candidate['loss']) / max(1.0, abs($loss));
				$intercept = $candidate['intercept'];
				$coefficients = $candidate['coefficients'];
				$loss = $candidate['loss'];
				$smallSteps = $improvement < 1e-6 ? $smallSteps + 1 : 0;
				
				if ($smallSteps >= 5) {
					return ['intercept' => $intercept, 'coefficients' => $coefficients];
				}
			}
			
			throw new \RuntimeException('Click model fitting reached its iteration limit.');
		}
		
		/**
		 * Compute the gradient of the regularized loss at the current parameters.
		 * @param array<int, LabeledSample> $training Training items
		 * @param array<int, string> $names Sorted feature names
		 * @param float $intercept Current intercept
		 * @param array<string, float> $coefficients Current coefficients
		 * @param array<string, float> $means Feature means
		 * @param array<string, float> $scales Feature scales
		 * @return array{0: float, 1: array<string, float>} Intercept gradient and coefficient gradients
		 */
		private function gradient(array $training, array $names, float $intercept, array $coefficients, array $means, array $scales): array {
			$gradient = array_fill_keys($names, 0.0);
			$interceptGradient = 0.0;
			
			foreach ($training as $sample) {
				$probability = $this->probability($sample['features'], $intercept, $coefficients, $means, $scales);
				$error = $probability - $sample['label'];
				$interceptGradient += $error;
				
				foreach ($names as $name) {
					$gradient[$name] += $error * (($sample['features'][$name] ?? 0.0) - $means[$name]) / $scales[$name];
				}
			}
			
			$interceptGradient /= count($training);
			
			foreach ($names as $name) {
				$gradient[$name] = $gradient[$name] / count($training) + 0.001 * $coefficients[$name];
			}
			
			return [$interceptGradient, $gradient];
		}
		
		/**
		 * Halve the step size until the loss does not increase, or the step becomes negligible.
		 * @param array<int, LabeledSample> $training Training items
		 * @param array<int, string> $names Sorted feature names
		 * @param float $loss Loss at the current parameters
		 * @param float $intercept Current intercept
		 * @param array<string, float> $coefficients Current coefficients
		 * @param float $interceptGradient Gradient of the intercept
		 * @param array<string, float> $gradient Gradient of each coefficient
		 * @param array<string, float> $means Feature means
		 * @param array<string, float> $scales Feature scales
		 * @return array{intercept: float, coefficients: array<string, float>, loss: float} Accepted or last-tried parameters
		 */
		private function lineSearch(array $training, array $names, float $loss, float $intercept, array $coefficients, float $interceptGradient, array $gradient, array $means, array $scales): array {
			$step = 1.0;
			
			do {
				$candidateIntercept = $intercept - $step * $interceptGradient;
				$candidateCoefficients = [];
				
				foreach ($names as $name) {
					$candidateCoefficients[$name] = $coefficients[$name] - $step * $gradient[$name];
				}
				
				$candidateLoss = $this->loss($training, $candidateIntercept, $candidateCoefficients, $means, $scales);
				
				if ($candidateLoss <= $loss || $step < 1e-12) {
					return [
						'intercept' => $candidateIntercept,
						'coefficients' => $candidateCoefficients,
						'loss' => $candidateLoss,
					];
				}
				
				$step /= 2;
			} while (true);
		}
		
		/**
		 * Score the holdout items with the fitted model and with the training click rate as a baseline.
		 * @param array<int, LabeledSample> $holdout Holdout items
		 * @param float $intercept Fitted intercept
		 * @param array<string, float> $coefficients Fitted coefficients
		 * @param array<string, float> $means Feature means
		 * @param array<string, float> $scales Feature scales
		 * @param float $rate Training click rate used as the baseline probability
		 * @return array{model_metrics: array<string, mixed>, baseline_metrics: array<string, mixed>, validated: bool}
		 */
		private function evaluateHoldout(array $holdout, float $intercept, array $coefficients, array $means, array $scales, float $rate): array {
			$predictions = [];
			
			foreach ($holdout as $sample) {
				$predictions[] = ['probability' => $this->probability($sample['features'], $intercept,
					$coefficients, $means, $scales), 'label' => $sample['label']];
			}
			
			$modelMetrics = $this->metrics($predictions);
			$baselineMetrics = $this->metrics(array_map(fn($sample) => ['probability' => $rate, 'label' => $sample['label']], $holdout));
			$validated = $modelMetrics['log_loss'] <= $baselineMetrics['log_loss'] &&
				$modelMetrics['brier'] <= $baselineMetrics['brier'] &&
				$modelMetrics['ece'] <= 0.05;
				
			return [
				'model_metrics' => $modelMetrics,
				'baseline_metrics' => $baselineMetrics,
				'validated' => $validated,
			];
		}
		
		/**
		 * Compute the regularized mean log loss of the training items.
		 * @param array<int, LabeledSample> $samples Training items
		 * @param float $intercept Current intercept
		 * @param array<string, float> $coefficients Current coefficients
		 * @param array<string, float> $means Feature means
		 * @param array<string, float> $scales Feature scales
		 * @return float Regularized mean log loss
		 */
		private function loss(array $samples, float $intercept, array $coefficients, array $means, array $scales): float {
			$sum = 0.0;
			
			foreach ($samples as $sample) {
				$p = self::clampProbability(
					$this->probability($sample['features'], $intercept, $coefficients, $means, $scales));
				$sum -= $sample['label'] ? log($p) : log(1 - $p);
			}
			
			return $sum / count($samples) + 0.0005 * array_sum(array_map(fn($value) => $value ** 2, $coefficients));
		}
		
		/**
		 * Compute the sigmoid probability of one item.
		 * @param array<string, float> $features Input values
		 * @param float $intercept Fitted intercept
		 * @param array<string, float> $coefficients Fitted coefficients
		 * @param array<string, float> $means Feature means
		 * @param array<string, float> $scales Feature scales
		 * @return float Sigmoid probability
		 */
		private function probability(array $features, float $intercept, array $coefficients, array $means, array $scales): float {
			$z = $intercept;
			
			foreach ($coefficients as $name => $coefficient) {
				$z += $coefficient * (($features[$name] ?? 0.0) - $means[$name]) / $scales[$name];
			}
			
			return $z >= 0 ? 1 / (1 + exp(-$z)) : exp($z) / (1 + exp($z));
		}
		
		/**
		 * Compute log loss, Brier score, expected calibration error, and calibration bins.
		 * @param array<int, ScoredSample> $predictions Holdout scores
		 * @return array<string, mixed> Log loss, Brier score, ECE, and calibration bins
		 */
		private function metrics(array $predictions): array {
			$logLoss = 0.0;
			$brier = 0.0;
			
			foreach ($predictions as $row) {
				$p = self::clampProbability($row['probability']);
				$logLoss -= $row['label'] ? log($p) : log(1 - $p);
				$brier += ($p - $row['label']) ** 2;
			}
			
			usort($predictions, fn($a, $b) => $a['probability'] <=> $b['probability']);
			$bins = [];
			$ece = 0.0;
			
			for ($index = 0; $index < 10; $index++) {
				$binStart = (int)floor($index * count($predictions) / 10);
				$slice = array_slice($predictions, $binStart, (int)floor(($index + 1) * count($predictions) / 10) - $binStart);
				
				if ($slice === []) {
					continue;
				}
				
				$predicted = array_sum(array_column($slice, 'probability')) / count($slice);
				$observed = array_sum(array_column($slice, 'label')) / count($slice);
				$bins[] = [
					'count' => count($slice),
					'predicted' => $predicted,
					'observed' => $observed,
				];
				$ece += count($slice) / count($predictions) * abs($predicted - $observed);
			}
			
			return [
				'log_loss' => $logLoss / count($predictions),
				'brier' => $brier / count($predictions),
				'ece' => $ece,
				'bins' => $bins,
			];
		}
	
		/**
		 * Clamp a probability away from 0 and 1 so log loss stays finite.
		 * @param float $probability Predicted probability
		 * @return float Clamped probability
		 */
		private static function clampProbability(float $probability): float {
			return min(1 - 1e-15, max(1e-15, $probability));
		}
	}
