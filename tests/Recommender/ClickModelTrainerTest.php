<?php

namespace Quellabs\Recommender\Tests;

use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use DateTimeImmutable;
use Quellabs\Recommender\Internal\Model\ClickModelTrainer;
use Quellabs\Recommender\RecommendationSource;

/** Mature-label and activation integration coverage. */
class ClickModelTrainerTest extends IntegrationTestCase {
    /** @return void */
    protected function setUp(): void {
        parent::setUp();
        $sql = file_get_contents(__DIR__ . '/../../packages/recommender/migrations/2026-10-evaluation-tables.sql');
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $statement) {
            $this->connection->execute($statement);
        }
        $this->connection->execute("DELETE FROM vogoo_impressions WHERE placement = 'train_test'");
        $this->connection->execute("DELETE FROM vogoo_models WHERE placement = 'train_test'");
    }

    /** @return void */
    public function testTrainingRequiresMatureCohort(): void {
        $trainer = new ClickModelTrainer($this->connection);
        $this->expectException(\RuntimeException::class);
        $trainer->train(1, 'train_test', [RecommendationSource::NewProducts],
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-02T00:00:00Z'),
            new DateTimeImmutable('2026-01-03T00:00:00Z'), 3600);
    }

    /** @return void */
    public function testRejectedCandidateCannotReplaceAnActiveModel(): void {
        $active = bin2hex(random_bytes(16));
        $rejected = bin2hex(random_bytes(16));
        foreach ([[$active, 'active', true], [$rejected, 'rejected', false]] as [$id, $status, $validated]) {
            $this->connection->execute('INSERT INTO vogoo_models
                (id, objective, category, placement, source_mask, context_key,
                feature_schema_version, artifact, trained_at, status)
                VALUES (UNHEX(?), \'click\', 1, \'train_test\', 16, \'\', 1, ?, UTC_TIMESTAMP(6), ?)',
                [$id, json_encode(['validated' => $validated], JSON_THROW_ON_ERROR), $status]);
        }
        try {
            try {
                (new ClickModelTrainer($this->connection))->activate($rejected);
                $this->fail('Rejected model was activated.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
            $row = $this->connection->execute('SELECT status FROM vogoo_models WHERE id = UNHEX(?)',
                [$active])->fetchAssoc();
            $this->assertSame('active', $row['status']);
        } finally {
            $this->connection->execute("DELETE FROM vogoo_models WHERE placement = 'train_test'");
        }
    }

    /** @return void */
    public function testChronologicalHoldoutKeepsWholeImpressionsTogether(): void {
        $snapshot = json_encode(['features' => [
            'new_products.log_depth_searched' => log(50),
            'new_products.present' => 1.0,
            'new_products.reciprocal_rank' => 1 / 61,
            'new_products.score' => 0.0,
            'new_products.count' => 0.0,
        ], 'depth_searched' => ['new_products' => 50]], JSON_THROW_ON_ERROR);
        $rows = [];
        for ($impression = 1; $impression <= 626; $impression++) {
            $shown = (new DateTimeImmutable('2026-01-01T00:00:00Z'))
                ->modify('+' . $impression . ' seconds')->format('Y-m-d H:i:s.u');
            foreach ([1, 2] as $position) {
                $features = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
                $features['features']['new_products.present'] = $position === 1 ? 1.0 : 0.0;
                $features['features']['new_products.reciprocal_rank'] = $position === 1 ? 1 / 61 : 0.0;
                $rows[] = ['impression_id' => sprintf('%032X', $impression),
                    'shown_at' => $shown, 'position' => $position,
                    'feature_snapshot' => json_encode($features, JSON_THROW_ON_ERROR),
                    'clicked' => $position === 1 ? 1 : 0];
            }
        }
        $tables = $this->createMock(StatementInterface::class);
        $tables->method('fetchAll')->willReturn(array_map(fn($name) => ['TABLE_NAME' => $name],
            ['vogoo_models', 'vogoo_impressions', 'vogoo_impression_items',
                'vogoo_impression_evidence', 'vogoo_outcomes']));
        $trainingRows = $this->createMock(StatementInterface::class);
        $trainingRows->method('fetchAll')->willReturn($rows);
        $insert = $this->createMock(StatementInterface::class);
        $artifact = null;
        $selectParams = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('execute')->willReturnCallback(function (string $sql, array $params = [])
            use ($tables, $trainingRows, $insert, &$artifact, &$selectParams): StatementInterface {
            if (str_contains($sql, 'information_schema.TABLES')) {
                return $tables;
            }
            if (str_contains($sql, 'HEX(i.id) AS impression_id')) {
                $selectParams = $params;
                $this->assertStringContainsString('ORDER BY i.shown_at ASC, i.id ASC', $sql);
                $this->assertStringContainsString('TIMESTAMPADD(SECOND, :mature_window', $sql);
                return $trainingRows;
            }
            $this->assertStringContainsString('INSERT INTO vogoo_models', $sql);
            $artifact = json_decode($params[5], true, 512, JSON_THROW_ON_ERROR);
            return $insert;
        });
        (new ClickModelTrainer($connection))->train(1, 'train_test',
            [RecommendationSource::NewProducts], new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new DateTimeImmutable('2026-01-02T00:00:00Z'),
            new DateTimeImmutable('2026-01-03T00:00:00Z'), 3600, 'tenant-a');
        $this->assertSame('tenant-a', $selectParams['context']);
        $this->assertSame(1000, $artifact['training_items']);
        $this->assertSame(252, $artifact['holdout_items']);
        $this->assertSame(500, $artifact['training_clicks']);
        $this->assertSame(126, $artifact['holdout_clicks']);
        $this->assertSame('click', $artifact['objective']);
        $this->assertSame(sprintf('%032x', 500), $artifact['training_interval']['last_impression_id']);
        $this->assertSame(sprintf('%032x', 501), $artifact['holdout_interval']['first_impression_id']);
        $this->assertLessThanOrEqual($artifact['holdout_interval']['first_shown_at'],
            $artifact['training_interval']['last_shown_at']);
    }

    /** @return void */
    public function testTrainingAndActivationUseMatureDisplayedItems(): void {
        $impressions = [];
        $items = [];
        $outcomes = [];
        for ($index = 1; $index <= 1250; $index++) {
            $hex = sprintf('%032x', $index);
            $shown = sprintf('2026-01-01 00:%02d:%02d.000000', intdiv($index - 1, 60) % 60, ($index - 1) % 60);
            $impressions[] = [$hex, $shown];
            $items[] = [$hex, json_encode(['features' => [
                'new_products.log_depth_searched' => log(50),
                'new_products.present' => (float)($index % 2),
                'new_products.reciprocal_rank' => $index % 2 ? 1 / 61 : 0.0,
                'new_products.score' => 0.0,
                'new_products.count' => 0.0,
            ], 'depth_searched' => ['new_products' => 50]], JSON_THROW_ON_ERROR)];
            if ($index % 2 === 1) {
                $outcomes[] = [$index, $hex, $shown];
            }
        }
        foreach (array_chunk($impressions, 100) as $batch) {
            $parts = [];
            $params = [];
            foreach ($batch as [$hex, $shown]) {
                $parts[] = "(UNHEX(?), 1, 'train_test', 16, '', 'rank_fusion', ?)";
                array_push($params, $hex, $shown);
            }
            $this->connection->execute('INSERT INTO vogoo_impressions
                (id, category, placement, source_mask, context_key, score_kind, shown_at) VALUES '
                . implode(',', $parts), $params);
        }
        foreach (array_chunk($items, 100) as $batch) {
            $parts = [];
            $params = [];
            foreach ($batch as [$hex, $snapshot]) {
                $parts[] = '(UNHEX(?), 42, 1, 0.016, 1, ?)';
                array_push($params, $hex, $snapshot);
            }
            $this->connection->execute('INSERT INTO vogoo_impression_items
                (impression_id, item_id, position, ranking_score, feature_schema_version, feature_snapshot)
                VALUES ' . implode(',', $parts), $params);
        }
        foreach (array_chunk($outcomes, 100) as $batch) {
            $parts = [];
            $params = [];
            foreach ($batch as [$index, $hex, $shown]) {
                $parts[] = "(?, UNHEX(?), 42, 'click', ?)";
                array_push($params, 'training-event-' . $index, $hex, $shown);
            }
            $this->connection->execute('INSERT INTO vogoo_outcomes
                (event_id, impression_id, item_id, event_type, occurred_at) VALUES '
                . implode(',', $parts), $params);
        }
        $immatureId = sprintf('%032x', 9999);
        $this->connection->execute('INSERT INTO vogoo_impressions
            (id, category, placement, source_mask, context_key, score_kind, shown_at)
            VALUES (UNHEX(?), 1, \'train_test\', 16, \'\', \'rank_fusion\', \'2026-01-02 23:59:00\')',
            [$immatureId]);
        $this->connection->execute('INSERT INTO vogoo_impression_items
            (impression_id, item_id, position, ranking_score, feature_schema_version, feature_snapshot)
            VALUES (UNHEX(?), 42, 1, 0.016, 1, ?)', [$immatureId, $items[0][1]]);
        $trainer = new ClickModelTrainer($this->connection);
        try {
            $id = $trainer->train(1, 'train_test', [RecommendationSource::NewProducts],
                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                new DateTimeImmutable('2026-01-03T00:00:00Z'),
                new DateTimeImmutable('2026-01-03T00:00:00Z'), 3600);
            $artifactRow = $this->connection->execute('SELECT artifact FROM vogoo_models
                WHERE id = UNHEX(?)', [$id])->fetchAssoc();
            $artifact = json_decode((string)$artifactRow['artifact'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1000, $artifact['training_items']);
            $this->assertSame(250, $artifact['holdout_items']);
            $this->assertSame('click', $artifact['objective']);
            $this->assertArrayHasKey('training_interval', $artifact);
            $this->assertArrayHasKey('holdout_interval', $artifact);
            $trainer->activate($id);
            $row = $this->connection->execute('SELECT status FROM vogoo_models
                WHERE id = UNHEX(?)', [$id])->fetchAssoc();
            $this->assertSame('active', $row['status']);
        } finally {
            $this->connection->execute("DELETE FROM vogoo_impressions WHERE placement = 'train_test'");
            $this->connection->execute("DELETE FROM vogoo_models WHERE placement = 'train_test'");
        }
    }
}
