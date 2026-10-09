<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\ProductId;

use Quellabs\Recommender\MemberId;

use PHPUnit\Framework\Attributes\DataProvider;
use Quellabs\Recommender\Reconciliation\ReconciliationTuning;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\Sources\ItemLinksSource;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\RecommendationEngine;
use Quellabs\Recommender\ArrayEligibilityProvider;
use Quellabs\Recommender\Sculpt\RebuildLinksCommand;
use Quellabs\Recommender\Sculpt\RecommenderProvider;
use Quellabs\Recommender\VisitorContext;
use Quellabs\Sculpt\ConfigurationManager;
use Quellabs\Sculpt\Console\ConsoleInput;
use Quellabs\Sculpt\Console\ConsoleOutput;

/** Integration coverage for incremental and rebuilt pair measures. */
class DerivedPairsTest extends IntegrationTestCase {
    use ItemLinkSlates;

    /** Supply each incremental mode.
     * @return array<string, array{bool, bool}>
     */
    public static function modes(): array {
        return ['neither' => [false, false], 'links' => [true, false],
            'slope' => [false, true], 'both' => [true, true]];
    }

    /** Verify independent measures through rating transitions and a full rebuild.
     * @param bool $links Maintain liked counts incrementally
     * @param bool $slope Maintain Slope One incrementally
     * @return void
     */
    #[DataProvider('modes')]
    public function testIncrementalMeasuresMatchRebuild(bool $links, bool $slope): void {
        $config = new RecommendationConfig(directLinks: $links, directSlope: $slope);
        $engine = new RecommendationEngine($this->connection, $config);
        $items = new ItemLinksSource($this->connection, $config);
        $engine->setRating(new MemberId(1), new ProductId(10), 0.9);
        $engine->setRating(new MemberId(1), new ProductId(20), 0.8);
        $engine->setRating(new MemberId(2), new ProductId(10), 0.4);
        $engine->setRating(new MemberId(2), new ProductId(20), 0.7);
        $this->assertPair(10, 20, $links ? 1 : 0, $slope ? 2 : 0, $slope ? 0.2 : 0.0);
        $this->assertSame($links ? [20] : [], array_map(fn($item) => $item->productId, $items->candidates(Subject::product(10), null, 10, new SourceSettings())));

        $engine->setRating(new MemberId(2), new ProductId(10), 0.9);
        $engine->setNotInterested(new MemberId(1), new ProductId(20));
        $engine->setRating(new MemberId(1), new ProductId(20), 0.7);
        $engine->deleteRating(new MemberId(2), new ProductId(20));
        $this->assertPair(10, 20, $links ? 1 : 0, $slope ? 1 : 0, $slope ? -0.2 : 0.0);
        $before = $this->rows();

        $this->rebuild($config);
        $rebuilt = $this->rows();
        $this->assertPair(10, 20, 1, 1, -0.2);
        $this->assertSame([20], array_map(fn($item) => $item->productId, $items->candidates(Subject::product(10), null, 10, new SourceSettings())));
        if ($links && $slope) {
            $this->assertEquals($before, $rebuilt);
        }
        $this->rebuild($config);
        $this->assertEquals($rebuilt, $this->rows());
    }

    /** An emptied category is cleared both explicitly and in an all-category rebuild.
     * @return void
     */
    public function testRebuildClearsStaleCategories(): void {
        $config = new RecommendationConfig(directLinks: true, directSlope: true);
        $engine = new RecommendationEngine($this->connection, $config);
        $engine->setRating(new MemberId(1), new ProductId(10), 0.9, 2);
        $engine->setRating(new MemberId(1), new ProductId(20), 0.9, 2);
        $this->assertNotEmpty($this->rows());
        $this->connection->execute('DELETE FROM vogoo_ratings WHERE category = 2');
        $this->rebuild($config);
        $this->assertSame([], $this->rows());

        $this->insertLink(10, 20, 1, 0.0, 3);
        $this->rebuild($config, 3);
        $this->assertSame([], $this->rows());
    }

