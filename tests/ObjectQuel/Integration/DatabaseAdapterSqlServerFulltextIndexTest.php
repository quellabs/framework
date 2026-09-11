<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeRowStatement;

	/**
	 * DatabaseAdapter::hasSqlServerFulltextIndex()/getSqlServerExtendedProperty()
	 * — the schema introspection DestroyIndexExecutor uses to correlate a
	 * `destroy Name on Table` against a sqlsrv table's fulltext index, which
	 * is itself unnamed at the T-SQL level (see
	 * objectquel-destroy-index-plan.md). No sqlsrv driver is available here,
	 * so DatabaseAdapter is partial-mocked with execute() stubbed to return
	 * a canned row, letting the real parsing logic run — same pattern as
	 * DatabaseAdapterForeignKeySqlServerTest.
	 */
	class DatabaseAdapterSqlServerFulltextIndexTest extends TestCase {

		private function makeAdapter(?array $row): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn('sqlsrv');
			$adapter->method('execute')->willReturn(new FakeRowStatement($row));

			return $adapter;
		}

		public function testHasFulltextIndexIsTrueWhenARowIsReturned(): void {
			$adapter = $this->makeAdapter(['found' => 1]);

			self::assertTrue($adapter->hasSqlServerFulltextIndex('ArticleEntity'));
		}

		public function testHasFulltextIndexIsFalseWhenNoRowIsReturned(): void {
			$adapter = $this->makeAdapter(null);

			self::assertFalse($adapter->hasSqlServerFulltextIndex('ArticleEntity'));
		}

		public function testGetExtendedPropertyReturnsTheStoredValue(): void {
			$adapter = $this->makeAdapter(['property_value' => 'article_fulltext_idx']);

			self::assertSame(
				'article_fulltext_idx',
				$adapter->getSqlServerExtendedProperty('ArticleEntity', 'quel_fulltext_index_name')
			);
		}

		public function testGetExtendedPropertyReturnsNullWhenUnset(): void {
			$adapter = $this->makeAdapter(null);

			self::assertNull($adapter->getSqlServerExtendedProperty('ArticleEntity', 'quel_fulltext_index_name'));
		}
	}
