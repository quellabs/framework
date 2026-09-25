<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstDelete;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureCompiler;
	use Quellabs\ObjectQuel\ObjectQuel\Passes\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Passes\QueryNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CoerceDateTimeParameters;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLDelete;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLReplace;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Both sides of a datetime comparison are Unix timestamps: columns, date strings, parameters,
	 * routine variables and cursor fields, in retrieve, replace, delete and routine code alike.
	 */
	class DateTimeComparisonTest extends TestCase {

		/**
		 * @return int 2099-01-01 00:00:00 as a Unix timestamp, read in PHP's time zone like the compiler does
		 */
		private static function year2099(): int {
			return (new \DateTime('2099-01-01 00:00:00'))->getTimestamp();
		}

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		/**
		 * @param string $query QUEL delete statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string The compiled MySQL statement
		 */
		private function compileDelete(string $query, array &$parameters = []): string {
			$ast = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstDelete::class, $ast);
			return (new QuelToSQLDelete($this->em()->getEntityStore(), new FakePlatformCapabilities('mysql'), null))->convertToSQL($ast, $parameters);
		}

		/**
		 * @param string $query QUEL replace statement
		 * @param array<string, mixed> $parameters Bound parameters, by reference
		 * @return string The compiled MySQL statement
		 */
		private function compileReplace(string $query, array &$parameters = []): string {
			$ast = (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
			self::assertInstanceOf(AstReplace::class, $ast);
			$compiler = new QuelToSQLReplace($this->em()->getEntityStore(), new FakePlatformCapabilities('mysql'), null, $this->em()->getUnitOfWork()->getVersionValueHandler());
			return $compiler->convertToSQL($ast, $parameters);
		}

		/**
		 * Compiles a retrieve after assigning known datetime catalog types to its routine calls.
		 * @param string $query ObjectQuel retrieve
		 * @param array<string, mixed> $parameters Query parameters, by reference
		 * @param bool $typeCalls Whether to assign a known datetime type to routine calls
		 * @return string Generated MySQL SELECT
		 */
		private function compileRetrieveWithDatetimeCalls(string $query, array &$parameters = [], bool $typeCalls = true): string {
			$store = $this->em()->getEntityStore();
			$platform = new FakePlatformCapabilities('mysql');
			$retrieve = (new Parser(new Lexer($query), $store))->parse();
			self::assertInstanceOf(AstRetrieve::class, $retrieve);

			(new IdentifierTypeResolver($store))->resolve($retrieve);
			$calls = new CollectNodes(AstRoutineCall::class);
			$retrieve->accept($calls);

			if ($typeCalls) {
				foreach ($calls->getCollectedNodes() as $call) {
					$call->setRoutineReturnType('datetime');
				}
			}

			(new QueryNormalizer($store))->transform($retrieve);
			$retrieve->accept(new CoerceDateTimeParameters($parameters));
			(new SemanticAnalyzer($store, $platform))->validate($retrieve);
			(new QueryOptimizer($this->em(), $platform))->transform($retrieve, $parameters);

			return (new QuelToSQLRetrieve($store, $parameters, $platform))->convertToSQL($retrieve);
		}

		/**
		 * @return void
		 */
		public function testDeleteComparesDateArithmeticWithTheColumnAsATimestamp(): void {
			$sql = $this->compileDelete('range of p is PostEntity delete p where p.createdAt < date("now") - date("30 days")');
			self::assertStringContainsString('WHERE UNIX_TIMESTAMP(`p`.`created_at`) < UNIX_TIMESTAMP() - 2592000', $sql);
		}

		/**
		 * @return void
		 */
		public function testDateStringBecomesATimestamp(): void {
			$sql = $this->compileDelete('range of p is PostEntity delete p where p.createdAt > "2099-01-01"');
			self::assertStringContainsString('WHERE UNIX_TIMESTAMP(`p`.`created_at`) > ' . self::year2099(), $sql);
		}

		/**
		 * @return void
		 */
		public function testDeleteParameterBecomesATimestamp(): void {
			$parameters = ['since' => new \DateTime('2099-01-01')];
			$sql = $this->compileDelete('range of p is PostEntity delete p where p.createdAt > :since', $parameters);

			self::assertStringContainsString('WHERE UNIX_TIMESTAMP(`p`.`created_at`) > :since', $sql);
			self::assertSame(self::year2099(), $parameters['since']);
		}

		/**
		 * @return void
		 */
		public function testReplaceWhereComparesAsTimestamps(): void {
			$sql = $this->compileReplace('range of p is PostEntity replace p (title = "x") where "2099-01-01" <= p.createdAt');
			self::assertStringContainsString('WHERE ' . self::year2099() . ' <= UNIX_TIMESTAMP(`p`.`created_at`)', $sql);
		}

		/**
		 * A placeholder can't be a datetime string in SET and a timestamp in WHERE at once.
		 * @return void
		 */
		public function testReplaceRejectsAParameterAssignedAndComparedAsDatetime(): void {
			$parameters = ['at' => new \DateTime('2099-01-01')];

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Parameter ':at' is both assigned and compared with a datetime");
			$this->compileReplace('range of p is PostEntity replace p (createdAt = :at) where p.createdAt < :at', $parameters);
		}

		/**
		 * @return void
		 */
		public function testStringThatIsNotADateIsRejected(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("'yesterday' is compared with a datetime");
			$this->compileDelete('range of p is PostEntity delete p where p.createdAt > "yesterday"');
		}

		/**
		 * @return void
		 */
		public function testRoutineVariablesAndCursorFieldsCompareAsTimestamps(): void {
			$statements = (new ProcedureCompiler($this->em(), new FakePlatformCapabilities('mysql'), null))->compile('
				define function f (datetime since) void {
					range of p is PostEntity
					range of q is PostEntity
					cursor c = retrieve (at = p.createdAt) where p.createdAt > since
					foreach c {
						if (c.at < "2099-01-01") {
							replace q (title = "x") where q.createdAt < c.at
						}
					}
				}
			');

			$sql = end($statements);
			self::assertStringContainsString('WHERE UNIX_TIMESTAMP(`p`.`created_at`) > UNIX_TIMESTAMP(_v_since)', $sql);
			self::assertStringContainsString('IF UNIX_TIMESTAMP(_row_c$at) < ' . self::year2099() . ' THEN', $sql);
			self::assertStringContainsString('WHERE UNIX_TIMESTAMP(`q`.`created_at`) < UNIX_TIMESTAMP(_row_c$at)', $sql);
			self::assertStringContainsString('DECLARE _row_c$at DATETIME;', $sql);
		}

		/**
		 * A catalog-typed datetime routine result compares in Unix timestamp space, like a datetime column.
		 * @return void
		 */
		public function testTypedDatetimeRoutineCallComparesAsTimestamp(): void {
			$sql = $this->compileRetrieveWithDatetimeCalls('range of p is PostEntity retrieve (p.id) where p.createdAt > latest_created_at()');
			self::assertStringContainsString('UNIX_TIMESTAMP(`p`.`created_at`) > UNIX_TIMESTAMP(`latest_created_at`())', $sql);
		}

		/**
		 * Typed datetime routine results normalize in either operand position and when compared with each other.
		 * @return void
		 */
		public function testTypedDatetimeRoutineCallsNormalizeOnBothSides(): void {
			$left = $this->compileRetrieveWithDatetimeCalls('range of p is PostEntity retrieve (p.id) where latest_created_at() < p.createdAt');
			$both = $this->compileRetrieveWithDatetimeCalls('range of p is PostEntity retrieve (p.id) where latest_created_at() < older_created_at()');

			self::assertStringContainsString('UNIX_TIMESTAMP(`latest_created_at`()) < UNIX_TIMESTAMP(`p`.`created_at`)', $left);
			self::assertStringContainsString('UNIX_TIMESTAMP(`latest_created_at`()) < UNIX_TIMESTAMP(`older_created_at`())', $both);
		}

		/**
		 * A date literal compared with a datetime routine result is converted to the same Unix timestamp representation.
		 * @return void
		 */
		public function testTypedDatetimeRoutineCallConvertsDateLiteral(): void {
			$sql = $this->compileRetrieveWithDatetimeCalls('range of p is PostEntity retrieve (p.id) where latest_created_at() > "2099-01-01"');
			self::assertStringContainsString('UNIX_TIMESTAMP(`latest_created_at`()) > ' . (new \DateTime('2099-01-01'))->getTimestamp(), $sql);
		}

		/**
		 * Bound date parameters compared with a typed routine result become Unix timestamps.
		 * @return void
		 */
		public function testTypedDatetimeRoutineCallCoercesDateParameter(): void {
			$parameters = ['since' => new \DateTime('2099-01-01')];
			$sql = $this->compileRetrieveWithDatetimeCalls('range of p is PostEntity retrieve (p.id) where latest_created_at() > :since', $parameters);

			self::assertStringContainsString('UNIX_TIMESTAMP(`latest_created_at`()) > :since', $sql);
			self::assertSame((new \DateTime('2099-01-01'))->getTimestamp(), $parameters['since']);
		}

		/**
		 * Datetime routine results normalize before timestamp arithmetic and retain SQL NULL comparison behavior.
		 * @return void
		 */
		public function testTypedDatetimeRoutineCallNormalizesArithmeticAndNullComparison(): void {
			$arithmetic = $this->compileRetrieveWithDatetimeCalls('range of p is PostEntity retrieve (p.id) where latest_created_at() + date("1 day") > p.createdAt');
			$nullComparison = $this->compileRetrieveWithDatetimeCalls('range of p is PostEntity retrieve (p.id) where latest_created_at() = null');

			self::assertStringContainsString('UNIX_TIMESTAMP(`latest_created_at`()) + 86400 > UNIX_TIMESTAMP(`p`.`created_at`)', $arithmetic);
			self::assertStringContainsString('UNIX_TIMESTAMP(`latest_created_at`()) = NULL', $nullComparison);
		}

		/**
		 * An unknown routine return type stays native and is not guessed to be a datetime.
		 * @return void
		 */
		public function testUnknownRoutineReturnTypeIsNotNormalizedAsDatetime(): void {
			$parameters = [];
			$sql = $this->compileRetrieveWithDatetimeCalls('range of p is PostEntity retrieve (p.id) where p.createdAt > latest_created_at()', $parameters, false);

			self::assertStringContainsString('UNIX_TIMESTAMP(`p`.`created_at`) > `latest_created_at`()', $sql);
		}
	}
