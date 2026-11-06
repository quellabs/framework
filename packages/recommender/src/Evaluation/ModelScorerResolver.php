<?php
	
	namespace Quellabs\Recommender\Evaluation;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Internal\Model\ClickModel;
	use Quellabs\Recommender\Internal\Model\ClickModelScorer;
	use Quellabs\Recommender\Internal\Reconciliation\SourceFeatures;
	use Quellabs\Recommender\RecommendationSource;
	use Quellabs\Recommender\Reconciliation\ActiveScorer;
	use Quellabs\Recommender\Reconciliation\ScorerResolver;
	
	/** Serves the active click model of a partition from the evaluation tables. */
	readonly class ModelScorerResolver implements ScorerResolver {
		
		/** @var Connection Ratings database connection */
		private Connection $connection;
		
		/**
		 * Store the database connection.
		 * @param Connection $connection Ratings database connection
		 */
		public function __construct(Connection $connection) {
			$this->connection = $connection;
		}
		
		/**
		 * Return the active click model for a partition, when the model table exists.
		 * @param int $category Resolved category
		 * @param string $placement Display surface
		 * @param array<int, RecommendationSource> $sources Enabled source set
		 * @param string|null $contextKey Model partition
		 * @return ActiveScorer|null Click-model scorer and model row ID, or null when none is active
		 * @throws \UnexpectedValueException When the model schema or feature names do not match the request
		 */
		public function resolve(int $category, string $placement, array $sources, ?string $contextKey): ?ActiveScorer {
			$exists = $this->connection->execute('
				SELECT
					COUNT(*) AS total
				FROM information_schema.tables
				WHERE table_schema = DATABASE() AND
				      table_name = :table_name
			', [
				'table_name' => 'vogoo_models',
			])->fetchAssoc();
			
			if ((int)$exists['total'] === 0) {
				return null;
			}
			
			$row = $this->connection->execute('
				SELECT
					HEX(id) AS model_id,
					feature_schema_version,
					artifact
				FROM vogoo_models
				WHERE objective = :objective AND
				      category = :category AND
				      placement = :placement AND
				      source_mask = :source_mask AND
				      context_key = :context_key AND
				      status = :status
			', [
				'objective'   => 'click',
				'category'    => $category,
				'placement'   => $placement,
				'source_mask' => RecommendationSource::mask($sources),
				'context_key' => $contextKey ?? '',
				'status'      => 'active',
			])->fetchAssoc();
			
			if (!$row) {
				return null;
			}
			
			if ((int)$row['feature_schema_version'] !== 1) {
				throw new \UnexpectedValueException("Active click model feature schema version {$row['feature_schema_version']} is incompatible; expected 1.");
			}
			
			$model = ClickModel::fromJson((string)$row['artifact']);
			$expected = array_merge(['log_position'], SourceFeatures::names($sources));
			
			sort($expected);
			
			if ($model->featureNames() !== $expected) {
				throw new \UnexpectedValueException("Active click model {$row['model_id']} feature names do not match the request sources.");
			}
			
			return new ActiveScorer(strtolower((string)$row['model_id']), new ClickModelScorer($model));
		}
	}
