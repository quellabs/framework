<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Mysql;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * DatabaseAdapter::getIndexUsageStatistics() against a real MySQL server,
	 * mirroring DatabaseAdapterForeignKeyMySqlTest's setup/skip pattern.
	 *
	 * performance_schema.table_io_waits_summary_by_index_usage carries one row
	 * per index the moment the table exists — counters start at zero, not
	 * "absent" — and COUNT_READ increments per index lookup performed against
	 * it. Skips entirely (does not fail) when no MySQL server is reachable.
	 */
	class DatabaseAdapterIndexUsageStatisticsMySqlTest extends TestCase {

		private ?DatabaseAdapter $adapter = null;

		protected function setUp(): void {
			$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
			$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
			$name = getenv('TEST_DB_NAME') ?: 'canvas_blog';
			$user = getenv('TEST_DB_USER') ?: 'root';
			$pass = getenv('TEST_DB_PASS') ?: '';

			try {
				$connection = new Connection([
					'driver'   => Mysql::class,
					'host'     => $host,
					'port'     => $port,
					'database' => $name,
					'username' => $user,
					'password' => $pass,
					'encoding' => 'utf8mb4',
				]);
				$connection->getDriver()->connect();
			} catch (\Throwable $e) {
				self::markTestSkipped('No live MySQL server reachable for this test: ' . $e->getMessage());
			}

			$this->adapter = new DatabaseAdapter($connection);
			$this->dropFixtureTable();
		}

		protected function tearDown(): void {
			if ($this->adapter !== null) {
				$this->dropFixtureTable();
			}
		}

		private function dropFixtureTable(): void {
			$this->adapter->execute('DROP TABLE IF EXISTS oq_idxstat_test');
		}

		public function testFreshlyCreatedIndexReportsZeroReadsAndWrites(): void {
			$this->adapter->execute(
				'CREATE TABLE oq_idxstat_test (id INT PRIMARY KEY, name VARCHAR(50), INDEX idx_name (name)) ENGINE=InnoDB'
			);

			$stats = $this->adapter->getIndexUsageStatistics(['oq_idxstat_test']);

			self::assertNotNull($stats);
			self::assertArrayHasKey('idx_name', $stats['oq_idxstat_test']);
			self::assertSame(0, $stats['oq_idxstat_test']['idx_name']['reads']);
			self::assertSame(0, $stats['oq_idxstat_test']['idx_name']['writes']);
		}

		public function testReadCounterIncrementsAfterAQueryUsesTheIndex(): void {
			$this->adapter->execute(
				'CREATE TABLE oq_idxstat_test (id INT PRIMARY KEY, name VARCHAR(50), INDEX idx_name (name)) ENGINE=InnoDB'
			);
			$this->adapter->execute("INSERT INTO oq_idxstat_test (id, name) VALUES (1, 'foo')");

			// Force the index to actually be used for the lookup.
			$this->adapter->execute("SELECT id FROM oq_idxstat_test WHERE name = 'foo'");

			$stats = $this->adapter->getIndexUsageStatistics(['oq_idxstat_test']);

			self::assertGreaterThan(0, $stats['oq_idxstat_test']['idx_name']['reads']);
		}

		public function testReturnsNoRowsForATableNotIncludedInTheTablesArgument(): void {
			$this->adapter->execute(
				'CREATE TABLE oq_idxstat_test (id INT PRIMARY KEY, name VARCHAR(50), INDEX idx_name (name)) ENGINE=InnoDB'
			);

			$stats = $this->adapter->getIndexUsageStatistics(['some_other_table']);

			self::assertNotNull($stats);
			self::assertArrayNotHasKey('oq_idxstat_test', $stats);
		}
	}
