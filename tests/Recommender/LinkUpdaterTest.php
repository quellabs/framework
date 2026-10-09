<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\Internal\Links\LinkUpdater;

/** Focused integration coverage for independent incremental deltas. */
class LinkUpdaterTest extends IntegrationTestCase {
    /** Each updater changes only its own measure and leaves shared rows until both are zero.
     * @return void
     */
    public function testIndependentCountsAndPruning(): void {
        $this->insertRating(1, 20, 0.8);
        $updater = new LinkUpdater($this->connection, new RecommendationConfig(directLinks: true, directSlope: true));
        $updater->updateLinks(1, 10, 1, 0.9, -1.0);
        $row = $this->fetchLinkRow(10, 20);
        $this->assertSame(1, (int)$row['liked_count']);
        $this->assertSame(0, (int)$row['slope_count']);

        $updater->updateSlope(1, 10, 1, 0.9, -1.0);
        $row = $this->fetchLinkRow(10, 20);
        $this->assertSame(1, (int)$row['liked_count']);
        $this->assertSame(1, (int)$row['slope_count']);
        $this->assertEqualsWithDelta(-0.1, (float)$row['diff_slope'], 0.00001);

        $updater->updateLinks(1, 10, 1, 0.4, 0.9);
        $row = $this->fetchLinkRow(10, 20);
        $this->assertSame(0, (int)$row['liked_count']);
        $this->assertSame(1, (int)$row['slope_count']);
        $updater->updateSlope(1, 10, 1, -1.0, 0.9);
        $this->assertNull($this->fetchLinkRow(10, 20));
        $this->assertNull($this->fetchLinkRow(20, 10));
    }
}
