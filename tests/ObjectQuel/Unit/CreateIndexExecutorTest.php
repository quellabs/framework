<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Execution\Executors\CreateIndexExecutor;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCreateIndex;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Coverage for CreateIndexExecutor's schema-introspection step — the
	 * part QuelToSQLCreateIndexTest can't reach, since it only exercises the
	 * pure-string compiler with already-resolved values handed in directly.
	 * mysql/pgsql fulltext and the plain/unique case need no introspection
	 * at all; sqlsrv/sqlite fulltext do (see CreateIndexExecutor's
	 * resolveFulltextPrerequisites()), which is what's under test here via
	 * a mocked DatabaseAdapter — no live connection of any of these four
	 * dialects actually exists in this suite.
	 */
	class CreateIndexExecutorTest extends TestCase {

		private function statementIndex(): AstCreateIndex {
			return new AstCreateIndex('ArticleEntity', 'article_fulltext_idx', ['title', 'body'], false, 'fulltext');
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

		public function testMysqlFulltextNeedsNoSchemaIntrospection(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('getIndexes');
			$connection->expects(self::never())->method('getPrimaryKey');

			(new CreateIndexExecutor($connection, new FakePlatformCapabilities('mysql')))->execute($this->statementIndex());

			self::assertSame(['CREATE FULLTEXT INDEX `article_fulltext_idx` ON `ArticleEntity` (`title`, `body`)'], $capturedSql);
		}

		public function testPostgresFulltextNeedsNoSchemaIntrospection(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->expects(self::never())->method('getIndexes');
			$connection->expects(self::never())->method('getPrimaryKey');

			(new CreateIndexExecutor($connection, new FakePlatformCapabilities('pgsql')))->execute($this->statementIndex());

			self::assertCount(1, $capturedSql);
			self::assertStringContainsString('USING GIN', $capturedSql[0]);
		}

		public function testSqlServerFulltextPrefersThePrimaryKeyIndex(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([
				'uniq_email' => ['type' => 'unique', 'columns' => ['email'], 'length' => null],
				'PK_ArticleEntity' => ['type' => 'primary', 'columns' => ['id'], 'length' => null],
			]);

			(new CreateIndexExecutor($connection, new FakePlatformCapabilities('sqlsrv')))->execute($this->statementIndex());

			self::assertCount(3, $capturedSql);
			self::assertStringContainsString('KEY INDEX [PK_ArticleEntity]', $capturedSql[1]);
		}

		public function testSqlServerFulltextFallsBackToAUniqueIndexWhenNoPrimaryKeyExists(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([
				'uniq_email' => ['type' => 'unique', 'columns' => ['email'], 'length' => null],
			]);

			(new CreateIndexExecutor($connection, new FakePlatformCapabilities('sqlsrv')))->execute($this->statementIndex());

			self::assertStringContainsString('KEY INDEX [uniq_email]', $capturedSql[1]);
		}

		public function testSqlServerFulltextRejectsATableWithNoPrimaryOrUniqueIndex(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getIndexes')->willReturn([
				'idx_plain' => ['type' => 'index', 'columns' => ['created_at'], 'length' => null],
			]);

			$this->expectException(QuelException::class);

			(new CreateIndexExecutor($connection, new FakePlatformCapabilities('sqlsrv')))->execute($this->statementIndex());
		}

		public function testSqliteFulltextResolvesThePrimaryKeyColumn(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKey')->willReturn('id');

			(new CreateIndexExecutor($connection, new FakePlatformCapabilities('sqlite')))->execute($this->statementIndex());

			self::assertCount(4, $capturedSql);
			self::assertStringContainsString("content_rowid='id'", $capturedSql[0]);
		}

		public function testSqliteFulltextRejectsATableWithNoPrimaryKey(): void {
			$capturedSql = [];
			$connection = $this->mockConnection($capturedSql);
			$connection->method('getPrimaryKey')->willReturn('');

			$this->expectException(QuelException::class);

			(new CreateIndexExecutor($connection, new FakePlatformCapabilities('sqlite')))->execute($this->statementIndex());
		}

		public function testThrowsWhenTheDdlStatementFails(): void {
			$connection = $this->createMock(DatabaseAdapter::class);
			$connection->method('execute')->willReturn(null);
			$connection->method('getLastErrorMessage')->willReturn('duplicate key name');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('duplicate key name');

			(new CreateIndexExecutor($connection, new FakePlatformCapabilities('mysql')))
				->execute(new AstCreateIndex('ArchiveLog', 'archive_log_email_idx', ['email'], false));
		}
	}
