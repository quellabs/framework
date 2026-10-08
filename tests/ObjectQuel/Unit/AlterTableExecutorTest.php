<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\ExecutionContext;
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
		 * @param string[] $capturedSql Populated, in call order, as DDL is executed
		 * @param array<string, string>|null $queryResultRow Row returned for schema lookups
		 */
		private function mockConnection(array &$capturedSql, ?array $queryResultRow = null): DatabaseAdapter {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')
				->willReturnCallback(function (string $sql) use (&$capturedSql, $queryResultRow) {
					if ($queryResultRow !== null && str_starts_with($sql, 'SELECT ')) {
						$statement = $this->createMock(StatementInterface::class);
						$statement->method('fetch')->willReturn($queryResultRow);
						return $statement;
					}
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
				->execute($this->parse('alter Posts (add view_count = integer nullable)'), new ExecutionContext([]));

			self::assertSame(['ALTER TABLE `Posts` ADD COLUMN `view_count` INT'], $capturedSql);
		}

		public function testPrimaryKeyOperationResolvesExistingColumnsViaIntrospection(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->willReturn(['old_id']);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (primary key (id))'), new ExecutionContext([]));

			self::assertSame(
				['ALTER TABLE `Posts` DROP PRIMARY KEY', 'ALTER TABLE `Posts` ADD PRIMARY KEY (`id`)'],
				$capturedSql
			);
		}

		public function testPrimaryKeyOperationOnPostgresResolvesConstraintName(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql, ['conname' => 'posts_pkey']);
			$connection->method('getPrimaryKeyColumns')->willReturn(['old_id']);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('pgsql')))
				->execute($this->parse('alter Posts (primary key (id))'), new ExecutionContext([]));

			self::assertSame(
				['ALTER TABLE "Posts" DROP CONSTRAINT "posts_pkey"', 'ALTER TABLE "Posts" ADD PRIMARY KEY ("id")'],
				$capturedSql
			);
		}

		public function testAddIndexIsAssembledIntoARealCreateIndexStatement(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add index idx_a (a, b))'), new ExecutionContext([]));

			self::assertSame(['CREATE INDEX `idx_a` ON `Posts` (`a`, `b`)'], $capturedSql);
		}

		public function testAddUniqueIndexIsAssembledCorrectly(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add unique index idx_a (a))'), new ExecutionContext([]));

			self::assertSame(['CREATE UNIQUE INDEX `idx_a` ON `Posts` (`a`)'], $capturedSql);
		}

		public function testDropIndexIsAssembledIntoARealDestroyIndexStatement(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (drop index idx_a)'), new ExecutionContext([]));

			self::assertSame(['DROP INDEX `idx_a` ON `Posts`'], $capturedSql);
		}

		public function testAddForeignKeyNeedsNoIntrospectionEither(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('getPrimaryKeyColumns');
			$connection->expects(self::never())->method('getIndexes');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users (id))'), new ExecutionContext([]));

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
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users)'), new ExecutionContext([]));

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
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users)'), new ExecutionContext([]));
		}

		public function testAddForeignKeyWithNoColumnListRejectsATargetWithACompositePrimaryKey(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->with('Users')->willReturn(['tenant_id', 'id']);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("the table has a composite primary key");

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users)'), new ExecutionContext([]));
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
				->execute($this->parse('alter Posts (add foreign key (author_id) references Users)'), new ExecutionContext([]));
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
			'), new ExecutionContext([]));

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
				'), new ExecutionContext([]));
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
				->execute($this->parse('alter Posts (drop legacy_flag)'), new ExecutionContext([]));
		}

		public function testDoesNotWrapInATransactionOnMysqlSinceDdlAutoCommitsThere(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('beginTrans');
			$connection->expects(self::never())->method('commitTrans');
			$connection->expects(self::never())->method('rollbackTrans');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add view_count = integer)'), new ExecutionContext([]));
		}

		public function testWrapsTheStatementSequenceInATransactionOnAPlatformThatSupportsTransactionalDdl(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::once())->method('beginTrans');
			$connection->expects(self::once())->method('commitTrans');
			$connection->expects(self::never())->method('rollbackTrans');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('pgsql')))
				->execute($this->parse('alter Posts (add view_count = integer, add index idx_view_count (view_count))'), new ExecutionContext([]));

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
				->execute($this->parse('alter Posts (drop legacy_flag)'), new ExecutionContext([]));
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function signatureRelevantOperations(): array {
			return [
				'drop column'      => ['alter Posts (drop legacy_flag)'],
				'rename column'    => ['alter Posts (rename title to old_title)'],
				'retype column'    => ['alter Posts (retype title = string(500))'],
				'set primary key'  => ['alter Posts (primary key (id))'],
				'drop primary key' => ['alter Posts (drop primary key)'],
			];
		}

		/**
		 * A column-shape or primary-key change is refused while a live binding exists on the
		 * table, before any DDL runs (objectquel-equel-triggers-design.md, stage 4).
		 * @param string $quel Alter statement
		 * @return void
		 */
		#[DataProvider('signatureRelevantOperations')]
		public function testRefusedWhileBindingExistsOnTable(string $quel): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('getPrimaryKeyColumns')->willReturn(['id']);
			$connection->expects(self::once())->method('findBindingTriggersOnTable')->with('Posts')
				->willReturn(['eq_posts_replace_audit_post']);
			$connection->expects(self::never())->method('execute');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("binding trigger(s) 'eq_posts_replace_audit_post' depend on its mapped columns");
			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse($quel), new ExecutionContext([]));
		}

		/**
		 * The same operations proceed normally once no live binding exists.
		 * @param string $quel Alter statement
		 * @return void
		 */
		#[DataProvider('signatureRelevantOperations')]
		public function testProceedsWhenNoBindingExistsOnTable(string $quel): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->willReturn(['id']);
			$connection->expects(self::once())->method('findBindingTriggersOnTable')->with('Posts')->willReturn([]);

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse($quel), new ExecutionContext([]));

			self::assertNotEmpty($capturedSql);
		}

		/**
		 * Adding a column never checks for a live binding: it can't invalidate one.
		 * @return void
		 */
		public function testAddColumnNeverChecksForBindings(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('findBindingTriggersOnTable');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (add view_count = integer)'), new ExecutionContext([]));

			self::assertNotEmpty($capturedSql);
		}

		/**
		 * Adding or dropping an index never checks for a live binding either.
		 * @return void
		 */
		public function testIndexOperationsNeverCheckForBindings(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('findBindingTriggersOnTable');

			(new AlterTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('alter Posts (drop index idx_a)'), new ExecutionContext([]));

			self::assertNotEmpty($capturedSql);
		}
	}
