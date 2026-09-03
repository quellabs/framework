<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeForeignKeyStatement;

	/**
	 * DatabaseAdapter::getIndexUsageStatistics()'s row-parsing logic for every
	 * dispatch branch, exercised via a partial mock the same way
	 * DatabaseAdapterForeignKeyPostgresTest covers the Postgres FK branch —
	 * no pdo_pgsql driver is available in this environment, and asserting the
	 * MySQL/Postgres branches return null on query failure or an unmapped
	 * engine doesn't need a live server either.
	 */
	class DatabaseAdapterIndexUsageStatisticsRowParsingTest extends TestCase {

		private function makeAdapter(string $dbType, ?array $rows): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn($dbType);

			if ($rows === null) {
				$adapter->method('execute')->willReturn(null);
			} else {
				$adapter->method('execute')->willReturn(new FakeForeignKeyStatement($rows));
			}

			return $adapter;
		}

		public function testMysqlBranchParsesReadsAndWritesPerTableAndIndex(): void {
			$adapter = $this->makeAdapter('mysql', [
				['table_name' => 'orders', 'index_name' => 'PRIMARY', 'reads' => 42, 'writes' => 3],
				['table_name' => 'orders', 'index_name' => 'idx_customer', 'reads' => 7, 'writes' => 0],
				['table_name' => 'customers', 'index_name' => 'PRIMARY', 'reads' => 10, 'writes' => 1],
			]);

			$stats = $adapter->getIndexUsageStatistics(['orders', 'customers']);

			self::assertSame(['reads' => 42, 'writes' => 3], $stats['orders']['PRIMARY']);
			self::assertSame(['reads' => 7, 'writes' => 0], $stats['orders']['idx_customer']);
			self::assertSame(['reads' => 10, 'writes' => 1], $stats['customers']['PRIMARY']);
		}

		public function testMariadbUsesTheSameBranchAsMysql(): void {
			$adapter = $this->makeAdapter('mariadb', [
				['table_name' => 'orders', 'index_name' => 'PRIMARY', 'reads' => 1, 'writes' => 2],
			]);

			$stats = $adapter->getIndexUsageStatistics(['orders']);

			self::assertSame(['reads' => 1, 'writes' => 2], $stats['orders']['PRIMARY']);
		}

		public function testMysqlBranchReturnsNullWhenTheQueryFails(): void {
			$adapter = $this->makeAdapter('mysql', null);

			self::assertNull($adapter->getIndexUsageStatistics(['orders']));
		}

		/**
		 * PostgreSQL exposes no per-index write counter (writes are tracked at
		 * the table level), so the parser fills in the -1 sentinel every row —
		 * callers render that as "n/a" rather than a misleading zero.
		 */
		public function testPostgresBranchReportsWritesAsSentinelMinusOne(): void {
			$adapter = $this->makeAdapter('pgsql', [
				['table_name' => 'orders', 'index_name' => 'orders_pkey', 'reads' => 15],
			]);

			$stats = $adapter->getIndexUsageStatistics(['orders']);

			self::assertSame(['reads' => 15, 'writes' => -1], $stats['orders']['orders_pkey']);
		}

		public function testPostgresBranchReturnsNullWhenTheQueryFails(): void {
			$adapter = $this->makeAdapter('pgsql', null);

			self::assertNull($adapter->getIndexUsageStatistics(['orders']));
		}

		/**
		 * SQLite (and any future unmapped driver) has no matching dispatch arm,
		 * so the default branch returns null without ever calling execute().
		 */
		public function testUnmappedEngineReturnsNullWithoutQuerying(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn('sqlite');
			$adapter->expects(self::never())->method('execute');

			self::assertNull($adapter->getIndexUsageStatistics(['orders']));
		}
	}
