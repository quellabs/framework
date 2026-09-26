<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAppend;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureCompiler;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLAppend;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLUpsert;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * A Unix timestamp (date() or date arithmetic) written to a datetime column, variable or return value is converted to a datetime.
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
			$compiler = new QuelToSQLAppend($this->em(), $platform, null, new QuelToSQLUpsert($this->em()->getEntityStore(), $platform, null, $replaceCompiler), $this->em()->getUnitOfWork()->getVersionValueHandler());
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
			return new QuelToSQLReplace($this->em()->getEntityStore(), new FakePlatformCapabilities($dialect), $dialect === 'sqlsrv' ? 'dbo' : null, $this->em()->getUnitOfWork()->getVersionValueHandler());
		}

		/**
		 * @param string $routine EQUEL routine definition
		 * @return string The statement creating it on MySQL
		 */
		private function compileRoutine(string $routine): string {
			$statements = (new ProcedureCompiler($this->em(), new FakePlatformCapabilities('mysql'), null))->compile($routine);
			return (string)end($statements);
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
			self::assertStringContainsString("SET `p`.`created_at` = `p`.`deleted_at`, `p`.`deleted_at` = '2025-01-01'", $sql);
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
		public function testReplaceConvertsAnIntegerColumn(): void {
			$sql = $this->compileReplace('range of p is PostEntity replace p (createdAt = p.id) where p.id = 1');
			self::assertStringContainsString('SET `p`.`created_at` = FROM_UNIXTIME(`p`.`id`)', $sql);
		}

		/**
		 * @return void
		 */
		public function testReplaceRejectsDateArithmeticWithAScalarColumn(): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("cannot use '+' between a date() value and a plain scalar");
			$this->compileReplace('range of p is PostEntity replace p (createdAt = date("now") + p.id) where p.id = 1');
		}

		/**
		 * @return void
		 */
		public function testReplaceRejectsDateArithmeticWithAScalarColumnInWhere(): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("cannot use '-' between a date() value and a plain scalar");
			$this->compileReplace('range of p is PostEntity replace p (title = "t") where date("now") - p.id > 5');
		}

		/**
		 * @return void
		 */
		public function testAppendRejectsDateArithmeticWithAParameter(): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("cannot use '+' between a date() value and a plain scalar");
			$this->compileAppend('range of p is PostEntity append to p (title = "t", content = "", published = false, TestEnum = "pending", testJSON = :j, createdAt = date("now") + :j, userId = 1)');
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

		/**
		 * @return void
		 */
		public function testRoutineConvertsVariablesReturnsAndWrites(): void {
			$sql = $this->compileRoutine('
				define function f (datetime since) datetime {
					datetime next = since + date("1 day")
					range of p is PostEntity
					replace p (createdAt = next - date("1 hour")) where p.id = 1
					return next + date("1 day")
				}
			');

			self::assertStringContainsString('SET _v_next = FROM_UNIXTIME(UNIX_TIMESTAMP(_v_since) + 86400);', $sql);
			self::assertStringContainsString('SET `p`.`created_at` = FROM_UNIXTIME(UNIX_TIMESTAMP(_v_next) - 3600)', $sql);
			self::assertStringContainsString('RETURN FROM_UNIXTIME(UNIX_TIMESTAMP(_v_next) + 86400);', $sql);
		}

		/**
		 * @return void
		 */
		public function testRoutineRejectsAnIntervalReturnedAsADatetime(): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("'f' is a datetime, but the value written to it is an interval.");
			$this->compileRoutine('define function f () datetime { return date("1 day") }');
		}

		/**
		 * A datetime-valued routine call written to a datetime column remains a native datetime expression.
		 * @return void
		 */
		public function testDatetimeRoutineResultWrittenToDatetimeColumnIsNotConverted(): void {
			$store = $this->em()->getEntityStore();
			$replace = (new Parser(new Lexer('range of p is PostEntity replace p (createdAt = latest_created_at()) where p.id = 1'), $store))->parse();
			self::assertInstanceOf(AstReplace::class, $replace);

			$calls = new CollectNodes(AstRoutineCall::class);
			$replace->accept($calls);

			foreach ($calls->getCollectedNodes() as $call) {
				$call->setRoutineReturnType('datetime');
			}

			$parameters = [];
			$sql = $this->replaceCompiler('mysql')->convertToSQL($replace, $parameters);
			self::assertStringContainsString('SET `p`.`created_at` = `latest_created_at`()', $sql);
		}
	}
