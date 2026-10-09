<?php
	
	namespace Quellabs\Recommender\Internal\Persistence;
	
	use Cake\Database\Connection;
	
	/** Detects the optional evaluation installation before recording or training. */
	final class EvaluationSchema {
		
		/**
		 * Throw unless every evaluation table exists in the current database.
		 * @param Connection $connection Evaluation database
		 * @return void
		 * @throws \RuntimeException When any evaluation table is missing
		 */
		public static function requireTables(Connection $connection): void {
			$tables = ['vogoo_models', 'vogoo_impressions', 'vogoo_impression_items',
				'vogoo_impression_evidence', 'vogoo_outcomes'];
			$params = [];
			$names = [];
			
			foreach ($tables as $index => $table) {
				$params['table_' . $index] = $table;
				$names[] = ':table_' . $index;
			}
			
			$inList = implode(',', $names);
			$rows = $connection->execute("
				SELECT
					TABLE_NAME
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = DATABASE() AND
				      TABLE_NAME IN ({$inList})
			", $params)->fetchAll('assoc');
			
			if (count($rows) !== count($tables)) {
				throw new \RuntimeException('Recommender evaluation tables are missing; run recommender:init-evaluation-db.');
			}
		}
		
		/**
		 * Check every evaluation table against the expected columns, engine, indexes and foreign keys.
		 * @param Connection $connection Evaluation database connection
		 * @return void
		 * @throws \RuntimeException When a table does not match the schema
		 */
		public static function verify(Connection $connection): void {
			foreach (self::EXPECTED_COLUMNS as $table => $columns) {
				self::verifyColumns($connection, $table, $columns);
				self::verifyEngine($connection, $table);
				self::verifyIndexes($connection, $table);
				self::verifyForeignKeys($connection, $table);
			}
		}
		
		/** @var array<string, array<int, string>> Expected column names per evaluation table */
		private const EXPECTED_COLUMNS = [
			'vogoo_models'              => ['id', 'objective', 'category', 'placement', 'source_mask',
				'context_key', 'feature_schema_version', 'artifact', 'trained_at', 'activated_at', 'status', 'active_marker'],
			'vogoo_impressions'         => ['id', 'category', 'placement', 'source_mask', 'context_key',
				'score_kind', 'member_id', 'shown_at'],
			'vogoo_impression_items'    => ['impression_id', 'item_id', 'position', 'ranking_score',
				'display_click_probability', 'model_id', 'feature_schema_version', 'feature_snapshot'],
			'vogoo_impression_evidence' => ['impression_id', 'item_id', 'source', 'raw_score',
				'source_rank', 'support_count', 'log_odds_contribution', 'contributing_item_ids'],
			'vogoo_outcomes'            => ['event_id', 'impression_id', 'item_id', 'event_type', 'occurred_at'],
		];
		
		/** @var array<string, array<string, array{0: bool, 1: array<int, string>}>> Expected unique flag and columns per index */
		private const EXPECTED_INDEXES = [
			'vogoo_models'              => [
				'PRIMARY'               => [true, ['id']],
				'uq_vogoo_active_model' => [true, ['objective', 'category', 'placement',
					'source_mask', 'context_key', 'active_marker']],
			],
			'vogoo_impressions'         => [
				'PRIMARY'                    => [true, ['id']],
				'ix_vogoo_impression_key'    => [false, ['category', 'placement', 'source_mask',
					'context_key', 'shown_at']],
				'ix_vogoo_impression_member' => [false, ['member_id', 'shown_at']],
			],
			'vogoo_impression_items'    => [
				'PRIMARY'                      => [true, ['impression_id', 'item_id']],
				'uq_vogoo_impression_position' => [true, ['impression_id', 'position']],
				'ix_vogoo_item_model'          => [false, ['model_id']],
			],
			'vogoo_impression_evidence' => [
				'PRIMARY' => [true, ['impression_id', 'item_id', 'source']],
			],
			'vogoo_outcomes'            => [
				'PRIMARY'               => [true, ['event_id']],
				'ix_vogoo_outcome_item' => [false, ['impression_id', 'item_id', 'event_type', 'occurred_at']],
			],
		];
		
		/** @var array<string, array<string, array{0: string, 1: array<int, string>, 2: array<int, string>, 3: string}>> Expected foreign keys per table */
		private const EXPECTED_FOREIGN_KEYS = [
			'vogoo_impression_items'    => [
				'fk_vogoo_item_impression' => ['vogoo_impressions', ['impression_id'], ['id'], 'CASCADE'],
				'fk_vogoo_item_model'      => ['vogoo_models', ['model_id'], ['id'], 'RESTRICT'],
			],
			'vogoo_impression_evidence' => [
				'fk_vogoo_evidence_item' => ['vogoo_impression_items',
					['impression_id', 'item_id'], ['impression_id', 'item_id'], 'CASCADE'],
			],
			'vogoo_outcomes'            => [
				'fk_vogoo_outcome_item' => ['vogoo_impression_items',
					['impression_id', 'item_id'], ['impression_id', 'item_id'], 'CASCADE'],
			],
		];
		
		/** @var array<string, array<int, string>> Columns allowed to be NULL per table */
		private const NULLABLE_COLUMNS = [
			'vogoo_models'              => ['activated_at', 'active_marker'],
			'vogoo_impressions'         => ['member_id'],
			'vogoo_impression_items'    => ['ranking_score', 'display_click_probability', 'model_id'],
			'vogoo_impression_evidence' => ['raw_score', 'source_rank', 'support_count',
				'log_odds_contribution', 'contributing_item_ids'],
			'vogoo_outcomes'            => [],
		];
		
		/** @var array<string, int> Required character length per column */
		private const COLUMN_LENGTHS = [
			'id'            => 16,
			'impression_id' => 16,
			'model_id'      => 16,
			'objective'     => 16,
			'placement'     => 64,
			'context_key'   => 128,
			'status'        => 16,
			'score_kind'    => 24,
			'source'        => 32,
			'event_id'      => 128,
			'event_type'    => 16,
		];
		
		/**
		 * Check that a table has exactly the expected columns with the expected types, nullability, and lengths.
		 * @param Connection $connection Evaluation database connection
		 * @param string $table Table name
		 * @param array<int, string> $columns Expected column names
		 * @return void
		 * @throws \RuntimeException When a column is missing, unexpected, or incompatible
		 */
		private static function verifyColumns(Connection $connection, string $table, array $columns): void {
			$rows = $connection->execute('
				SELECT
					COLUMN_NAME,
					DATA_TYPE,
					COLUMN_TYPE,
					IS_NULLABLE,
					COLLATION_NAME,
					EXTRA,
					CHARACTER_MAXIMUM_LENGTH,
					DATETIME_PRECISION,
					COLUMN_DEFAULT
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND
				      TABLE_NAME = :table
			', [
				'table' => $table,
			])->fetchAll('assoc');
			$actual = array_column($rows, 'COLUMN_NAME');
			
			if (array_diff($columns, $actual) !== [] || array_diff($actual, $columns) !== []) {
				throw new \RuntimeException("Existing {$table} table does not match the evaluation schema.");
			}
			
			$byName = array_column($rows, null, 'COLUMN_NAME');
			
			foreach ($columns as $column) {
				$definition = $byName[$column];
				$expectedType = self::expectedColumnType($column);
				$nullable = in_array($column, self::NULLABLE_COLUMNS[$table], true);
				
				if (
					self::hasIncompatibleType($definition, $column, $expectedType, $nullable) ||
					self::hasIncompatibleShape($definition, $column, $expectedType)
				) {
					throw new \RuntimeException("Existing {$table}.{$column} has an incompatible definition.");
				}
			}
		}
		
		/**
		 * Report whether a column's data type, nullability, unsigned flag or collation differ from the schema.
		 * @param array<string, string|int|float|bool|null> $definition information_schema row for the column
		 * @param string $column Column name
		 * @param string $expectedType Expected DATA_TYPE value
		 * @param bool $nullable Whether the column must allow NULL
		 * @return bool True when the definition differs
		 */
		private static function hasIncompatibleType(array $definition, string $column, string $expectedType, bool $nullable): bool {
			$unsigned = in_array($expectedType, ['int', 'bigint'], true);
			$asciiKey = in_array($column, ['placement', 'context_key', 'event_id'], true);
			
			return $definition['DATA_TYPE'] !== $expectedType ||
				($definition['IS_NULLABLE'] === 'YES') !== $nullable ||
				($unsigned && !str_contains((string)$definition['COLUMN_TYPE'], 'unsigned')) ||
				($asciiKey && $definition['COLLATION_NAME'] !== 'ascii_bin');
		}
		
		/**
		 * Report whether a column's length, precision, default or generated marker differ from the schema.
		 * @param array<string, string|int|float|bool|null> $definition information_schema row for the column
		 * @param string $column Column name
		 * @param string $expectedType Expected DATA_TYPE value
		 * @return bool True when the definition differs
		 */
		private static function hasIncompatibleShape(array $definition, string $column, string $expectedType): bool {
			return (isset(self::COLUMN_LENGTHS[$column]) &&
					(int)$definition['CHARACTER_MAXIMUM_LENGTH'] !== self::COLUMN_LENGTHS[$column]) ||
				($expectedType === 'datetime' && (int)$definition['DATETIME_PRECISION'] !== 6) ||
				($column === 'context_key' && $definition['COLUMN_DEFAULT'] !== '') ||
				($column === 'active_marker' && !str_contains((string)$definition['EXTRA'], 'GENERATED'));
		}
		
		/**
		 * Return the expected database data type of an evaluation column.
		 * @param string $column Column name
		 * @return string Expected DATA_TYPE value
		 */
		private static function expectedColumnType(string $column): string {
			return match ($column) {
				'id', 'impression_id', 'model_id' => 'binary',
				'artifact', 'feature_snapshot', 'contributing_item_ids' => 'json',
				'shown_at', 'occurred_at', 'trained_at', 'activated_at' => 'datetime',
				'ranking_score', 'display_click_probability', 'raw_score', 'log_odds_contribution' => 'double',
				'active_marker' => 'tinyint',
				'category', 'source_mask', 'member_id', 'item_id', 'position', 'source_rank',
				'feature_schema_version' => 'int',
				'support_count' => 'bigint',
				default => 'varchar',
			};
		}
		
		/**
		 * Check that a table uses InnoDB.
		 * @param Connection $connection Evaluation database connection
		 * @param string $table Table name
		 * @return void
		 * @throws \RuntimeException When the table does not use InnoDB
		 */
		private static function verifyEngine(Connection $connection, string $table): void {
			$engine = $connection->execute('
				SELECT
					ENGINE
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = DATABASE() AND
				      TABLE_NAME = :table
			', [
				'table' => $table,
			])->fetchAssoc();
			
			if ($engine['ENGINE'] !== 'InnoDB') {
				throw new \RuntimeException("Existing {$table} table must use InnoDB.");
			}
		}
		
		/**
		 * Check that a table's indexes match the expected definitions and have no prefix lengths.
		 * @param Connection $connection Evaluation database connection
		 * @param string $table Table name
		 * @return void
		 * @throws \RuntimeException When an index is incompatible or has a prefix length
		 */
		private static function verifyIndexes(Connection $connection, string $table): void {
			$indexes = $connection->execute('
				SELECT
					INDEX_NAME,
					COLUMN_NAME,
					NON_UNIQUE,
					SUB_PART
				FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = DATABASE() AND
				      TABLE_NAME = :table
				ORDER BY INDEX_NAME, SEQ_IN_INDEX
			', [
				'table' => $table,
			])->fetchAll('assoc');
			
			$actualIndexes = [];
			
			foreach ($indexes as $index) {
				$name = $index['INDEX_NAME'];
				$actualIndexes[$name][0] = (int)$index['NON_UNIQUE'] === 0;
				$actualIndexes[$name][1][] = $index['COLUMN_NAME'];
				
				if ($index['SUB_PART'] !== null) {
					throw new \RuntimeException("Existing {$table} has a prefix index.");
				}
			}
			
			foreach (self::EXPECTED_INDEXES[$table] as $name => $definition) {
				if (($actualIndexes[$name] ?? null) !== $definition) {
					throw new \RuntimeException("Existing {$table}.{$name} has an incompatible index definition.");
				}
			}
		}
		
		/**
		 * Check that a table's foreign keys match the expected referenced table, columns, and delete rule.
		 * @param Connection $connection Evaluation database connection
		 * @param string $table Table name
		 * @return void
		 * @throws \RuntimeException When a foreign key is incompatible
		 */
		private static function verifyForeignKeys(Connection $connection, string $table): void {
			foreach (self::EXPECTED_FOREIGN_KEYS[$table] ?? [] as $name => $definition) {
				$keys = $connection->execute('
					SELECT
						k.COLUMN_NAME,
						k.REFERENCED_TABLE_NAME,
						k.REFERENCED_COLUMN_NAME,
						r.DELETE_RULE
					FROM information_schema.KEY_COLUMN_USAGE k
					JOIN information_schema.REFERENTIAL_CONSTRAINTS r
					  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND
					     r.TABLE_NAME = k.TABLE_NAME AND
					     r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
					WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND
					      k.TABLE_NAME = :table AND
					      k.CONSTRAINT_NAME = :constraint
					ORDER BY k.ORDINAL_POSITION
				', [
					'table'      => $table,
					'constraint' => $name,
				])->fetchAll('assoc');
				
				$actualDefinition = $keys === [] ? null : [$keys[0]['REFERENCED_TABLE_NAME'],
					array_column($keys, 'COLUMN_NAME'), array_column($keys, 'REFERENCED_COLUMN_NAME'),
					$keys[0]['DELETE_RULE']];
				
				if ($actualDefinition !== $definition) {
					throw new \RuntimeException("Existing {$table}.{$name} has an incompatible foreign key.");
				}
			}
		}
	}
