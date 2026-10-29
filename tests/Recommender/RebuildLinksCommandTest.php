<?php

namespace Quellabs\Recommender\Tests;

use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\Sculpt\RebuildLinksCommand;
use Quellabs\Recommender\Sculpt\RecommenderProvider;
use Quellabs\Sculpt\ConfigurationManager;
use Quellabs\Sculpt\Console\ConsoleInput;
use Quellabs\Sculpt\Console\ConsoleOutput;

/** Focused rebuild failure coverage. */
class RebuildLinksCommandTest extends IntegrationTestCase {
    /** Failed staging computation leaves the previous derived data usable.
     * @return void
     */
    public function testComputationFailurePreservesPriorRows(): void {
        $this->insertRating(1, 10, 0.9);
        $this->insertRating(1, 20, 0.9);
        $this->insertLink(10, 20, 3, 0.2);
        $prior = $this->fetchLinkRow(10, 20);

        $failing = new class($this->connection) extends Connection {
            /** @param Connection $inner Connection to delegate to */
            public function __construct(private Connection $inner) {}

            /** Fail the staging insert after creating its temporary table.
             * @param string $sql SQL statement
             * @param array $params Bound parameters
             * @param array $types Parameter types
             * @return StatementInterface Statement
             */
            public function execute(string $sql, array $params = [], array $types = []): StatementInterface {
                if (str_contains($sql, 'INSERT INTO vogoo_links_stage')) {
                    throw new \RuntimeException('Injected staging failure');
                }
                return $this->inner->execute($sql, $params, $types);
            }
        };
        $provider = $this->createMock(RecommenderProvider::class);
        $provider->method('getConnection')->willReturn($failing);
        $provider->method('getRecommendationConfig')->willReturn(new RecommendationConfig());
        $stream = fopen('php://memory', 'w+');
        try {
            $output = new ConsoleOutput($stream);
            $command = new RebuildLinksCommand(new ConsoleInput($output), $output, $provider);
            try {
                $command->execute(new ConfigurationManager(['--category=1']));
                $this->fail('Expected staging failure');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Injected staging failure', $exception->getMessage());
            }
            $this->assertSame($prior, $this->fetchLinkRow(10, 20));
        } finally {
            fclose($stream);
        }
    }
}
