<?php

namespace Quellabs\Recommender\Tests;

use DateTimeImmutable;
use Quellabs\Recommender\ScoreKind;
use Quellabs\Recommender\Evaluation\AttributionWindows;
use Quellabs\Recommender\Evaluation\EvaluationRecorder;
use Quellabs\Recommender\Evaluation\EvaluationReport;
use Quellabs\Recommender\Evaluation\ImpressionId;
use Quellabs\Recommender\Evaluation\OutcomeType;
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\Reconciliation\RecommendationReconciler;
use Quellabs\Recommender\Reconciliation\ReconciliationRequest;
use Quellabs\Recommender\Reconciliation\ReconciledRecommendation;
use Quellabs\Recommender\RecommendationList;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\SourceEvidence;
use Quellabs\Recommender\Sculpt\InitEvaluationCommand;
use Quellabs\Recommender\Sculpt\PruneEvaluationCommand;
use Quellabs\Recommender\Sculpt\RecommenderProvider;
use Quellabs\Sculpt\ConfigurationManager;
use Quellabs\Sculpt\Console\ConsoleInput;
use Quellabs\Sculpt\Console\ConsoleOutput;

/** Opt-in evaluation persistence and attribution checks. */
class EvaluationTest extends IntegrationTestCase {
    /** @return void */
    protected function setUp(): void {
        parent::setUp();
        $sql = file_get_contents(__DIR__ . '/../../packages/recommender/migrations/2026-10-evaluation-tables.sql');
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $statement) {
            $this->connection->execute($statement);
        }
        $this->connection->execute('DELETE FROM vogoo_impressions');
    }

    /** @return void */
    public function testOptInRecordingRetryAndAttribution(): void {
        $recorder = new EvaluationRecorder($this->connection);
        $report = new EvaluationReport($this->connection);
        $item = new ReconciledRecommendation(42, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $list = RecommendationList::fromDisplayedItems(1, 'home', [$item]);
        $shown = new DateTimeImmutable('2026-01-01T12:00:00+00:00');
        $end = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $windows = new AttributionWindows(3600, 7200);
        $empty = $report->summary(1, $shown, $end, $end, $windows);
        $this->assertSame(0, $empty->impressions);
        $this->assertSame(0.0, $empty->clickThroughRate());
        $id = $recorder->recordImpression($list, 7, $shown);
        $click = new DateTimeImmutable('2026-01-01T13:00:00+00:00');
        $recorder->recordOutcome($id, 42, 'event-1', OutcomeType::Click, $click);
        $recorder->recordOutcome($id, 42, 'event-1', OutcomeType::Click, $click);
        $recorder->recordOutcome($id, 42, 'event-2', OutcomeType::Click, $click);
        $summary = $report->summary(1, $shown, $end, $end, $windows);
        $this->assertSame(1, $summary->impressions);
        $this->assertSame(1, $summary->clickedItems);
        $this->assertSame(1.0, $summary->clickThroughRate());
        $this->assertSame(1, $report->summary(1, $shown, $end, $end, $windows,
            RecommendationSource::NewProducts)->clickedItems);
        $this->assertSame(0, $report->summary(1, $shown, $end, $end, $windows,
            RecommendationSource::SlopeOne)->impressions);
        $recorder->recordOutcome($id, 42, 'purchase-1', OutcomeType::Purchase, $click);
        $recorder->recordOutcome($id, 42, 'purchase-2', OutcomeType::Purchase, $click);
        $this->assertSame(1, $report->summary(1, $shown, $end, $end, $windows)->purchasedItems);
        try {
            $recorder->recordOutcome($id, 42, 'event-1', OutcomeType::Purchase, $click);
            $this->fail('Conflicting event IDs must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $recorder->deleteMemberHistory(7);
        $this->assertSame(0, $report->summary(1, $shown, $end, $end, $windows)->impressions);
    }

    /** @return void */
    public function testOutcomeBoundariesAndAsOfCutoff(): void {
        $recorder = new EvaluationRecorder($this->connection);
        $item = new ReconciledRecommendation(42, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $other = new ReconciledRecommendation(43, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 2)]);
        $shown = new DateTimeImmutable('2026-01-01T12:00:00Z');
        $id = $recorder->recordImpression(RecommendationList::fromDisplayedItems(1, 'home', [$item, $other]),
            null, $shown);
        try {
            $recorder->recordOutcome($id, 42, 'too-early', OutcomeType::Click,
                new DateTimeImmutable('2026-01-01T11:59:59Z'));
            $this->fail('Outcome before display was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $recorder->recordOutcome($id, 42, 'exact-boundary', OutcomeType::Click,
            new DateTimeImmutable('2026-01-01T13:00:00Z'));
        $recorder->recordOutcome($id, 43, 'past-boundary', OutcomeType::Click,
            new DateTimeImmutable('2026-01-01T13:00:01Z'));
        $report = new EvaluationReport($this->connection);
        $end = new DateTimeImmutable('2026-01-02T00:00:00Z');
        $windows = new AttributionWindows(3600, 3600);
        $before = $report->summary(1, $shown, $end, $end, $windows);
        $this->assertSame(2, $before->impressions);
        $this->assertSame(1, $before->clickedItems);
        $this->assertSame(0.5, $before->clickThroughRate());
        $short = $report->summary(1, $shown, $end, $end, new AttributionWindows(3599, 3600));
        $this->assertSame(0, $short->clickedItems);
    }

    /** @return void */
    public function testAsOfExcludesAnOutcomeThatArrivesAfterTheReportCutoff(): void {
        $recorder = new EvaluationRecorder($this->connection);
        $item = new ReconciledRecommendation(42, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $shown = new DateTimeImmutable('2026-01-01T23:30:00Z');
        $id = $recorder->recordImpression(RecommendationList::fromDisplayedItems(1, 'home', [$item]),
            null, $shown);
        $recorder->recordOutcome($id, 42, 'next-day-click', OutcomeType::Click,
            new DateTimeImmutable('2026-01-02T00:15:00Z'));
        $report = new EvaluationReport($this->connection);
        $end = new DateTimeImmutable('2026-01-02T00:00:00Z');
        $windows = new AttributionWindows(3600, 3600);
        $this->assertSame(0, $report->summary(1, $shown, $end, $end, $windows)->clickedItems);
        $this->assertSame(1, $report->summary(1, $shown, $end,
            new DateTimeImmutable('2026-01-03T00:00:00Z'), $windows)->clickedItems);
    }

    /** @return void */
    public function testReportSeparatesCategoryAndContextPartitions(): void {
        $recorder = new EvaluationRecorder($this->connection);
        $item = new ReconciledRecommendation(42, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $shown = new DateTimeImmutable('2026-01-01T00:00:00Z');
        foreach ([[1, null], [1, 'tenant-a'], [2, null]] as [$category, $context]) {
            $recorder->recordImpression(RecommendationList::fromDisplayedItems(
                $category, 'home', [$item], $context), null, $shown);
        }
        $report = new EvaluationReport($this->connection);
        $end = new DateTimeImmutable('2026-01-02T00:00:00Z');
        $windows = new AttributionWindows(3600, 3600);
        $this->assertSame(1, $report->summary(1, $shown, $end, $end, $windows)->impressions);
        $this->assertSame(1, $report->summary(1, $shown, $end, $end, $windows,
            contextKey: 'tenant-a')->impressions);
        $this->assertSame(1, $report->summary(2, $shown, $end, $end, $windows)->impressions);
        $this->assertSame(0, $report->summary(2, $shown, $end, $end, $windows,
            contextKey: 'tenant-a')->impressions);
    }

    /** @return void */
    public function testRecorderRejectsInvalidEventIdsAndUndisplayedItems(): void {
        $recorder = new EvaluationRecorder($this->connection);
        $item = new ReconciledRecommendation(42, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $id = $recorder->recordImpression(RecommendationList::fromDisplayedItems(1, 'home', [$item]),
            null, new DateTimeImmutable('2026-01-01T00:00:00Z'));
        foreach (['', "bad\nevent", str_repeat('x', 129)] as $eventId) {
            try {
                $recorder->recordOutcome($id, 42, $eventId, OutcomeType::Click,
                    new DateTimeImmutable('2026-01-01T00:01:00Z'));
                $this->fail('Invalid event ID was accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $recorder->recordOutcome($id, 43, 'unshown', OutcomeType::Click,
            new DateTimeImmutable('2026-01-01T00:01:00Z'));
    }

    /** @return void */
    public function testEvaluationFailureDoesNotRollBackAnExistingRatingWrite(): void {
        $this->insertRating(7, 42, 0.8);
        $item = new ReconciledRecommendation(42, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $list = RecommendationList::fromDisplayedItems(1, 'home', [$item]);
        try {
            (new EvaluationRecorder($this->connection))->recordImpression($list, -1);
            $this->fail('Invalid member ID was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $this->assertNotNull($this->fetchRatingRow(7, 42));
        $count = $this->connection->execute('SELECT COUNT(*) AS total FROM vogoo_impressions')
            ->fetchAssoc();
        $this->assertSame(0, (int)$count['total']);
    }

    /** @return void */
    public function testImpressionIdRejectsInvalidTokens(): void {
        $this->expectException(\InvalidArgumentException::class);
        new ImpressionId('not-a-token');
    }

    /** @return void */
    public function testActiveModelScoresReferenceAndActualPositions(): void {
        $names = ['log_position', 'new_products.log_depth_searched', 'new_products.present',
            'new_products.reciprocal_rank', 'new_products.score', 'new_products.count'];
        $coefficients = array_fill_keys($names, 0.0);
        $coefficients['log_position'] = -1.0;
        $artifact = ['feature_schema_version' => 1, 'intercept' => 0.0,
            'coefficients' => $coefficients,
            'means' => array_fill_keys($names, 0.0), 'scales' => array_fill_keys($names, 1.0),
            'validated' => true];
        $modelId = bin2hex(random_bytes(16));
        $this->connection->execute('INSERT INTO vogoo_models
            (id, objective, category, placement, source_mask, context_key,
            feature_schema_version, artifact, trained_at, status)
            VALUES (UNHEX(?), \'click\', 1, \'home\', 16, \'\', 1, ?, UTC_TIMESTAMP(6), \'active\')',
            [$modelId, json_encode($artifact, JSON_THROW_ON_ERROR)]);
        try {
            $request = new ReconciliationRequest(new ArrayEligibilityProvider([40, 41]),
                [RecommendationSource::NewProducts], 2, 'home', [40, 41]);
            $list = (new RecommendationReconciler($this->connection, $this->config))
                ->recommendMember(7, $request);
            $this->assertSame(ScoreKind::ClickProbability, $list->scoreKind);
            $otherContext = new ReconciliationRequest(new ArrayEligibilityProvider([40, 41]),
                [RecommendationSource::NewProducts], 2, 'home', [40, 41], contextKey: 'tenant-x');
            $this->assertSame(ScoreKind::RankFusion,
                (new RecommendationReconciler($this->connection, $this->config))
                    ->recommendMember(7, $otherContext)->scoreKind);
            $otherPlacement = new ReconciliationRequest(new ArrayEligibilityProvider([40, 41]),
                [RecommendationSource::NewProducts], 2, 'other', [40, 41]);
            $otherCategory = new ReconciliationRequest(new ArrayEligibilityProvider([40, 41]),
                [RecommendationSource::NewProducts], 2, 'home', [40, 41], category: 2);
            $this->assertSame(ScoreKind::RankFusion,
                (new RecommendationReconciler($this->connection, $this->config))
                    ->recommendMember(7, $otherPlacement)->scoreKind);
            $this->assertSame(ScoreKind::RankFusion,
                (new RecommendationReconciler($this->connection, $this->config))
                    ->recommendMember(7, $otherCategory)->scoreKind);
            $this->assertSame($modelId, $list->modelId);
            $this->assertSame(0.5, $list->items[0]->rankingScore);
            $recorder = new EvaluationRecorder($this->connection);
            $impressionId = $recorder->recordImpression($list, null,
                new DateTimeImmutable('2026-01-01T00:00:00Z'));
            $recorder->recordOutcome($impressionId, $list->items[0]->itemId, 'model-click',
                OutcomeType::Click, new DateTimeImmutable('2026-01-01T00:01:00Z'));
            $rows = $this->connection->execute('SELECT position, display_click_probability
                FROM vogoo_impression_items WHERE impression_id = ? ORDER BY position',
                [$impressionId->binary()])->fetchAll('assoc');
            $this->assertEqualsWithDelta(0.5, (float)$rows[0]['display_click_probability'], 0.00001);
            $this->assertLessThan(0.5, (float)$rows[1]['display_click_probability']);
            $calibration = (new EvaluationReport($this->connection))->calibrationByModel(
                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                new DateTimeImmutable('2026-01-02T00:00:00Z'),
                new DateTimeImmutable('2026-01-03T00:00:00Z'), 3600);
            $this->assertSame(2, $calibration[$modelId . ':home']['impressions']);
            $this->assertSame(0.5, $calibration[$modelId . ':home']['observed_click_rate']);
            $this->connection->execute('UPDATE vogoo_models SET feature_schema_version = 2
                WHERE id = UNHEX(?)', [$modelId]);
            try {
                (new RecommendationReconciler($this->connection, $this->config))
                    ->recommendMember(7, $request);
                $this->fail('An incompatible active feature schema was accepted.');
            } catch (\UnexpectedValueException) {
                $this->assertTrue(true);
            }
        } finally {
            $this->connection->execute('DELETE FROM vogoo_impressions');
            $this->connection->execute('DELETE FROM vogoo_models WHERE id = UNHEX(?)', [$modelId]);
        }
    }

    /** @return void */
    public function testEvaluationInitIsIdempotentAndPruneRequiresExplicitCutoff(): void {
        $provider = new class($this->connection) extends RecommenderProvider {
            /** @param \Cake\Database\Connection $database Test connection. */
            public function __construct(private \Cake\Database\Connection $database) {}
            /** @return \Cake\Database\Connection Test connection. */
            public function getConnection(): \Cake\Database\Connection { return $this->database; }
        };
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream);
        $input = new ConsoleInput($output);
        $this->assertSame(0, (new InitEvaluationCommand($input, $output, $provider))
            ->execute(new ConfigurationManager()));
        $prune = new PruneEvaluationCommand($input, $output, $provider);
        try {
            $prune->execute(new ConfigurationManager());
            $this->fail('Pruning without a cutoff should fail.');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $item = new ReconciledRecommendation(42, null,
            [new SourceEvidence(RecommendationSource::NewProducts, sourceRank: 1)]);
        $recorder = new EvaluationRecorder($this->connection);
        $impressionId = $recorder->recordImpression(
            RecommendationList::fromDisplayedItems(1, 'prune_test', [$item]), null,
            new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $recorder->recordOutcome($impressionId, 42, 'prune-click', OutcomeType::Click,
            new DateTimeImmutable('2026-01-01T00:01:00Z'));
        $this->assertSame(0, $prune->execute(new ConfigurationManager(['--before=2026-02-01T00:00:00Z', '--batch-size=1'])));
        $remaining = $this->connection->execute("SELECT COUNT(*) AS total FROM vogoo_impressions WHERE placement = 'prune_test'")
            ->fetchAssoc();
        $this->assertSame(0, (int)$remaining['total']);
        foreach (['vogoo_impression_items', 'vogoo_impression_evidence',
            'vogoo_outcomes'] as $table) {
            $children = $this->connection->execute("SELECT COUNT(*) AS total FROM {$table}
                WHERE impression_id = ?", [$impressionId->binary()])->fetchAssoc();
            $this->assertSame(0, (int)$children['total']);
        }
        fclose($stream);
    }

    /** @return void */
    public function testEvaluationInitRejectsAnIndexWithTheRightNameButWrongColumns(): void {
        $provider = new class($this->connection) extends RecommenderProvider {
            /** @param \Cake\Database\Connection $database Test connection. */
            public function __construct(private \Cake\Database\Connection $database) {}
            /** @return \Cake\Database\Connection Test connection. */
            public function getConnection(): \Cake\Database\Connection { return $this->database; }
        };
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream);
        $command = new InitEvaluationCommand(new ConsoleInput($output), $output, $provider);
        $this->connection->execute('ALTER TABLE vogoo_impressions DROP INDEX ix_vogoo_impression_key');
        $this->connection->execute('ALTER TABLE vogoo_impressions ADD INDEX ix_vogoo_impression_key (category)');
        try {
            $this->expectException(\RuntimeException::class);
            $command->execute(new ConfigurationManager());
        } finally {
            $this->connection->execute('ALTER TABLE vogoo_impressions DROP INDEX ix_vogoo_impression_key');
            $this->connection->execute('ALTER TABLE vogoo_impressions ADD INDEX ix_vogoo_impression_key
                (category, placement, source_mask, context_key, shown_at)');
            fclose($stream);
        }
    }

    /** @return void */
    public function testEvaluationInitRejectsAnIncorrectForeignKeyDeleteRule(): void {
        $provider = new class($this->connection) extends RecommenderProvider {
            /** @param \Cake\Database\Connection $database Test connection. */
            public function __construct(private \Cake\Database\Connection $database) {}
            /** @return \Cake\Database\Connection Test connection. */
            public function getConnection(): \Cake\Database\Connection { return $this->database; }
        };
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream);
        $command = new InitEvaluationCommand(new ConsoleInput($output), $output, $provider);
        $this->connection->execute('ALTER TABLE vogoo_outcomes DROP FOREIGN KEY fk_vogoo_outcome_item');
        $this->connection->execute('ALTER TABLE vogoo_outcomes ADD CONSTRAINT fk_vogoo_outcome_item
            FOREIGN KEY (impression_id, item_id) REFERENCES vogoo_impression_items(impression_id, item_id)
            ON DELETE RESTRICT');
        try {
            $this->expectException(\RuntimeException::class);
            $command->execute(new ConfigurationManager());
        } finally {
            $this->connection->execute('ALTER TABLE vogoo_outcomes DROP FOREIGN KEY fk_vogoo_outcome_item');
            $this->connection->execute('ALTER TABLE vogoo_outcomes ADD CONSTRAINT fk_vogoo_outcome_item
                FOREIGN KEY (impression_id, item_id) REFERENCES vogoo_impression_items(impression_id, item_id)
                ON DELETE CASCADE');
            fclose($stream);
        }
    }

    /** @return void */
    public function testEvaluationInitRejectsAnIncorrectColumnWidth(): void {
        $provider = new class($this->connection) extends RecommenderProvider {
            /** @param \Cake\Database\Connection $database Test connection. */
            public function __construct(private \Cake\Database\Connection $database) {}
            /** @return \Cake\Database\Connection Test connection. */
            public function getConnection(): \Cake\Database\Connection { return $this->database; }
        };
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream);
        $command = new InitEvaluationCommand(new ConsoleInput($output), $output, $provider);
        $this->connection->execute("ALTER TABLE vogoo_impressions
            MODIFY context_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''");
        try {
            $this->expectException(\RuntimeException::class);
            $command->execute(new ConfigurationManager());
        } finally {
            $this->connection->execute("ALTER TABLE vogoo_impressions
                MODIFY context_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''");
            fclose($stream);
        }
    }

    /** @return void */
    public function testAuditedSignalOutsideTopSliceIsLoggedAndReportable(): void {
        for ($id = 100; $id <= 150; $id++) {
            $rating = 1.0 - ($id - 100) / 100;
            $this->insertRating($id, $id, $rating);
            $this->insertRating($id + 1000, $id, $rating);
        }
        $request = new ReconciliationRequest(new ArrayEligibilityProvider([150]),
            [RecommendationSource::TopRated], 1, 'audit_test', additionalCandidateIds: [150]);
        $list = (new RecommendationReconciler($this->connection, $this->config))
            ->recommendMember(1, $request);
        $shown = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $id = (new EvaluationRecorder($this->connection))->recordImpression($list, null, $shown);
        $row = $this->connection->execute('SELECT source, source_rank FROM vogoo_impression_evidence
            WHERE impression_id = ? AND item_id = 150', [$id->binary()])->fetchAssoc();
        $this->assertSame('top_rated', $row['source']);
        $this->assertNull($row['source_rank']);
        $summary = (new EvaluationReport($this->connection))->summary(1, $shown,
            new DateTimeImmutable('2026-01-02T00:00:00Z'),
            new DateTimeImmutable('2026-01-03T00:00:00Z'),
            new AttributionWindows(3600, 7200), RecommendationSource::TopRated);
        $this->assertSame(1, $summary->impressions);
    }
}
