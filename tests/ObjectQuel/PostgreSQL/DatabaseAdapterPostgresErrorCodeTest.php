<?php

namespace Quellabs\ObjectQuel\Tests\PostgreSQL;

use PHPUnit\Framework\TestCase;

class DatabaseAdapterPostgresErrorCodeTest extends TestCase {
	/**
	 * A PostgreSQL SQLSTATE with letters remains available after a failed query.
	 * @return void
	 */
	public function testSqlStateIsPreserved(): void {
		$adapter = $GLOBALS['test_em']->getConnection();
		self::assertNull($adapter->execute('SELECT * FROM missing_table'));
		self::assertSame('42P01', $adapter->getLastError());
		self::assertStringContainsString('missing_table', $adapter->getLastErrorMessage());
	}
}
