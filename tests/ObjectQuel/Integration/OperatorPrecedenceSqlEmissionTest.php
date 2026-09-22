<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/** Regression coverage for ProcessExpression emitting SQL that drops operator precedence/grouping. */
	class OperatorPrecedenceSqlEmissionTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'h', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob',   'h', 1)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (3, 'carol', 'h', 1)");
		}

		/** A lower-precedence OR nested under AND must keep its parentheses in the emitted SQL. */
		public function testOrNestedInAndKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id, u.username)
				where u.banned = 0 and (u.username = 'alice' or u.username = 'carol')
			");

			$this->assertCount(1, $rows);
			$this->assertSame('alice', $rows[0]['u.username']);
		}

		/** Same as above with the lower-precedence OR on the left of AND instead of the right. */
		public function testOrNestedInAndKeepsItsGroupingOnTheLeft(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id, u.username)
				where (u.username = 'alice' or u.username = 'carol') and u.banned = 0
			");

			$this->assertCount(1, $rows);
			$this->assertSame('alice', $rows[0]['u.username']);
		}

		/** `u.id * (3 + 4)` must not be re-read as `u.id * 3 + 4`. */
		public function testMultiplicationOverParenthesizedAdditionKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (v = u.id * (3 + 4))
				where u.id = 2
			");

			$this->assertCount(1, $rows);
			$this->assertSame(14, $rows[0]['v']);
		}

		/** `100 - (10 - u.id)` must not be re-read as `100 - 10 - u.id` (subtraction isn't associative). */
		public function testSubtractionOfParenthesizedSubtractionKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (v = 100 - (10 - u.id))
				where u.id = 2
			");

			$this->assertCount(1, $rows);
			$this->assertSame(92, $rows[0]['v']);
		}

		/** `20 / (10 / u.id)` must not be re-read as `20 / 10 / u.id` (division isn't associative). */
		public function testDivisionOfParenthesizedDivisionKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (v = 20 / (10 / u.id))
				where u.id = 2
			");

			$this->assertCount(1, $rows);
			$this->assertSame(4.0, (float)$rows[0]['v']);
		}

		/** LIKE conversion's left operand needs its own operandSql() call to parenthesize a lower-precedence OR. */
		public function testWildcardComparisonParenthesizesALowerPrecedenceLeftOperand(): void {
			$plan = $this->em->explainQuery("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where (u.username = 'alice' or u.banned = 1) = 'x*'
			");

			$this->assertStringContainsString(
				'(`u`.`username` = "alice" OR `u`.`banned` = 1) LIKE "x%"',
				$plan->getSql()[0]
			);
		}
	}
