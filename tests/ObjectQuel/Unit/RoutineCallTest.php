<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstCount;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstReplace;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\AstInterface;
	use Quellabs\ObjectQuel\ObjectQuel\Passes\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\ProcedureCompiler;
	use Quellabs\ObjectQuel\ObjectQuel\Passes\QueryNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLReplace;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Calls to stored routines inside queries: parsing, per-engine SQL and where they're rejected.
	 */
	class RoutineCallTest extends TestCase {

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		/**
		 * @param string $query ObjectQuel statement
		 * @return AstInterface|null Parsed statement
		 */
		private function parse(string $query): ?AstInterface {
			return (new Parser(new Lexer($query), $this->em()->getEntityStore()))->parse();
		}

		/**
		 * @param string $query ObjectQuel statement
		 * @return AstInterface The first target-list expression of the parsed retrieve
		 */
		private function firstValue(string $query): AstInterface {
			$retrieve = $this->parse($query);
			self::assertInstanceOf(AstRetrieve::class, $retrieve);
			$value = $retrieve->getValues()[0];
			self::assertInstanceOf(AstAlias::class, $value);
			return $value->getExpression();
		}

		/**
		 * Runs a retrieve through the query pipeline and compiles it for one engine.
		 * @param string $databaseType Target engine
		 * @param string $query ObjectQuel retrieve
		 * @return string The SELECT statement
		 */
		private function retrieveSql(string $databaseType, string $query): string {
			$retrieve = $this->parse($query);
			self::assertInstanceOf(AstRetrieve::class, $retrieve);

			$store = $this->em()->getEntityStore();
			$platform = new FakePlatformCapabilities($databaseType);
			$parameters = [];

			(new IdentifierTypeResolver($store))->resolve($retrieve);
			(new QueryNormalizer($store))->transform($retrieve);
			(new SemanticAnalyzer($store, $platform))->validate($retrieve);
			(new QueryOptimizer($this->em(), $platform))->transform($retrieve, $parameters);

			return (new QuelToSQLRetrieve($store, $parameters, $platform))->convertToSQL($retrieve);
		}

		/**
		 * @return void
		 */
		public function testParsesACallWithArguments(): void {
			$call = $this->firstValue('range of u is UserEntity retrieve (n = f(u.id, 1 + 2))');

			self::assertInstanceOf(AstRoutineCall::class, $call);
			self::assertSame('f', $call->getName());
			self::assertCount(2, $call->getArguments());
		}

		/**
		 * @return void
		 */
		public function testParsesACallWithoutArguments(): void {
			$call = $this->firstValue('range of u is UserEntity retrieve (n = f())');

			self::assertInstanceOf(AstRoutineCall::class, $call);
			self::assertSame([], $call->getArguments());
		}

		/**
		 * @return void
		 */
		public function testBuiltinsTakePrecedence(): void {
			self::assertInstanceOf(AstCount::class, $this->firstValue('range of u is UserEntity retrieve (n = COUNT(u.id))'));
		}

		/**
		 * @return void
		 */
		public function testDottedNameIsNotACall(): void {
			$this->expectException(ParserException::class);
			$this->expectExceptionMessage('Command u.f is not valid.');
			$this->parse('range of u is UserEntity retrieve (n = u.f(1))');
		}

		/**
		 * @return array<string, array{string, string, string}>
		 */
		public static function retrieveCalls(): array {
			return [
				'pgsql' => ['pgsql', '"f"("u"."id", 1)', '"g"() > 0'],
				'sqlsrv' => ['sqlsrv', '[dbo].[f]([u].[id], 1)', '[dbo].[g]() > 0'],
				'mysql' => ['mysql', '`f`(`u`.`id`, 1)', '`g`() > 0'],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $value Expected call in the select list
		 * @param string $condition Expected call in the WHERE clause
		 * @return void
		 */
		#[DataProvider('retrieveCalls')]
		public function testRetrieveSql(string $databaseType, string $value, string $condition): void {
			$sql = $this->retrieveSql($databaseType, 'range of u is UserEntity retrieve (n = f(u.id, 1)) where g() > 0');

			self::assertStringContainsString($value, $sql);
			self::assertStringContainsString($condition, $sql);
		}

		/**
		 * @return array<string, array{string, string}>
		 */
		public static function replaceStatements(): array {
			return [
				'pgsql' => ['pgsql', 'UPDATE "users" as "u" SET "username" = "f"("u"."username") WHERE "u"."id" = 1'],
				'sqlsrv' => ['sqlsrv', 'UPDATE [u] SET [u].[username] = [dbo].[f]([u].[username]) FROM [users] as [u] WHERE [u].[id] = 1'],
				'mysql' => ['mysql', 'UPDATE `users` as `u` SET `u`.`username` = `f`(`u`.`username`) WHERE `u`.`id` = 1'],
			];
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $expected Expected UPDATE statement
		 * @return void
		 */
		#[DataProvider('replaceStatements')]
		public function testReplaceSql(string $databaseType, string $expected): void {
			$replace = $this->parse('range of u is UserEntity replace u (username = f(u.username)) where u.id = 1');
			self::assertInstanceOf(AstReplace::class, $replace);

			$em = $this->em();
			$compiler = new QuelToSQLReplace($em->getEntityStore(), new FakePlatformCapabilities($databaseType), $em->getUnitOfWork()->getVersionValueHandler());
			$parameters = [];
			self::assertSame($expected, $compiler->convertToSQL($replace, $parameters));
		}

		/**
		 * @return void
		 */
		public function testRangelessRetrieveCantCall(): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("'f' is a routine call, which the database runs, but this query runs in PHP");
			$this->retrieveSql('mysql', 'retrieve (n = f(1))');
		}

		/**
		 * @return array<string, array{string}>
		 */
		public static function engines(): array {
			return ['pgsql' => ['pgsql'], 'sqlsrv' => ['sqlsrv'], 'mysql' => ['mysql']];
		}

		/**
		 * @param string $databaseType Target engine
		 * @return void
		 */
		#[DataProvider('engines')]
		public function testRoutineCantBeNamedAfterABuiltin(string $databaseType): void {
			$this->expectException(SemanticException::class);
			$this->expectExceptionMessage("'Count' is a built-in function, so a routine by that name could never be called.");
			(new ProcedureCompiler($this->em(), new FakePlatformCapabilities($databaseType)))->compile('define function Count () integer { return 1 }');
		}
	}
