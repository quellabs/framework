<?php

	namespace Quellabs\ObjectQuel\Tests;

	/**
	 * Regression test for AggregateOptimizer's WINDOW strategy computing an empty
	 * PARTITION BY.
	 *
	 * A mixed query (aggregate + non-aggregate column) over a single range, on a
	 * database that supports window functions, is planned as STRATEGY_WINDOW rather
	 * than DIRECT + GROUP BY (more efficient — see AggregateOptimizer::chooseStrategy()).
	 * The rewrite never threaded the other selected columns into the window's
	 * PARTITION BY at all, so every row's window aggregate was computed over the
	 * whole table (`SUM(...) OVER ()`) instead of within its own partition — silently
	 * wrong data, not a thrown error, since the query still executed successfully.
	 */
	class AggregateWindowPartitionTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', 0)");

			$posts = [
				[1, 'p1', 1],
				[2, 'p2', 1],
				[3, 'p3', 1],
				[4, 'p4', 2],
				[5, 'p5', 2],
			];

			foreach ($posts as [$id, $title, $userId]) {
				$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
					VALUES ({$id}, '{$title}', 'content', 1, '2024-01-0{$id} 00:00:00', 'pending', '{}', {$userId})");
			}
		}

		public function testWindowAggregatePartitionsByOtherSelectedColumn(): void {
			// Single range, mixed query -> STRATEGY_WINDOW. o.userId must become the
			// PARTITION BY: user 1's posts (ids 1-3) each show total 6; user 2's
			// (ids 4-5) each show total 9 -- not 15 (the whole-table sum) for every row.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, total = sum(o.id))
				sort by o.userId
			"));

			$this->assertSame(
				[[1, 6], [1, 6], [1, 6], [2, 9], [2, 9]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['total']], $result)
			);
		}
	}
