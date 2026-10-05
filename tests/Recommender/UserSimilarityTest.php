<?php

namespace Quellabs\Recommender\Tests;

use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use Quellabs\Recommender\RecommendationEngine;
use Quellabs\Recommender\Internal\UserSimilarity;

/** Integration coverage for neighbour scoring and ordering. */
class UserSimilarityTest extends IntegrationTestCase {
    /** Zero-weight neighbours are omitted even at a requested minimum of zero.
     * @return void
     */
    public function testZeroSimilarityCannotEnterRecommendationWeights(): void {
        $engine = new RecommendationEngine($this->connection, $this->config);
        $users = new UserSimilarity($this->connection, $this->config, $engine);
        $engine->setRating(1, 10, 1.0);
        $engine->setRating(2, 10, 0.0);
        $engine->setRating(2, 20, 1.0);
        $this->assertSame([], $users->getNeighbours(1, 0));
        $this->assertSame([], $users->memberGetRecommendedItems(1, 0));
    }

    /** Equal similarities use member ID to give stable limited pages.
     * @return void
     */
    public function testEqualSimilarityOrderIsStable(): void {
        $engine = new RecommendationEngine($this->connection, $this->config);
        $users = new UserSimilarity($this->connection, $this->config, $engine);
        foreach ([1, 2, 3] as $member) {
            $engine->setRating($member, 10, 0.8);
        }
        $this->assertSame([2, 3], array_column($users->getNeighbours(1), 'member_id'));
        $this->assertSame([2], array_column($users->getNeighbours(1, limit: 1), 'member_id'));
    }

    /** Grouped neighbour reads keep query count independent of neighbour count.
     * @return void
     */
    public function testRecommendationUsesGroupedQueries(): void {
        $writer = new RecommendationEngine($this->connection, $this->config);
        $writer->setRating(1, 10, 0.9);
        foreach (range(2, 21) as $member) {
            $writer->setRating($member, 10, 0.9);
            $writer->setRating($member, 100 + $member, 0.9);
        }
        $counting = new class($this->connection) extends Connection {
            public int $queries = 0;

            /** @param Connection $inner Shared connection */
            public function __construct(private Connection $inner) {}

            /** Count and delegate SQL statements.
             * @param string $sql SQL statement
             * @param array $params Bound values
             * @param array $types Parameter types
             * @return StatementInterface Statement
             */
            public function execute(string $sql, array $params = [], array $types = []): StatementInterface {
                $this->queries++;
                return $this->inner->execute($sql, $params, $types);
            }
        };
        $engine = new RecommendationEngine($counting, $this->config);
        $users = new UserSimilarity($counting, $this->config, $engine);
        $this->assertCount(20, $users->memberGetRecommendedItems(1));
        $this->assertSame(3, $counting->queries);
        $this->assertSame($users->memberSimilarity(1, 2),
            $users->getNeighbours(1, limit: 1)[0]['similarity']);
    }
}
