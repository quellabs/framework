<?php

namespace Quellabs\Recommender\Tests;

use Quellabs\Recommender\Sculpt\RecommenderProvider;
use Quellabs\Recommender\Sculpt\TrainClickModelCommand;
use Quellabs\Sculpt\ConfigurationManager;
use Quellabs\Sculpt\Console\ConsoleInput;
use Quellabs\Sculpt\Console\ConsoleOutput;

/** Validates training command inputs before model data is read. */
class ClickModelCommandTest extends IntegrationTestCase {
    /** @return void */
    public function testTrainingCommandRejectsInvalidTimestamps(): void {
        $provider = new class($this->connection) extends RecommenderProvider {
            /** @param \Cake\Database\Connection $database Test connection. */
            public function __construct(private \Cake\Database\Connection $database) {}
            /** @return \Cake\Database\Connection Test connection. */
            public function getConnection(): \Cake\Database\Connection { return $this->database; }
        };
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream);
        try {
            foreach (['2026-01-01Z', '2026-02-30T00:00:00Z'] as $invalid) {
                try {
                    (new TrainClickModelCommand(new ConsoleInput($output), $output, $provider))
                        ->execute(new ConfigurationManager(['--category=1', '--placement=home',
                            '--sources=new_products', '--from=' . $invalid,
                            '--to=2026-03-02T00:00:00Z', '--as-of=2026-03-03T00:00:00Z',
                            '--click-window-seconds=3600']));
                    $this->fail('Invalid training timestamp was accepted.');
                } catch (\InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return void */
    public function testTrainingCommandRejectsDuplicateSources(): void {
        $provider = new class($this->connection) extends RecommenderProvider {
            /** @param \Cake\Database\Connection $database Test connection. */
            public function __construct(private \Cake\Database\Connection $database) {}
            /** @return \Cake\Database\Connection Test connection. */
            public function getConnection(): \Cake\Database\Connection { return $this->database; }
        };
        $stream = fopen('php://temp', 'w+');
        $output = new ConsoleOutput($stream);
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new TrainClickModelCommand(new ConsoleInput($output), $output, $provider))
                ->execute(new ConfigurationManager(['--category=1', '--placement=home',
                    '--sources=new_products,new_products', '--from=2026-01-01T00:00:00Z',
                    '--to=2026-01-02T00:00:00Z', '--as-of=2026-01-03T00:00:00Z',
                    '--click-window-seconds=3600']));
        } finally {
            fclose($stream);
        }
    }
}
