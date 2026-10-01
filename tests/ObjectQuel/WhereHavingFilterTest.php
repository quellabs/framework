<?php

	namespace Quellabs\ObjectQuel\Tests;

	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;
	use Quellabs\ObjectQuel\Tests\Support\RetrieveSqlCompiler;

	/**
	 * End-to-end coverage for WhereHavingFilterRewriter — the pass that moves a
	 * plain (non-window-shaped) aggregate WHERE condition (e.g. `where sum(o.id)
	 * <= 100`) into an implicit SQL HAVING clause, since SQL forbids referencing
	 * a plain aggregate in WHERE. ObjectQuel has no `having` keyword; the
	 * compiler infers the clause from the condition's shape.
	 *
	 * Fixture matches WhereWindowFilterTest/SequenceFunctionTest: two users, five
	 * posts (three for user 1, two for user 2) — user 1's post ids sum to 6, user
	 * 2's to 9.
	 */
	class WhereHavingFilterTest extends ObjectQuelTestCase {

		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', FALSE)");
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (2, 'bob', 'hash2', FALSE)");

			$posts = [
				[1, 'p1', 'TRUE', 1],
				[2, 'p2', 'FALSE', 1],
				[3, 'p3', 'TRUE', 1],
				[4, 'p4', 'TRUE', 2],
				[5, 'p5', 'TRUE', 2],
			];

			foreach ($posts as [$id, $title, $published, $userId]) {
				$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
					VALUES ({$id}, '{$title}', 'content', {$published}, '2024-01-0{$id} 00:00:00', 'pending', '{}', {$userId})");
			}
		}

		public function testAggregateOnlyQueryIsFilteredOutByHaving(): void {
			// Every post's id sums to 15 (aggregate-only shape, no GROUP BY needed) —
			// filtered out entirely once HAVING rejects it.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (total = sum(o.id))
				where total <= 12
			"));

			$this->assertSame([], $result);
		}

		public function testAggregateOnlyQueryIsKeptWhenHavingConditionPasses(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (total = sum(o.id))
				where total <= 20
			"));

			$this->assertSame([15], array_map(fn($row) => (int) $row['total'], $result));
		}

		public function testMixedQueryGroupsThenFiltersViaHaving(): void {
			// o.userId forces a mixed (non-aggregate-only) query shape, so GROUP BY
			// is inferred from it — the plain aggregate condition must compile to
			// HAVING, not a window function (which would be incompatible with the
			// GROUP BY this shape requires). User 1's total is 1+2+3=6 (kept, <= 6),
			// user 2's is 4+5=9 (dropped).
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, total = sum(o.id))
				where total <= 6
			"));

			$this->assertSame(
				[[1, 6]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['total']], $result)
			);
		}

		public function testMixedLeafSplitsPlainConditionIntoWhereAndAggregateIntoHaving(): void {
			// o.id <> 2 applies per-row, before grouping (stays in WHERE); total <= 6
			// applies per-group, after grouping (moves to HAVING). Post 2 (user 1) is
			// excluded by WHERE before aggregation, so user 1's HAVING-visible total
			// is 1+3=4, not 6 — and user 2's total (4+5=9) still fails the HAVING
			// condition either way.
			//
			// A boolean WHERE column (e.g. o.published) is deliberately avoided here:
			// combining it with GROUP BY hits an unrelated, pre-existing Postgres
			// incompatibility (the query builder wraps every extra referenced column
			// in MIN(...) for GROUP BY safety, and Postgres has no MIN(boolean)) that
			// already reproduces with the pre-existing `sum(x by y)` GROUP BY feature
			// too - out of scope for this HAVING feature.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, total = sum(o.id))
				where o.id <> 2 and total <= 6
			"));

			$this->assertSame(
				[[1, 4]],
				array_map(fn($row) => [(int) $row['o.userId'], (int) $row['total']], $result)
			);
		}

		public function testAggregatesOwnInlineFilterStaysInWhereNotHaving(): void {
			// avg(o.id where o.published = true) has its own inline `where` (no `by`),
			// so it's planned as a correlated scalar subquery (STRATEGY_SUBQUERY_FILTERED)
			// rather than a plain grouped aggregate. A correlated scalar subquery is
			// already legal directly inside WHERE on every engine — WhereHavingFilterRewriter
			// deliberately leaves it there rather than moving it to HAVING, since
			// moving it would be unnecessary and would break on engines (SQLite) that
			// only treat HAVING as valid on a query with a *literal* aggregate call
			// visible outside any nested subquery. See testFilteredAggregateSubqueryStaysInWhere
			// below for the SQL-shape assertion.
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, avgId = avg(o.id where o.published = true))
				where avgId <= 2
			"));

			$this->assertSame([], $result);
		}

		public function testAggregatesOwnInlineFilterKeepsRowsWhenConditionPasses(): void {
			$result = iterator_to_array($this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, avgId = avg(o.id where o.published = true))
				where avgId <= 4
			"));

			$this->assertCount(5, $result);
		}

		public function testRejectsPlainAggregateFilterCombinedWithOr(): void {
			$this->expectException(QuelException::class);

			$this->em->executeQuery("
				range of o is PostEntity
				retrieve (o.userId, total = sum(o.id))
				where total <= 6 or o.published = true
			");
		}

		// -------------------------------------------------------------------------
		// SQL-shape assertions (offline compilation, no live query needed) —
		// verifies the placement/absence of clauses that the numeric tests above
		// can't distinguish from each other by result alone.
		// -------------------------------------------------------------------------

		private function compile(string $query): string {
			$platform = new class('mysql') extends FakePlatformCapabilities {
				public function supportsWindowFunctions(): bool {
					return true;
				}
			};

			return (new RetrieveSqlCompiler($this->em, $platform))->compile($query);
		}

		public function testMixedQueryHavingDoesNotBecomeAWindowFunction(): void {
			// The case this rewriter exists to get right: without the HAVING-aware
			// guard in AggregateOptimizer, a mixed query's aggregate would silently
			// become a window function instead (STRATEGY_WINDOW avoids GROUP BY,
			// which conflicts with the GROUP BY this shape needs).
			$sql = $this->compile("
				range of o is PostEntity
				retrieve (o.userId, total = sum(o.id))
				where total <= 100
			");

			$this->assertStringContainsString('GROUP BY', $sql);
			$this->assertStringContainsString('HAVING', $sql);
			$this->assertStringNotContainsString('OVER (', $sql);
		}

		public function testFilteredAggregateSubqueryStaysInWhere(): void {
			// See testAggregatesOwnInlineFilterStaysInWhereNotHaving for why this
			// shape must NOT move to HAVING: it stays a WHERE-legal scalar subquery.
			$sql = $this->compile("
				range of o is PostEntity
				retrieve (o.userId, avgId = avg(o.id where o.published = true))
				where avgId <= 2
			");

			$this->assertStringContainsString('WHERE', $sql);
			$this->assertStringNotContainsString('HAVING', $sql);
			$this->assertStringContainsString('AVG(', $sql);
		}
	}
