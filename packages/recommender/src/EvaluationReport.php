<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use DateTimeImmutable;
use Quellabs\Recommender\Internal\Identifier;
use Quellabs\Recommender\Internal\Persistence\EvaluationSchema;
use Quellabs\Recommender\Internal\Persistence\MysqlTimestamp;
use Quellabs\Recommender\Internal\Query\OutcomeSubquery;

/** Descriptive reporting over opted-in displayed-item impressions. */
readonly class EvaluationReport {
	
	/** @var Connection Evaluation database connection */
	private Connection $connection;
	
	/**
	 * Build a report over the evaluation database.
	 * @param Connection $connection Evaluation database
	 */
	public function __construct(Connection $connection) {
		$this->connection = $connection;
	}
	
	/**
	 * Return displayed-item outcome counts and rates for a display interval.
	 * @param int $category Resolved category
	 * @param DateTimeImmutable $start Inclusive display start
	 * @param DateTimeImmutable $end Exclusive display end
	 * @param DateTimeImmutable $asOf Included outcome cutoff
	 * @param AttributionWindows $windows Caller-selected windows
	 * @param RecommendationSource|null $source Optional descriptive source filter
	 * @param string|null $contextKey Exact model partition
	 * @return EvaluationSummary Displayed-item outcome counts and rates
	 * @throws \InvalidArgumentException When the category, interval, or cutoff is invalid
	 */
	public function summary(
		int                 $category,
		DateTimeImmutable   $start,
		DateTimeImmutable   $end,
		DateTimeImmutable   $asOf,
		AttributionWindows  $windows,
		?RecommendationSource $source = null,
		?string             $contextKey = null
	): EvaluationSummary {
		EvaluationSchema::requireTables($this->connection);
		$this->assertSummaryArguments($category, $start, $end, $asOf, $contextKey);

		$params = [
			'category'        => $category,
			'context'         => $contextKey ?? '',
			'start'           => MysqlTimestamp::utc($start),
			'end'             => MysqlTimestamp::utc($end),
			'as_of'           => MysqlTimestamp::utc($asOf),
			'as_of2'          => MysqlTimestamp::utc($asOf),
			'click_window'    => $windows->clickSeconds,
			'purchase_window' => $windows->purchaseSeconds,
		];

		if ($source !== null) {
			$params['source'] = $source->value;
		}

		$row = $this->connection->execute($this->summarySql($source), $params)->fetchAssoc();

		return new EvaluationSummary((int)$row['impressions'], (int)$row['clicked'],
			(int)$row['purchased'], $asOf, $windows);
	}

	/**
	 * Validate the arguments of a summary query.
	 * @param int $category Resolved category
	 * @param DateTimeImmutable $start Inclusive display start
	 * @param DateTimeImmutable $end Exclusive display end
	 * @param DateTimeImmutable $asOf Included outcome cutoff
	 * @param string|null $contextKey Exact model partition
	 * @return void
	 * @throws \InvalidArgumentException When the category, interval, cutoff, or context key is invalid
	 */
	private function assertSummaryArguments(int $category, DateTimeImmutable $start, DateTimeImmutable $end,
		DateTimeImmutable $asOf, ?string $contextKey): void {
		if ($category < 0 || $category > Identifier::MAX) {
			throw new \InvalidArgumentException("Category must be an unsigned 32-bit integer, got {$category}.");
		}

		if ($start >= $end) {
			throw new \InvalidArgumentException('Report start ' . $start->format(DATE_ATOM) . ' must be before its end ' . $end->format(DATE_ATOM) . '.');
		}

		if ($asOf < $end) {
			throw new \InvalidArgumentException('Outcome cutoff ' . $asOf->format(DATE_ATOM) . ' must not precede the report end ' . $end->format(DATE_ATOM) . '.');
		}

		if ($contextKey !== null) {
			ReconciliationRequest::validateKey($contextKey, 128, 'context');
		}
	}

	/**
	 * Build the summary query, optionally restricted to impressions carrying evidence from one source.
	 * @param RecommendationSource|null $source Optional descriptive source filter
	 * @return string SQL with named parameters
	 */
	private function summarySql(?RecommendationSource $source): string {
		$clicked = OutcomeSubquery::exists('click', 'as_of', 'click_window');
		$purchased = OutcomeSubquery::exists('purchase', 'as_of2', 'purchase_window');
		$whereSource = $source === null ? '' : 'AND EXISTS (SELECT 1 FROM vogoo_impression_evidence e
            WHERE e.impression_id = item.impression_id AND e.item_id = item.item_id AND e.source = :source)';

		return "SELECT COUNT(*) AS impressions,
            COALESCE(SUM({$clicked}), 0) AS clicked,
            COALESCE(SUM({$purchased}), 0) AS purchased
            FROM vogoo_impressions i JOIN vogoo_impression_items item ON item.impression_id = i.id
            WHERE i.category = :category AND i.context_key = :context
            AND i.shown_at >= :start AND i.shown_at < :end {$whereSource}";
	}
	
	/**
	 * Compare mature observed clicks with logged display-position estimates by model and placement.
	 * @param DateTimeImmutable $start Inclusive display start
	 * @param DateTimeImmutable $end Exclusive display end
	 * @param DateTimeImmutable $asOf Outcome cutoff
	 * @param int $clickWindowSeconds Positive attribution period
	 * @return array<string, array<string, mixed>> Model and placement calibration summaries
	 * @throws \InvalidArgumentException When the interval or click window is invalid
	 */
	public function calibrationByModel(DateTimeImmutable $start, DateTimeImmutable $end,
		DateTimeImmutable $asOf, int $clickWindowSeconds): array {
		EvaluationSchema::requireTables($this->connection);
		
		if ($start >= $end || $asOf < $end) {
			throw new \InvalidArgumentException('Calibration interval must be ordered start < end <= cutoff.');
		}
		
		if ($clickWindowSeconds < 1) {
			throw new \InvalidArgumentException("Click window must be positive, got {$clickWindowSeconds}.");
		}
		
		$rows = $this->fetchCalibrationRows($start, $end, $asOf, $clickWindowSeconds);
		$result = [];
		
		foreach (self::groupCalibrationSamples($rows) as $key => $samples) {
			$result[$key] = self::summarizeCalibrationGroup($key, $samples, $asOf, $clickWindowSeconds);
		}
		
		return $result;
	}
	
	/**
	 * Fetch one row per displayed item that has a model probability and a mature click window.
	 * @param DateTimeImmutable $start Inclusive display start
	 * @param DateTimeImmutable $end Exclusive display end
	 * @param DateTimeImmutable $asOf Outcome cutoff
	 * @param int $clickWindowSeconds Positive attribution period
	 * @return array<int, array{model_id: string, placement: string, probability: float|string, clicked: int|string}> Calibration rows
	 */
	private function fetchCalibrationRows(DateTimeImmutable $start, DateTimeImmutable $end,
		DateTimeImmutable $asOf, int $clickWindowSeconds): array {
		$clicked = OutcomeSubquery::exists('click', 'as_of', 'window');

		return $this->connection->execute("SELECT LOWER(HEX(item.model_id)) AS model_id,
            i.placement, item.display_click_probability AS probability,
            {$clicked} AS clicked
            FROM vogoo_impressions i JOIN vogoo_impression_items item ON item.impression_id = i.id
            WHERE item.model_id IS NOT NULL AND item.display_click_probability IS NOT NULL
                AND i.shown_at >= :start AND i.shown_at < :end
                AND TIMESTAMPADD(SECOND, :mature_window, i.shown_at) <= :mature_as_of",
			['as_of'         => MysqlTimestamp::utc($asOf), 'window' => $clickWindowSeconds,
			 'start'         => MysqlTimestamp::utc($start), 'end' => MysqlTimestamp::utc($end),
			 'mature_window' => $clickWindowSeconds, 'mature_as_of' => MysqlTimestamp::utc($asOf)])->fetchAll('assoc');
	}
	
	/**
	 * Group calibration rows into samples keyed by "model_id:placement".
	 * @param array<int, array{model_id: string, placement: string, probability: float|string, clicked: int|string}> $rows Rows from fetchCalibrationRows()
	 * @return array<string, list<array{probability: float, clicked: int}>> Samples per model and placement
	 */
	private static function groupCalibrationSamples(array $rows): array {
		$groups = [];
		
		foreach ($rows as $row) {
			$key = $row['model_id'] . ':' . $row['placement'];
			$groups[$key][] = ['probability' => (float)$row['probability'], 'clicked' => (int)$row['clicked']];
		}
		
		return $groups;
	}
	
	/**
	 * Summarize one model and placement group: observed click rate, Brier score, and decile bins.
	 * @param string $key Group key in the form "model_id:placement"
	 * @param list<array{probability: float, clicked: int}> $samples Samples in the group
	 * @param DateTimeImmutable $asOf Outcome cutoff
	 * @param int $clickWindowSeconds Positive attribution period
	 * @return array<string, mixed> Calibration summary for the group
	 */
	private static function summarizeCalibrationGroup(string $key, array $samples, DateTimeImmutable $asOf,
		int $clickWindowSeconds): array {
		usort($samples, fn($a, $b) => $a['probability'] <=> $b['probability']);
		$count = count($samples);
		$clicks = array_sum(array_column($samples, 'clicked'));
		$meanProbability = array_sum(array_column($samples, 'probability')) / $count;
		$brier = array_sum(array_map(fn($row) => ($row['probability'] - $row['clicked']) ** 2, $samples)) / $count;
		[$modelId, $placement] = explode(':', $key, 2);
		
		return ['model_id'                 => $modelId, 'placement' => $placement,
		        'impressions'              => $count, 'observed_click_rate' => $clicks / $count,
		        'mean_display_probability' => $meanProbability, 'brier' => $brier,
		        'bins'                     => self::calibrationBins($samples, $count), 'as_of' => $asOf,
		        'click_window_seconds'     => $clickWindowSeconds];
	}
	
	/**
	 * Split sorted samples into up to ten bins of near-equal size.
	 * @param list<array{probability: float, clicked: int}> $samples Samples sorted by probability
	 * @param int $count Number of samples
	 * @return list<array{count: int, predicted: float, observed: float}> Non-empty bins
	 */
	private static function calibrationBins(array $samples, int $count): array {
		$bins = [];
		
		for ($index = 0; $index < 10; $index++) {
			$start = (int)floor($index * $count / 10);
			$slice = array_slice($samples, $start, (int)floor(($index + 1) * $count / 10) - $start);
			
			if ($slice === []) {
				continue;
			}
			
			$bins[] = ['count'     => count($slice),
			           'predicted' => array_sum(array_column($slice, 'probability')) / count($slice),
			           'observed'  => array_sum(array_column($slice, 'clicked')) / count($slice)];
		}
		
		return $bins;
	}
	}