    /** Detailed item results expose scores and contributors.
     * @return void
     */
    public function testDetailedResultsAndColdStart(): void {
        $config = new RecommendationConfig(directLinks: true, directSlope: true);
        $this->config = $config;
        $engine = new RecommendationEngine($this->connection, $config);
        $engine->setRating(new MemberId(1), new ProductId(10), 0.9);
        $engine->setRating(new MemberId(1), new ProductId(20), 0.8);
        $engine->setRating(new MemberId(2), new ProductId(10), 0.9);
        $engine->setRating(new MemberId(2), new ProductId(20), 0.8);
        $engine->setRating(new MemberId(2), new ProductId(30), 0.9);
        $engine->setNotInterested(new MemberId(3), new ProductId(20));
        $engine->setRating(new MemberId(3), new ProductId(10), 0.9);
        $member = $this->memberLinks(3, new ArrayEligibilityProvider([20, 30]));
        $this->assertSame(RecommendationSource::ItemLinks, $member[0]->evidence[0]->source);
        $this->assertSame(30, $member[0]->productId);
        $this->assertSame([10], $member[0]->evidence[0]->contributingProductIds);
        $this->assertGreaterThan(0, $member[0]->rankingScore);

        $visitor = new VisitorContext($config);
        $visitor->setNotInterested(20);
        $fallback = $this->visitorLinks($visitor, new ArrayEligibilityProvider([20, 30]),
            tuning: new ReconciliationTuning(sources: new SourceSettings(topRatedMinRatings: 1)));
        $this->assertCount(1, $fallback);
        $this->assertSame(30, $fallback[0]->productId);
        $this->assertSame(RecommendationSource::TopRated, $fallback[0]->evidence[0]->source);
        $this->assertSame([], $fallback[0]->evidence[0]->contributingProductIds);

        $visitor->setRating(10, 0.9);
        $collaborative = $this->visitorLinks($visitor, new ArrayEligibilityProvider([20, 30]));
        $this->assertCount(1, $collaborative);
        $this->assertSame(30, $collaborative[0]->productId);
        $this->assertSame(RecommendationSource::ItemLinks, $collaborative[0]->evidence[0]->source);
        $this->assertSame([10], $collaborative[0]->evidence[0]->contributingProductIds);
    }

    /** Member and product deletion remove both pair directions.
     * @return void
     */
    public function testMemberAndProductDeletionMatchRebuild(): void {
        $config = new RecommendationConfig(directLinks: true, directSlope: true);
        $engine = new RecommendationEngine($this->connection, $config);
        foreach ([1, 2] as $member) {
            $engine->setRating(new MemberId($member), new ProductId(10), 0.9);
            $engine->setRating(new MemberId($member), new ProductId(20), 0.8);
        }
        $engine->deleteMember(1);
        $this->assertPair(10, 20, 1, 1, -0.1);
        $this->assertPair(20, 10, 1, 1, 0.1);
        $before = $this->rows();
        $this->rebuild($config);
        $this->assertEquals($before, $this->rows());
        $engine->deleteProduct(20);
        $this->assertSame([], $this->rows());
        $this->rebuild($config);
        $this->assertSame([], $this->rows());
    }

