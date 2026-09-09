<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\Executors\CreateTableExecutor;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateTable;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Coverage for CreateTableExecutor's embedded-index assembly and
	 * transaction-wrapping steps — the parts the suite's live MySQL
	 * connection (see tests/Integration/CreateTableTest) can't exercise,
	 * since MySQL DDL auto-commits per statement (see
	 * PlatformCapabilitiesInterface::supportsTransactionalDDL()). No live
	 * connection of any of these four dialects actually exists in this
	 * suite — a mocked DatabaseAdapter stands in, same as
	 * AlterTableExecutorTest.
	 */
	class CreateTableExecutorTest extends TestCase {

		private function parse(string $query): AstCreateTable {
			$ast = (new Parser(new Lexer($query), $GLOBALS['test_em']->getEntityStore()))->parse();
			self::assertInstanceOf(AstCreateTable::class, $ast);
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

		public function testEmbeddedIndexIsAssembledIntoARealCreateIndexStatementAfterTheTable(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);

			(new CreateTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('create Posts (id = integer, index idx_id (id))'));

			self::assertSame(
				['CREATE TABLE `Posts` (`id` INT)', 'CREATE INDEX `idx_id` ON `Posts` (`id`)'],
				$capturedSql
			);
		}

		public function testEmbeddedForeignKeyWithNoColumnListResolvesTheReferencedTablesPrimaryKey(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->with('Users')->willReturn(['id']);

			(new CreateTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('create Posts (id = integer, author_id = integer not null, foreign key (author_id) references Users)'));

			self::assertSame(
				['CREATE TABLE `Posts` (`id` INT, `author_id` INT NOT NULL, CONSTRAINT `fk_Posts_author_id` FOREIGN KEY (`author_id`) REFERENCES `Users` (`id`) ON DELETE RESTRICT ON UPDATE NO ACTION)'],
				$capturedSql
			);
		}

		public function testEmbeddedForeignKeyWithNoColumnListRejectsATargetWithNoPrimaryKey(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKeyColumns')->with('Users')->willReturn([]);

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("the table has no primary key");

			(new CreateTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('create Posts (id = integer, author_id = integer not null, foreign key (author_id) references Users)'));
		}

		public function testDoesNotWrapInATransactionOnMysqlSinceDdlAutoCommitsThere(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('beginTrans');
			$connection->expects(self::never())->method('commitTrans');
			$connection->expects(self::never())->method('rollbackTrans');

			(new CreateTableExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute($this->parse('create Posts (id = integer)'));
		}

		public function testWrapsTheStatementSequenceInATransactionOnAPlatformThatSupportsTransactionalDdl(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::once())->method('beginTrans');
			$connection->expects(self::once())->method('commitTrans');
			$connection->expects(self::never())->method('rollbackTrans');

			(new CreateTableExecutor($connection, new FakePlatformCapabilities('pgsql')))
				->execute($this->parse('create Posts (id = integer, index idx_id (id))'));

			self::assertCount(2, $capturedSql);
		}

		public function testRollsBackTheTransactionWhenAStatementFailsOnAPlatformThatSupportsTransactionalDdl(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willReturn(null);
			$connection->method('getLastErrorMessage')->willReturn('table already exists');
			$connection->expects(self::once())->method('beginTrans');
			$connection->expects(self::never())->method('commitTrans');
			$connection->expects(self::once())->method('rollbackTrans');

			$this->expectException(QuelException::class);

			(new CreateTableExecutor($connection, new FakePlatformCapabilities('pgsql')))
				->execute($this->parse('create Posts (id = integer)'));
		}
	}
