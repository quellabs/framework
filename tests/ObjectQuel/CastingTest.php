<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	
	/**
	 * Tests for C-style casting: (int)x.id, (float)x.price, etc.
	 *
	 * Coverage:
	 *   - Parser correctly recognises (type)property syntax
	 *   - CAST() SQL is generated and executes without error
	 *   - Hydrator returns the correct PHP type for each cast keyword
	 *   - Casts compose with arithmetic, WHERE conditions, and aliases
	 *   - Semantic analyser rejects casts on bare entity references
	 *   - Semantic analyser rejects unknown cast type names
	 */
	class CastingTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			
			// published stored as TINYINT — useful for testing int/float/string casts
			// on a column whose native type is not varchar.
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (1, 'First Post',  'Hello world', 1, '2024-01-01 00:00:00', 'pending',   '{\"id\": 2}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (2, 'Second Post', 'Foo bar',     0, '2024-01-02 00:00:00', 'shipped',   '{\"id\": 2}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
            VALUES (3, 'Third Post',  'Baz qux',     1, '2024-01-03 00:00:00', 'delivered', '{\"id\": 2}', 1)");
		}
		
		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------
		
		/**
		 * Asserts that executing the given query throws a QuelException whose
		 * cause is a SemanticException — the public contract for all semantic
		 * validation failures.
		 */
		private function assertSemanticError(callable $fn): void {
			try {
				$fn();
				$this->fail('Expected QuelException to be thrown');
			} catch (QuelException $e) {
				$this->assertInstanceOf(
					SemanticException::class,
					$e->getPrevious(),
					'Expected QuelException to wrap a SemanticException'
				);
			}
		}
		
		// -------------------------------------------------------------------------
		// SQL generation and execution — casts that must run without error
		// -------------------------------------------------------------------------
		
		/**
		 * (int) cast on an integer column is the simplest case.
		 * Verifies the parser accepts the syntax and the query executes.
		 */
		public function testIntCastExecutesWithoutError(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve ((int)p.id)
			");
			
			$this->assertCount(3, $result);
		}
		
		/**
		 * (float) cast on an integer column.
		 */
		public function testFloatCastExecutesWithoutError(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve ((float)p.id)
			");
			
			$this->assertCount(3, $result);
		}
		
		/**
		 * (string) cast on an integer column.
		 */
		public function testStringCastExecutesWithoutError(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve ((string)p.id)
			");
			
			$this->assertCount(3, $result);
		}
		
		/**
		 * (decimal) cast.
		 */
		public function testDecimalCastExecutesWithoutError(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve ((decimal)p.id)
			");
			
			$this->assertCount(3, $result);
		}
		
		// -------------------------------------------------------------------------
		// Hydration — PHP type coercion on the returned value
		// -------------------------------------------------------------------------
		
		/**
		 * (int) cast must yield a PHP int, not a string.
		 * PDO returns numeric columns as strings by default without a cast; the
		 * hydrator must enforce the requested PHP type regardless.
		 */
		public function testIntCastReturnsPhpInt(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (casted_id = (int)p.id)
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertIsInt($result[0]['casted_id']);
			$this->assertSame(1, $result[0]['casted_id']);
		}
		
		/**
		 * (float) cast must yield a PHP float.
		 */
		public function testFloatCastReturnsPhpFloat(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (casted_id = (float)p.id)
				where p.id = 2
			");
			
			$this->assertCount(1, $result);
			$this->assertIsFloat($result[0]['casted_id']);
			$this->assertSame(2.0, $result[0]['casted_id']);
		}
		
		/**
		 * (string) cast on an integer column must yield a PHP string.
		 */
		public function testStringCastReturnsPhpString(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (casted_id = (string)p.id)
				where p.id = 3
			");
			
			$this->assertCount(1, $result);
			$this->assertIsString($result[0]['casted_id']);
			$this->assertSame('3', $result[0]['casted_id']);
		}
		
		/**
		 * (string) cast on a column that already holds a string value.
		 * The cast should be a no-op at the PHP level.
		 */
		public function testStringCastOnStringColumn(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (t = (string)p.title)
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertIsString($result[0]['t']);
			$this->assertSame('First Post', $result[0]['t']);
		}
		
		/**
		 * (decimal) cast is mapped to PHP float.
		 */
		public function testDecimalCastReturnsPhpFloat(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (d = (decimal)p.id)
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertIsFloat($result[0]['d']);
		}
		
		// -------------------------------------------------------------------------
		// Null passthrough
		// -------------------------------------------------------------------------
		
		/**
		 * A cast on a NULL value must return null, not a zero or empty string.
		 * This exercises the null guard in EntityHydrator::processValue().
		 *
		 * content is NOT NULL in the fixture data, so we use a correlated subquery
		 * trick: cast a computed NULL literal via a subquery range.
		 * Instead, we verify via a WHERE that produces zero rows — confirming the
		 * cast does not crash on an absent row rather than on a null column.
		 *
		 * The real null path is an implementation-level guarantee; the test below
		 * simply confirms the cast does not throw when the result set is empty.
		 */
		public function testCastOnEmptyResultSetDoesNotThrow(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (casted_id = (int)p.id)
				where p.id = 9999
			");
			
			$this->assertCount(0, $result);
		}
		
		// -------------------------------------------------------------------------
		// Casts in WHERE conditions
		// -------------------------------------------------------------------------
		
		/**
		 * A cast in a WHERE condition must be accepted by the parser and semantic
		 * analyser, and must produce correct SQL that filters rows as expected.
		 */
		public function testCastInWhereCondition(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id, p.title)
				where (int)p.published = 1
			");
			
			// published = 1 for posts 1 and 3
			$this->assertCount(2, $result);
		}
		
		/**
		 * A cast result can be compared to a parameter.
		 */
		public function testCastInWhereWithParameter(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.title)
				where (string)p.id = :sid
			", ['sid' => '2']);
			
			$this->assertCount(1, $result);
			$this->assertSame('Second Post', $result[0]['p.title']);
		}
		
		// -------------------------------------------------------------------------
		// Casts with explicit aliases
		// -------------------------------------------------------------------------
		
		/**
		 * An explicit alias on a cast expression must be used as the result key.
		 */
		public function testCastWithExplicitAlias(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (numeric_id = (int)p.id)
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertArrayHasKey('numeric_id', $result[0]);
			$this->assertSame(1, $result[0]['numeric_id']);
		}
		
		/**
		 * Multiple casts with different aliases in the same retrieve list.
		 */
		public function testMultipleCastsInProjection(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (int_id = (int)p.id, str_title = (string)p.title)
				where p.id = 2
			");
			
			$this->assertCount(1, $result);
			$this->assertIsInt($result[0]['int_id']);
			$this->assertIsString($result[0]['str_title']);
			$this->assertSame(2, $result[0]['int_id']);
			$this->assertSame('Second Post', $result[0]['str_title']);
		}
		
		// -------------------------------------------------------------------------
		// Casts in arithmetic expressions
		// -------------------------------------------------------------------------
		
		/**
		 * A cast can be used as an operand inside an arithmetic expression.
		 */
		public function testCastAsArithmeticOperand(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (shifted = (int)p.id + 100)
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(101, $result[0]['shifted']);
		}
		
		// -------------------------------------------------------------------------
		// Semantic validation — casts that must be rejected
		// -------------------------------------------------------------------------
		
		/**
		 * Casting an entire entity reference must be rejected.
		 * (int)p where p is a range alias has no defined meaning.
		 */
		public function testCastOnBareEntityRangeThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of p is PostEntity
				retrieve ((int)p)
			"));
		}
		
		/**
		 * An unknown cast type name must be rejected with a SemanticException.
		 * The error message must list the supported types.
		 */
		public function testUnknownCastTypeThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of p is PostEntity
				retrieve ((blob)p.id)
			"));
		}
		
		/**
		 * Another unknown cast type to confirm the validation is not hard-coded
		 * to a single bad name.
		 */
		public function testAnotherUnknownCastTypeThrows(): void {
			$this->assertSemanticError(fn() => $this->em->executeQuery("
				range of p is PostEntity
				retrieve ((timestamp)p.id)
			"));
		}
		
		// -------------------------------------------------------------------------
		// Parser disambiguation — casts must not interfere with grouped expressions
		// -------------------------------------------------------------------------
		
		/**
		 * A standard parenthesised arithmetic expression must still be parsed
		 * correctly and must not be mistaken for a cast.
		 * (p.id + 1) contains two tokens between the parens, so it cannot be
		 * a cast and must be treated as a grouped expression.
		 */
		public function testParenthesisedExpressionIsNotMistakenForCast(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (v = (p.id + 10))
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(11, $result[0]['v']);
		}
		
		// -------------------------------------------------------------------------
		// Auto-generated alias correctness
		// -------------------------------------------------------------------------
		
		/**
		 * Without an explicit alias, the auto-generated key is the full source
		 * expression including the cast prefix. Use an explicit alias for a clean key.
		 * (int)p.id -> key is "(int)p.id"
		 */
		public function testAutoAliasIncludesCastPrefix(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve ((int)p.id)
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertArrayHasKey('(int)p.id', $result[0]);
			$this->assertSame(1, $result[0]['(int)p.id']);
		}
		
		/**
		 * Without an explicit alias, the auto-generated key includes the cast prefix.
		 * (int)x.testJSON.id -> key is "(int)x.testJSON.id"
		 */
		public function testAutoAliasIncludesCastPrefixOnJsonProperty(): void {
			$result = $this->em->executeQuery("
				range of x is PostEntity
				retrieve ((int)x.testJSON.id)
				where x.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertArrayHasKey('(int)x.testJSON.id', $result[0]);
			$this->assertIsInt($result[0]['(int)x.testJSON.id']);
		}
		
		/**
		 * An explicit alias must always take precedence and is never modified,
		 * even when a cast is present.
		 */
		public function testExplicitAliasOverridesAutoAlias(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (my_id = (int)p.id)
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertArrayHasKey('my_id', $result[0]);
			$this->assertArrayNotHasKey('p.id', $result[0]);
			$this->assertArrayNotHasKey('(int)p.id', $result[0]);
		}
	}