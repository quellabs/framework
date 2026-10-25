<?php

	namespace Quellabs\ObjectQuel\Tests\MySQL;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/** Regression coverage for ProcessExpression emitting SQL that drops operator precedence/grouping. */
	class OperatorPrecedenceSqlEmissionTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'h', FALSE)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob',   'h', TRUE)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (3, 'carol', 'h', TRUE)");
		}

		/**
		 * `banned = false AND (username = 'alice' OR username = 'carol')` must keep
		 * carol excluded (she's banned). Without parentheses around the OR,
		 * AND's higher precedence re-groups this as
		 * `(banned = false AND username = 'alice') OR username = 'carol'`, which
		 * wrongly admits carol via the bare OR clause.
		 */
		public function testOrNestedInAndKeepsItsGrouping(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id, u.username)
				where u.banned = false and (u.username = 'alice' or u.username = 'carol')
			");

			$this->assertCount(1, $rows);
			$this->assertSame('alice', $rows[0]['u.username']);
		}

		/** Same as above with the lower-precedence OR on the left of AND instead of the right. */
		public function testOrNestedInAndKeepsItsGroupingOnTheLeft(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id, u.username)
				where (u.username = 'alice' or u.username = 'carol') and u.banned = false
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

		/**
		 * handleWildcardString() and handleRegularExpression() render their
		 * left operand directly (the right side is the wildcard/regex
		 * pattern, never a nested operator), bypassing handleGenericExpression's
		 * normal left/right handling — so they need their own operandSql()
		 * call. `(username = 'alice' or banned = true) = 'x*'` forces the LIKE
		 * conversion's left operand to be a lower-precedence OR expression;
		 * without parentheses it would compile as
		 * `username = 'alice' or banned = true like "x%"`, which the database
		 * reads as `username = 'alice' or (banned = true like "x%")` — silently
		 * losing the wildcard comparison against the OR expression entirely.
		 */
		public function testWildcardComparisonParenthesizesALowerPrecedenceLeftOperand(): void {
			$plan = $this->em->explainQuery("
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where (u.username = 'alice' or u.banned = true) = 'x*'
			");

			$connection = $this->em->getConnection();
			$alias = $connection->escapeIdentifier('u');
			$username = $connection->escapeIdentifier('username');
			$banned = $connection->escapeIdentifier('banned');
			$this->assertStringContainsString("({$alias}.{$username} = 'alice' OR {$alias}.{$banned} = true) LIKE 'x%'", $plan->getSql()[0]);
		}
	}
