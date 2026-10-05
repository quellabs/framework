<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use DateTimeImmutable;
use Quellabs\Recommender\Internal\Model\ClickModel;
use Quellabs\Recommender\Internal\Model\SourceFeatures;
use Quellabs\Recommender\Internal\Persistence\EvaluationSchema;
use Quellabs\Recommender\Internal\Identifier;
use Quellabs\Recommender\Internal\Persistence\MysqlTimestamp;

/** Explicit, transactional recording of displayed recommendations and outcomes. */
readonly class EvaluationRecorder {
	
	/** @var Connection Evaluation database connection */
	private Connection $connection;
	
	/**
	 * Build a recorder over the evaluation database.
	 * @param Connection $connection Evaluation database
	 */
	public function __construct(Connection $connection) {
		$this->connection = $connection;
	}
	
	/**
	 * Record the displayed order of a list and return its impression token.
	 * @param RecommendationList $shown Actual displayed order
	 * @param int|null $memberId Optional persisted member
	 * @param DateTimeImmutable|null $shownAt Actual display time
	 * @return ImpressionId New opaque impression token
	 * @throws \InvalidArgumentException When the list is empty or the member ID is invalid
	 * @throws \UnexpectedValueException When a calibrated list does not match its stored model
	 */
	public function recordImpression(RecommendationList $shown, ?int $memberId = null,
		?DateTimeImmutable $shownAt = null): ImpressionId {
		EvaluationSchema::requireTables($this->connection);
		
		if ($shown->items === []) {
			throw new \InvalidArgumentException('An impression needs at least one displayed item.');
		}
		
		if ($memberId !== null && ($memberId < 0 || $memberId > Identifier::MAX)) {
			throw new \InvalidArgumentException("Member ID must be an unsigned 32-bit integer, got {$memberId}.");
		}
		
		$model = $shown->scoreKind === 'click_probability' ? $this->loadCalibratedModel($shown) : null;
		$id = ImpressionId::generate();
		$timestamp = MysqlTimestamp::utc($shownAt ?? new DateTimeImmutable('now'));
		
		$this->connection->transactional(function () use ($shown, $memberId, $id, $timestamp, $model): void {
			$this->insertImpressionRow($id, $shown, $memberId, $timestamp);
			
			foreach ($shown->items as $index => $item) {
				$this->insertImpressionItem($id, $shown, $item, $index + 1, $model);
			}
		});
		
		return $id;
	}
	
	/**
	 * Record an outcome for a displayed item, ignoring exact retries of the same event.
	 * @param ImpressionId $impressionId Display token
	 * @param int $itemId Displayed item ID
	 * @param string $eventId Stable printable ASCII event key, 1 to 128 characters
	 * @param OutcomeType $type Action type
	 * @param DateTimeImmutable $occurredAt Event time
	 * @return void
	 * @throws \InvalidArgumentException When the IDs are invalid or the event conflicts with a stored one
	 */
	public function recordOutcome(ImpressionId $impressionId, int $itemId, string $eventId,
		OutcomeType $type, DateTimeImmutable $occurredAt): void {
		EvaluationSchema::requireTables($this->connection);
		
		if ($itemId < 0 || $itemId > Identifier::MAX) {
			throw new \InvalidArgumentException("Item ID must be an unsigned 32-bit integer, got {$itemId}.");
		}
		
		if (preg_match('/^[\x20-\x7e]{1,128}$/D', $eventId) !== 1) {
			throw new \InvalidArgumentException("Event ID must be 1 to 128 printable ASCII characters, got '{$eventId}'.");
		}
		
		$timestamp = MysqlTimestamp::utc($occurredAt);
		
		$this->connection->transactional(function () use ($impressionId, $itemId, $eventId, $type, $timestamp): void {
			$this->assertOutcomeFollowsDisplay($impressionId, $itemId, $timestamp);
			$this->connection->execute('INSERT INTO recommender_outcomes
                (event_id, impression_id, item_id, event_type, occurred_at)
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE event_id = event_id',
				[$eventId, $impressionId->binary(), $itemId, $type->value, $timestamp]);
			$this->assertStoredEventMatches($impressionId, $itemId, $eventId, $type, $timestamp);
		});
	}
	
	/**
	 * Delete the evaluation history recorded for a member.
	 * @param int $memberId Member whose evaluation history should be erased
	 * @return void
	 * @throws \InvalidArgumentException When the member ID is not an unsigned 32-bit integer
	 */
	public function deleteMemberHistory(int $memberId): void {
		EvaluationSchema::requireTables($this->connection);
		
		if ($memberId < 0 || $memberId > Identifier::MAX) {
			throw new \InvalidArgumentException("Member ID must be an unsigned 32-bit integer, got {$memberId}.");
		}
		
		$this->connection->execute('DELETE FROM recommender_impressions WHERE member_id = ?', [$memberId]);
	}
	
	/**
	 * Load the stored click model for a calibrated list and check that it matches the list.
	 * @param RecommendationList $shown Calibrated list
	 * @return ClickModel The model referenced by the list
	 * @throws \UnexpectedValueException When the model is missing or does not match the list
	 */
	private function loadCalibratedModel(RecommendationList $shown): ClickModel {
		$row = $this->connection->execute('SELECT artifact, objective, category, placement,
                source_mask, context_key, feature_schema_version FROM recommender_models WHERE id = UNHEX(?)',
			[$shown->modelId])->fetchAssoc();
			
		if (!$row) {
			throw new \UnexpectedValueException("Calibrated list refers to missing model {$shown->modelId}.");
		}
		
		if ($row['objective'] !== 'click' || (int)$row['category'] !== $shown->category
			|| $row['placement'] !== $shown->placement || (int)$row['source_mask'] !== $shown->sourceMask()
			|| $row['context_key'] !== ($shown->contextKey ?? '')
			|| (int)$row['feature_schema_version'] !== 1) {
			throw new \UnexpectedValueException("Calibrated list does not match the partition of model {$shown->modelId}.");
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
				throw new \UnexpectedValueException("Features or reference score of item {$item->itemId} do not match model {$shown->modelId}.");
			}
		}
		
		return $model;
	}
	
	/**
	 * Insert the impression header row.
	 * @param ImpressionId $id Impression token
	 * @param RecommendationList $shown Displayed list
	 * @param int|null $memberId Optional persisted member
	 * @param string $timestamp UTC display timestamp
	 * @return void
	 */
	private function insertImpressionRow(ImpressionId $id, RecommendationList $shown, ?int $memberId, string $timestamp): void {
		$this->connection->execute('INSERT INTO recommender_impressions
            (id, category, placement, source_mask, context_key, score_kind, member_id, shown_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
			[$id->binary(), $shown->category, $shown->placement, $shown->sourceMask(),
				$shown->contextKey ?? '', $shown->scoreKind, $memberId, $timestamp]);
	}
	
	/**
	 * Insert one displayed item and its source evidence.
	 * @param ImpressionId $id Impression token
	 * @param RecommendationList $shown Displayed list
	 * @param ReconciledRecommendation $item Displayed item
	 * @param int $position One-based display position
	 * @param ClickModel|null $model Calibrated model, or null for uncalibrated lists
	 * @return void
	 */
	private function insertImpressionItem(ImpressionId $id, RecommendationList $shown, ReconciledRecommendation $item,
		int $position, ?ClickModel $model): void {
		$snapshot = json_encode(['features' => $item->featureSnapshot,
			'depth_searched' => $item->searchedDepths], JSON_THROW_ON_ERROR);
		$schemaVersion = $this->hasCompleteFeatureSnapshot($item, $shown->sources) ? 1 : 0;
		$displayProbability = $model?->probability($item->featureSnapshot, $position);
		
		$this->connection->execute('INSERT INTO recommender_impression_items
            (impression_id, item_id, position, ranking_score, display_click_probability,
            model_id, feature_schema_version, feature_snapshot)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
			[$id->binary(), $item->itemId, $position, $item->rankingScore,
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
	
	/**
	 * Check that the item was displayed in the impression and that the outcome is not earlier than the display.
	 * @param ImpressionId $impressionId Display token
	 * @param int $itemId Displayed item ID
	 * @param string $timestamp UTC outcome timestamp
	 * @return void
	 * @throws \InvalidArgumentException When the item was not displayed or the outcome precedes the display
	 */
	private function assertOutcomeFollowsDisplay(ImpressionId $impressionId, int $itemId, string $timestamp): void {
		$row = $this->connection->execute('SELECT i.shown_at FROM recommender_impressions i
                JOIN recommender_impression_items item ON item.impression_id = i.id
                WHERE i.id = ? AND item.item_id = ?', [$impressionId->binary(), $itemId])->fetchAssoc();
		
		if (!$row) {
			throw new \InvalidArgumentException("Item {$itemId} is not part of impression {$impressionId->hex}.");
		}
		
		if ($timestamp < (string)$row['shown_at']) {
			throw new \InvalidArgumentException("Outcome at {$timestamp} precedes the display time {$row['shown_at']}.");
		}
	}
	
	/**
	 * Check that a stored event with this ID has the same details as the one just written.
	 * @param ImpressionId $impressionId Display token
	 * @param int $itemId Displayed item ID
	 * @param string $eventId Event key
	 * @param OutcomeType $type Action type
	 * @param string $timestamp UTC outcome timestamp
	 * @return void
	 * @throws \InvalidArgumentException When the stored event differs from the requested one
	 */
	private function assertStoredEventMatches(ImpressionId $impressionId, int $itemId, string $eventId,
		OutcomeType $type, string $timestamp): void {
		$stored = $this->connection->execute('SELECT HEX(impression_id) AS impression_hex,
                item_id, event_type, occurred_at FROM recommender_outcomes WHERE event_id = ?', [$eventId])->fetchAssoc();
		
		if (strtolower((string)$stored['impression_hex']) !== $impressionId->hex
			|| (int)$stored['item_id'] !== $itemId || $stored['event_type'] !== $type->value
			|| (string)$stored['occurred_at'] !== $timestamp) {
			throw new \InvalidArgumentException("Event ID '{$eventId}' was already recorded with different details.");
		}
	}
		
	/**
	 * Check whether an item carries a complete version-1 feature snapshot for the enabled sources.
	 * @param ReconciledRecommendation $item Displayed item
	 * @param array<int, RecommendationSource> $sources Enabled source set
	 * @return bool Whether this item has a complete version-1 feature snapshot
	 */
	private function hasCompleteFeatureSnapshot(ReconciledRecommendation $item, array $sources): bool {
		$expected = SourceFeatures::names($sources);
		$sourceNames = array_map(fn($source) => $source->value, $sources);
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
