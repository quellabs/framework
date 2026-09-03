<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * SQLite exposes no index usage counters at the engine level, so
	 * DatabaseAdapter::getIndexUsageStatistics() must return null for it — the
	 * same "unavailable, not zero" contract the MySQL/Postgres branches use on
	 * query failure. Covered here since SQLite is the only engine available in
	 * this environment without a live external database server.
	 */
	class DatabaseAdapterIndexUsageStatisticsSqliteTest extends TestCase {
		use FkTestSupport;

		public function testReturnsNullForAnIndexedTable(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
			$adapter->execute('CREATE INDEX idx_email ON users (email)');

			self::assertNull($adapter->getIndexUsageStatistics(['users']));
		}

		public function testReturnsNullForAnEmptyTableList(): void {
			$adapter = $this->makeSqliteAdapter();

			self::assertNull($adapter->getIndexUsageStatistics([]));
		}
	}
