<?php

namespace Quellabs\Recommender\Sculpt;

use Cake\Database\Connection;
use Quellabs\Sculpt\ConfigurationManager;

/** Installs only the optional evaluation and model tables. */
class InitEvaluationCommand extends RecommenderCommand {
	
	/** @var array<string, array<int, string>> Expected column names per evaluation table */
	private const EXPECTED_COLUMNS = [
		'recommender_models'              => ['id', 'objective', 'category', 'placement', 'source_mask',
			'context_key', 'feature_schema_version', 'artifact', 'trained_at', 'activated_at', 'status', 'active_marker'],
		'recommender_impressions'         => ['id', 'category', 'placement', 'source_mask', 'context_key',
			'score_kind', 'member_id', 'shown_at'],
		'recommender_impression_items'    => ['impression_id', 'item_id', 'position', 'ranking_score',
			'display_click_probability', 'model_id', 'feature_schema_version', 'feature_snapshot'],
		'recommender_impression_evidence' => ['impression_id', 'item_id', 'source', 'raw_score',
			'source_rank', 'support_count', 'log_odds_contribution', 'contributing_item_ids'],
		'recommender_outcomes'            => ['event_id', 'impression_id', 'item_id', 'event_type', 'occurred_at'],
	];
	
	/** @var array<string, array<string, array{0: bool, 1: array<int, string>}>> Expected unique flag and columns per index */
	private const EXPECTED_INDEXES = [
		'recommender_models'              => [
			'PRIMARY'                     => [true, ['id']],
			'uq_recommender_active_model' => [true, ['objective', 'category', 'placement',
				'source_mask', 'context_key', 'active_marker']],
		],
		'recommender_impressions'         => [
			'PRIMARY'                          => [true, ['id']],
			'ix_recommender_impression_key'    => [false, ['category', 'placement', 'source_mask',
				'context_key', 'shown_at']],
			'ix_recommender_impression_member' => [false, ['member_id', 'shown_at']],
		],
		'recommender_impression_items'    => [
			'PRIMARY'                            => [true, ['impression_id', 'item_id']],
			'uq_recommender_impression_position' => [true, ['impression_id', 'position']],
			'ix_recommender_item_model'          => [false, ['model_id']],
		],
		'recommender_impression_evidence' => [
			'PRIMARY' => [true, ['impression_id', 'item_id', 'source']],
		],
		'recommender_outcomes'            => [
			'PRIMARY'                     => [true, ['event_id']],
			'ix_recommender_outcome_item' => [false, ['impression_id', 'item_id', 'event_type', 'occurred_at']],
		],
	];
	
	/** @var array<string, array<string, array{0: string, 1: array<int, string>, 2: array<int, string>, 3: string}>> Expected foreign keys per table */
	private const EXPECTED_FOREIGN_KEYS = [
		'recommender_impression_items'    => [
			'fk_recommender_item_impression' => ['recommender_impressions', ['impression_id'], ['id'], 'CASCADE'],
			'fk_recommender_item_model'      => ['recommender_models', ['model_id'], ['id'], 'RESTRICT'],
		],
		'recommender_impression_evidence' => [
			'fk_recommender_evidence_item' => ['recommender_impression_items',
				['impression_id', 'item_id'], ['impression_id', 'item_id'], 'CASCADE'],
		],
		'recommender_outcomes'            => [
			'fk_recommender_outcome_item' => ['recommender_impression_items',
				['impression_id', 'item_id'], ['impression_id', 'item_id'], 'CASCADE'],
		],
	];
	
	/** @var array<string, array<int, string>> Columns allowed to be NULL per table */
	private const NULLABLE_COLUMNS = [
		'recommender_models'              => ['activated_at', 'active_marker'],
		'recommender_impressions'         => ['member_id'],
		'recommender_impression_items'    => ['ranking_score', 'display_click_probability', 'model_id'],
		'recommender_impression_evidence' => ['raw_score', 'source_rank', 'support_count',
			'log_odds_contribution', 'contributing_item_ids'],
		'recommender_outcomes'            => [],
	];
	
	/** @var array<string, int> Required character length per column */
	private const COLUMN_LENGTHS = [
		'id'        => 16, 'impression_id' => 16, 'model_id' => 16,
		'objective' => 16, 'placement' => 64, 'context_key' => 128,
		'status'    => 16, 'score_kind' => 24, 'source' => 32,
		'event_id'  => 128, 'event_type' => 16,
	];
	
	/**
	 * Return the command signature.
	 * @return string Command signature
	 */
	public function getSignature(): string {
		return 'recommender:init-evaluation-db';
	}
	
	/**
	 * Return the short command description.
	 * @return string Short command description
	 */
	public function getDescription(): string {
		return 'Create or verify optional recommender evaluation tables';
	}
	
	/**
	 * Return the usage help.
	 * @return string Usage help
	 */
	public function getHelp(): string {
		return 'Usage: sculpt recommender:init-evaluation-db';
	}
	
