<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\Executors\IndexVisibilityExecutor;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstHideIndex;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstShowIndex;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Coverage for IndexVisibilityExecutor: that it runs
	 * QuelToSQLIndexVisibility's compiled statement and surfaces a
	 * QuelException on failure or unsupported-dialect rejection. No live
	 * connection of any of the four dialects exists in this suite (see
	 * DestroyIndexExecutorTest for the equivalent precedent).
	 */
	class IndexVisibilityExecutorTest extends TestCase {

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

		public function testExecuteHideRunsASingleStatement(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new IndexVisibilityExecutor($connection, new FakePlatformCapabilities('mysql')))
				->executeHide(new AstHideIndex('archive_log_email_idx', 'ArchiveLog'));

			self::assertSame(['ALTER TABLE `ArchiveLog` ALTER INDEX `archive_log_email_idx` INVISIBLE'], $capturedSql);
		}

		public function testExecuteShowRunsASingleStatement(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new IndexVisibilityExecutor($connection, new FakePlatformCapabilities('mysql')))
				->executeShow(new AstShowIndex('archive_log_email_idx', 'ArchiveLog'));

			self::assertSame(['ALTER TABLE `ArchiveLog` ALTER INDEX `archive_log_email_idx` VISIBLE'], $capturedSql);
		}

		public function testThrowsWhenTheDdlStatementFails(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willReturn(null);
			$connection->method('getLastErrorMessage')->willReturn('unknown index');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('unknown index');

			(new IndexVisibilityExecutor($connection, new FakePlatformCapabilities('mysql')))
				->executeHide(new AstHideIndex('archive_log_email_idx', 'ArchiveLog'));
		}

		public function testThrowsOnAnUnsupportedDialectWithoutTouchingTheConnection(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->expects(self::never())->method('execute');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('only MySQL 8.0+ and MariaDB 10.6+ support hiding an index');

			(new IndexVisibilityExecutor($connection, new FakePlatformCapabilities('pgsql')))
				->executeHide(new AstHideIndex('archive_log_email_idx', 'ArchiveLog'));
		}
	}
