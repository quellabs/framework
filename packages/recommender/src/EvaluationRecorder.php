<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use DateTimeImmutable;
use DateTimeZone;

/** Explicit, transactional recording of displayed recommendations and outcomes. */
readonly class EvaluationRecorder {
    /** @param Connection $connection Evaluation database. */
    public function __construct(private Connection $connection) {}

    /** @param RecommendationList $shown Actual displayed order
     * @param int|null $memberId Optional persisted member
     * @param DateTimeImmutable|null $shownAt Actual display time
     * @return ImpressionId New opaque impression token
     */
    public function recordImpression(RecommendationList $shown, ?int $memberId = null,
        ?DateTimeImmutable $shownAt = null): ImpressionId {
        EvaluationSchema::requireTables($this->connection);
        if ($shown->items === [] || ($memberId !== null && ($memberId < 0 || $memberId > 4294967295))) {
            throw new \InvalidArgumentException('An impression needs displayed items and a valid member ID.');
        }
        $model = null;
        if ($shown->scoreKind === 'click_probability') {
            $row = $this->connection->execute('SELECT artifact, objective, category, placement,
                source_mask, context_key, feature_schema_version FROM recommender_models WHERE id = UNHEX(?)',
                [$shown->modelId])->fetchAssoc();
            if (!$row) {
                throw new \UnexpectedValueException('Calibrated list refers to a missing model.');
            }
            if ($row['objective'] !== 'click' || (int)$row['category'] !== $shown->category
                || $row['placement'] !== $shown->placement || (int)$row['source_mask'] !== $shown->sourceMask()
                || $row['context_key'] !== ($shown->contextKey ?? '')
                || (int)$row['feature_schema_version'] !== 1) {
                throw new \UnexpectedValueException('Calibrated list does not match its model partition.');
            }
            $model = ClickModel::fromJson((string)$row['artifact']);
            $expectedFeatures = array_values(array_filter($model->featureNames(),
                fn($name) => $name !== 'log_position'));
            foreach ($shown->items as $item) {
                $actualFeatures = array_keys($item->featureSnapshot);
                sort($actualFeatures);
                if ($actualFeatures !== $expectedFeatures
                    || !$this->hasCompleteFeatureSnapshot($item, $shown->sources)
                    || $item->rankingScore === null
                    || abs($model->probability($item->featureSnapshot, 1) - $item->rankingScore) > 1e-9) {
                    throw new \UnexpectedValueException('Calibrated item features or reference score do not match the model.');
                }
            }
        }
        $id = ImpressionId::generate();
        $timestamp = self::utc($shownAt ?? new DateTimeImmutable('now'));
        $this->connection->transactional(function () use ($shown, $memberId, $id, $timestamp, $model): void {
            $this->connection->execute('INSERT INTO recommender_impressions
                (id, category, placement, source_mask, context_key, score_kind, member_id, shown_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$id->binary(), $shown->category, $shown->placement, $shown->sourceMask(),
                    $shown->contextKey ?? '', $shown->scoreKind, $memberId, $timestamp]);
            foreach ($shown->items as $index => $item) {
                $snapshot = json_encode(['features' => $item->featureSnapshot,
                    'depth_searched' => $item->searchedDepths], JSON_THROW_ON_ERROR);
                $schemaVersion = $this->hasCompleteFeatureSnapshot($item, $shown->sources) ? 1 : 0;
                $displayProbability = $model?->probability($item->featureSnapshot, $index + 1);
                $this->connection->execute('INSERT INTO recommender_impression_items
                    (impression_id, item_id, position, ranking_score, display_click_probability,
                    model_id, feature_schema_version, feature_snapshot)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$id->binary(), $item->itemId, $index + 1, $item->rankingScore,
                        $displayProbability, $shown->modelId === null ? null : hex2bin($shown->modelId),
                        $schemaVersion, $snapshot]);
                foreach ($item->evidence as $signal) {
                    $this->connection->execute('INSERT INTO recommender_impression_evidence
                        (impression_id, item_id, source, raw_score, source_rank, support_count,
                        log_odds_contribution, contributing_item_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$id->binary(), $item->itemId, $signal->source->value, $signal->rawScore,
                            $signal->sourceRank, $signal->supportCount, $signal->logOddsContribution,
                            json_encode($signal->contributingItemIds, JSON_THROW_ON_ERROR)]);
                }
            }
        });
        return $id;
    }

    /** @param ImpressionId $impressionId Display token
     * @param int $itemId Displayed item ID
     * @param string $eventId Stable printable ASCII event key
     * @param OutcomeType $type Action type
     * @param DateTimeImmutable $occurredAt Event time
     * @return void
     */
    public function recordOutcome(ImpressionId $impressionId, int $itemId, string $eventId,
        OutcomeType $type, DateTimeImmutable $occurredAt): void {
        EvaluationSchema::requireTables($this->connection);
        if ($itemId < 0 || $itemId > 4294967295 || preg_match('/^[\x20-\x7e]{1,128}$/D', $eventId) !== 1) {
            throw new \InvalidArgumentException('Invalid item or event ID.');
        }
        $timestamp = self::utc($occurredAt);
        $this->connection->transactional(function () use ($impressionId, $itemId, $eventId, $type, $timestamp): void {
            $row = $this->connection->execute('SELECT i.shown_at FROM recommender_impressions i
                JOIN recommender_impression_items item ON item.impression_id = i.id
                WHERE i.id = ? AND item.item_id = ?', [$impressionId->binary(), $itemId])->fetchAssoc();
            if (!$row || $timestamp < (string)$row['shown_at']) {
                throw new \InvalidArgumentException('Outcome must follow a displayed item.');
            }
            $this->connection->execute('INSERT INTO recommender_outcomes
                (event_id, impression_id, item_id, event_type, occurred_at)
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE event_id = event_id',
                [$eventId, $impressionId->binary(), $itemId, $type->value, $timestamp]);
            $stored = $this->connection->execute('SELECT HEX(impression_id) AS impression_hex,
                item_id, event_type, occurred_at FROM recommender_outcomes WHERE event_id = ?', [$eventId])->fetchAssoc();
            if (strtolower((string)$stored['impression_hex']) !== $impressionId->hex
                || (int)$stored['item_id'] !== $itemId || $stored['event_type'] !== $type->value
                || (string)$stored['occurred_at'] !== $timestamp) {
                throw new \InvalidArgumentException('Event ID was reused with conflicting details.');
            }
        });
    }

    /** @param int $memberId Member whose evaluation history should be erased
     * @return void
     */
    public function deleteMemberHistory(int $memberId): void {
        EvaluationSchema::requireTables($this->connection);
        if ($memberId < 0 || $memberId > 4294967295) {
            throw new \InvalidArgumentException('Invalid member ID.');
        }
        $this->connection->execute('DELETE FROM recommender_impressions WHERE member_id = ?', [$memberId]);
    }

    /** @param DateTimeImmutable $time Caller time
     * @return string UTC MySQL microsecond timestamp
     */
    private static function utc(DateTimeImmutable $time): string {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @param ReconciledRecommendation $item Displayed item
     * @param array<int, RecommendationSource> $sources Enabled source set
     * @return bool Whether this item has a complete version-1 feature snapshot
     */
    private function hasCompleteFeatureSnapshot(ReconciledRecommendation $item, array $sources): bool {
        $expected = [];
        $sourceNames = [];
        foreach ($sources as $source) {
            $sourceNames[] = $source->value;
            foreach (['log_depth_searched', 'present', 'reciprocal_rank', 'score', 'count'] as $name) {
                $expected[] = $source->value . '.' . $name;
            }
        }
        sort($expected);
        sort($sourceNames);
        $actual = array_keys($item->featureSnapshot);
        $depthSources = array_keys($item->searchedDepths);
        sort($actual);
        sort($depthSources);
        if ($actual !== $expected || $depthSources !== $sourceNames) {
            return false;
        }
        foreach ($item->searchedDepths as $source => $depth) {
            if ($depth < 50 || abs(log($depth) - $item->featureSnapshot[$source . '.log_depth_searched']) > 1e-9) {
                return false;
            }
        }
        return true;
    }
}