	/**
	 * Apply the evaluation migration, then verify each evaluation table against the expected schema.
	 * @param ConfigurationManager $config CLI configuration
	 * @return int Exit status
	 * @throws \RuntimeException When the migration file is missing or a table does not match the schema
	 */
	public function execute(ConfigurationManager $config): int {
		$connection = $this->getRecommenderProvider()->getConnection();

		$this->applyMigration($connection);
		
		foreach (self::EXPECTED_COLUMNS as $table => $columns) {
			$this->verifyColumns($connection, $table, $columns);
			$this->verifyEngine($connection, $table);
			$this->verifyIndexes($connection, $table);
			$this->verifyForeignKeys($connection, $table);
		}
		
		$this->output->success('Evaluation tables are ready.');
		return 0;
	}
	
	/**
	 * Run the evaluation migration statements, skipping SQL comment lines.
	 * @param Connection $connection Evaluation database connection
	 * @return void
	 * @throws \RuntimeException When the migration file is missing or cannot be parsed
	 */
	private function applyMigration(Connection $connection): void {
		$path = dirname(__DIR__, 2) . '/migrations/2026-10-evaluation-tables.sql';
		$sql = file_get_contents($path);
		
		if ($sql === false) {
			throw new \RuntimeException('Evaluation migration file is missing.');
		}
		
		$withoutComments = preg_replace('/^--.*$/m', '', $sql);
		
		if ($withoutComments === null) {
			throw new \RuntimeException('Could not parse evaluation migration.');
		}
		
		$statements = array_filter(array_map('trim', explode(';', $withoutComments)));
		
		foreach ($statements as $statement) {
			$connection->execute($statement);
		}
	}
	
	/**
	 * Check that a table has exactly the expected columns with the expected types, nullability, and lengths.
	 * @param Connection $connection Evaluation database connection
	 * @param string $table Table name
	 * @param array<int, string> $columns Expected column names
	 * @return void
	 * @throws \RuntimeException When a column is missing, unexpected, or incompatible
	 */
	private function verifyColumns(Connection $connection, string $table, array $columns): void {
		$rows = $connection->execute('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE,
            COLLATION_NAME, EXTRA, CHARACTER_MAXIMUM_LENGTH, DATETIME_PRECISION,
            COLUMN_DEFAULT FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchAll('assoc');
		$actual = array_column($rows, 'COLUMN_NAME');
		
		if (array_diff($columns, $actual) !== [] || array_diff($actual, $columns) !== []) {
			throw new \RuntimeException("Existing {$table} table does not match the evaluation schema.");
		}
		
		$byName = array_column($rows, null, 'COLUMN_NAME');
		
		foreach ($columns as $column) {
			$definition = $byName[$column];
			$expectedType = self::expectedColumnType($column);
			$nullable = in_array($column, self::NULLABLE_COLUMNS[$table], true);
			$unsigned = in_array($expectedType, ['int', 'bigint'], true);
			$asciiKey = in_array($column, ['placement', 'context_key', 'event_id'], true);
			
			if ($definition['DATA_TYPE'] !== $expectedType || ($definition['IS_NULLABLE'] === 'YES') !== $nullable
				|| ($unsigned && !str_contains((string)$definition['COLUMN_TYPE'], 'unsigned'))
				|| ($asciiKey && $definition['COLLATION_NAME'] !== 'ascii_bin')
				|| (isset(self::COLUMN_LENGTHS[$column])
					&& (int)$definition['CHARACTER_MAXIMUM_LENGTH'] !== self::COLUMN_LENGTHS[$column])
				|| ($expectedType === 'datetime' && (int)$definition['DATETIME_PRECISION'] !== 6)
				|| ($column === 'context_key' && $definition['COLUMN_DEFAULT'] !== '')
				|| ($column === 'active_marker' && !str_contains((string)$definition['EXTRA'], 'GENERATED'))) {
				throw new \RuntimeException("Existing {$table}.{$column} has an incompatible definition.");
			}
		}
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
	private function verifyEngine(Connection $connection, string $table): void {
		$engine = $connection->execute('SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchAssoc();
		
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
	private function verifyIndexes(Connection $connection, string $table): void {
		$indexes = $connection->execute('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$table])->fetchAll('assoc');
		
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
	private function verifyForeignKeys(Connection $connection, string $table): void {
		foreach (self::EXPECTED_FOREIGN_KEYS[$table] ?? [] as $name => $definition) {
			$keys = $connection->execute('SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                k.REFERENCED_COLUMN_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
                JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                 AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ?
                ORDER BY k.ORDINAL_POSITION', [$table, $name])->fetchAll('assoc');
			
			$actualDefinition = $keys === [] ? null : [$keys[0]['REFERENCED_TABLE_NAME'],
				array_column($keys, 'COLUMN_NAME'), array_column($keys, 'REFERENCED_COLUMN_NAME'),
				$keys[0]['DELETE_RULE']];
				
			if ($actualDefinition !== $definition) {
				throw new \RuntimeException("Existing {$table}.{$name} has an incompatible foreign key.");
			}
		}
	}
}
