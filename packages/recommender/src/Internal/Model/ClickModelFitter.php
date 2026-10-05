<?php
	
	namespace Quellabs\Recommender\Internal\Model;
	
	/** Deterministic full-batch logistic fitting and chronological holdout checks. */
	final class ClickModelFitter {
		/** @param array<int, array{features:array<string,float>,label:int}> $training Mature training items
		 * @param array<int, array{features:array<string,float>,label:int}> $holdout Later mature items
		 * @return array<string, mixed> Versioned model artifact and validation metrics
		 */
		public function fit(array $training, array $holdout): array {
			if ($training === [] || $holdout === []) {
				throw new \InvalidArgumentException('Training and holdout must be nonempty.');
			}
			$names = array_keys($training[0]['features']);
			sort($names);
			$means = [];
			$scales = [];
			foreach ($names as $name) {
				$values = array_map(fn($sample) => (float)($sample['features'][$name] ?? 0.0), $training);
				$mean = array_sum($values) / count($values);
				$variance = array_sum(array_map(fn($value) => ($value - $mean) ** 2, $values)) / count($values);
				$means[$name] = $mean;
				$scales[$name] = $variance > 0 ? sqrt($variance) : 1.0;
			}
			$trainingRate = array_sum(array_column($training, 'label')) / count($training);
			$rate = min(1 - 1e-9, max(1e-9, $trainingRate));
			$intercept = log($rate / (1 - $rate));
			$coefficients = array_fill_keys($names, 0.0);
			$loss = $this->loss($training, $intercept, $coefficients, $means, $scales);
			$smallSteps = 0;
			$converged = false;
			for ($iteration = 0; $iteration < 1000; $iteration++) {
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
				$step = 1.0;
				do {
					$candidateIntercept = $intercept - $step * $interceptGradient;
					$candidateCoefficients = [];
					foreach ($names as $name) {
						$candidateCoefficients[$name] = $coefficients[$name] - $step * $gradient[$name];
					}
					$candidateLoss = $this->loss($training, $candidateIntercept, $candidateCoefficients, $means, $scales);
					if ($candidateLoss <= $loss || $step < 1e-12) {
						break;
					}
					$step /= 2;
				} while (true);
				$improvement = ($loss - $candidateLoss) / max(1.0, abs($loss));
				if ($candidateLoss > $loss || !is_finite($candidateLoss)) {
					throw new \RuntimeException('Click model fitting did not converge.');
				}
				$intercept = $candidateIntercept;
				$coefficients = $candidateCoefficients;
				$loss = $candidateLoss;
				$smallSteps = $improvement < 1e-6 ? $smallSteps + 1 : 0;
				if ($smallSteps >= 5) {
					$converged = true;
					break;
				}
			}
			if (!$converged) {
				throw new \RuntimeException('Click model fitting reached its iteration limit.');
			}
			$predictions = [];
			foreach ($holdout as $sample) {
				$predictions[] = ['probability'              => $this->probability($sample['features'], $intercept,
					$coefficients, $means, $scales), 'label' => $sample['label']];
			}
			$modelMetrics = $this->metrics($predictions);
			$baselineMetrics = $this->metrics(array_map(fn($sample) => ['probability' => $rate, 'label' => $sample['label']], $holdout));
			$validated = $modelMetrics['log_loss'] <= $baselineMetrics['log_loss']
				&& $modelMetrics['brier'] <= $baselineMetrics['brier'] && $modelMetrics['ece'] <= 0.05;
			return ['feature_schema_version' => 1, 'intercept' => $intercept,
			        'coefficients'           => $coefficients, 'means' => $means, 'scales' => $scales,
			        'training_rate'          => $trainingRate, 'training_items' => count($training),
			        'holdout_items'          => count($holdout), 'model_metrics' => $modelMetrics,
			        'baseline_metrics'       => $baselineMetrics, 'validated' => $validated];
		}
		
		/** @param array<int, array{features:array<string,float>,label:int}> $samples Training items
		 * @param float $intercept Current intercept
		 * @param array<string,float> $coefficients Current coefficients
		 * @param array<string,float> $means Feature means
		 * @param array<string,float> $scales Feature standard deviations
		 * @return float Regularized mean log loss
		 */
		private function loss(array $samples, float $intercept, array $coefficients, array $means, array $scales): float {
			$sum = 0.0;
			foreach ($samples as $sample) {
				$p = min(1 - 1e-15, max(1e-15,
					$this->probability($sample['features'], $intercept, $coefficients, $means, $scales)));
				$sum -= $sample['label'] ? log($p) : log(1 - $p);
			}
			return $sum / count($samples) + 0.0005 * array_sum(array_map(fn($value) => $value ** 2, $coefficients));
		}
		
		/** @param array<string,float> $features Input values
		 * @param float $intercept Fitted intercept
		 * @param array<string,float> $coefficients Fitted coefficients
		 * @param array<string,float> $means Feature means
		 * @param array<string,float> $scales Feature scales
		 * @return float Sigmoid probability
		 */
		private function probability(array $features, float $intercept, array $coefficients,
			array $means, array $scales): float {
			$z = $intercept;
			foreach ($coefficients as $name => $coefficient) {
				$z += $coefficient * (($features[$name] ?? 0.0) - $means[$name]) / $scales[$name];
			}
			return $z >= 0 ? 1 / (1 + exp(-$z)) : exp($z) / (1 + exp($z));
		}
		
		/** @param array<int, array{probability:float,label:int}> $predictions Holdout scores
		 * @return array<string,mixed> Log loss, Brier score, ECE, and calibration bins
		 */
		private function metrics(array $predictions): array {
			$logLoss = 0.0;
			$brier = 0.0;
			foreach ($predictions as $row) {
				$p = min(1 - 1e-15, max(1e-15, $row['probability']));
				$logLoss -= $row['label'] ? log($p) : log(1 - $p);
				$brier += ($p - $row['label']) ** 2;
			}
			usort($predictions, fn($a, $b) => $a['probability'] <=> $b['probability']);
			$bins = [];
			$ece = 0.0;
			for ($index = 0; $index < 10; $index++) {
				$slice = array_slice($predictions, (int)floor($index * count($predictions) / 10),
					(int)floor(($index + 1) * count($predictions) / 10) - (int)floor($index * count($predictions) / 10));
				if ($slice === []) {
					continue;
				}
				$predicted = array_sum(array_column($slice, 'probability')) / count($slice);
				$observed = array_sum(array_column($slice, 'label')) / count($slice);
				$bins[] = ['count' => count($slice), 'predicted' => $predicted, 'observed' => $observed];
				$ece += count($slice) / count($predictions) * abs($predicted - $observed);
			}
			return ['log_loss' => $logLoss / count($predictions), 'brier' => $brier / count($predictions),
			        'ece'      => $ece, 'bins' => $bins];
		}
	}
