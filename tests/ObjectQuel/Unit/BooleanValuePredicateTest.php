<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRoutineCall;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\IdentifierTypeResolver;
	use Quellabs\ObjectQuel\ObjectQuel\Pipeline\QueryNormalizer;
	use Quellabs\ObjectQuel\ObjectQuel\Routines\ProcedureCompiler;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQL\QuelToSQLRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\SemanticAnalyzer;
	use Quellabs\ObjectQuel\ObjectQuel\Visitors\CollectNodes;
	use Quellabs\ObjectQuel\Planner\QueryOptimizer;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Scalar booleans in predicate positions and predicates in value positions, per engine.
	 * The expected SQL is not run against SQL Server or PostgreSQL here.
	 */
	class BooleanValuePredicateTest extends TestCase {

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private function em(): EntityManager {
			$em = $GLOBALS['test_em'];
			self::assertInstanceOf(EntityManager::class, $em);
			return $em;
		}

		/**
		 * Runs a retrieve through the query pipeline with every routine call typed as returning $callType.
		 * @param string $databaseType Target engine
		 * @param string $query ObjectQuel retrieve
		 * @param string|null $callType Abstract return type given to every routine call
		 * @return string The SELECT statement
		 */
		private function retrieveSql(string $databaseType, string $query, ?string $callType = 'boolean'): string {
			$em = $this->em();
			$store = $em->getEntityStore();
			$retrieve = (new Parser(new Lexer($query), $store))->parse();
			self::assertInstanceOf(AstRetrieve::class, $retrieve);

			$calls = new CollectNodes(AstRoutineCall::class);
			$retrieve->accept($calls);

			foreach ($calls->getCollectedNodes() as $call) {
				$call->setRoutineReturnType($callType);
			}

			$platform = new FakePlatformCapabilities($databaseType);
			$parameters = [];
			(new IdentifierTypeResolver($store))->resolve($retrieve);
			(new QueryNormalizer($store))->transform($retrieve);
			(new SemanticAnalyzer($store, $platform))->validate($retrieve);
			(new QueryOptimizer($em, $platform))->transform($retrieve, $parameters);

			return (new QuelToSQLRetrieve($store, $parameters, $platform, $databaseType === 'sqlsrv' ? 'dbo' : null))->convertToSQL($retrieve);
		}

		/**
		 * @param string $databaseType Target engine
		 * @param string $source Routine source
		 * @return string The CREATE statement
		 */
		private function routineSql(string $databaseType, string $source): string {
			$statements = (new ProcedureCompiler($this->em(), new FakePlatformCapabilities($databaseType), $databaseType === 'sqlsrv' ? 'dbo' : null))->compile($source);
			self::assertNotEmpty($statements);
			return $statements[array_key_last($statements)];
		}

		/**
		 * @return array<string, array{string, non-empty-string}>
		 */
		public static function sqlServerConditions(): array {
			return [
				'direct call' => ['where f(u.id)', 'WHERE [dbo].[f]([u].[id]) = 1'],
				'negated call' => ['where not f(u.id)', 'WHERE NOT([dbo].[f]([u].[id]) = 1)'],
				'logical operands' => ['where u.banned or f(u.id) and not f(u.id)', 'WHERE [u].[banned] = 1 OR [dbo].[f]([u].[id]) = 1 AND NOT([dbo].[f]([u].[id]) = 1)'],
				'compared to true' => ['where f(u.id) = true', 'WHERE [dbo].[f]([u].[id]) = 1'],
				'compared to false' => ['where f(u.id) = false', 'WHERE [dbo].[f]([u].[id]) = 0'],
				'boolean cast' => ['where (bool)u.id', 'WHERE CAST([u].[id] AS BIT) = 1'],
				'negated cast' => ['where not (bool)u.id', 'WHERE NOT(CAST([u].[id] AS BIT) = 1)'],
				'column compared to true' => ['where u.banned = true', 'WHERE [u].[banned] = 1'],
				'predicate operand' => ['where u.banned = (u.id > 3)', 'WHERE [u].[banned] = CASE WHEN [u].[id] > 3 THEN 1 WHEN NOT ([u].[id] > 3) THEN 0 END'],
			];
		}

		/**
		 * @param string $clause WHERE clause in ObjectQuel
		 * @param non-empty-string $expected Expected WHERE clause
		 * @return void
		 */
		#[DataProvider('sqlServerConditions')]
		public function testSqlServerConditions(string $clause, string $expected): void {
			$sql = $this->retrieveSql('sqlsrv', "range of u is UserEntity retrieve (u.id) {$clause}");
			self::assertStringEndsWith($expected, $sql);
		}

		/**
		 * A predicate selected as a value becomes a NULL-preserving CASE; a scalar boolean stays as is.
		 * @return void
		 */
		public function testSqlServerSelectedValues(): void {
			$sql = $this->retrieveSql('sqlsrv', 'range of u is UserEntity retrieve (x = f(u.id), y = (u.id > 3), z = (bool)(u.id > 3))');

			self::assertSame('SELECT [dbo].[f]([u].[id]) as [x],CASE WHEN [u].[id] > 3 THEN 1 WHEN NOT ([u].[id] > 3) THEN 0 END as [y],CAST(CASE WHEN [u].[id] > 3 THEN 1 WHEN NOT ([u].[id] > 3) THEN 0 END AS BIT) as [z] FROM [users] as [u]', $sql);
		}

		/**
		 * @return array<string, array{string, string, non-empty-string}>
		 */
		public static function nativeBooleanEngines(): array {
			return [
				'pgsql' => ['pgsql', 'SELECT "u"."id" > 3 as "y","u"."id" as "u.id" FROM "users" as "u" WHERE "f"("u"."id") AND NOT("f"("u"."id") = true)', 'WHERE ("u"."id" > 3) = "f"("u"."id")'],
				'mysql' => ['mysql', 'SELECT `u`.`id` > 3 as `y`,`u`.`id` as `u.id` FROM `users` as `u` WHERE `f`(`u`.`id`) AND NOT(`f`(`u`.`id`) = true)', 'WHERE (`u`.`id` > 3) = `f`(`u`.`id`)'],
			];
		}

		/**
		 * Engines with boolean literals use predicates and boolean values interchangeably; nested comparisons keep their grouping.
		 * @param string $databaseType Target engine
		 * @param string $expected Expected SELECT statement
		 * @param non-empty-string $nestedComparison Expected WHERE clause comparing a comparison
		 * @return void
		 */
		#[DataProvider('nativeBooleanEngines')]
		public function testNativeBooleanEngines(string $databaseType, string $expected, string $nestedComparison): void {
			self::assertSame($expected, $this->retrieveSql($databaseType, 'range of u is UserEntity retrieve (y = (u.id > 3)) where f(u.id) and not (f(u.id) = true)'));
			self::assertStringEndsWith($nestedComparison, $this->retrieveSql($databaseType, 'range of u is UserEntity retrieve (u.id) where (u.id > 3) = f(u.id)'));
		}

		/**
		 * @return array<string, array{string, non-empty-string, non-empty-string}>
		 */
		public static function isEmptyBooleans(): array {
			return [
				'pgsql' => ['pgsql', 'WHERE ("u"."banned" IS NULL OR "u"."banned" = false)', 'WHERE (("u"."id" > 3) IS NULL OR ("u"."id" > 3) = false)'],
				'mysql' => ['mysql', 'WHERE (`u`.`banned` IS NULL OR `u`.`banned` = false)', 'WHERE ((`u`.`id` > 3) IS NULL OR (`u`.`id` > 3) = false)'],
				'sqlsrv' => ['sqlsrv', 'WHERE ([u].[banned] IS NULL OR [u].[banned] = 0)', 'WHERE ((CASE WHEN [u].[id] > 3 THEN 1 WHEN NOT ([u].[id] > 3) THEN 0 END) IS NULL OR (CASE WHEN [u].[id] > 3 THEN 1 WHEN NOT ([u].[id] > 3) THEN 0 END) = 0)'],
			];
		}

		/**
		 * is_empty() compares a boolean column or predicate with the engine's false, not '' or 0.
		 * @param string $databaseType Target engine
		 * @param non-empty-string $column Expected WHERE clause for a boolean column
		 * @param non-empty-string $predicate Expected WHERE clause for a comparison
		 * @return void
		 */
		#[DataProvider('isEmptyBooleans')]
		public function testIsEmptyComparesBooleansWithFalse(string $databaseType, string $column, string $predicate): void {
			self::assertStringEndsWith($column, $this->retrieveSql($databaseType, 'range of u is UserEntity retrieve (u.id) where is_empty(u.banned)'));
			self::assertStringEndsWith($predicate, $this->retrieveSql($databaseType, 'range of u is UserEntity retrieve (u.id) where is_empty(u.id > 3)'));
		}

		/**
		 * Function arguments take comparisons and AND/OR without extra parentheses.
		 * @return void
		 */
		public function testFunctionArgumentsTakeLogicalExpressions(): void {
			self::assertStringEndsWith('WHERE ((`u`.`id` > 3 OR `u`.`banned`) IS NULL OR (`u`.`id` > 3 OR `u`.`banned`) = false)', $this->retrieveSql('mysql', 'range of u is UserEntity retrieve (u.id) where is_empty(u.id > 3 or u.banned)'));
			self::assertStringEndsWith('WHERE `f`(`u`.`id` > 3 AND `u`.`banned`, `u`.`id`)', $this->retrieveSql('mysql', 'range of u is UserEntity retrieve (u.id) where f(u.id > 3 and u.banned, u.id)'));
		}

		/**
		 * A comparison with 1 isn't folded into the routine call itself, which may return an integer.
		 * @return void
		 */
		public function testIntegerCallComparedToOneIsKept(): void {
			self::assertStringEndsWith('WHERE `f`(`u`.`id`) = 1', $this->retrieveSql('mysql', 'range of u is UserEntity retrieve (u.id) where f(u.id) = 1', 'integer'));
		}

		/**
		 * Routine-body calls have no known return type, yet convert by position: compared to 1 as a condition, kept as a value.
		 * @return void
		 */
		public function testSqlServerRoutineFunction(): void {
			$sql = $this->routineSql('sqlsrv', '
				define function a (int n, boolean flag) boolean {
					boolean x = b(n) and n > 3
					boolean y = b(n)
					if (b(n)) {
						return flag
					}
					if ((bool)n) {
						return x = (n > 2)
					}
					return (bool)(n > 1)
				}
			');

			self::assertSame(<<<'SQL'
				CREATE OR ALTER FUNCTION [dbo].[a](@n INT, @flag BIT)
				RETURNS BIT
				AS
				BEGIN
					DECLARE @x BIT;
					DECLARE @y BIT;
					SET @x = CASE WHEN [dbo].[b](@n) = 1 AND @n > 3 THEN 1 WHEN NOT ([dbo].[b](@n) = 1 AND @n > 3) THEN 0 END;
					SET @y = [dbo].[b](@n);
					IF [dbo].[b](@n) = 1
					BEGIN
						RETURN @flag;
					END;
					IF CAST(@n AS BIT) = 1
					BEGIN
						RETURN CASE WHEN @x = CASE WHEN @n > 2 THEN 1 WHEN NOT (@n > 2) THEN 0 END THEN 1 WHEN NOT (@x = CASE WHEN @n > 2 THEN 1 WHEN NOT (@n > 2) THEN 0 END) THEN 0 END;
					END;
					RETURN CAST(CASE WHEN @n > 1 THEN 1 WHEN NOT (@n > 1) THEN 0 END AS BIT);
				END;
				SQL, $sql);
		}

		/**
		 * Written values and cursor columns convert predicates; write and cursor conditions compare calls to 1.
		 * @return void
		 */
		public function testSqlServerRoutineStatements(): void {
			$sql = $this->routineSql('sqlsrv', '
				define function a (int n) void {
					range of u is UserEntity
					cursor cc = retrieve (u.id, k = (u.id > n)) where b(u.id)
					foreach cc {
						if (cc.k) {
							replace u (banned = (u.id > n)) where b(u.id)
						}
					}
					replace u (banned = b(n)) where not b(u.id)
					delete u where b(u.id) or not b(n)
					append to u (username = "x", password = "y", banned = (n > 3))
				}
			');

			self::assertStringContainsString('CURSOR LOCAL FORWARD_ONLY STATIC READ_ONLY FOR SELECT [u].[id] as [id],CASE WHEN [u].[id] > @n THEN 1 WHEN NOT ([u].[id] > @n) THEN 0 END as [k] FROM [users] as [u] WHERE [dbo].[b]([u].[id]) = 1;', $sql);
			self::assertStringContainsString('IF @_row_cc$k = 1', $sql);
			self::assertStringContainsString('UPDATE [u] SET [u].[banned] = CASE WHEN [u].[id] > @n THEN 1 WHEN NOT ([u].[id] > @n) THEN 0 END FROM [users] as [u] WHERE [dbo].[b]([u].[id]) = 1;', $sql);
			self::assertStringContainsString('UPDATE [u] SET [u].[banned] = [dbo].[b](@n) FROM [users] as [u] WHERE NOT([dbo].[b]([u].[id]) = 1);', $sql);
			self::assertStringContainsString('DELETE [u] FROM [users] as [u] WHERE [dbo].[b]([u].[id]) = 1 OR NOT([dbo].[b](@n) = 1);', $sql);
			self::assertStringContainsString("INSERT INTO [users] ([username], [password], [banned]) VALUES ('x', 'y', CASE WHEN @n > 3 THEN 1 WHEN NOT (@n > 3) THEN 0 END);", $sql);
		}

		/**
		 * @return array<string, array{string, list<string>}>
		 */
		public static function nativeBooleanRoutines(): array {
			return [
				'pgsql' => ['pgsql', ['"x" := "b"("_routine"."n") AND "_routine"."n" > 3;', 'IF "b"("_routine"."n") THEN', 'RETURN "_routine"."n" > 2;']],
				'mysql' => ['mysql', ['SET _v_x = `b`(_v_n) AND _v_n > 3;', 'IF `b`(_v_n) THEN', 'RETURN _v_n > 2;']],
			];
		}

		/**
		 * Engines with boolean literals keep routine-body calls and predicates unconverted.
		 * @param string $databaseType Target engine
		 * @param list<string> $expected Statements the routine contains
		 * @return void
		 */
		#[DataProvider('nativeBooleanRoutines')]
		public function testNativeBooleanRoutines(string $databaseType, array $expected): void {
			$sql = $this->routineSql($databaseType, '
				define function a (int n) boolean {
					boolean x = b(n) and n > 3
					if (b(n)) {
						return x
					}
					return (n > 2)
				}
			');

			foreach ($expected as $statement) {
				self::assertStringContainsString($statement, $sql);
			}
		}
	}
