<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Regression coverage for a SQL-emission bug found while investigating
	 * bug-soft-delete-left-join-boolean-null.md: AstBinaryOperator (AND/OR),
	 * AstExpression (comparisons), AstTerm (+/-), and AstFactor (*, /) all
	 * rendered through ProcessExpression::handleGenericExpression() as flat
	 * "{left} {op} {right}" text with no grouping of their own. Parentheses
	 * in the query source are consumed by the parser to shape the AST and
	 * then discarded (see ArithmeticExpression::parsePrimaryExpression()),
	 * so the compiled SQL carried no record of them — a nested operator
	 * that binds more loosely than its parent (OR under AND) or a
	 * non-associative operator explicitly re-grouped on the right (- or /)
	 * silently changed meaning once re-parsed by the database under normal
	 * SQL operator precedence.
	 */
	class OperatorPrecedenceSqlEmissionTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'h', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob',   'h', 1)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (3, 'carol', 'h', 1)");
		}

		/**
		 * `banned = 0 AND (username = 'alice' OR username = 'carol')` must keep
		 * carol excluded (she's banned). Without parentheses around the OR,
		 * AND's higher precedence re-groups this as
		 * `(banned = 0 AND username = 'alice') OR username = 'carol'`, which
		 * wrongly admits carol via the bare OR clause.
		 */
		public function testOrNestedInAndKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id, u.username)
				where u.banned = 0 and (u.username = 'alice' or u.username = 'carol')
			");

			$this->assertCount(1, $rows);
			$this->assertSame('alice', $rows[0]['u.username']);
		}

		/**
		 * `u.id * (3 + 4)` must not be re-read as `u.id * 3 + 4` once the
		 * parentheses that built this AST are gone from the emitted SQL.
		 */
		public function testMultiplicationOverParenthesizedAdditionKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (v = u.id * (3 + 4))
				where u.id = 2
			");

			$this->assertCount(1, $rows);
			$this->assertSame(14, $rows[0]['v']);
		}

		/**
		 * `100 - (10 - u.id)` must not be re-read as `100 - 10 - u.id` —
		 * subtraction is not associative, so dropping the parentheses around
		 * a same-precedence right operand changes the result.
		 */
		public function testSubtractionOfParenthesizedSubtractionKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (v = 100 - (10 - u.id))
				where u.id = 2
			");

			$this->assertCount(1, $rows);
			$this->assertSame(92, $rows[0]['v']);
		}

		/**
		 * `20 / (10 / u.id)` must not be re-read as `20 / 10 / u.id` —
		 * division is not associative either.
		 */
		public function testDivisionOfParenthesizedDivisionKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (v = 20 / (10 / u.id))
				where u.id = 2
			");

			$this->assertCount(1, $rows);
			$this->assertSame(4.0, (float)$rows[0]['v']);
		}
	}
