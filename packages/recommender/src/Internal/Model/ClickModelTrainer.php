<?php
	
	namespace Quellabs\Recommender\Internal\Model;
	
	use Cake\Database\Connection;
	use DateTimeImmutable;
	use Quellabs\Recommender\Internal\Identifier;
	use Quellabs\Recommender\Internal\Persistence\EvaluationSchema;
	use Quellabs\Recommender\Internal\Persistence\MysqlTimestamp;
	use Quellabs\Recommender\Internal\Query\OutcomeSubquery;
	use Quellabs\Recommender\Internal\Reconciliation\SourceFeatures;
	use Quellabs\Recommender\RecommendationSource;
	
	/**
	 * Builds versioned model candidates from mature, opted-in impression snapshots.
	 *
	 * @phpstan-import-type LabeledSample from ClickModelFitter
	 * @phpstan-type MatureRow array{impression_id: string, shown_at: string, position: int|string, feature_snapshot: string, clicked: int|string}
	 */
	readonly class ClickModelTrainer {
		
		/** @var Connection Evaluation database connection */
		private Connection $connection;
		
		/**
		 * Build the trainer.
		 * @param Connection $connection Evaluation database
		 */
		public function __construct(Connection $connection) {
			$this->connection = $connection;
		}
		
		/**
		 * Train a candidate click model for one scoring partition and store it as a candidate artifact.
		 * @param int $category Model category
		 * @param string $placement Exact display surface
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @param DateTimeImmutable $from Inclusive cohort start
		 * @param DateTimeImmutable $to Exclusive cohort end
		 * @param DateTimeImmutable $asOf Outcome cutoff
		 * @param int $clickWindowSeconds Positive click attribution period
		 * @param string|null $contextKey Exact caller partition
		 * @return string Hex ID of an immutable candidate artifact
		 * @throws \InvalidArgumentException When the request is invalid
		 * @throws \RuntimeException When there are too few mature items or clicks to train
		 */
		public function train(int $category, string $placement, array $sources, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $asOf, int $clickWindowSeconds, ?string $contextKey = null): string {
			EvaluationSchema::requireTables($this->connection);
			$mask = $this->validateTrainingRequest($category, $placement, $sources, $from, $to, $asOf, $clickWindowSeconds, $contextKey);
			[$expectedFeatures, $expectedDepthSources] = self::expectedSchema($sources);
			$rows = $this->fetchMatureRows($category, $placement, $mask, $contextKey, $from, $to, $asOf, $clickWindowSeconds);
			[$groups, $groupTimes] = $this->groupSamples($rows, $expectedFeatures, $expectedDepthSources);
			[$training, $holdout, $split] = self::splitByImpression($groups);
			$trainingClicks = array_sum(array_column($training, 'label'));
			$holdoutClicks = array_sum(array_column($holdout, 'label'));
			self::assertSampleSizes(count($training), count($holdout), $trainingClicks, $holdoutClicks);
			
			$artifact = (new ClickModelFitter())->fit($training, $holdout);
			$groupIds = array_keys($groups);
			$artifact['objective'] = 'click';
			$artifact['training_interval'] = self::interval($groupIds, $groupTimes, 0, $split - 1);
			$artifact['holdout_interval'] = self::interval($groupIds, $groupTimes, $split, count($groupIds) - 1);
			$artifact['click_window_seconds'] = $clickWindowSeconds;
			$artifact['from'] = MysqlTimestamp::utc($from);
			$artifact['to'] = MysqlTimestamp::utc($to);
			$artifact['as_of'] = MysqlTimestamp::utc($asOf);
			$artifact['training_clicks'] = $trainingClicks;
			$artifact['holdout_clicks'] = $holdoutClicks;
			
			$id = bin2hex(random_bytes(16));
			$this->connection->execute('INSERT INTO vogoo_models
			(id, objective, category, placement, source_mask, context_key, feature_schema_version,
				artifact, trained_at, activated_at, status)
			VALUES (UNHEX(:id), \'click\', :category, :placement, :source_mask, :context_key, 1,
				:artifact, UTC_TIMESTAMP(6), NULL, :status)',
			[
				'id'          => $id,
				'category'    => $category,
				'placement'   => $placement,
				'source_mask' => $mask,
				'context_key' => $contextKey ?? '',
				'artifact'    => json_encode($artifact, JSON_THROW_ON_ERROR),
				'status'      => $artifact['validated'] ? 'validated' : 'rejected',
			]);
					
			return $id;
		}
		
		/**
		 * Activate a validated candidate, retiring the previously active model of the same partition.
		 * @param string $modelId Candidate hex token
		 * @return void
		 * @throws \InvalidArgumentException When the ID is malformed, or the model is missing or not validated
		 */
		public function activate(string $modelId): void {
			EvaluationSchema::requireTables($this->connection);
			
			if (preg_match('/^[0-9a-f]{32}$/D', $modelId) !== 1) {
				throw new \InvalidArgumentException("Model ID must be 32 lowercase hexadecimal characters, got '{$modelId}'.");
			}
			
			$this->connection->transactional(function () use ($modelId): void {
				$row = $this->connection->execute('
					SELECT
						objective,
						category,
						placement,
						source_mask,
						context_key,
						artifact,
						status
					FROM vogoo_models
					WHERE id = UNHEX(:model_id)
					FOR UPDATE
				', [
					'model_id' => $modelId,
				])->fetchAssoc();
					
				if (!$row || $row['status'] !== 'validated') {
					throw new \InvalidArgumentException("Model '{$modelId}' is missing or has not passed validation.");
				}
				
				$artifact = json_decode((string)$row['artifact'], true, 512, JSON_THROW_ON_ERROR);
				
				if (!is_array($artifact) || ($artifact['validated'] ?? false) !== true) {
					throw new \InvalidArgumentException("Model '{$modelId}' artifact failed validation.");
				}
				
				$this->connection->execute('UPDATE vogoo_models SET status = \'retired\'
				WHERE objective = :objective AND
					category = :category AND
					placement = :placement AND
					source_mask = :source_mask AND
					context_key = :context_key AND
					status = \'active\'',
				[
					'objective'   => $row['objective'],
					'category'    => $row['category'],
					'placement'   => $row['placement'],
					'source_mask' => $row['source_mask'],
					'context_key' => $row['context_key'],
				]);
				$this->connection->execute('UPDATE vogoo_models SET status = \'active\',
				activated_at = UTC_TIMESTAMP(6) WHERE id = UNHEX(:id)', ['id' => $modelId]);
			});
		}
		
		/**
		 * Validate the training request and return the source bit mask.
		 * @param int $category Model category
		 * @param string $placement Exact display surface
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @param DateTimeImmutable $from Inclusive cohort start
		 * @param DateTimeImmutable $to Exclusive cohort end
		 * @param DateTimeImmutable $asOf Outcome cutoff, not earlier than the cohort end
		 * @param int $clickWindowSeconds Positive click attribution period
		 * @param string|null $contextKey Exact caller partition
		 * @return int Canonical source mask
		 * @throws \InvalidArgumentException When the request is invalid
		 */
		private function validateTrainingRequest(int $category, string $placement, array $sources, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $asOf, int $clickWindowSeconds, ?string $contextKey): int {
			if ($category < 0 || $category > Identifier::MAX) {
				throw new \InvalidArgumentException("Category must be an unsigned 32-bit integer, got {$category}.");
			}
	
			if ($from >= $to) {
				throw new \InvalidArgumentException('Cohort start ' . $from->format(DATE_ATOM) . ' must be before its end ' . $to->format(DATE_ATOM) . '.');
			}
	
			if ($to > $asOf) {
				throw new \InvalidArgumentException('Cohort end ' . $to->format(DATE_ATOM) . ' must not follow the outcome cutoff ' . $asOf->format(DATE_ATOM) . '.');
			}
	
			if ($clickWindowSeconds < 1) {
				throw new \InvalidArgumentException("Click window must be positive, got {$clickWindowSeconds}.");
			}
	
			if ($sources === []) {
				throw new \InvalidArgumentException('At least one recommendation source is required.');
			}
	
			Identifier::validateKey($placement, 64, 'placement');
	
			if ($contextKey !== null) {
				Identifier::validateKey($contextKey, 128, 'context');
			}
	
			return self::distinctSourceMask($sources);
		}
	
		/**
		 * Combine the enabled sources into a source mask, rejecting repeated or non-source values.
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @return int Canonical source mask
		 * @throws \InvalidArgumentException When a source is repeated or is not a RecommendationSource
		 */
		private static function distinctSourceMask(array $sources): int {
			$mask = 0;
	
			foreach ($sources as $source) {
				if (!$source instanceof RecommendationSource || ($mask & $source->bit()) !== 0) {
					throw new \InvalidArgumentException('Sources must be distinct RecommendationSource values.');
				}
	
				$mask |= $source->bit();
			}
	
			return $mask;
		}
		
		/**
		 * Return the feature names and depth sources that a version-1 snapshot must contain.
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @return array{0: array<int, string>, 1: array<int, string>} Sorted feature names and sorted depth source values
		 */
		private static function expectedSchema(array $sources): array {
			$expectedDepthSources = array_map(fn($source) => $source->value, $sources);
			sort($expectedDepthSources);
			return [SourceFeatures::names($sources), $expectedDepthSources];
		}
		
		/**
		 * Fetch the mature displayed items of the cohort, one row per item, in display order.
		 * @param int $category Model category
		 * @param string $placement Exact display surface
		 * @param int $mask Canonical source mask
		 * @param string|null $contextKey Exact caller partition
		 * @param DateTimeImmutable $from Inclusive cohort start
		 * @param DateTimeImmutable $to Exclusive cohort end
		 * @param DateTimeImmutable $asOf Outcome cutoff
		 * @param int $clickWindowSeconds Positive click attribution period
		 * @return array<int, MatureRow> Rows with impression, position, snapshot, and click fields
		 */
		private function fetchMatureRows(int $category, string $placement, int $mask, ?string $contextKey, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $asOf, int $clickWindowSeconds): array {
			$clicked = OutcomeSubquery::exists('click', 'as_of', 'click_window');
	
			return $this->connection->execute("
				SELECT
					HEX(i.id) AS impression_id,
					i.shown_at,
					item.position,
					item.feature_snapshot,
					{$clicked} AS clicked
				FROM vogoo_impressions i
				JOIN vogoo_impression_items item ON item.impression_id = i.id
				WHERE i.category = :category AND
				      i.placement = :placement AND
				      i.source_mask = :mask AND
				      i.context_key = :context AND
				      item.feature_schema_version = 1 AND
				      i.shown_at >= :from AND
				      i.shown_at < :to AND
				      TIMESTAMPADD(SECOND, :mature_window, i.shown_at) <= :mature_as_of
				ORDER BY i.shown_at ASC, i.id ASC, item.position ASC
			", [
				'category' => $category,
				'placement' => $placement,
				'mask' => $mask,
				'context' => $contextKey ?? '',
				'from' => MysqlTimestamp::utc($from),
				'to' => MysqlTimestamp::utc($to),
				'as_of' => MysqlTimestamp::utc($asOf),
				'click_window' => $clickWindowSeconds,
				'mature_window' => $clickWindowSeconds,
				'mature_as_of' => MysqlTimestamp::utc($asOf),
			])->fetchAll('assoc');
		}
		
		/**
		 * Group the valid snapshot rows by impression, keeping each impression's shown time.
		 * @param array<int, MatureRow> $rows Rows from fetchMatureRows()
		 * @param array<int, string> $expectedFeatures Sorted feature names of schema version 1
		 * @param array<int, string> $expectedDepthSources Sorted depth source values
		 * @return array{0: array<string, list<LabeledSample>>, 1: array<string, string>}
		 *     Items per impression ID, and shown time per impression ID
		 */
		private function groupSamples(array $rows, array $expectedFeatures, array $expectedDepthSources): array {
			$groups = [];
			$groupTimes = [];
			
			foreach ($rows as $row) {
				$features = $this->decodeSnapshot($row, $expectedFeatures, $expectedDepthSources);
				
				if ($features === null) {
					continue;
				}
				
				$impressionId = (string)$row['impression_id'];
				$groups[$impressionId][] = ['features' => $features, 'label' => (int)$row['clicked']];
				$groupTimes[$impressionId] = (string)$row['shown_at'];
			}
			
			return [$groups, $groupTimes];
		}
		
		/**
		 * Decode one item's feature snapshot, adding its log position, or return null when the snapshot is unusable.
		 * @param MatureRow $row Row from fetchMatureRows()
		 * @param array<int, string> $expectedFeatures Sorted feature names of schema version 1
		 * @param array<int, string> $expectedDepthSources Sorted depth source values
		 * @return array<string, float>|null Feature values, or null when the snapshot has no features or depths
		 * @throws \UnexpectedValueException When the snapshot does not match schema version 1
		 */
		private function decodeSnapshot(array $row, array $expectedFeatures, array $expectedDepthSources): ?array {
			$snapshot = json_decode((string)$row['feature_snapshot'], true, 512, JSON_THROW_ON_ERROR);
			
			if (
				!is_array($snapshot) ||
				!isset($snapshot['features'], $snapshot['depth_searched']) ||
				!is_array($snapshot['features']) ||
				!is_array($snapshot['depth_searched']) ||
				$snapshot['features'] === []
			) {
				return null;
			}
			
			$features = self::decodeFeatureValues($snapshot['features']);
			$actualFeatures = array_keys($features);
			sort($actualFeatures);
			
			if ($actualFeatures !== $expectedFeatures) {
				throw new \UnexpectedValueException('Recorded feature snapshot does not match schema version 1.');
			}
			
			self::assertDepthsMatch($snapshot['depth_searched'], $features, $expectedDepthSources);
			$features['log_position'] = log((int)$row['position']);
			return $features;
		}
		
		/**
		 * Convert recorded feature values to floats, rejecting any that are not finite numbers.
		 * @param array<mixed> $values Recorded feature values keyed by feature name
		 * @return array<string, float> Feature values as floats
		 * @throws \UnexpectedValueException When a feature name is not a string or a value is not a finite number
		 */
		private static function decodeFeatureValues(array $values): array {
			$features = [];

			foreach ($values as $name => $value) {
				if (!is_string($name) || !is_numeric($value) || !is_finite((float)$value)) {
					throw new \UnexpectedValueException('Recorded feature ' . var_export($name, true) . ' must be a finite number.');
				}

				$features[$name] = (float)$value;
			}

			return $features;
		}

		/**
		 * Check that the recorded depths cover the enabled sources and agree with their log-depth features.
		 * @param array<mixed> $depths Recorded depth per source
		 * @param array<string, float> $features Decoded feature values
		 * @param array<int, string> $expectedDepthSources Sorted depth source values
		 * @return void
		 * @throws \UnexpectedValueException When the depths do not match the enabled sources or their features
		 */
		private static function assertDepthsMatch(array $depths, array $features, array $expectedDepthSources): void {
			$actualDepthSources = array_keys($depths);
			sort($actualDepthSources);
			
			if ($actualDepthSources !== $expectedDepthSources) {
				throw new \UnexpectedValueException('Recorded source depths do not match the enabled set.');
			}
			
			foreach ($depths as $source => $depth) {
				if (
					!is_string($source) ||
					!is_int($depth) ||
					!SourceFeatures::depthMatches($depth, $features[$source . '.log_depth_searched'])
				) {
					throw new \UnexpectedValueException('Recorded source depth does not match its feature.');
				}
			}
		}
		
		/**
		 * Split impressions by order: the first 80% train the model and the rest hold it out.
		 * @param array<string, list<LabeledSample>> $groups Items per impression, in time order
		 * @return array{0: list<LabeledSample>, 1: list<LabeledSample>, 2: int}
		 *     Training items, holdout items, and the number of training impressions
		 */
		private static function splitByImpression(array $groups): array {
			$split = (int)floor(count($groups) * 0.8);
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
			
			return [$training, $holdout, $split];
		}
		
		/**
		 * Require enough training and holdout items and clicks for a meaningful fit and check.
		 * @param int $trainingItems Number of training items
		 * @param int $holdoutItems Number of holdout items
		 * @param int $trainingClicks Clicks among the training items
		 * @param int $holdoutClicks Clicks among the holdout items
		 * @return void
		 * @throws \RuntimeException When the sample is too small
		 */
		private static function assertSampleSizes(int $trainingItems, int $holdoutItems, int $trainingClicks, int $holdoutClicks): void {
			if (
				$trainingItems < 1000 ||
				$trainingClicks < 100 ||
				$holdoutClicks < 20 ||
				$holdoutItems - $holdoutClicks < 20
			) {
				throw new \RuntimeException("Not enough mature displayed items and clicks to train a model: {$trainingItems} training items with {$trainingClicks} clicks, {$holdoutItems} holdout items with {$holdoutClicks} clicks.");
			}
		}
		
		/**
		 * Describe the first and last impression of a span of the time-ordered groups.
		 * @param array<int, string> $groupIds Impression IDs in time order
		 * @param array<string, string> $groupTimes Shown time per impression ID
		 * @param int $first Index of the first impression
		 * @param int $last Index of the last impression
		 * @return array{first_impression_id: string, first_shown_at: string, last_impression_id: string, last_shown_at: string}
		 */
		private static function interval(array $groupIds, array $groupTimes, int $first, int $last): array {
			return [
				'first_impression_id' => strtolower($groupIds[$first]),
				'first_shown_at'      => $groupTimes[$groupIds[$first]],
				'last_impression_id'  => strtolower($groupIds[$last]),
				'last_shown_at'       => $groupTimes[$groupIds[$last]],
			];
		}
		}