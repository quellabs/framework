<?php

namespace Quellabs\Recommender\Sculpt;

use DateTimeImmutable;
use Quellabs\Recommender\Internal\Model\ClickModelTrainer;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Sculpt\ConfigurationManager;

/** Trains a candidate model for one exact scoring partition. */
class TrainClickModelCommand extends RecommenderCommand {
	
	/**
	 * Return the command signature.
	 * @return string Command signature
	 */
	public function getSignature(): string {
		return 'recommender:train-click-model';
	}
	
	/**
	 * Return the short command description.
	 * @return string Short command description
	 */
	public function getDescription(): string {
		return 'Train a click model from mature displayed-item impressions';
	}
	
	/**
	 * Return the usage help.
	 * @return string Usage help
	 */
	public function getHelp(): string {
		return 'Usage: sculpt recommender:train-click-model --category=N --placement=KEY --sources=list --from=UTC --to=UTC --as-of=UTC --click-window-seconds=N [--context=KEY]';
	}
	
	/**
	 * Train a candidate model for the requested partition and print its sample counts and metrics.
	 * @param ConfigurationManager $config Explicit cohort and partition options
	 * @return int Exit status
	 * @throws \InvalidArgumentException When an option is missing or invalid
	 * @throws \UnexpectedValueException When the stored artifact is malformed
	 * @throws \DateMalformedStringException
	 * @throws \JsonException
	 */
	public function execute(ConfigurationManager $config): int {
		$category = $config->get('category');
		$placement = $config->get('placement');
		$rawSources = $config->get('sources');
		$window = $config->get('click-window-seconds');
		
		if ((!is_int($category) && !is_string($category))
			|| (!is_int($window) && !is_string($window))
			|| !ctype_digit((string)$category) || !is_string($placement) || !is_string($rawSources)
			|| !ctype_digit((string)$window) || (int)$window < 1) {
			throw new \InvalidArgumentException('Category, placement, sources, and positive click window are required.');
		}
		
		$sources = $this->parseSources($rawSources);
		$context = $config->get('context');
		
		if ($context !== null && !is_string($context)) {
			throw new \InvalidArgumentException('Context must be a string.');
		}
		
		$connection = $this->getRecommenderProvider()->getConnection();
		$id = (new ClickModelTrainer($connection))->train((int)$category, $placement,
			$sources, self::parseTimestamp($config->get('from')), self::parseTimestamp($config->get('to')),
			self::parseTimestamp($config->get('as-of')), (int)$window, $context);
		$row = $connection->execute('
			SELECT
				artifact,
				status
			FROM vogoo_models
			WHERE id = UNHEX(:id)
		', [
			'id' => $id,
		])->fetchAssoc();
		
		$artifact = json_decode((string)$row['artifact'], true, 512, JSON_THROW_ON_ERROR);
		
		if (!is_array($artifact)) {
			throw new \UnexpectedValueException('Stored model artifact is invalid.');
		}
		
		$this->output->success("Created model candidate {$id} ({$row['status']}).");
		$this->printSampleCounts($artifact);
		$this->printMetrics($artifact);
		return 0;
	}
	
	/**
	 * Parse a comma-separated list of source values, rejecting unknown and repeated entries.
	 * @param string $rawSources Comma-separated source values
	 * @return array<int, RecommendationSource> Sources in the order given
	 * @throws \InvalidArgumentException When a value is unknown or repeated
	 */
	private function parseSources(string $rawSources): array {
		$sources = [];
		
		foreach (explode(',', $rawSources) as $value) {
			$source = RecommendationSource::tryFrom($value);
			
			if ($source === null) {
				throw new \InvalidArgumentException("Unknown source '{$value}'.");
			}
			
			if (in_array($source, $sources, true)) {
				throw new \InvalidArgumentException("Source '{$value}' is listed more than once.");
			}
			
			$sources[] = $source;
		}
		
		return $sources;
	}
	
	/**
	 * Parse an ISO-8601 timestamp that carries an explicit UTC offset.
	 * @param mixed $raw Raw option value
	 * @return DateTimeImmutable Parsed timestamp
	 * @throws \InvalidArgumentException When the value is not a valid timestamp with an offset
	 */
	private static function parseTimestamp(mixed $raw): DateTimeImmutable {
		if (!is_string($raw) || preg_match(
				'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
				$raw) !== 1) {
			throw new \InvalidArgumentException('Training timestamps require ISO-8601 with an explicit UTC offset.');
		}
		
		try {
			$time = new DateTimeImmutable($raw);
		} catch (\Exception $exception) {
			throw new \InvalidArgumentException("Invalid training timestamp '{$raw}'.", previous: $exception);
		}
		
		$errors = DateTimeImmutable::getLastErrors();
		
		if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
			throw new \InvalidArgumentException("Invalid training timestamp '{$raw}'.");
		}
		
		return $time;
	}
	
	/**
	 * Print the item and click counts of the training and holdout sets.
	 * @param array<mixed> $artifact Decoded model artifact
	 * @return void
	 * @throws \UnexpectedValueException When a count is missing or not numeric
	 */
	private function printSampleCounts(array $artifact): void {
		$counts = [];
		
		foreach (['training_items', 'training_clicks', 'holdout_items', 'holdout_clicks'] as $key) {
			if (!isset($artifact[$key]) || !is_numeric($artifact[$key])) {
				throw new \UnexpectedValueException("Stored model sample count '{$key}' is invalid.");
			}
			
			$counts[$key] = (int)$artifact[$key];
		}
		
		$this->output->writeLn('Training items: ' . $counts['training_items']
			. ', clicks: ' . $counts['training_clicks']
			. '; holdout items: ' . $counts['holdout_items']
			. ', clicks: ' . $counts['holdout_clicks']);
	}
	
	/**
	 * Print the baseline and model metrics and the model's calibration bins.
	 * @param array<mixed> $artifact Decoded model artifact
	 * @return void
	 * @throws \UnexpectedValueException When a metric or the calibration bins are missing or invalid
	 */
	private function printMetrics(array $artifact): void {
		foreach (['baseline_metrics' => 'Baseline', 'model_metrics' => 'Model'] as $key => $label) {
			$metrics = $artifact[$key];
			
			if (!is_array($metrics) || !isset($metrics['log_loss'], $metrics['brier'], $metrics['ece'])
				|| !is_numeric($metrics['log_loss']) || !is_numeric($metrics['brier'])
				|| !is_numeric($metrics['ece'])) {
				throw new \UnexpectedValueException("Stored {$label} metrics are invalid.");
			}
			
			$this->output->writeLn($label . ' log loss: ' . (float)$metrics['log_loss']
				. ', Brier: ' . (float)$metrics['brier'] . ', ECE: ' . (float)$metrics['ece']);
		}
		
		$modelMetrics = $artifact['model_metrics'];
		
		if (!is_array($modelMetrics) || !isset($modelMetrics['bins']) || !is_array($modelMetrics['bins'])) {
			throw new \UnexpectedValueException('Stored model calibration bins are invalid.');
		}
		
		$this->output->writeLn('Calibration bins: ' . json_encode($modelMetrics['bins'], JSON_THROW_ON_ERROR));
	}
}
