<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/** Descriptive reporting over opted-in displayed-item impressions. */
readonly class EvaluationReport {
    /** @param Connection $connection Evaluation database. */
    public function __construct(private Connection $connection) {}

    /** @param int $category Resolved category
     * @param DateTimeImmutable $start Inclusive display start
     * @param DateTimeImmutable $end Exclusive display end
     * @param DateTimeImmutable $asOf Included outcome cutoff
     * @param AttributionWindows $windows Caller-selected windows
     * @param RecommendationSource|null $source Optional descriptive source filter
     * @param string|null $contextKey Exact model partition
     * @return EvaluationSummary Displayed-item outcome counts and rates
     */
    public function summary(int $category, DateTimeImmutable $start, DateTimeImmutable $end,
        DateTimeImmutable $asOf, AttributionWindows $windows, ?RecommendationSource $source = null,
        ?string $contextKey = null): EvaluationSummary {
        EvaluationSchema::requireTables($this->connection);
        if ($category < 0 || $category > 4294967295 || $start >= $end || $asOf < $end) {
            throw new \InvalidArgumentException('Invalid category or report interval.');
        }
        if ($contextKey !== null) {
            ReconciliationRequest::validateKey($contextKey, 128, 'context');
        }
        $whereSource = $source === null ? '' : 'AND EXISTS (SELECT 1 FROM recommender_impression_evidence e
            WHERE e.impression_id = item.impression_id AND e.item_id = item.item_id AND e.source = :source)';
        $params = ['category' => $category, 'context' => $contextKey ?? '',
            'start' => self::utc($start), 'end' => self::utc($end), 'as_of' => self::utc($asOf),
            'click_window' => $windows->clickSeconds, 'purchase_window' => $windows->purchaseSeconds];
        if ($source !== null) {
            $params['source'] = $source->value;
        }
        $row = $this->connection->execute("SELECT COUNT(*) AS impressions,
            COALESCE(SUM(EXISTS (SELECT 1 FROM recommender_outcomes o
                WHERE o.impression_id = item.impression_id AND o.item_id = item.item_id
                AND o.event_type = 'click' AND o.occurred_at >= i.shown_at AND o.occurred_at <= :as_of
                AND o.occurred_at <= TIMESTAMPADD(SECOND, :click_window, i.shown_at))), 0) AS clicked,
            COALESCE(SUM(EXISTS (SELECT 1 FROM recommender_outcomes p
                WHERE p.impression_id = item.impression_id AND p.item_id = item.item_id
                AND p.event_type = 'purchase' AND p.occurred_at >= i.shown_at AND p.occurred_at <= :as_of2
                AND p.occurred_at <= TIMESTAMPADD(SECOND, :purchase_window, i.shown_at))), 0) AS purchased
            FROM recommender_impressions i JOIN recommender_impression_items item ON item.impression_id = i.id
            WHERE i.category = :category AND i.context_key = :context
            AND i.shown_at >= :start AND i.shown_at < :end {$whereSource}",
            $params + ['as_of2' => self::utc($asOf)])->fetchAssoc();
        return new EvaluationSummary((int)$row['impressions'], (int)$row['clicked'],
            (int)$row['purchased'], $asOf, $windows);
    }

    /**
     * Compare mature observed clicks with logged display-position estimates by model and placement.
     * @param DateTimeImmutable $start Inclusive display start
     * @param DateTimeImmutable $end Exclusive display end
     * @param DateTimeImmutable $asOf Outcome cutoff
     * @param int $clickWindowSeconds Positive attribution period
     * @return array<string, array<string, mixed>> Model and placement calibration summaries
     */
    public function calibrationByModel(DateTimeImmutable $start, DateTimeImmutable $end,
        DateTimeImmutable $asOf, int $clickWindowSeconds): array {
        EvaluationSchema::requireTables($this->connection);
        if ($start >= $end || $asOf < $end || $clickWindowSeconds < 1) {
            throw new \InvalidArgumentException('Invalid calibration interval or click window.');
        }
        $rows = $this->connection->execute('SELECT LOWER(HEX(item.model_id)) AS model_id,
            i.placement, item.display_click_probability AS probability,
            EXISTS (SELECT 1 FROM recommender_outcomes o WHERE o.impression_id = i.id
                AND o.item_id = item.item_id AND o.event_type = \'click\'
                AND o.occurred_at >= i.shown_at AND o.occurred_at <= :as_of
                AND o.occurred_at <= TIMESTAMPADD(SECOND, :window, i.shown_at)) AS clicked
            FROM recommender_impressions i JOIN recommender_impression_items item ON item.impression_id = i.id
            WHERE item.model_id IS NOT NULL AND item.display_click_probability IS NOT NULL
                AND i.shown_at >= :start AND i.shown_at < :end
                AND TIMESTAMPADD(SECOND, :mature_window, i.shown_at) <= :mature_as_of',
            ['as_of' => self::utc($asOf), 'window' => $clickWindowSeconds,
                'start' => self::utc($start), 'end' => self::utc($end),
                'mature_window' => $clickWindowSeconds, 'mature_as_of' => self::utc($asOf)])->fetchAll('assoc');
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['model_id'] . ':' . $row['placement'];
            $groups[$key][] = ['probability' => (float)$row['probability'], 'clicked' => (int)$row['clicked']];
        }
        $result = [];
        foreach ($groups as $key => $samples) {
            usort($samples, fn($a, $b) => $a['probability'] <=> $b['probability']);
            $count = count($samples);
            $clicks = array_sum(array_column($samples, 'clicked'));
            $meanProbability = array_sum(array_column($samples, 'probability')) / $count;
            $brier = array_sum(array_map(fn($row) => ($row['probability'] - $row['clicked']) ** 2, $samples)) / $count;
            $bins = [];
            for ($index = 0; $index < 10; $index++) {
                $slice = array_slice($samples, (int)floor($index * $count / 10),
                    (int)floor(($index + 1) * $count / 10) - (int)floor($index * $count / 10));
                if ($slice !== []) {
                    $bins[] = ['count' => count($slice),
                        'predicted' => array_sum(array_column($slice, 'probability')) / count($slice),
                        'observed' => array_sum(array_column($slice, 'clicked')) / count($slice)];
                }
            }
            [$modelId, $placement] = explode(':', $key, 2);
            $result[$key] = ['model_id' => $modelId, 'placement' => $placement,
                'impressions' => $count, 'observed_click_rate' => $clicks / $count,
                'mean_display_probability' => $meanProbability, 'brier' => $brier,
                'bins' => $bins, 'as_of' => $asOf, 'click_window_seconds' => $clickWindowSeconds];
        }
        return $result;
    }

    /** @param DateTimeImmutable $time Caller time
     * @return string UTC MySQL timestamp
     */
    private static function utc(DateTimeImmutable $time): string {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
