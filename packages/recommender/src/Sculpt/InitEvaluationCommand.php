<?php

namespace Quellabs\Recommender\Sculpt;

use Quellabs\Sculpt\ConfigurationManager;
use Quellabs\Sculpt\Contracts\CommandBase;

/** Installs only the optional evaluation and model tables. */
class InitEvaluationCommand extends CommandBase {
    /** @return string Command signature. */
    public function getSignature(): string { return 'recommender:init-evaluation-db'; }

    /** @return string Short command description. */
    public function getDescription(): string { return 'Create or verify optional recommender evaluation tables'; }

    /** @return string Usage help. */
    public function getHelp(): string { return 'Usage: sculpt recommender:init-evaluation-db'; }

    /** @param ConfigurationManager $config CLI configuration
     * @return int Exit status
     */
    public function execute(ConfigurationManager $config): int {
        /** @var RecommenderProvider $provider */
        $provider = $this->provider;
        $connection = $provider->getConnection();
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
        $expected = [
            'recommender_models' => ['id', 'objective', 'category', 'placement', 'source_mask',
                'context_key', 'feature_schema_version', 'artifact', 'trained_at', 'activated_at', 'status', 'active_marker'],
            'recommender_impressions' => ['id', 'category', 'placement', 'source_mask', 'context_key',
                'score_kind', 'member_id', 'shown_at'],
            'recommender_impression_items' => ['impression_id', 'item_id', 'position', 'ranking_score',
                'display_click_probability', 'model_id', 'feature_schema_version', 'feature_snapshot'],
            'recommender_impression_evidence' => ['impression_id', 'item_id', 'source', 'raw_score',
                'source_rank', 'support_count', 'log_odds_contribution', 'contributing_item_ids'],
            'recommender_outcomes' => ['event_id', 'impression_id', 'item_id', 'event_type', 'occurred_at'],
        ];
        $expectedIndexes = [
            'recommender_models' => ['PRIMARY', 'uq_recommender_active_model'],
            'recommender_impressions' => ['PRIMARY', 'ix_recommender_impression_key',
                'ix_recommender_impression_member'],
            'recommender_impression_items' => ['PRIMARY', 'uq_recommender_impression_position',
                'ix_recommender_item_model'],
            'recommender_impression_evidence' => ['PRIMARY'],
            'recommender_outcomes' => ['PRIMARY', 'ix_recommender_outcome_item'],
        ];
        $expectedForeignKeys = [
            'recommender_impression_items' => ['fk_recommender_item_impression', 'fk_recommender_item_model'],
            'recommender_impression_evidence' => ['fk_recommender_evidence_item'],
            'recommender_outcomes' => ['fk_recommender_outcome_item'],
        ];
        $nullableColumns = [
            'recommender_models' => ['activated_at', 'active_marker'],
            'recommender_impressions' => ['member_id'],
            'recommender_impression_items' => ['ranking_score', 'display_click_probability', 'model_id'],
            'recommender_impression_evidence' => ['raw_score', 'source_rank', 'support_count',
                'log_odds_contribution', 'contributing_item_ids'],
            'recommender_outcomes' => [],
        ];
        foreach ($expected as $table => $columns) {
            $rows = $connection->execute('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE,
                COLLATION_NAME, EXTRA FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchAll('assoc');
            $actual = array_column($rows, 'COLUMN_NAME');
            if (array_diff($columns, $actual) !== [] || array_diff($actual, $columns) !== []) {
                throw new \RuntimeException("Existing {$table} table does not match the evaluation schema.");
            }
            $byName = array_column($rows, null, 'COLUMN_NAME');
            foreach ($columns as $column) {
                $type = $byName[$column]['DATA_TYPE'];
                $expectedType = match ($column) {
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
                $definition = $byName[$column];
                $nullable = in_array($column, $nullableColumns[$table], true);
                $unsigned = in_array($expectedType, ['int', 'bigint'], true);
                $asciiKey = in_array($column, ['placement', 'context_key', 'event_id'], true);
                if ($type !== $expectedType || ($definition['IS_NULLABLE'] === 'YES') !== $nullable
                    || ($unsigned && !str_contains((string)$definition['COLUMN_TYPE'], 'unsigned'))
                    || ($asciiKey && $definition['COLLATION_NAME'] !== 'ascii_bin')
                    || ($column === 'active_marker' && !str_contains((string)$definition['EXTRA'], 'GENERATED'))) {
                    throw new \RuntimeException("Existing {$table}.{$column} has an incompatible definition.");
                }
            }
            $engine = $connection->execute('SELECT ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchAssoc();
            if ($engine['ENGINE'] !== 'InnoDB') {
                throw new \RuntimeException("Existing {$table} table must use InnoDB.");
            }
            $indexes = $connection->execute('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchAll('assoc');
            if (array_diff($expectedIndexes[$table], array_column($indexes, 'INDEX_NAME')) !== []) {
                throw new \RuntimeException("Existing {$table} table is missing an evaluation index.");
            }
            if (isset($expectedForeignKeys[$table])) {
                $keys = $connection->execute('SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->fetchAll('assoc');
                if (array_diff($expectedForeignKeys[$table], array_column($keys, 'CONSTRAINT_NAME')) !== []) {
                    throw new \RuntimeException("Existing {$table} table is missing an evaluation foreign key.");
                }
            }
        }
        $this->output->success('Evaluation tables are ready.');
        return 0;
    }
}
