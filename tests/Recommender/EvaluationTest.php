<?php

namespace Quellabs\Recommender\Tests;

use DateTimeImmutable;
use Quellabs\Recommender\AttributionWindows;
use Quellabs\Recommender\EvaluationRecorder;
use Quellabs\Recommender\EvaluationReport;
use Quellabs\Recommender\OutcomeType;
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\RecommendationReconciler;
use Quellabs\Recommender\ReconciliationRequest;
use Quellabs\Recommender\ReconciledRecommendation;
use Quellabs\Recommender\RecommendationList;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\SourceEvidence;
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
        $this->connection->execute('DELETE FROM recommender_impressions');
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
        $this->connection->execute('INSERT INTO recommender_models
            (id, objective, category, placement, source_mask, context_key,
            feature_schema_version, artifact, trained_at, status)
            VALUES (UNHEX(?), \'click\', 1, \'home\', 16, \'\', 1, ?, UTC_TIMESTAMP(6), \'active\')',
            [$modelId, json_encode($artifact, JSON_THROW_ON_ERROR)]);
        try {
            $request = new ReconciliationRequest(new ArrayEligibilityProvider([40, 41]),
                [RecommendationSource::NewProducts], 2, 'home', [40, 41]);
            $list = (new RecommendationReconciler($this->connection, $this->config))
                ->recommendMember(7, $request);
            $this->assertSame('click_probability', $list->scoreKind);
            $otherContext = new ReconciliationRequest(new ArrayEligibilityProvider([40, 41]),
                [RecommendationSource::NewProducts], 2, 'home', [40, 41], contextKey: 'tenant-x');
            $this->assertSame('rank_fusion',
                (new RecommendationReconciler($this->connection, $this->config))
                    ->recommendMember(7, $otherContext)->scoreKind);
            $this->assertSame($modelId, $list->modelId);
            $this->assertSame(0.5, $list->items[0]->rankingScore);
            $recorder = new EvaluationRecorder($this->connection);
            $impressionId = $recorder->recordImpression($list, null,
                new DateTimeImmutable('2026-01-01T00:00:00Z'));
            $recorder->recordOutcome($impressionId, $list->items[0]->itemId, 'model-click',
                OutcomeType::Click, new DateTimeImmutable('2026-01-01T00:01:00Z'));
            $rows = $this->connection->execute('SELECT position, display_click_probability
                FROM recommender_impression_items WHERE impression_id = ? ORDER BY position',
                [$impressionId->binary()])->fetchAll('assoc');
            $this->assertEqualsWithDelta(0.5, (float)$rows[0]['display_click_probability'], 0.00001);
            $this->assertLessThan(0.5, (float)$rows[1]['display_click_probability']);
            $calibration = (new EvaluationReport($this->connection))->calibrationByModel(
                new DateTimeImmutable('2026-01-01T00:00:00Z'),
                new DateTimeImmutable('2026-01-02T00:00:00Z'),
                new DateTimeImmutable('2026-01-03T00:00:00Z'), 3600);
            $this->assertSame(2, $calibration[$modelId . ':home']['impressions']);
            $this->assertSame(0.5, $calibration[$modelId . ':home']['observed_click_rate']);
        } finally {
            $this->connection->execute('DELETE FROM recommender_impressions');
            $this->connection->execute('DELETE FROM recommender_models WHERE id = UNHEX(?)', [$modelId]);
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
        (new EvaluationRecorder($this->connection))->recordImpression(
            RecommendationList::fromDisplayedItems(1, 'prune_test', [$item]), null,
            new DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->assertSame(0, $prune->execute(new ConfigurationManager(['--before=2026-02-01T00:00:00Z', '--batch-size=1'])));
        $remaining = $this->connection->execute("SELECT COUNT(*) AS total FROM recommender_impressions WHERE placement = 'prune_test'")
            ->fetchAssoc();
        $this->assertSame(0, (int)$remaining['total']);
        fclose($stream);
    }
}
