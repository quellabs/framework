<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\Executors\DestroyIndexExecutor;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDestroyIndex;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Coverage for DestroyIndexExecutor: that it runs QuelToSQLDestroyIndex's
	 * compiled statement(s) in order and surfaces a QuelException on
	 * failure. No live connection of any of the four dialects exists in
	 * this suite — the plain MySQL case is the only one that ever compiles
	 * to more than one statement (see QuelToSQLDestroyIndexTest), which is
	 * what's worth verifying stops at the first failure here.
	 */
	class DestroyIndexExecutorTest extends TestCase {

		private function statement(bool $ifExists = false): AstDestroyIndex {
			return new AstDestroyIndex('archive_log_email_idx', 'ArchiveLog', $ifExists);
		}

		/**
		 * @param string[] $capturedSql Populated, in call order, as execute() is invoked
		 */
		private function mockConnection(array &$capturedSql): DatabaseAdapter {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')
				->willReturnCallback(function (string $sql) use (&$capturedSql) {
					$capturedSql[] = $sql;
					return $this->createMock(StatementInterface::class);
				});

			return $connection;
		}

		public function testPlainDestroyRunsASingleStatement(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('mysql')))->execute($this->statement());

			self::assertSame(['DROP INDEX `archive_log_email_idx` ON `ArchiveLog`'], $capturedSql);
		}

		public function testPlainMysqlIfExistsRunsAllFiveEmulationStatementsInOrder(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->statement(ifExists: true));

			self::assertCount(5, $capturedSql);
			self::assertStringStartsWith('SET @idx_exists', $capturedSql[0]);
			self::assertSame('PREPARE stmt FROM @sql', $capturedSql[2]);
			self::assertSame('EXECUTE stmt', $capturedSql[3]);
			self::assertSame('DEALLOCATE PREPARE stmt', $capturedSql[4]);
		}

		public function testStopsAtTheFirstFailingStatement(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$callCount = 0;

			$connection->method('execute')->willReturnCallback(function () use (&$callCount) {
				$callCount++;
				return null;
			});

			$connection->method('getLastErrorMessage')->willReturn('unknown index');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('unknown index');

			try {
				(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('mysql')))
					->execute($this->statement(ifExists: true));
			} finally {
				self::assertSame(1, $callCount);
			}
		}

		public function testThrowsWhenTheDdlStatementFails(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willReturn(null);
			$connection->method('getLastErrorMessage')->willReturn('unknown index');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('unknown index');

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('mysql')))->execute($this->statement());
		}

		public function testSqlServerOrdinaryIndexNeverConsultsTheFulltextTag(): void {
			// $indexName is already an ordinary index (per getIndexes()) —
			// no need to ask whether it's the table's fulltext index at all.
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn(['archive_log_email_idx' => ['type' => 'index', 'columns' => ['email'], 'length' => null]]);
			$connection->expects(self::never())->method('hasSqlServerFulltextIndex');
			$connection->expects(self::never())->method('getSqlServerExtendedProperty');

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('sqlsrv')))->execute($this->statement());

			self::assertSame(['DROP INDEX [archive_log_email_idx] ON [ArchiveLog]'], $capturedSql);
		}

		public function testSqlServerFulltextIndexResolvesViaTheExtendedPropertyTag(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([]);
			$connection->method('hasSqlServerFulltextIndex')->willReturn(true);
			$connection->method('getSqlServerExtendedProperty')->willReturn('archive_log_email_idx');

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('sqlsrv')))->execute($this->statement());

			self::assertCount(2, $capturedSql);
			self::assertStringContainsString('sp_dropextendedproperty', $capturedSql[0]);
			self::assertSame('DROP FULLTEXT INDEX ON [ArchiveLog]', $capturedSql[1]);
		}

		public function testSqlServerFallsBackToTheOrdinaryPathWhenTheTagDoesNotMatch(): void {
			// A table with a fulltext index tagged under a different name —
			// this $indexName isn't it, so this must not be mistaken for a
			// fulltext destroy; it falls through to the ordinary (typo)
			// path exactly as it would without any fulltext on the table.
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([]);
			$connection->method('hasSqlServerFulltextIndex')->willReturn(true);
			$connection->method('getSqlServerExtendedProperty')->willReturn('some_other_idx');

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('sqlsrv')))->execute($this->statement());

			self::assertSame(['DROP INDEX [archive_log_email_idx] ON [ArchiveLog]'], $capturedSql);
		}

		public function testSqlServerFallsBackToTheOrdinaryPathWhenTheTableHasNoFulltextIndex(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([]);
			$connection->method('hasSqlServerFulltextIndex')->willReturn(false);
			$connection->expects(self::never())->method('getSqlServerExtendedProperty');

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('sqlsrv')))->execute($this->statement());

			self::assertSame(['DROP INDEX [archive_log_email_idx] ON [ArchiveLog]'], $capturedSql);
		}

		public function testSqliteOrdinaryIndexNeverConsultsFts5Resolution(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn(['archive_log_email_idx' => ['type' => 'index', 'columns' => ['email'], 'length' => null]]);
			$connection->expects(self::never())->method('getSqliteFts5BaseTable');

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('sqlite')))->execute($this->statement());

			self::assertSame(['DROP INDEX `archive_log_email_idx`'], $capturedSql);
		}

		public function testSqliteFts5VirtualTableResolvesViaTheContentOption(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([]);
			$connection->method('getSqliteFts5BaseTable')->willReturn('ArchiveLog');

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('sqlite')))->execute($this->statement());

			self::assertSame(
				[
					'DROP TRIGGER `archive_log_email_idx_ai`',
					'DROP TRIGGER `archive_log_email_idx_ad`',
					'DROP TRIGGER `archive_log_email_idx_au`',
					'DROP TABLE `archive_log_email_idx`',
				],
				$capturedSql
			);
		}

		public function testSqliteFallsBackToTheOrdinaryPathWhenNoFts5TableMatches(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([]);
			$connection->method('getSqliteFts5BaseTable')->willReturn(null);

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('sqlite')))->execute($this->statement());

			self::assertSame(['DROP INDEX `archive_log_email_idx`'], $capturedSql);
		}

		public function testSqliteFallsBackToTheOrdinaryPathWhenTheFts5TableBelongsToADifferentTable(): void {
			// An FTS5 virtual table happens to share this index name but was
			// built against a different base table — not a match.
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([]);
			$connection->method('getSqliteFts5BaseTable')->willReturn('SomeOtherTable');

			(new DestroyIndexExecutor($connection, new FakePlatformCapabilities('sqlite')))->execute($this->statement());

			self::assertSame(['DROP INDEX `archive_log_email_idx`'], $capturedSql);
		}
	}
