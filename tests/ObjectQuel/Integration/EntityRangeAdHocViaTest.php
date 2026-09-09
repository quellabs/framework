<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Quellabs\ObjectQuel\Tests\ObjectQuelTestCase;

	/**
	 * Integration coverage for an entity range's `via` accepting a literal
	 * join condition, not just a declared-relation name — e.g.
	 * `via u.id = p.userId` instead of `via p.user`. Both forms share one
	 * grammar slot: Rules\Range::parseEntityRangeTail() parses the full
	 * expression grammar instead of only a property chain.
	 *
	 * The relation-name form is already covered by InverseOfValidationTest;
	 * this suite is specifically about the ad hoc literal-condition form and
	 * the capability it unlocks that a `where`-based cross join between two
	 * entity ranges cannot: a WHERE filter after an implicit cross join
	 * always behaves like an INNER join, so it can never produce a row for
	 * the unmatched side. `via <condition>` compiles to a real LEFT JOIN.
	 */
	class EntityRangeAdHocViaTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'has-post', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'no-post', 'hash2', 0)");
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
				VALUES (1, 'Hello', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', '{\"id\": 1}', 1)");
		}

		public function testAdHocViaOnAnEntityRangeGeneratesARealLeftJoin(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				range of p is App\\Entities\\PostEntity via u.id = p.userId
				retrieve (u.username, p.title)
				sort by u.username asc
			");

			$this->assertCount(2, $rows);
			$this->assertSame('has-post', $rows[0]['u.username']);
			$this->assertSame('Hello', $rows[0]['p.title']);
			$this->assertSame('no-post', $rows[1]['u.username']);
			$this->assertNull($rows[1]['p.title']);
		}

		/**
		 * Contrast case: the same condition written as a `where` filter
		 * instead of `via` behaves like an INNER join — no-post's row is
		 * dropped entirely. This is exactly the gap the ad hoc `via` form
		 * above closes; without it, there was no way to keep that row.
		 */
		public function testTheEquivalentWhereConditionDropsTheUnmatchedRow(): void {
			$rows = $this->em->getAll("
				range of u is App\\Entities\\UserEntity
				range of p is App\\Entities\\PostEntity
				retrieve (u.username, p.title)
				where u.id = p.userId
			");

			$this->assertCount(1, $rows);
			$this->assertSame('has-post', $rows[0]['u.username']);
		}

		/**
		 * Regression guard: the declared-relation form of `via` (resolved
		 * through RewriteViaRelationToJoinCondition) still works unaffected
		 * by parseEntityRangeTail() now parsing a full expression instead of
		 * only a property chain.
		 */
		public function testRelationNameViaStillWorksSideBySide(): void {
			$rows = $this->em->getAll("
				range of p is App\\Entities\\PostEntity
				range of u is App\\Entities\\UserEntity via p.user
				retrieve (p.title, u.username)
			");

			$this->assertCount(1, $rows);
			$this->assertSame('Hello', $rows[0]['p.title']);
			$this->assertSame('has-post', $rows[0]['u.username']);
		}
	}