    /** The release migration discards ambiguous legacy counts without touching ratings.
     * @return void
     */
    public function testLegacySchemaMigration(): void {
        $this->insertRating(1, 10, 0.9);
        $this->connection->execute('CREATE TEMPORARY TABLE vogoo_legacy_links_fixture (
            item_id1 INT UNSIGNED NOT NULL, item_id2 INT UNSIGNED NOT NULL,
            category INT UNSIGNED NOT NULL, cnt INT NOT NULL, diff_slope FLOAT NOT NULL,
            PRIMARY KEY (item_id1, item_id2, category))');
        try {
            $this->connection->execute('INSERT INTO vogoo_legacy_links_fixture VALUES (10, 20, 1, 7, 0.4)');
            $sql = file_get_contents(__DIR__ . '/../../packages/recommender/migrations/2026-10-independent-pair-counts.sql');
            $sql = str_replace('vogoo_links', 'vogoo_legacy_links_fixture', $sql);
            $sql = implode("\n", array_filter(explode("\n", $sql), fn($line) => !str_starts_with(trim($line), '--')));
            foreach (explode(';', $sql) as $statement) {
                if (trim($statement) !== '') {
                    $this->connection->execute($statement);
                }
            }
            $this->assertSame([], $this->connection->execute('SELECT * FROM vogoo_legacy_links_fixture')->fetchAll('assoc'));
            $this->assertNotNull($this->fetchRatingRow(1, 10));
            $columns = array_column($this->connection->execute('SHOW COLUMNS FROM vogoo_legacy_links_fixture')->fetchAll('assoc'), 'Field');
            $this->assertContains('liked_count', $columns);
            $this->assertContains('slope_count', $columns);
            $this->assertNotContains('cnt', $columns);
        } finally {
            $this->connection->execute('DROP TEMPORARY TABLE IF EXISTS vogoo_legacy_links_fixture');
        }
    }

    /** A failed rating insert rolls back its preceding pair updates.
     * @return void
     */
    public function testRatingWriteFailureRollsBackDerivedRows(): void {
        $config = new RecommendationConfig(directLinks: true, directSlope: true);
        $engine = new RecommendationEngine($this->connection, $config);
        $engine->setRating(new MemberId(1), new ProductId(10), 0.9);
        $this->connection->execute("CREATE TRIGGER vogoo_rating_failure BEFORE INSERT ON vogoo_ratings
            FOR EACH ROW BEGIN IF NEW.product_id = 30 THEN SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Injected rating failure'; END IF; END");
        try {
            try {
                $engine->setRating(new MemberId(1), new ProductId(30), 0.8);
                $this->fail('Expected rating insert failure');
            } catch (\Exception $exception) {
                $this->assertNull($this->fetchRatingRow(1, 30));
                $this->assertNull($this->fetchLinkRow(10, 30));
                $this->assertNull($this->fetchLinkRow(30, 10));
            }
        } finally {
            $this->connection->execute('DROP TRIGGER IF EXISTS vogoo_rating_failure');
        }
    }

    /** Compare one directed pair with expected independent values.
     * @param int $first First item
     * @param int $second Second item
     * @param int $liked Liked count
     * @param int $slope Slope count
     * @param float $diff Differential sum
     * @return void
     */
    private function assertPair(int $first, int $second, int $liked, int $slope, float $diff): void {
        $row = $this->fetchLinkRow($first, $second);
        if ($liked === 0 && $slope === 0) {
            $this->assertNull($row);
            return;
        }
        $this->assertNotNull($row);
        $this->assertSame($liked, (int)$row['liked_count']);
        $this->assertSame($slope, (int)$row['slope_count']);
        $this->assertEqualsWithDelta($diff, (float)$row['diff_slope'], 0.00001);
    }

    /** Return stable raw pair values.
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array {
        return $this->connection->execute('SELECT item_id1, item_id2, category, liked_count, slope_count, diff_slope
            FROM vogoo_links ORDER BY category, item_id1, item_id2')->fetchAll('assoc');
    }

    /** Execute the public rebuild command with the test connection.
     * @param RecommendationConfig $config Recommendation settings
     * @param int|null $category Optional category
     * @return void
     */
    private function rebuild(RecommendationConfig $config, ?int $category = null): void {
        $provider = $this->createMock(RecommenderProvider::class);
        $provider->method('getConnection')->willReturn($this->connection);
        $provider->method('getRecommendationConfig')->willReturn($config);
        $stream = fopen('php://memory', 'w+');
        $output = new ConsoleOutput($stream);
        $command = new RebuildLinksCommand(new ConsoleInput($output), $output, $provider);
        $this->assertSame(0, $command->execute(new ConfigurationManager($category === null ? [] : ["--category={$category}"])));
        fclose($stream);
    }
}
