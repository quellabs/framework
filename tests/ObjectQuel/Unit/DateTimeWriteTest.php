<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLAppend;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLUpsert;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * A Unix timestamp (date() or date arithmetic) written to a datetime column is converted to a datetime.
	 */
	class DateTimeWriteTest extends TestCase {

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		/**
		 * @param string $query QUEL replace statement
		 * @param string $dialect Target engine
		 * @return string The compiled statement
		 */
		private function compileReplace(string $query, string $dialect = 'mysql'): string {
			$ast = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstReplace::class, $ast);
			$parameters = [];
			return $this->replaceCompiler($dialect)->convertToSQL($ast, $parameters);
		}

		/**
		 * @param string $query QUEL append statement
		 * @return string The compiled MySQL statement
		 */
		private function compileAppend(string $query): string {
			$ast = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstAppend::class, $ast);

			$platform = new FakePlatformCapabilities('mysql');
			$replaceCompiler = $this->replaceCompiler('mysql');
			$compiler = new QuelToSQLAppend($this->em(), $platform, new QuelToSQLUpsert($this->em()->getEntityStore(), $platform, $replaceCompiler), $this->em()->getUnitOfWork()->getVersionValueHandler());
			$parameters = ['j' => '{}'];

			if ($ast->isInsertFromSelect()) {
				$compiler->prepareSource($ast->getSourceOrFail(), $parameters);
			}

			return $compiler->convertToSQL($ast, $parameters)->primarySql;
		}

		/**
		 * @param string $dialect Target engine
		 * @return QuelToSQLReplace
		 */
		private function replaceCompiler(string $dialect): QuelToSQLReplace {
			return new QuelToSQLReplace($this->em()->getEntityStore(), new FakePlatformCapabilities($dialect), $this->em()->getUnitOfWork()->getVersionValueHandler());
		}

		/**
		 * @return void
		 */
		public function testReplaceConvertsDateArithmeticOnAColumn(): void {
			$sql = $this->compileReplace('range of p is PostEntity replace p (createdAt = p.createdAt + date("1 day")) where p.id = 1');
			self::assertStringContainsString('SET `p`.`created_at` = FROM_UNIXTIME(UNIX_TIMESTAMP(`p`.`created_at`) + 86400)', $sql);
		}

		/**
		 * @return void
		 */
		public function testReplaceLeavesNativeDatetimesAlone(): void {
			$sql = $this->compileReplace('range of p is PostEntity replace p (createdAt = p.deletedAt, deletedAt = "2025-01-01") where p.id = 1');
			self::assertStringContainsString('SET `p`.`created_at` = `p`.`deleted_at`, `p`.`deleted_at` = ', $sql);
			self::assertStringNotContainsString('FROM_UNIXTIME', $sql);
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function dialectProvider(): array {
			return [
				'pgsql'  => ['pgsql', "(TO_TIMESTAMP(UNIX_TIMESTAMP() + 86400) AT TIME ZONE 'UTC')"],
				'sqlite' => ['sqlite', "datetime(UNIX_TIMESTAMP() + 86400, 'unixepoch')"],
				'sqlsrv' => ['sqlsrv', "DATEADD(SECOND, CAST(UNIX_TIMESTAMP() + 86400 AS BIGINT) % 86400, DATEADD(DAY, CAST(UNIX_TIMESTAMP() + 86400 AS BIGINT) / 86400, CAST('1970-01-01' AS DATETIME2)))"],
			];
		}

		/**
		 * The fake platform keeps UNIX_TIMESTAMP() for the forward conversion on every engine.
		 * @dataProvider dialectProvider
		 * @param string $dialect Target engine
		 * @param string $expected Converted value
		 * @return void
		 */
		public function testEachEngineConvertsBack(string $dialect, string $expected): void {
			$sql = $this->compileReplace('range of p is PostEntity replace p (createdAt = date("now") + date("1 day")) where p.id = 1', $dialect);
			self::assertStringContainsString($expected, $sql);
		}

		/**
		 * @return void
		 */
		public function testIntervalIsRejected(): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("'createdAt' is a datetime, but the value written to it is an interval.");
			$this->compileReplace('range of p is PostEntity replace p (createdAt = date("1 day")) where p.id = 1');
		}

		/**
		 * @return void
		 */
		public function testAppendConvertsValues(): void {
			$sql = $this->compileAppend('range of p is PostEntity append to p (title = "t", content = "", published = false, TestEnum = "pending", testJSON = :j, createdAt = date("now"), userId = 1)');
			self::assertStringContainsString(':j, FROM_UNIXTIME(UNIX_TIMESTAMP()), 1)', $sql);
		}

		/**
		 * @return void
		 */
		public function testAppendFromRetrieveConvertsTheSourceColumn(): void {
			$sql = $this->compileAppend('
				range of p is PostEntity
				range of q is PostEntity
				append to p (title, content, published, TestEnum, testJSON, createdAt, userId)
				retrieve (q.title, q.content, q.published, q.TestEnum, q.testJSON, at = q.createdAt + date("1 day"), q.userId) where q.id = 1
			');

			self::assertStringContainsString('`__append_source`.`q.testJSON`, FROM_UNIXTIME(`__append_source`.`at`), `__append_source`.`q.userId`', $sql);
		}
	}
