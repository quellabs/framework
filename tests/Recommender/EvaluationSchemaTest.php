<?php

namespace Quellabs\Recommender\Tests;

use Cake\Database\Connection;
use Cake\Database\StatementInterface;
use PHPUnit\Framework\TestCase;
use Quellabs\Recommender\Internal\Persistence\EvaluationSchema;

/** Missing optional schema produces a clear package error. */
class EvaluationSchemaTest extends TestCase {
    /** @return void */
    public function testMissingTablesProduceInstallationHint(): void {
        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([['TABLE_NAME' => 'vogoo_models']]);
        $connection = $this->createMock(Connection::class);
        $connection->method('execute')->willReturn($statement);
        $this->expectExceptionMessage('run recommender:init-evaluation-db');
        EvaluationSchema::requireTables($connection);
    }
}
