<?php

	namespace Quellabs\ObjectQuel\Tests;

	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * End-to-end coverage for WindowChainRewriter — the pass that extracts a
	 * sequence function nested inside another aggregate's argument (e.g.
	 * sum(x - lag(x sort by y) sort by y)) into its own derived-table range,
	 * since SQL can't nest a window function inside another aggregate's
	 * argument in the same query block.
	 *
	 * Fixture matches SequenceFunctionTest: two users, five posts (three for
	 * user 1, two for user 2), so PARTITION BY (inferred from o.userId) can be
	 * verified independently of o.id (excluded as the primary key).
	 */
	class WindowChainTest extends ObjectQuelTestCase {

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

		public function testRunningSumOfGapBetweenConsecutiveRowsWithinPartition(): void {
			// gap = id - lag(id) is NULL for the first row of each partition (no
			// previous row), then the id delta for later rows (always 1 here,
			// consecutive ids). SUM ignores NULLs, so the running sum starts at 0.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.id, o.userId, gapSum = sum(o.id - lag(o.id sort by o.id) sort by o.id))
				sort by o.id
			"));

			$this->assertSame(
				[
					[1, 1, 0],
					[2, 1, 1],
					[3, 1, 2],
					[4, 2, 0],
					[5, 2, 1],
				],
				array_map(
					fn($row) => [(int) $row['o.id'], (int) $row['o.userId'], (int) $row['gapSum']],
					$result
				)
			);
		}

		public function testChainingRelinksExplicitInnerByToTheHelperRange(): void {
			// Same shape as testRunningSumOfGapBetweenConsecutiveRowsWithinPartition, but
			// the inner lag() partitions explicitly via `by o.userId` instead of relying
			// on inference from the outer query's own SELECT items — verifies that
			// WindowChainRewriter's relinkIdentifiers() correctly re-points the `by`
			// list's identifier at the helper range's cloned column, not the original.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.id, o.userId, gapSum = sum(o.id - lag(o.id by o.userId sort by o.id) sort by o.id))
				sort by o.id
			"));

			$this->assertSame(
				[
					[1, 1, 0],
					[2, 1, 1],
					[3, 1, 2],
					[4, 2, 0],
					[5, 2, 1],
				],
				array_map(
					fn($row) => [(int) $row['o.id'], (int) $row['o.userId'], (int) $row['gapSum']],
					$result
				)
			);
		}

		public function testChainingWithoutOuterSortByStillExtractsNestedWindowFunction(): void {
			// The outer sum has no sort by of its own — it's a plain (non-window)
			// aggregate, but SQL still can't nest lag()'s OVER(...) inside its
			// argument, so extraction must happen regardless of the outer's own
			// strategy. total = sum() is the only SELECT item, so there are no
			// partition columns — lag() runs over the whole table ordered by id
			// (ids 1..5). Row 1's gap is NULL (no previous row, ignored by SUM);
			// rows 2-5 each contribute id - lag(id) = 1, so the total is 4.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (total = sum(o.id - lag(o.id sort by o.id)))
			"));

			$this->assertCount(1, $result);
			$this->assertSame(4, (int) $result[0]['total']);
		}

		public function testChainingForcesWindowStrategyOnOuterAggregateAndRejectsDistinctMix(): void {
			// The outer sum() has its own trailing `sort by o.id`, so — even after
			// WindowChainRewriter extracts the nested lag() into a helper range —
			// the outer sum still requires the window strategy for itself. countu()
			// can never use it (DISTINCT is excluded), so mixing the two in the same
			// aggregate-only query has no valid SQL rendering and must fail loudly.
			$this->expectException(QuelException::class);

			$this->em->executeQuery("
				range of o is PostEntity
				retrieve (
					total = sum(o.id - lag(o.id sort by o.id) sort by o.id),
					uniqueUsers = countu(o.userId)
				)
			");
		}

		public function testChainingRejectsMultiRangeQueries(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery("
				range of o is PostEntity
				range of u is UserEntity
				retrieve (total = sum(o.id - lag(o.id sort by o.id)), u.username)
			");
		}
	}
