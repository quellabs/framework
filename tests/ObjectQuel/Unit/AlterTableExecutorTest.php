<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\Executors\AlterTableExecutor;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Coverage for AlterTableExecutor's schema-introspection and index-sugar
	 * assembly steps — the parts QuelToSQLAlterTest can't reach, since that
	 * only exercises the pure-string column/PK compiler with already-
	 * resolved values handed in directly, and never touches index
	 * sub-operations at all (see QuelToSQLAlter's class docblock). No live
	 * connection of any of these four dialects actually exists in this
	 * suite — a mocked DatabaseAdapter stands in, same as
	 * CreateIndexExecutorTest/DestroyIndexExecutorTest.
	 */
	class AlterTableExecutorTest extends TestCase {

		private function parse(string $query): AstAlterTable {
			$ast = (new Parser(new Lexer($query), $GLOBALS['test_em']->getEntityStore()))->parse();
			self::assertInstanceOf(AstAlterTable::class, $ast);
			return $ast;
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

		public function testPlainColumnOperationsNeedNoIntrospection(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('getPrimaryKeyColumns');
			$connection->expects(self::never())->method('getIndexes');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add view_count = integer nullable)'));

			self::assertSame(['ALTER TABLE `Posts` ADD COLUMN `view_count` INT'], $capturedSql);
		}

		public function testPrimaryKeyOperationResolvesExistingColumnsViaIntrospection(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->willReturn(['old_id']);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (primary key (id))'));

			self::assertSame(
				['ALTER TABLE `Posts` DROP PRIMARY KEY', 'ALTER TABLE `Posts` ADD PRIMARY KEY (`id`)'],
				$capturedSql
			);
		}

		public function testPrimaryKeyOperationOnPostgresResolvesConstraintNameViaGetIndexes(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->willReturn(['old_id']);
			$connection->method('getIndexes')->willReturn([
				'uniq_email' => ['type' => 'unique', 'columns' => ['email'], 'length' => null],
				'posts_pkey' => ['type' => 'primary', 'columns' => ['old_id'], 'length' => null],
			]);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('pgsql')))
				->execute($this->parse('alter Posts (primary key (id))'));

			self::assertSame(
				['ALTER TABLE "Posts" DROP CONSTRAINT "posts_pkey"', 'ALTER TABLE "Posts" ADD PRIMARY KEY ("id")'],
				$capturedSql
			);
		}

		public function testAddIndexIsAssembledIntoARealCreateIndexStatement(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add index idx_a (a, b))'));

			self::assertSame(['CREATE INDEX `idx_a` ON `Posts` (`a`, `b`)'], $capturedSql);
		}

		public function testAddUniqueIndexIsAssembledCorrectly(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add unique index idx_a (a))'));

			self::assertSame(['CREATE UNIQUE INDEX `idx_a` ON `Posts` (`a`)'], $capturedSql);
		}

		public function testDropIndexIsAssembledIntoARealDestroyIndexStatement(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (drop index idx_a)'));

			self::assertSame(['DROP INDEX `idx_a` ON `Posts`'], $capturedSql);
		}

		public function testAddForeignKeyNeedsNoIntrospectionEither(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('getPrimaryKeyColumns');
			$connection->expects(self::never())->method('getIndexes');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users (id))'));

			self::assertSame(
				['ALTER TABLE `Posts` ADD CONSTRAINT `fk_Posts_author_id` FOREIGN KEY (`author_id`) REFERENCES `Users` (`id`) ON DELETE RESTRICT ON UPDATE NO ACTION'],
				$capturedSql
			);
		}

		public function testAddForeignKeyWithNoColumnListResolvesTheReferencedTablesPrimaryKey(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->with('Users')->willReturn(['id']);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users)'));

			self::assertSame(
				['ALTER TABLE `Posts` ADD CONSTRAINT `fk_Posts_author_id` FOREIGN KEY (`author_id`) REFERENCES `Users` (`id`) ON DELETE RESTRICT ON UPDATE NO ACTION'],
				$capturedSql
			);
		}

		public function testAddForeignKeyWithNoColumnListRejectsATargetWithNoPrimaryKey(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->with('Users')->willReturn([]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("the table has no primary key");

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users)'));
		}

		public function testAddForeignKeyWithNoColumnListRejectsATargetWithACompositePrimaryKey(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->with('Users')->willReturn(['tenant_id', 'id']);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("the table has a composite primary key");

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users)'));
		}

		/**
		 * SQLite rejects `add foreign key` outright (see
		 * QuelToSQLAlterTest::testAddForeignKeyOnSqliteIsRejected) regardless
		 * of the referenced column, so a column-less `references Table`
		 * must not trigger the schema-introspection round trip that resolves
		 * it — doing so would be wasted work, and could surface the wrong
		 * error (a resolution failure) instead of the real "SQLite has no
		 * ALTER TABLE support for foreign keys" one.
		 */
		public function testAddForeignKeyWithNoColumnListOnSqliteSkipsResolutionAndReportsTheRealError(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->expects(self::never())->method('getPrimaryKeyColumns');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('SQLite has no ALTER TABLE support for adding or dropping foreign keys');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('sqlite')))
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users)'));
		}

		public function testColumnAndPrimaryKeyOperationsRunBeforeIndexOperationsRegardlessOfDeclarationOrder(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			// Declared with the index op first — must still execute after
			// the column add (see objectquel-index-clause-design.md,
			// decision 3).
			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))->execute($this->parse('
				alter Posts (
					add index idx_view_count (view_count),
					add view_count = integer
				)
			'));

			self::assertSame(
				[
					'ALTER TABLE `Posts` ADD COLUMN `view_count` INT NOT NULL',
					'CREATE INDEX `idx_view_count` ON `Posts` (`view_count`)',
				],
				$capturedSql
			);
		}

		public function testStopsAtTheFirstFailingStatement(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$callCount = 0;

			$connection->method('execute')->willReturnCallback(function () use (&$callCount) {
				$callCount++;
				return null;
			});

			$connection->method('getLastErrorMessage')->willReturn('duplicate column name');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('duplicate column name');

			try {
				(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))->execute($this->parse('
					alter Posts (add a = integer, add b = integer)
				'));
			} finally {
				self::assertSame(1, $callCount);
			}
		}

		public function testThrowsWhenTheDdlStatementFails(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willReturn(null);
			$connection->method('getLastErrorMessage')->willReturn('unknown column');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('unknown column');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (drop legacy_flag)'));
		}

		public function testDoesNotWrapInATransactionOnMysqlSinceDdlAutoCommitsThere(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('beginTrans');
			$connection->expects(self::never())->method('commitTrans');
			$connection->expects(self::never())->method('rollbackTrans');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add view_count = integer)'));
		}

		public function testWrapsTheStatementSequenceInATransactionOnAPlatformThatSupportsTransactionalDdl(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::once())->method('beginTrans');
			$connection->expects(self::once())->method('commitTrans');
			$connection->expects(self::never())->method('rollbackTrans');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('pgsql')))
				->execute($this->parse('alter Posts (add view_count = integer, add index idx_view_count (view_count))'));

			self::assertCount(2, $capturedSql);
		}

		public function testRollsBackTheTransactionWhenAStatementFailsOnAPlatformThatSupportsTransactionalDdl(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willReturn(null);
			$connection->method('getLastErrorMessage')->willReturn('unknown column');
			$connection->expects(self::once())->method('beginTrans');
			$connection->expects(self::never())->method('commitTrans');
			$connection->expects(self::once())->method('rollbackTrans');

			$this->expectException(QuelException::class);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('pgsql')))
				->execute($this->parse('alter Posts (drop legacy_flag)'));
		}
	}
