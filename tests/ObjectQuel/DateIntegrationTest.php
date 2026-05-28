<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;
	
	/**
	 * Integration tests for the date() function in ObjectQuel queries.
	 *
	 * Coverage:
	 *
	 *   Interval arithmetic
	 *     date("N unit") folds to an integer literal at parse time.
	 *     date("N unit") + date("M unit") is plain integer addition in SQL.
	 *
	 *   date("now")
	 *     Emits the platform's current-timestamp function.
	 *
	 *   Column argument — date(p.createdAt)
	 *     Wraps the column with UNIX_TIMESTAMP() / strftime / EXTRACT EPOCH.
	 *
	 *   WHERE filtering with date arithmetic
	 *     The primary use case: filter rows by comparing a timestamp column
	 *     to date("now") ± an interval.
	 *
	 *   SELECT list hydration
	 *     date() in a retrieve() expression returns a \DateTime object.
	 *     date() wrapped in an explicit (int) cast returns a PHP int.
	 *
	 *   Composite intervals
	 *     "4 years 20 minutes" and similar multi-component strings.
	 *
	 *   Parser errors
	 *     Unrecognised interval strings must produce a parser error.
	 */
	class DateIntegrationTest extends ObjectQuelTestCase {
		
		protected function seedFixtures(): void {
			$this->exec("INSERT INTO users (id, username, password, banned) VALUES (1, 'alice', 'hash1', 0)");
			
			// Three posts with known created_at timestamps:
			//   post 1 — 10 days ago
			//   post 2 — 40 days ago
			//   post 3 — 1 year + 1 day ago
			// Computed relative to NOW() in the database so the tests are not
			// sensitive to the clock at fixture-build time.
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (1, 'Recent',    'content', 1, DATE_SUB(NOW(), INTERVAL 10  DAY),  'pending',   '{\"id\": 1}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (2, 'OldPost',   'content', 1, DATE_SUB(NOW(), INTERVAL 40  DAY),  'shipped',   '{\"id\": 2}', 1)");
			
			$this->exec("INSERT INTO posts (id, title, content, published, created_at, test_enum, test_json, user_id)
                VALUES (3, 'VeryOld',   'content', 1, DATE_SUB(NOW(), INTERVAL 366 DAY),  'delivered', '{\"id\": 3}', 1)");
		}
		
		// =========================================================================
		// Helpers
		// =========================================================================
		
		private function assertParserError(callable $fn): void {
			try {
				$fn();
				$this->fail('Expected QuelException to be thrown');
			} catch (QuelException $e) {
				// Any QuelException is fine — parser errors are wrapped as QuelException.
				$this->addToAssertionCount(1);
			}
		}
		
		private function assertSemanticError(callable $fn): void {
			try {
				$fn();
				$this->fail('Expected QuelException to be thrown');
			} catch (QuelException $e) {
				$this->assertInstanceOf(
					SemanticException::class,
					$e->getPrevious(),
					'Expected QuelException to wrap a SemanticException'
				);
			}
		}
		
		// =========================================================================
		// Interval folding — date("N unit") is a bare integer in SQL
		// =========================================================================
		
		/**
		 * date("30 days") must execute without error. The generated SQL
		 * should contain the integer literal 2592000, not a function call.
		 */
		public function testIntervalFoldsToIntegerAndExecutes(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				where date(p.createdAt) > date(\"now\") - date(\"30 days\")
			");
			
			// Only post 1 (10 days ago) is within the last 30 days.
			$this->assertCount(1, $result);
			$this->assertSame(1, $result[0]['p.id']);
		}
		
		/**
		 * Two date() intervals added together must produce the correct combined
		 * second count: date("6 days") + date("2 days") == 691200 (8 days).
		 */
		public function testIntervalAdditionFoldsCorrectly(): void {
			// We cannot directly inspect the SQL, but we can verify the arithmetic
			// is correct by checking that the combined interval filters correctly.
			// 6 days + 2 days = 8 days total. post 1 is 10 days old, so it must
			// NOT be found here; posts 2 and 3 are also outside 8 days, but post 1
			// is within 10 days. Use the sum as a threshold.
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				where date(p.createdAt) > date(\"now\") - (date(\"6 days\") + date(\"2 days\"))
			");
			
			// 8 days ago: none of the posts are within 8 days (post 1 is 10 days old).
			$this->assertCount(0, $result);
		}
		
		/**
		 * Interval subtraction: date("40 days") - date("20 days") must fold to
		 * 20 days worth of seconds (1728000). Verified by retrieving the computed
		 * value directly rather than relying on compound WHERE precedence.
		 */
		public function testIntervalSubtractionFoldsCorrectly(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (diff = date(\"40 days\") - date(\"20 days\"))
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			// 40 days - 20 days = 20 days = 1728000 seconds
			$this->assertSame(1728000, (int) $result[0]['diff']);
		}
		
		/**
		 * (datetime) cast applied to a date() interval expression before arithmetic.
		 * (datetime)date('2 days') produces a datetime, minus date('1 day') which
		 * is an interval — datetime - interval → datetime. Must not throw and must
		 * return a \DateTime without requiring parentheses around the cast operand.
		 */
		public function testDatetimeCastBeforeArithmeticDoesNotThrow(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (ts = (datetime)date(\"2 days\") - date(\"1 day\"))
				where p.id = 1
			");

			$this->assertCount(1, $result);
			$this->assertInstanceOf(\DateTime::class, $result[0]['ts']);
		}

		// =========================================================================
		// date("now")
		// =========================================================================
		
		/**
		 * date("now") must return a value greater than any reasonable past timestamp,
		 * confirming the platform now-function is emitted and executes correctly.
		 */
		public function testDateNowIsGreaterThanPastTimestamp(): void {
			// All posts were created in the past, so all timestamps must be
			// less than NOW(). A WHERE that filters date(p.createdAt) < date("now")
			// must return all rows.
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				where date(p.createdAt) < date(\"now\")
			");
			
			$this->assertCount(3, $result);
		}
		
		// =========================================================================
		// WHERE filtering — the primary use case
		// =========================================================================
		
		/**
		 * Posts created within the last 30 days.
		 * Only post 1 (10 days ago) must be returned.
		 */
		public function testFilterPostsCreatedWithinLast30Days(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.title)
				where date(p.createdAt) > date(\"now\") - date(\"30 days\")
			");
			
			$this->assertCount(1, $result);
			$this->assertSame('Recent', $result[0]['p.title']);
		}
		
		/**
		 * Posts created within the last 60 days.
		 * Posts 1 (10 days) and 2 (40 days) must be returned.
		 */
		public function testFilterPostsCreatedWithinLast60Days(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				where date(p.createdAt) > date(\"now\") - date(\"60 days\")
				sort by p.id asc
			");
			
			$this->assertCount(2, $result);
			$this->assertSame(1, $result[0]['p.id']);
			$this->assertSame(2, $result[1]['p.id']);
		}
		
		/**
		 * Posts older than 1 year.
		 * Only post 3 (366 days old) must be returned.
		 */
		public function testFilterPostsOlderThanOneYear(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.title)
				where date(p.createdAt) < date(\"now\") - date(\"1 year\")
			");
			
			$this->assertCount(1, $result);
			$this->assertSame('VeryOld', $result[0]['p.title']);
		}
		
		/**
		 * With a parameter-bound cutoff date.
		 * :cutoff is a Unix timestamp; compare directly against date(p.createdAt).
		 */
		public function testFilterWithParameterCutoff(): void {
			// cutoff = 20 days ago
			$cutoff = time() - 20 * 86400;
			
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				where date(p.createdAt) > :cutoff
			", ['cutoff' => $cutoff]);
			
			// Only post 1 (10 days old) is after the 20-day cutoff.
			$this->assertCount(1, $result);
			$this->assertSame(1, $result[0]['p.id']);
		}
		
		// =========================================================================
		// Composite interval strings (QUEL-style "N unit M unit")
		// =========================================================================
		
		/**
		 * "1 year 10 days" is a composite interval. Posts older than 1 year + 10
		 * days must be returned; post 3 is 366 days old which is just past
		 * 1 year (365 days) but not past 1 year + 10 days (375 days).
		 */
		public function testCompositeIntervalOneYearTenDays(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.title)
				where date(p.createdAt) < date(\"now\") - date(\"1 year 10 days\")
			");
			
			// post 3 is 366 days old, which is less than 375 days — not returned.
			$this->assertCount(0, $result);
		}
		
		/**
		 * "1 year" alone must still match post 3 (366 days > 365 days).
		 * This also confirms the single-unit path still works after the
		 * multi-unit parser was introduced.
		 */
		public function testSingleYearIntervalMatchesPostOlderThanOneYear(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.title)
				where date(p.createdAt) < date(\"now\") - date(\"1 year\")
			");
			
			$this->assertCount(1, $result);
			$this->assertSame('VeryOld', $result[0]['p.title']);
		}
		
		// =========================================================================
		// SELECT list — hydration
		// =========================================================================
		
		/**
		 * date(p.createdAt) in the retrieve list must hydrate as \DateTime.
		 */
		public function testDateColumnInSelectListReturnsDateTime(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (ts = date(p.createdAt))
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertInstanceOf(\DateTime::class, $result[0]['ts']);
		}
		
		/**
		 * date("now") in the retrieve list. UNIX_TIMESTAMP() with no argument
		 * returns null when MySQL's timezone system tables are not populated
		 * (a common server configuration). This test verifies the query executes
		 * without error and produces exactly one row; the value is not asserted
		 * since it is environment-dependent.
		 *
		 * The companion test testDateColumnInSelectListReturnsDateTime() covers
		 * the hydration contract for the column-argument form, which is reliable
		 * across all environments.
		 */
		public function testDateNowInSelectListExecutesWithoutError(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (now = date(\"now\"))
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertArrayHasKey('now', $result[0]);
		}
		
		/**
		 * The \DateTime returned for date(p.createdAt) must be approximately
		 * 10 days in the past (within a 1-minute tolerance for test execution time).
		 */
		public function testDateColumnHydratesCorrectTimestamp(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (ts = date(p.createdAt))
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$dt = $result[0]['ts'];
			$this->assertInstanceOf(\DateTime::class, $dt);
			
			$ageInSeconds = time() - $dt->getTimestamp();
			
			// Post 1 was inserted as DATE_SUB(NOW(), INTERVAL 10 DAY).
			// Allow ±60 seconds of tolerance for test execution time.
			$this->assertGreaterThan(10 * 86400 - 60, $ageInSeconds);
			$this->assertLessThan(10 * 86400 + 60, $ageInSeconds);
		}
		
		/**
		 * Wrapping date() in an explicit (int) cast must return a PHP int,
		 * not a \DateTime. The AstCast branch in processValue() takes precedence.
		 */
		public function testDateColumnWithIntCastReturnsInt(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (ts = (int)date(p.createdAt))
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			$this->assertIsInt($result[0]['ts']);
		}
		
		// =========================================================================
		// ORDER BY with date arithmetic
		// =========================================================================
		
		/**
		 * date() in a sort expression must not crash and must produce the
		 * expected order (most recent first = ascending timestamp).
		 */
		public function testSortByDateColumnAscending(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				sort by date(p.createdAt) asc
			");
			
			$ids = array_column($result->fetchAll(), 'p.id');
			$this->assertSame([3, 2, 1], $ids);
		}
		
		public function testSortByDateColumnDescending(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				sort by date(p.createdAt) desc
			");
			
			$ids = array_column($result->fetchAll(), 'p.id');
			$this->assertSame([1, 2, 3], $ids);
		}
		
		// =========================================================================
		// Error cases — parser must reject unrecognised interval strings
		// =========================================================================
		
		/**
		 * An interval string with an unknown unit must throw at parse time.
		 * IntervalParser rejects unrecognised units with a ParserException, which
		 * QueryExecutor wraps as a QuelException.
		 */
		public function testUnknownIntervalUnitThrows(): void {
			$this->expectException(\Quellabs\ObjectQuel\Exception\QuelException::class);
			
			$this->em->executeQuery("
				range of p is PostEntity
				retrieve (p.id)
				where date(p.createdAt) < date(\"now\") - date(\"6 fortnights\")
			");
		}
		
		/**
		 * A bare "now" passed as an interval to arithmetic must not be treated
		 * as a zero interval — it emits the NOW function, so subtracting
		 * date("now") from date("now") is legal and should yield ~0.
		 */
		public function testDateNowMinusDateNowIsApproximatelyZero(): void {
			$result = $this->em->executeQuery("
				range of p is PostEntity
				retrieve (diff = date(\"now\") - date(\"now\"))
				where p.id = 1
			");
			
			$this->assertCount(1, $result);
			// The difference between two NOW() calls should be 0 or 1 second.
			$this->assertLessThanOrEqual(1, abs((int) $result[0]['diff']));
		}
	}