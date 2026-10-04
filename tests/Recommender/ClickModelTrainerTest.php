<?php

namespace Quellabs\Recommender\Tests;

use DateTimeImmutable;
use Quellabs\Recommender\ClickModelTrainer;
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
        $this->connection->execute("DELETE FROM recommender_impressions WHERE placement = 'train_test'");
        $this->connection->execute("DELETE FROM recommender_models WHERE placement = 'train_test'");
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
            $this->connection->execute('INSERT INTO recommender_models
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
            $row = $this->connection->execute('SELECT status FROM recommender_models WHERE id = UNHEX(?)',
                [$active])->fetchAssoc();
            $this->assertSame('active', $row['status']);
        } finally {
            $this->connection->execute("DELETE FROM recommender_models WHERE placement = 'train_test'");
        }
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
            $this->connection->execute('INSERT INTO recommender_impressions
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
            $this->connection->execute('INSERT INTO recommender_impression_items
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
            $this->connection->execute('INSERT INTO recommender_outcomes
                (event_id, impression_id, item_id, event_type, occurred_at) VALUES '
                . implode(',', $parts), $params);
        }
        $trainer = new ClickModelTrainer($this->connection);
        try {
            $id = $trainer->train(1, 'train_test', [RecommendationSource::NewProducts],
                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                new DateTimeImmutable('2026-01-02T00:00:00Z'),
                new DateTimeImmutable('2026-01-03T00:00:00Z'), 3600);
            $trainer->activate($id);
            $row = $this->connection->execute('SELECT status FROM recommender_models
                WHERE id = UNHEX(?)', [$id])->fetchAssoc();
            $this->assertSame('active', $row['status']);
        } finally {
            $this->connection->execute("DELETE FROM recommender_impressions WHERE placement = 'train_test'");
            $this->connection->execute("DELETE FROM recommender_models WHERE placement = 'train_test'");
        }
    }
}
