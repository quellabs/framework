<?php

	namespace Quellabs\ObjectQuel\Tests;

	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * End-to-end coverage for sequence functions (rank, dense_rank, row_number, lag)
	 * and running aggregates (sum with an inline `sort by`), exercising the full
	 * pipeline: parser -> AggregateOptimizer window-strategy planning -> SQL
	 * generation -> execution against a real database.
	 *
	 * Fixture: two users, five posts (three for user 1, two for user 2), so tests
	 * can verify PARTITION BY (inferred from `o.userId`) behaves independently of
	 * `o.id`, which is excluded from partition inference because it's the primary
	 * key — the same fix that makes it possible to also display a row's own id
	 * alongside a sequence function without collapsing every row into its own
	 * single-row partition.
	 */
	class SequenceFunctionTest extends ObjectQuelTestCase {

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

		public function testRowNumberOrdersRowsWithinPartition(): void {
			// Sorted by o.id (a real column) rather than the "rn" alias — the outer
			// query's own sort-by-alias expansion is a separate mechanism unrelated
			// to sequence functions, and not exercised here.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, rn = row_number(sort by o.id))
				sort by o.userId, o.id
			"));

			$this->assertSame(
				[[1, 1], [1, 2], [1, 3], [2, 1], [2, 2]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['rn']], $result)
			);
		}

		public function testRankLeavesGapsAfterTies(): void {
			// Aggregate-only, no partition — published values [1,0,1,1,1] sorted
			// desc give four ties at rank 1 and one row at rank 5 (RANK skips the
			// ranks consumed by the tie, unlike DENSE_RANK).
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (r = rank(sort by o.published desc))
			"));

			$ranks = array_map(fn($row) => (int) $row['r'], $result);
			sort($ranks);

			$this->assertSame([1, 1, 1, 1, 5], $ranks);
		}

		public function testDenseRankHasNoGapsAfterTies(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (r = dense_rank(sort by o.published desc))
			"));

			$ranks = array_map(fn($row) => (int) $row['r'], $result);
			sort($ranks);

			$this->assertSame([1, 1, 1, 1, 2], $ranks);
		}

		public function testLagReturnsPreviousRowValueWithinPartition(): void {
			// o.id is displayed but excluded from partition inference (primary key),
			// so o.userId alone is the partition key — this is the scenario that
			// previously broke under plain GROUP BY-style inference.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.id, o.userId, prevTitle = lag(o.title sort by o.id))
				sort by o.id
			"));

			$this->assertSame(
				[
					[1, 1, null],
					[2, 1, 'p1'],
					[3, 1, 'p2'],
					[4, 2, null],
					[5, 2, 'p4'],
				],
				array_map(
					fn($row) => [(int) $row['o.id'], (int) $row['o.userId'], $row['prevTitle']],
					$result
				)
			);
		}

		public function testRunningSumAccumulatesWithinPartition(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.id, o.userId, runningTotal = sum(o.id sort by o.id))
				sort by o.id
			"));

			$this->assertSame(
				[
					[1, 1, 1],
					[2, 1, 3],
					[3, 1, 6],
					[4, 2, 4],
					[5, 2, 9],
				],
				array_map(
					fn($row) => [(int) $row['o.id'], (int) $row['o.userId'], (int) $row['runningTotal']],
					$result
				)
			);
		}

		public function testAggregateOnlyQueryForcesPlainAggregatesThroughWindowStrategyToo(): void {
			// total=sum(o.id) has no inline sort by of its own, but rank() in the
			// same aggregate-only query requires the window strategy, which can't
			// collapse to one row — so sum() must also become a window aggregate
			// (SUM(...) OVER ()) instead of DIRECT_AGG_ONLY, keeping row counts
			// consistent across every projection in the query.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (total = sum(o.id), r = rank(sort by o.id desc))
			"));

			$this->assertCount(5, $result);

			foreach ($result as $row) {
				$this->assertSame(15, (int) $row['total']);
			}

			$ranks = array_map(fn($row) => (int) $row['r'], $result);
			sort($ranks);
			$this->assertSame([1, 2, 3, 4, 5], $ranks);
		}

		public function testMixingDistinctAggregateWithSequenceFunctionInAggregateOnlyQueryThrows(): void {
			// countu() can never use the window strategy (DISTINCT is excluded), so
			// forcing it alongside rank() in the same aggregate-only query has no
			// valid SQL rendering — this must fail loudly, not silently mix strategies.
			$this->expectException(QuelException::class);

			$this->em->executeQuery("
				range of o is PostEntity
				retrieve (c = countu(o.userId), r = rank(sort by o.id desc))
			");
		}
	}
