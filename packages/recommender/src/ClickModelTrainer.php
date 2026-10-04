<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/** Builds versioned model candidates from mature, opted-in impression snapshots. */
readonly class ClickModelTrainer {
    /** @param Connection $connection Evaluation database. */
    public function __construct(private Connection $connection) {}

    /** @param int $category Model category
     * @param string $placement Exact display surface
     * @param array<int, RecommendationSource> $sources Enabled source set
     * @param DateTimeImmutable $from Inclusive cohort start
     * @param DateTimeImmutable $to Exclusive cohort end
     * @param DateTimeImmutable $asOf Outcome cutoff
     * @param int $clickWindowSeconds Positive click attribution period
     * @param string|null $contextKey Exact caller partition
     * @return string Hex ID of an immutable candidate artifact
     */
    public function train(int $category, string $placement, array $sources, DateTimeImmutable $from,
        DateTimeImmutable $to, DateTimeImmutable $asOf, int $clickWindowSeconds,
        ?string $contextKey = null): string {
        EvaluationSchema::requireTables($this->connection);
        if ($category < 0 || $category > 4294967295 || $from >= $to || $to > $asOf
            || $clickWindowSeconds < 1 || $sources === []) {
            throw new \InvalidArgumentException('Invalid model training interval or key.');
        }
        ReconciliationRequest::validateKey($placement, 64, 'placement');
        if ($contextKey !== null) {
            ReconciliationRequest::validateKey($contextKey, 128, 'context');
        }
        $mask = 0;
        foreach ($sources as $source) {
            if (!$source instanceof RecommendationSource || ($mask & $source->bit()) !== 0) {
                throw new \InvalidArgumentException('Sources must be distinct enum values.');
            }
            $mask |= $source->bit();
        }
        $expectedFeatures = [];
        $expectedDepthSources = [];
        foreach ($sources as $source) {
            $expectedDepthSources[] = $source->value;
            foreach (['log_depth_searched', 'present', 'reciprocal_rank', 'score', 'count'] as $name) {
                $expectedFeatures[] = $source->value . '.' . $name;
            }
        }
        sort($expectedFeatures);
        sort($expectedDepthSources);
        $rows = $this->connection->execute('SELECT HEX(i.id) AS impression_id, i.shown_at,
            item.position, item.feature_snapshot,
            EXISTS (SELECT 1 FROM recommender_outcomes o WHERE o.impression_id = i.id
                AND o.item_id = item.item_id AND o.event_type = \'click\'
                AND o.occurred_at >= i.shown_at AND o.occurred_at <= :as_of
                AND o.occurred_at <= TIMESTAMPADD(SECOND, :click_window, i.shown_at)) AS clicked
            FROM recommender_impressions i JOIN recommender_impression_items item ON item.impression_id = i.id
            WHERE i.category = :category AND i.placement = :placement AND i.source_mask = :mask
                AND i.context_key = :context AND item.feature_schema_version = 1
                AND i.shown_at >= :from AND i.shown_at < :to
                AND TIMESTAMPADD(SECOND, :mature_window, i.shown_at) <= :mature_as_of
            ORDER BY i.shown_at ASC, i.id ASC, item.position ASC',
            ['category' => $category, 'placement' => $placement, 'mask' => $mask,
                'context' => $contextKey ?? '', 'from' => self::utc($from), 'to' => self::utc($to),
                'as_of' => self::utc($asOf), 'click_window' => $clickWindowSeconds,
                'mature_window' => $clickWindowSeconds, 'mature_as_of' => self::utc($asOf)])->fetchAll('assoc');
        $groups = [];
        $groupTimes = [];
        foreach ($rows as $row) {
            $snapshot = json_decode((string)$row['feature_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || !isset($snapshot['features'], $snapshot['depth_searched'])
                || !is_array($snapshot['features']) || !is_array($snapshot['depth_searched'])
                || $snapshot['features'] === []) {
                continue;
            }
            $decoded = $snapshot['features'];
            $features = [];
            foreach ($decoded as $name => $value) {
                if (!is_string($name) || !is_numeric($value) || !is_finite((float)$value)) {
                    throw new \UnexpectedValueException('Invalid recorded feature snapshot.');
                }
                $features[$name] = (float)$value;
            }
            $actualFeatures = array_keys($features);
            sort($actualFeatures);
            if ($actualFeatures !== $expectedFeatures) {
                throw new \UnexpectedValueException('Recorded feature snapshot does not match schema version 1.');
            }
            $depths = $snapshot['depth_searched'];
            $actualDepthSources = array_keys($depths);
            sort($actualDepthSources);
            if ($actualDepthSources !== $expectedDepthSources) {
                throw new \UnexpectedValueException('Recorded source depths do not match the enabled set.');
            }
            foreach ($depths as $source => $depth) {
                if (!is_string($source) || !is_int($depth) || $depth < 50
                    || abs(log($depth) - $features[$source . '.log_depth_searched']) > 1e-9) {
                    throw new \UnexpectedValueException('Recorded source depth does not match its feature.');
                }
            }
            $features['log_position'] = log((int)$row['position']);
            $impressionId = (string)$row['impression_id'];
            $groups[$impressionId][] = ['features' => $features, 'label' => (int)$row['clicked']];
            $groupTimes[$impressionId] = (string)$row['shown_at'];
        }
        $groupIds = array_keys($groups);
        $split = (int)floor(count($groupIds) * 0.8);
        $training = [];
        $holdout = [];
        foreach (array_values($groups) as $index => $items) {
            foreach ($items as $item) {
                if ($index < $split) {
                    $training[] = $item;
                } else {
                    $holdout[] = $item;
                }
            }
        }
        $trainingClicks = array_sum(array_column($training, 'label'));
        $holdoutClicks = array_sum(array_column($holdout, 'label'));
        if (count($training) < 1000 || $trainingClicks < 100
            || $holdoutClicks < 20 || count($holdout) - $holdoutClicks < 20) {
            throw new \RuntimeException('Not enough mature displayed items and clicks to train a model.');
        }
        $artifact = (new ClickModelFitter())->fit($training, $holdout);
        $artifact['objective'] = 'click';
        $artifact['training_interval'] = [
            'first_impression_id' => strtolower($groupIds[0]),
            'first_shown_at' => $groupTimes[$groupIds[0]],
            'last_impression_id' => strtolower($groupIds[$split - 1]),
            'last_shown_at' => $groupTimes[$groupIds[$split - 1]],
        ];
        $artifact['holdout_interval'] = [
            'first_impression_id' => strtolower($groupIds[$split]),
            'first_shown_at' => $groupTimes[$groupIds[$split]],
            'last_impression_id' => strtolower($groupIds[count($groupIds) - 1]),
            'last_shown_at' => $groupTimes[$groupIds[count($groupIds) - 1]],
        ];
        $artifact['click_window_seconds'] = $clickWindowSeconds;
        $artifact['from'] = self::utc($from);
        $artifact['to'] = self::utc($to);
        $artifact['as_of'] = self::utc($asOf);
        $artifact['training_clicks'] = $trainingClicks;
        $artifact['holdout_clicks'] = $holdoutClicks;
        $id = bin2hex(random_bytes(16));
        $this->connection->execute('INSERT INTO recommender_models
            (id, objective, category, placement, source_mask, context_key, feature_schema_version,
                artifact, trained_at, activated_at, status)
            VALUES (UNHEX(?), \'click\', ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP(6), NULL, ?)',
            [$id, $category, $placement, $mask, $contextKey ?? '',
                json_encode($artifact, JSON_THROW_ON_ERROR), $artifact['validated'] ? 'validated' : 'rejected']);
        return $id;
    }

    /** @param string $modelId Candidate hex token
     * @return void Activates a validated candidate atomically
     */
    public function activate(string $modelId): void {
        EvaluationSchema::requireTables($this->connection);
        if (preg_match('/^[0-9a-f]{32}$/D', $modelId) !== 1) {
            throw new \InvalidArgumentException('Invalid model ID.');
        }
        $this->connection->transactional(function () use ($modelId): void {
            $row = $this->connection->execute('SELECT objective, category, placement, source_mask,
                context_key, artifact, status FROM recommender_models WHERE id = UNHEX(?) FOR UPDATE',
                [$modelId])->fetchAssoc();
            if (!$row || $row['status'] !== 'validated') {
                throw new \InvalidArgumentException('Model is missing or has not passed validation.');
            }
            $artifact = json_decode((string)$row['artifact'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($artifact) || ($artifact['validated'] ?? false) !== true) {
                throw new \InvalidArgumentException('Model artifact failed validation.');
            }
            $this->connection->execute('UPDATE recommender_models SET status = \'retired\'
                WHERE objective = ? AND category = ? AND placement = ? AND source_mask = ?
                AND context_key = ? AND status = \'active\'',
                [$row['objective'], $row['category'], $row['placement'], $row['source_mask'], $row['context_key']]);
            $this->connection->execute('UPDATE recommender_models SET status = \'active\',
                activated_at = UTC_TIMESTAMP(6) WHERE id = UNHEX(?)', [$modelId]);
        });
    }

    /** @param DateTimeImmutable $time Caller time
     * @return string UTC timestamp
     */
    private static function utc(DateTimeImmutable $time): string {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
