<?php
	
	namespace Quellabs\Recommender\Evaluation;
	
	use Quellabs\Recommender\ScoreKind;
	use Cake\Database\Connection;
	use DateTimeImmutable;
	use Quellabs\Recommender\Internal\Model\ClickModel;
	use Quellabs\Recommender\Internal\Reconciliation\SourceFeatures;
	use Quellabs\Recommender\Internal\Persistence\EvaluationSchema;
	use Quellabs\Recommender\Internal\Identifier;
	use Quellabs\Recommender\Internal\Persistence\MysqlTimestamp;

	use Quellabs\Recommender\RecommendationList;
	
	use Quellabs\Recommender\RecommendationSource;
	
	use Quellabs\Recommender\Reconciliation\ReconciledRecommendation;
	use Quellabs\Recommender\Reconciliation\ReconciliationDiagnostics;
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
		 * @param int|null $member Optional persisted member ID
		 * @param DateTimeImmutable|null $shownAt Actual display time
		 * @return ImpressionId New opaque impression token
		 * @throws \InvalidArgumentException When the list is empty or the member ID is invalid
		 * @throws \UnexpectedValueException When a calibrated list does not match its stored model
		 */
		public function recordImpression(RecommendationList $shown, ?int $member = null,
			?DateTimeImmutable $shownAt = null): ImpressionId {
			if ($member !== null) {
				Identifier::assertId($member, 'Member ID');
			}

			EvaluationSchema::requireTables($this->connection);
			
			if ($shown->items === []) {
				throw new \InvalidArgumentException('An impression needs at least one displayed item.');
			}

			$model = $shown->scorerId === null ? null : $this->loadCalibratedModel($shown);
			$id = ImpressionId::generate();
			$timestamp = MysqlTimestamp::utc($shownAt ?? new DateTimeImmutable('now'));
			
			$this->connection->transactional(function () use ($shown, $member, $id, $timestamp, $model): void {
				$this->insertImpressionRow($id, $shown, $member, $timestamp);
				
				foreach ($shown->items as $index => $item) {
					$this->insertImpressionItem($id, $shown, $item, $index + 1, $model);
				}
			});
			
			return $id;
		}
		
		/**
		 * Record an outcome for a displayed item, ignoring exact retries of the same event.
		 * @param ImpressionId $impressionId Display token
		 * @param int $product Displayed item ID
		 * @param string $eventId Stable printable ASCII event key, 1 to 128 characters
		 * @param OutcomeType $type Action type
		 * @param DateTimeImmutable $occurredAt Event time
		 * @return void
		 * @throws \InvalidArgumentException|\Exception When the IDs are invalid or the event conflicts with a stored one
		 */
		public function recordOutcome(ImpressionId $impressionId, int $product, string $eventId,
			OutcomeType $type, DateTimeImmutable $occurredAt): void {
			Identifier::assertId($product, 'Product ID');
			EvaluationSchema::requireTables($this->connection);

			if (preg_match('/^[\x20-\x7e]{1,128}$/D', $eventId) !== 1) {
				throw new \InvalidArgumentException("Event ID must be 1 to 128 printable ASCII characters, got '{$eventId}'.");
			}
			
			$timestamp = MysqlTimestamp::utc($occurredAt);
			
			$this->connection->transactional(function () use ($impressionId, $product, $eventId, $type, $timestamp): void {
				$this->assertOutcomeFollowsDisplay($impressionId, $product, $timestamp);
				
				$this->connection->execute('INSERT INTO vogoo_outcomes
	                (event_id, impression_id, item_id, event_type, occurred_at)
	                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE event_id = event_id
                ',
					[
						$eventId,
						$impressionId->binary(),
						$product,
						$type->value,
						$timestamp
					]
				);
				
				$this->assertStoredEventMatches($impressionId, $product, $eventId, $type, $timestamp);
			});
		}
		
		/**
		 * Delete the evaluation history recorded for a member.
		 * @param int $member Member whose evaluation history should be erased
		 * @return void
		 * @throws \InvalidArgumentException When the member ID is not an unsigned 32-bit integer
		 */
		public function deleteMemberEvaluations(int $member): void {
			Identifier::assertId($member, 'Member ID');
			EvaluationSchema::requireTables($this->connection);

			$this->connection->execute('DELETE FROM vogoo_impressions WHERE member_id = ?', [$member]);
		}
		
		/**
		 * Load the stored click model for a calibrated list and check that it matches the list.
		 * @param RecommendationList $shown Calibrated list
		 * @return ClickModel The model referenced by the list
		 * @throws \UnexpectedValueException When the model is missing or does not match the list
		 */
		private function loadCalibratedModel(RecommendationList $shown): ClickModel {
			if (preg_match('/^[0-9a-f]{32}$/D', (string)$shown->scorerId) !== 1) {
				throw new \UnexpectedValueException("Scorer ID '{$shown->scorerId}' is not a model row ID.");
			}

			$row = $this->connection->execute('
				SELECT
					artifact,
					objective,
					category,
					placement,
					source_mask,
					context_key,
					feature_schema_version
				FROM vogoo_models
				WHERE id = UNHEX(:model_id)
			', [
				'model_id' => $shown->scorerId,
			])->fetchAssoc();
			
			if (!$row) {
				throw new \UnexpectedValueException("Calibrated list refers to missing model {$shown->scorerId}.");
			}
			
			$this->assertPartitionMatches($row, $shown);

			$model = ClickModel::fromJson((string)$row['artifact']);
			$this->assertItemsMatchModel($shown, $model);
			return $model;
		}

		/**
		 * Check that a stored model row belongs to the partition of a calibrated list.
		 * @param array<string, string|int|float|bool|null> $row Stored model row
		 * @param RecommendationList $shown Calibrated list
		 * @return void
		 * @throws \UnexpectedValueException When the row does not match the list partition
		 */
		private function assertPartitionMatches(array $row, RecommendationList $shown): void {
			if (
				$row['objective'] !== 'click' ||
				(int)$row['category'] !== $shown->category ||
				$row['placement'] !== $shown->placement ||
				(int)$row['source_mask'] !== $shown->sourceMask() ||
				$row['context_key'] !== ($shown->contextKey ?? '') ||
				(int)$row['feature_schema_version'] !== 1
			) {
				throw new \UnexpectedValueException("Calibrated list does not match the partition of model {$shown->scorerId}.");
			}
		}

		/**
		 * Check that each displayed item carries the model's features and reproduces its reference score.
		 * @param RecommendationList $shown Calibrated list
		 * @param ClickModel $model Model referenced by the list
		 * @return void
		 * @throws \UnexpectedValueException When an item's features or reference score do not match the model
		 */
		private function assertItemsMatchModel(RecommendationList $shown, ClickModel $model): void {
			$expectedFeatures = array_values(array_filter($model->featureNames(),
				fn($name) => $name !== 'log_position'));

			foreach ($shown->items as $item) {
				$diagnostics = $this->diagnosticsOf($shown, $item);
				$actualFeatures = array_keys($diagnostics->featureSnapshot);
				sort($actualFeatures);

				if (
					$actualFeatures !== $expectedFeatures ||
					!$this->hasCompleteFeatureSnapshot($diagnostics, $shown->sources) ||
					$item->rankingScore === null ||
					abs($model->probability($diagnostics->featureSnapshot, 1) - $item->rankingScore) > 1e-9
				) {
					throw new \UnexpectedValueException("Features or reference score of product {$item->productId} do not match model {$shown->scorerId}.");
				}
			}
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
			$this->connection->execute('INSERT INTO vogoo_impressions
	            (id, category, placement, source_mask, context_key, score_kind, member_id, shown_at)
	            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
				[$id->binary(), $shown->category, $shown->placement, $shown->sourceMask(),
					$shown->contextKey ?? '', $shown->scoreKind->value, $memberId, $timestamp]);
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
			$diagnostics = $this->diagnosticsOf($shown, $item);
			$snapshot = json_encode(['features'       => $diagnostics->featureSnapshot,
			                         'depth_searched' => $diagnostics->searchedDepths], JSON_THROW_ON_ERROR);
			$schemaVersion = $this->hasCompleteFeatureSnapshot($diagnostics, $shown->sources) ? 1 : 0;
			$displayProbability = $model?->probability($diagnostics->featureSnapshot, $position);
			
			$this->connection->execute('INSERT INTO vogoo_impression_items
	            (impression_id, item_id, position, ranking_score, display_click_probability,
	            model_id, feature_schema_version, feature_snapshot)
	            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
				[$id->binary(), $item->productId, $position, $item->rankingScore,
					$displayProbability, $shown->scorerId === null ? null : hex2bin($shown->scorerId),
					$schemaVersion, $snapshot]);
			
			foreach ($item->evidence as $signal) {
				$this->connection->execute('INSERT INTO vogoo_impression_evidence
	                (impression_id, item_id, source, raw_score, source_rank, support_count,
	                log_odds_contribution, contributing_item_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
					[$id->binary(), $item->productId, $signal->source->value, $signal->rawScore,
						$signal->sourceRank, $signal->supportCount, $signal->logOddsContribution,
						json_encode($signal->contributingProductIds, JSON_THROW_ON_ERROR)]);
			}
		}
		
		/**
		 * Check that the item was displayed in the impression and that the outcome is not earlier than the display.
		 * @param ImpressionId $impressionId Display token
		 * @param int $productId Displayed item ID
		 * @param string $timestamp UTC outcome timestamp
		 * @return void
		 * @throws \InvalidArgumentException When the item was not displayed or the outcome precedes the display
		 */
		private function assertOutcomeFollowsDisplay(ImpressionId $impressionId, int $productId, string $timestamp): void {
			$row = $this->connection->execute('
				SELECT
					i.shown_at
				FROM vogoo_impressions i
				JOIN vogoo_impression_items item ON item.impression_id = i.id
				WHERE i.id = :impression_id AND
				      item.item_id = :item_id
			', [
				'impression_id' => $impressionId->binary(),
				'item_id'       => $productId,
			])->fetchAssoc();
			
			if (!$row) {
				throw new \InvalidArgumentException("Product {$productId} is not part of impression {$impressionId->hex}.");
			}
			
			if ($timestamp < (string)$row['shown_at']) {
				throw new \InvalidArgumentException("Outcome at {$timestamp} precedes the display time {$row['shown_at']}.");
			}
		}
		
		/**
		 * Check that a stored event with this ID has the same details as the one just written.
		 * @param ImpressionId $impressionId Display token
		 * @param int $productId Displayed item ID
		 * @param string $eventId Event key
		 * @param OutcomeType $type Action type
		 * @param string $timestamp UTC outcome timestamp
		 * @return void
		 * @throws \InvalidArgumentException When the stored event differs from the requested one
		 */
		private function assertStoredEventMatches(ImpressionId $impressionId, int $productId, string $eventId,
			OutcomeType $type, string $timestamp): void {
			$stored = $this->connection->execute('
				SELECT
					HEX(impression_id) AS impression_hex,
					item_id,
					event_type,
					occurred_at
				FROM vogoo_outcomes
				WHERE event_id = :event_id
			', [
				'event_id' => $eventId,
			])->fetchAssoc();
			
			if (
				strtolower((string)$stored['impression_hex']) !== $impressionId->hex ||
				(int)$stored['item_id'] !== $productId ||
				$stored['event_type'] !== $type->value ||
				(string)$stored['occurred_at'] !== $timestamp
			) {
				throw new \InvalidArgumentException("Event ID '{$eventId}' was already recorded with different details.");
			}
		}
		
		/**
		 * Return the diagnostics of a displayed item: empty for a direct list, required for a ranked list.
		 * @param RecommendationList $shown Displayed list
		 * @param ReconciledRecommendation $item Displayed item
		 * @return ReconciliationDiagnostics Features and depths of the item
		 * @throws \UnexpectedValueException When a ranked list item has no diagnostics
		 */
		private function diagnosticsOf(RecommendationList $shown, ReconciledRecommendation $item): ReconciliationDiagnostics {
			if ($item->diagnostics !== null) {
				return $item->diagnostics;
			}

			if ($shown->scoreKind === ScoreKind::Direct) {
				return new ReconciliationDiagnostics();
			}

			throw new \UnexpectedValueException("Product {$item->productId} has no diagnostics; reconcile with diagnostics: true to record a ranked impression.");
		}

		/**
		 * Check whether the diagnostics hold a complete version-1 feature snapshot for the enabled sources.
		 * @param ReconciliationDiagnostics $diagnostics Features and depths of a displayed item
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @return bool Whether the diagnostics are a complete version-1 feature snapshot
		 */
		private function hasCompleteFeatureSnapshot(ReconciliationDiagnostics $diagnostics, array $sources): bool {
			$expected = SourceFeatures::names($sources);
			$sourceNames = array_map(fn($source) => $source->value, $sources);
			sort($sourceNames);
			$actual = array_keys($diagnostics->featureSnapshot);
			$depthSources = array_keys($diagnostics->searchedDepths);
			sort($actual);
			sort($depthSources);
			
			if ($actual !== $expected || $depthSources !== $sourceNames) {
				return false;
			}
			
			foreach ($diagnostics->searchedDepths as $source => $depth) {
				if (!SourceFeatures::depthMatches($depth, $diagnostics->featureSnapshot[$source . '.log_depth_searched'])) {
					return false;
				}
			}
			
			return true;
		}
	}
