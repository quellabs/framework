<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeRowStatement;

	/**
	 * DatabaseAdapter::getSqliteFts5BaseTable() — the schema introspection
	 * DestroyIndexExecutor uses to recognize a SQLite FTS5 external-content
	 * virtual table (see QuelToSQLCreateIndex::compileSqliteFulltext()),
	 * which doesn't appear in getIndexes() at all (see
	 * objectquel-destroy-index-plan.md). No sqlite driver is needed here —
	 * DatabaseAdapter is partial-mocked with execute() stubbed to return a
	 * canned sqlite_master row, letting the real regex parsing run — same
	 * pattern as DatabaseAdapterForeignKeySqlServerTest.
	 */
	class DatabaseAdapterSqliteFts5Test extends TestCase {

		private function makeAdapter(?array $row): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn('sqlite');
			$adapter->method('execute')->willReturn(new FakeRowStatement($row));

			return $adapter;
		}

		public function testParsesTheContentOptionFromTheCreateVirtualTableStatement(): void {
			$adapter = $this->makeAdapter([
				'sql' => "CREATE VIRTUAL TABLE `article_fulltext_idx` USING fts5(`title`, `body`, content='ArticleEntity', content_rowid='id')",
			]);

			self::assertSame('ArticleEntity', $adapter->getSqliteFts5BaseTable('article_fulltext_idx'));
		}

		public function testReturnsNullWhenNoSuchTableExists(): void {
			$adapter = $this->makeAdapter(null);

			self::assertNull($adapter->getSqliteFts5BaseTable('nonexistent'));
		}

		public function testReturnsNullWhenTheTableIsNotAnFts5VirtualTable(): void {
			// An ordinary table happens to share the name — not a match.
			$adapter = $this->makeAdapter([
				'sql' => 'CREATE TABLE `article_fulltext_idx` (`id` INTEGER PRIMARY KEY)',
			]);

			self::assertNull($adapter->getSqliteFts5BaseTable('article_fulltext_idx'));
		}
	}
