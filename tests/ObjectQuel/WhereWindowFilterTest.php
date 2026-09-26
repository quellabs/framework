<?php

	namespace Quellabs\ObjectQuel\Tests;

	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * End-to-end coverage for WhereWindowFilterRewriter — the pass that extracts
	 * a WHERE condition filtering on a window-shaped sequence function's result
	 * (e.g. `where rn <= 3` where `rn = row_number(...)`) into its own
	 * derived-table range, since SQL can't reference a window function's result
	 * in the same query block's WHERE. This is the "top N per group" pattern.
	 *
	 * Fixture matches SequenceFunctionTest/WindowChainTest: two users, five posts
	 * (three for user 1, two for user 2), so PARTITION BY (inferred from
	 * o.userId) can be verified independently of o.id (excluded as the primary
	 * key).
	 */
	class WhereWindowFilterTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', 0)");

			$posts = [
				[1, 'p1', 1, 1],
				[2, 'p2', 0, 1],
				[3, 'p3', 1, 1],
				[4, 'p4', 1, 2],
				[5, 'p5', 1, 2],
			];

			foreach ($posts as [$id, $title, $published, $userId]) {
				$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
					VALUES ({$id}, '{$title}', 'content', {$published}, '2024-01-0{$id} 00:00:00', 'pending', '{}', {$userId})");
			}
		}

		public function testTopTwoPerGroupFiltersUsingRowNumber(): void {
			// User 1 has 3 posts (ids 1,2,3), user 2 has 2 (ids 4,5). Keeping the
			// first 2 rows per user by id drops post 3 and keeps everything else.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id, rn = row_number(sort by o.id))
				where rn <= 2
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1, 1], [1, 2, 2], [2, 4, 1], [2, 5, 2]],
				array_map(
					fn($row) => [(int) $row['o.userId'], (int) $row['o.id'], (int) $row['rn']],
					$result
				)
			);
		}

		public function testFilterWorksWithoutTheAliasBeingSelected(): void {
			// The sequence function need not appear in the SELECT list at all —
			// partition inference doesn't depend on it being displayed.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id)
				where row_number(sort by o.id) <= 1
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1], [2, 4]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testPlainConditionIsCarriedIntoTheHelperRangesRanking(): void {
			// Only published posts should be ranked at all — o.published = 1
			// excludes post 2 (user 1's unpublished post) from the ranking itself,
			// not just from the final result, so user 1's published posts (1, 3)
			// get row numbers 1 and 2 rather than 1 and 3.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id, rn = row_number(sort by o.id))
				where rn <= 1 and o.published = 1
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1, 1], [2, 4, 1]],
				array_map(
					fn($row) => [(int) $row['o.userId'], (int) $row['o.id'], (int) $row['rn']],
					$result
				)
			);
		}

		public function testMultipleIndependentSequenceFunctionFiltersAreBothApplied(): void {
			// Two independent sequence-function filters ANDed together — each
			// becomes its own column on the same helper range. Neither alias is
			// selected: once the WHERE has narrowed a partition down, a window
			// function re-evaluated in the outer SELECT operates over just the
			// survivors (standard SQL - a window function runs after WHERE), so
			// this only asserts on row identity, not on a redisplayed rank.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id)
				where row_number(sort by o.id) <= 2 and row_number(sort by o.id desc) <= 1
				sort by o.userId, o.id
			"));

			// rn <= 2 keeps the first two rows per user by id; rnDesc <= 1 keeps
			// only the single last row per user by id. For user 1 (3 posts, ids
			// 1-3) the last row is id 3, ascending rank 3 — fails rn <= 2, so
			// nothing survives for user 1. For user 2 (2 posts, ids 4-5) the last
			// row is id 5, ascending rank 2 — satisfies both filters at once,
			// proving each is independently enforced rather than one silently
			// winning.
			$this->assertSame(
				[[2, 5]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testSingleSequenceFunctionEmbeddedInArithmeticIsSupported(): void {
			// Only *combining two* sequence functions in one condition is out of
			// scope (see testRejectsTwoSequenceFunctionsInASingleCondition) — one
			// function wrapped in an otherwise ordinary expression is fine.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id)
				where row_number(sort by o.id) + 1 <= 3
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1], [1, 2], [2, 4], [2, 5]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testExplicitByOnAFilterFunctionOverridesInference(): void {
			// Same partitioning as the inferred case, but written explicitly —
			// exercises AstUtilities::buildPartitionItemsFromExplicitBy() for a
			// function referenced directly in WHERE (not via an alias).
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id)
				where row_number(by o.userId sort by o.id) <= 1
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1], [2, 4]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testRankAsAFilterFunction(): void {
			// A different sequence function than row_number(), to confirm the
			// mechanism isn't row_number()-specific. Partition is o.userId alone —
			// o.published is excluded from inference as rank()'s own sort column.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id, o.published)
				where rank(sort by o.published desc) <= 1
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1], [1, 3], [2, 4], [2, 5]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testLagAsAFilterFunction(): void {
			// lag() is value-bearing (unlike row_number()/rank()), and its
			// argument is a boolean-typed column — this specifically regression-
			// tests a BooleanConstantOptimizer bug where `expr = 1` folds down to
			// bare `expr` without updating the surviving node's parent pointer,
			// which broke replacement here whenever a `= 1`/`= 0` comparison wraps
			// the sequence function directly (see BooleanConstantOptimizer::propagate()).
			// lag(o.published) is null for each partition's first row (no previous
			// row) and otherwise the *previous* row's published value: user 1's
			// id 2 (prev id 1 published=1) and user 2's id 5 (prev id 4
			// published=1) are the only rows where that previous value is 1.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id)
				where lag(o.published sort by o.id) = 1
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 2], [2, 5]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testSequenceFunctionOnTheRightHandSideOfTheComparison(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id)
				where 2 >= row_number(sort by o.id)
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1], [1, 2], [2, 4], [2, 5]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testThreeWayAndChainWithTheFilterInTheMiddle(): void {
			// Exercises splitTopLevelAndConditions() flattening a chain deeper
			// than two conditions, with the window condition sandwiched between
			// two plain ones rather than first or last.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id)
				where o.published = 1 and row_number(sort by o.id) <= 2 and o.id > 0
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1], [1, 3], [2, 4], [2, 5]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testNotWrappedSequenceFunctionCondition(): void {
			// `not (rn > 2)` is a single top-level leaf (not an AND), with the
			// window-shaped node nested inside the NOT rather than a direct
			// operand of the comparison — the extraction must still find and
			// replace it correctly.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id)
				where not (row_number(sort by o.id) > 2)
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1], [1, 2], [2, 4], [2, 5]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['o.id']], $result)
			);
		}

		public function testBareByAggregateFilterActsLikeHaving(): void {
			// sum(o.id by o.userId) has a `by` but no `sort by` — it's classic
			// collapsing GROUP BY in SELECT (see SequenceFunctionTest's
			// testBareByCollapsesLikeClassicGroupBy), not a sequence function, but
			// AstUtilities::isWindowShaped() is still true (getPartitionBy() !==
			// null), so it's in scope here too. Because a bare-`by` aggregate
			// broadcasts one uniform value across its whole partition, filtering
			// on it in WHERE never partially excludes a partition — every row of
			// a kept partition survives together — so this mechanism gives
			// HAVING-equivalent filtering for free: user 1's total is 1+2+3=6
			// (kept, <= 6), user 2's is 4+5=9 (dropped).
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, total = sum(o.id by o.userId))
				where total <= 6
			"));

			$this->assertSame(
				[[1, 6]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['total']], $result)
			);
		}

		public function testRejectsSequenceFunctionFilterCombinedWithOr(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id, rn = row_number(sort by o.id))
				where rn <= 1 or o.published = 1
			");
		}

		public function testRejectsTwoSequenceFunctionsInASingleCondition(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, o.id, rn = row_number(sort by o.id), r = rank(sort by o.id))
				where rn + r <= 2
			");
		}

		public function testRejectsMultiRangeQueries(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery("
				range of o is PostEntity
				range of u is UserEntity
				retrieve (o.id, u.username)
				where row_number(sort by o.id) <= 2 and o.userId = u.id
			");
		}
	}
