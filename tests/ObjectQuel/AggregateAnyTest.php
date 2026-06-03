<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use Quellabs\ObjectQuel\Exception\QuelException;
	
	/**
	 * Tests for queries combining aggregate functions (SUM, COUNT, etc.) with ANY(),
	 * and for the alias-expansion feature that allows SELECT aliases to be referenced
	 * in WHERE / ORDER BY clauses.
	 *
	 * These tests cover the bugs fixed in the following areas:
	 *
	 *   1. AstNodeReplacer — NodeConditionWrapper replaced with NodeSingleExpression
	 *      so that AstAlias is a valid replacement parent.
	 *
	 *   2. CollectAggregates — removed spl_object_id deduplication that was needed
	 *      as a workaround for the macro double-traversal bug.
	 *
	 *   3. AstRetrieve — removed $macros system entirely; projection list is now
	 *      searched directly by ExpandMacros for alias substitution.
	 *
	 *   4. ExpandMacros — rewired to look up SELECT aliases by name and substitute
	 *      deep clones of their expressions into WHERE / ORDER BY.
	 *
	 *   5. AstRetrieve::deepClone — no longer attempts to clone macro expressions
	 *      separately, which previously triggered "AstAny cannot be made a root node".
	 */
	class AggregateAnyTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (1, 'completed', 'Hello world', 1, '2024-01-01 00:00:00', 'pending', '{\"id\": 1}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (2, 'draft', 'Foo bar', 0, '2024-01-02 00:00:00', 'shipped', '{\"id\": 2}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (3, 'completed', 'Baz qux', 1, '2024-01-03 00:00:00', 'delivered', '{\"id\": 3}', 1)");
		}
		
		// -------------------------------------------------------------------------
		// SUM alone — baseline to confirm aggregate-only queries still work
		// -------------------------------------------------------------------------
		
		/**
		 * A plain SUM with no ANY() must produce a single row with the correct total.
		 * This is the baseline; if this breaks, later combined tests are meaningless.
		 */
		public function testSumAloneReturnsCorrectTotal(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (total = sum(o.id))
			");
			
			$this->assertCount(1, $result);
			// IDs 1 + 2 + 3 = 6
			$this->assertSame(6, (int) $result[0]['total']);
		}
		
		// -------------------------------------------------------------------------
		// ANY alone — baseline
		// -------------------------------------------------------------------------
		
		/**
		 * A plain ANY() with no aggregate in the same projection list must return
		 * 1 when matching rows exist and 0 when they do not.
		 */
		public function testAnyAloneReturnsTrueWhenMatchFound(): void {
			// any() in the SELECT list is a scalar subquery evaluated per row — one
			// result row per post, each carrying the same EXISTS check result.
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (hasCompleted = any(o.id where o.title = 'completed'))
			");
			
			$this->assertCount(3, $result);
			
			foreach ($result as $row) {
				$this->assertSame(1, (int) $row['hasCompleted']);
			}
		}
		
		public function testAnyAloneReturnsFalseWhenNoMatch(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (hasMissing = any(o.id where o.title = 'nonexistent'))
			");
			
			$this->assertCount(3, $result);
			
			foreach ($result as $row) {
				$this->assertSame(0, (int) $row['hasMissing']);
			}
		}
		
		// -------------------------------------------------------------------------
		// SUM + ANY combined — the primary regression case
		//
		// This combination previously threw:
		//   "Cannot replace child of type AstAny in parent of type AstAlias"
		// and later:
		//   "Cannot replace child of type AstSum in parent of type AstAlias"
		// -------------------------------------------------------------------------
		
		/**
		 * A retrieve combining SUM and ANY in the same projection list must not throw
		 * and must return correct values for both projections.
		 *
		 * Regression test for the AstNodeReplacer NodeConditionWrapper → NodeSingleExpression
		 * fix and the CollectAggregates double-collection bug caused by the macro system.
		 */
		public function testSumAndAnyInSameProjectionList(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (
					total = sum(o.id),
					hasCompleted = any(o.id where o.title = 'completed')
				)
			");
			
			// aggregate-only query (SUM + ANY-derived subquery) — collapses to one summary row.
			$this->assertCount(1, $result);
			$this->assertSame(6, (int) $result[0]['total']);
			$this->assertSame(1, (int) $result[0]['hasCompleted']);
		}
		
		/**
		 * SUM + ANY where the ANY condition matches nothing — both projections
		 * must still be present and correct.
		 */
		public function testSumAndAnyWhenAnyMatchesNothing(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (
					total = sum(o.id),
					hasMissing = any(o.id where o.title = 'nonexistent')
				)
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(6, (int) $result[0]['total']);
			$this->assertSame(0, (int) $result[0]['hasMissing']);
		}
		
		/**
		 * Multiple ANY() expressions alongside a SUM — ensures the optimizer
		 * loop handles more than one AstAny in the same query correctly.
		 */
		public function testSumAndMultipleAny(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (
					total        = sum(o.id),
					hasCompleted = any(o.id where o.title = 'completed'),
					hasDraft     = any(o.id where o.title = 'draft')
				)
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(6, (int) $result[0]['total']);
			$this->assertSame(1, (int) $result[0]['hasCompleted']);
			$this->assertSame(1, (int) $result[0]['hasDraft']);
		}
		
		/**
		 * Order matters: ANY first, then SUM. Ensures the bug was not order-dependent.
		 */
		public function testAnyBeforeSumInProjectionList(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (
					hasCompleted = any(o.id where o.title = 'completed'),
					total        = sum(o.id)
				)
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(1, (int) $result[0]['hasCompleted']);
			$this->assertSame(6, (int) $result[0]['total']);
		}
		
		/**
		 * An aggregate-only query (all projections are aggregates or ANY-derived
		 * subqueries) must return exactly one summary row regardless of how many
		 * source rows exist. No GROUP BY should be emitted.
		 *
		 * Regression for the AstUtilities::isAggregateEquivalent fix: AnyOptimizer
		 * rewrites AstAny → AstSubquery before AggregateOptimizer runs. Without the
		 * fix, areAllSelectFieldsAggregates() returned false (AstSubquery is not an
		 * AstAggregate), causing AstSum to be lifted into a correlated subquery and
		 * the outer FROM to produce one row per post instead of one summary row.
		 */
		public function testAggregateOnlyQueryReturnsSingleRow(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (
					total        = sum(o.id),
					hasCompleted = any(o.id where o.title = 'completed')
				)
			");
			
			// Three posts in the fixture — must still collapse to one summary row
			$this->assertCount(1, $result);
			$this->assertSame(6, (int) $result[0]['total']);
			$this->assertSame(1, (int) $result[0]['hasCompleted']);
		}
		
		/**
		 * Aggregate-only with ANY matching nothing — still one row, hasCompleted = 0.
		 */
		public function testAggregateOnlyQueryReturnsSingleRowWhenAnyMatchesNothing(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (
					total      = sum(o.id),
					hasMissing = any(o.id where o.title = 'nonexistent')
				)
			");
			
			$this->assertCount(1, $result);
			$this->assertSame(6, (int) $result[0]['total']);
			$this->assertSame(0, (int) $result[0]['hasMissing']);
		}
		
		// -------------------------------------------------------------------------
		// deepClone regression — "AstAny cannot be made a root node"
		//
		// StageFactory calls AstRetrieve::deepClone() when building the execution
		// plan. The old macro system cloned AstAny with setParent(null), which
		// AstAny rejects. This is exercised whenever a query with ANY() reaches
		// the execution plan builder.
		// -------------------------------------------------------------------------
		
		/**
		 * A query containing ANY() must complete without the deepClone path
		 * throwing "AstAny cannot be made a root node".
		 */
		public function testAnyQueryDoesNotThrowDuringDeepClone(): void {
			// If deepClone is broken, executeQuery throws before returning
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (hasCompleted = any(o.id where o.title = 'completed'))
			");
			
			$this->assertNotNull($result);
		}
		
		// -------------------------------------------------------------------------
		// Alias expansion (macro system replacement)
		//
		// SELECT aliases can be referenced in WHERE / ORDER BY. ExpandMacros now
		// looks up the projection list directly instead of a separate $macros index.
		//
		// Note: aggregate aliases (SUM, COUNT, etc.) cannot be referenced in WHERE
		// because SQL forbids aggregates in WHERE clauses. Only non-aggregate
		// expressions are valid alias expansion targets in WHERE.
		// -------------------------------------------------------------------------
		
		/**
		 * A property alias defined in the SELECT list can be referenced in WHERE.
		 * ExpandMacros substitutes a deep clone of the aliased expression so the
		 * original SELECT projection is left intact.
		 */
		public function testPropertyAliasUsedInWhere(): void {
			// t = o.title is aliased in SELECT; WHERE t = 'completed' expands to
			// WHERE o.title = 'completed', matching posts 1 and 3.
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (t = o.title)
				where t = 'completed'
			");
			
			$this->assertCount(2, $result);
			
			foreach ($result as $row) {
				$this->assertSame('completed', $row['t']);
			}
		}
		
		/**
		 * Alias expansion in WHERE must use an independent clone of the SELECT
		 * expression — mutating the WHERE copy must not corrupt the SELECT projection.
		 */
		public function testAliasExpansionDoesNotCorruptSelectProjection(): void {
			// If the substituted expression is not a deep clone, the normalizer or
			// optimizer rewriting the WHERE copy could corrupt the SELECT projection,
			// causing the alias key to disappear or return a wrong value.
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (t = o.title)
				where t = 'draft'
			");
			
			$this->assertCount(1, $result);
			$this->assertArrayHasKey('t', $result[0]);
			$this->assertSame('draft', $result[0]['t']);
		}
		
		/**
		 * Alias referenced in WHERE with a non-equality condition.
		 */
		public function testPropertyAliasInWhereWithLikeCondition(): void {
			$result = $this->em->executeQuery("
				range of o is PostEntity
				retrieve (pid = o.id)
				where pid > 1
			");
			
			$this->assertCount(2, $result);
			
			foreach ($result as $row) {
				$this->assertGreaterThan(1, $row['pid']);
			}
		}
		
		// -------------------------------------------------------------------------
		// Duplicate alias detection
		//
		// The duplicate check was previously done via macroExists(). It now uses
		// hasValueAlias() which scans $values directly.
		// -------------------------------------------------------------------------
		
		/**
		 * Declaring the same alias name twice in the projection list must throw
		 * a parser exception, regardless of whether the expressions differ.
		 */
		public function testDuplicateAliasThrows(): void {
			$this->expectException(QuelException::class);
			
			$this->em->executeQuery("
				range of o is PostEntity
				retrieve (total = sum(o.id), total = count(o.id))
			");
		}
	}