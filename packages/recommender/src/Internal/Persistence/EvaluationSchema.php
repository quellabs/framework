<?php

namespace Quellabs\Recommender\Internal\Persistence;

use Cake\Database\Connection;

/** Detects the optional evaluation installation before recording or training. */
final class EvaluationSchema {
    /** @param Connection $connection Evaluation database
     * @return void Throws when any required table is absent
     */
    public static function requireTables(Connection $connection): void {
        $tables = ['recommender_models', 'recommender_impressions', 'recommender_impression_items',
            'recommender_impression_evidence', 'recommender_outcomes'];
        $rows = $connection->execute('SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?,?,?,?,?)', $tables)->fetchAll('assoc');
        if (count($rows) !== count($tables)) {
            throw new \RuntimeException('Recommender evaluation tables are missing; run recommender:init-evaluation-db.');
        }
    }
}
