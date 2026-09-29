<?php

namespace Quellabs\ObjectQuel\Tests\PostgreSQL;

use Cake\Database\Connection;
use Cake\Database\Exception\QueryException;
use PHPUnit\Framework\TestCase;
use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

/**
 * DatabaseAdapter::execute()'s PostgreSQL SQLSTATE handling. No pdo_pgsql
 * driver is available here, so DatabaseAdapter is partial-mocked with
 * getDatabaseType() stubbed to 'pgsql' and its connection replaced with one
 * whose execute() throws the same exception shape a real PDO pgsql driver
 * would — same precedent as DatabaseAdapterForeignKeyPostgresTest and
 * DatabaseAdapterPostgresColumnsTest, but execute() itself is left real
 * since its SQLSTATE-extraction logic is what's under test.
 */
class DatabaseAdapterPostgresErrorCodeTest extends TestCase {

	/**
	 * Builds the \PDOException a real pdo_pgsql driver throws: PDO sets a string
	 * SQLSTATE on $code directly, bypassing the constructor's `int $code` signature.
	 * @param string $sqlState PostgreSQL SQLSTATE, e.g. '42P01'
	 * @param string $message Driver error message
	 * @return \PDOException
	 */
	private function makePdoException(string $sqlState, string $message): \PDOException {
		$exception = new \PDOException($message);
		$property = new \ReflectionProperty(\PDOException::class, 'code');
		$property->setAccessible(true);
		$property->setValue($exception, $sqlState);
		return $exception;
	}

	/**
	 * A PostgreSQL SQLSTATE with letters remains available after a failed query.
	 * @return void
	 */
	public function testSqlStateIsPreserved(): void {
		$pdoException = $this->makePdoException('42P01', 'ERROR: relation "missing_table" does not exist');

		$connection = $this->createMock(Connection::class);
		$connection->method('execute')->willThrowException(new QueryException('SELECT * FROM missing_table', $pdoException));

		$adapter = $this->getMockBuilder(DatabaseAdapter::class)
			->disableOriginalConstructor()
			->onlyMethods(['getDatabaseType'])
			->getMock();

		$adapter->method('getDatabaseType')->willReturn('pgsql');

		$connectionProperty = new \ReflectionProperty(DatabaseAdapter::class, 'connection');
		$connectionProperty->setAccessible(true);
		$connectionProperty->setValue($adapter, $connection);

		self::assertNull($adapter->execute('SELECT * FROM missing_table'));
		self::assertSame('42P01', $adapter->getLastError());
		self::assertStringContainsString('missing_table', $adapter->getLastErrorMessage());
	}
}
