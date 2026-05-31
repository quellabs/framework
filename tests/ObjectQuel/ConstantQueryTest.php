<?php
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use Quellabs\ObjectQuel\Exception\QuelException;
	
	/**
	 * Tests for rangeless retrieve() queries — queries that declare no ranges and
	 * contain only constant expressions in the projection list.
	 *
	 * These queries bypass the database and JSON execution paths entirely: the
	 * planner emits a ConstantStage, and PlanExecutor delegates to
	 * ConstantQueryExecutor, which evaluates every projection via ConditionEvaluator
	 * against an empty row and returns exactly one synthetic result row.
	 *
	 * Coverage:
	 *   - Semantic validation accepts rangeless queries (no "must have a range" error)
	 *   - Always returns exactly one row, regardless of projection content
	 *   - Numeric and string literals (int, float, string, bool)
	 *   - Arithmetic expressions (+, -, *, /, nested)
	 *   - Parameters (:name bindings), including inside arithmetic
	 *   - Explicit aliases
	 *   - Auto-generated aliases (source text slice, trimmed)
	 *   - Multiple projections in one query
	 *   - concat() and ifnull() functions
	 *   - date() function (intervals, "now", datetime strings)
	 *   - fetchRow() API compatibility
	 */
	class ConstantQueryTest extends ObjectQuelTestCase {
		
		/**
		 * No fixtures needed — constant queries never touch the database.
		 */
		protected array $truncateTables = [];
		
		// -------------------------------------------------------------------------
		// Acceptance — rangeless queries must not be rejected
		// -------------------------------------------------------------------------
		
		/**
		 * A retrieve() with no ranges and a single literal must be accepted
		 * by the semantic analyser without throwing.
		 * Previously validateAtLeastOneRangeWithoutVia() would throw here.
		 */
		public function testRangelessQueryIsAccepted(): void {
			$result = $this->em->executeQuery("retrieve(v = 1)");
			$this->assertNotNull($result);
		}
		
		// -------------------------------------------------------------------------
		// Row count — always exactly one row
		// -------------------------------------------------------------------------
		
		/**
		 * A constant query always produces exactly one result row, since it is
		 * evaluated against a single synthetic empty row with no data source.
		 */
		public function testRangelessQueryReturnsExactlyOneRow(): void {
			$result = $this->em->executeQuery("retrieve(v = 42)");
			$this->assertCount(1, $result);
		}
		
		// -------------------------------------------------------------------------
		// Literals
		// -------------------------------------------------------------------------
		
		public function testIntegerLiteralIsReturned(): void {
			$result = $this->em->executeQuery("retrieve(v = 42)");
			$this->assertSame(42, $result[0]['v']);
		}
		
		public function testNegativeIntegerLiteralIsReturned(): void {
			$result = $this->em->executeQuery("retrieve(v = -7)");
			$this->assertSame(-7, $result[0]['v']);
		}
		
		public function testFloatLiteralIsReturned(): void {
			$result = $this->em->executeQuery("retrieve(v = 3.14)");
			$this->assertEqualsWithDelta(3.14, $result[0]['v'], 0.0001);
		}
		
		public function testFloatLiteralIsPhpFloat(): void {
			$result = $this->em->executeQuery("retrieve(v = 1.0)");
			$this->assertIsFloat($result[0]['v']);
		}
		
		public function testIntegerLiteralIsPhpInt(): void {
			$result = $this->em->executeQuery("retrieve(v = 5)");
			$this->assertIsInt($result[0]['v']);
		}
		
		public function testStringLiteralIsReturned(): void {
			$result = $this->em->executeQuery("retrieve(v = 'hello')");
			$this->assertSame('hello', $result[0]['v']);
		}
		
		public function testEmptyStringLiteralIsReturned(): void {
			$result = $this->em->executeQuery("retrieve(v = '')");
			$this->assertSame('', $result[0]['v']);
		}
		
		public function testTrueLiteralIsReturned(): void {
			$result = $this->em->executeQuery("retrieve(v = true)");
			$this->assertTrue((bool) $result[0]['v']);
		}
		
		public function testFalseLiteralIsReturned(): void {
			$result = $this->em->executeQuery("retrieve(v = false)");
			$this->assertFalse((bool) $result[0]['v']);
		}
		
		// -------------------------------------------------------------------------
		// Arithmetic
		// -------------------------------------------------------------------------
		
		public function testAdditionIsEvaluated(): void {
			$result = $this->em->executeQuery("retrieve(v = 3 + 4)");
			$this->assertSame(7, $result[0]['v']);
		}
		
		public function testSubtractionIsEvaluated(): void {
			$result = $this->em->executeQuery("retrieve(v = 10 - 3)");
			$this->assertSame(7, $result[0]['v']);
		}
		
		public function testMultiplicationIsEvaluated(): void {
			$result = $this->em->executeQuery("retrieve(v = 6 * 7)");
			$this->assertSame(42, $result[0]['v']);
		}
		
		public function testDivisionIsEvaluated(): void {
			$result = $this->em->executeQuery("retrieve(v = 10 / 4)");
			$this->assertEqualsWithDelta(2.5, $result[0]['v'], 0.0001);
		}
		
		public function testNestedArithmeticIsEvaluated(): void {
			$result = $this->em->executeQuery("retrieve(v = (2 + 3) * 4)");
			$this->assertSame(20, $result[0]['v']);
		}
		
		// -------------------------------------------------------------------------
		// Parameters
		// -------------------------------------------------------------------------
		
		public function testParameterIsResolved(): void {
			$result = $this->em->executeQuery("retrieve(v = :x)", ['x' => 99]);
			$this->assertSame(99, $result[0]['v']);
		}
		
		public function testStringParameterIsResolved(): void {
			$result = $this->em->executeQuery("retrieve(v = :name)", ['name' => 'world']);
			$this->assertSame('world', $result[0]['v']);
		}
		
		public function testParameterInArithmeticIsResolved(): void {
			$result = $this->em->executeQuery("retrieve(v = :x + 1)", ['x' => 41]);
			$this->assertSame(42, $result[0]['v']);
		}
		
		// -------------------------------------------------------------------------
		// Multiple projections
		// -------------------------------------------------------------------------
		
		public function testMultipleProjectionsAreAllReturned(): void {
			$result = $this->em->executeQuery("retrieve(a = 1, b = 2, c = 3)");
			$this->assertCount(1, $result);
			$this->assertSame(1, $result[0]['a']);
			$this->assertSame(2, $result[0]['b']);
			$this->assertSame(3, $result[0]['c']);
		}
		
		public function testMixedTypeProjectionsAreAllReturned(): void {
			$result = $this->em->executeQuery("retrieve(n = 42, s = 'hi', f = 1.5)");
			$this->assertSame(42, $result[0]['n']);
			$this->assertSame('hi', $result[0]['s']);
			$this->assertEqualsWithDelta(1.5, $result[0]['f'], 0.0001);
		}
		
		// -------------------------------------------------------------------------
		// Functions — concat, ifnull
		// -------------------------------------------------------------------------
		
		public function testConcatOfLiterals(): void {
			$result = $this->em->executeQuery("retrieve(v = concat('foo', 'bar'))");
			$this->assertSame('foobar', $result[0]['v']);
		}
		
		public function testConcatWithParameter(): void {
			$result = $this->em->executeQuery("retrieve(v = concat('Hello, ', :name))", ['name' => 'world']);
			$this->assertSame('Hello, world', $result[0]['v']);
		}
		
		public function testIfNullReturnsFirstValueWhenNotNull(): void {
			$result = $this->em->executeQuery("retrieve(v = ifnull(1, 99))");
			$this->assertSame(1, $result[0]['v']);
		}
		
		// -------------------------------------------------------------------------
		// date() function
		//
		// date() converts its argument to a Unix timestamp. In a constant query
		// there are no columns, so only string literals and "now" are meaningful.
		// All four argument forms handled by ConditionEvaluator are covered:
		//   1. Interval string  — pre-folded to int at parse time
		//   2. "now"            — time() at evaluation time
		//   3. Date string      — strtotime("YYYY-MM-DD")
		//   4. Datetime string  — strtotime("YYYY-MM-DD HH:MM:SS")
		// -------------------------------------------------------------------------
		
		/**
		 * A plain interval string is folded to integer seconds at parse time.
		 * "1 day" = 86400 seconds.
		 */
		public function testDateIntervalDayIsConvertedToSeconds(): void {
			$result = $this->em->executeQuery("retrieve(v = date('1 day'))");
			$this->assertSame(86400, $result[0]['v']);
		}
		
		/**
		 * Composite interval: "1 hour 30 minutes" = 3600 + 1800 = 5400 seconds.
		 */
		public function testDateCompositeIntervalIsConvertedToSeconds(): void {
			$result = $this->em->executeQuery("retrieve(v = date('1 hour 30 minutes'))");
			$this->assertSame(5400, $result[0]['v']);
		}
		
		/**
		 * date("now") returns a \DateTime for the current moment, consistent with
		 * what the hydrator produces for database date() results.
		 */
		public function testDateNowReturnsDateTime(): void {
			$before = new \DateTime();
			$result = $this->em->executeQuery("retrieve(v = date('now'))");
			$after = new \DateTime();
			$this->assertInstanceOf(\DateTime::class, $result[0]['v']);
			$this->assertGreaterThanOrEqual($before->getTimestamp(), $result[0]['v']->getTimestamp());
			$this->assertLessThanOrEqual($after->getTimestamp(), $result[0]['v']->getTimestamp());
		}
		
		/**
		 * A date-only string ("YYYY-MM-DD") is converted to a \DateTime, consistent
		 * with what the hydrator produces for database date() results.
		 */
		public function testDateStringDateOnlyReturnsDateTime(): void {
			$result = $this->em->executeQuery("retrieve(v = date('2024-01-15'))");
			$this->assertInstanceOf(\DateTime::class, $result[0]['v']);
			$this->assertSame('2024-01-15', $result[0]['v']->format('Y-m-d'));
		}
		
		/**
		 * A full datetime string ("YYYY-MM-DD HH:MM:SS") is converted to a \DateTime.
		 */
		public function testDateStringDatetimeReturnsDateTime(): void {
			$result = $this->em->executeQuery("retrieve(v = date('2024-01-15 10:30:00'))");
			$this->assertInstanceOf(\DateTime::class, $result[0]['v']);
			$this->assertSame('2024-01-15 10:30:00', $result[0]['v']->format('Y-m-d H:i:s'));
		}
		
		/**
		 * date() used in arithmetic: two intervals added together produce an int.
		 * "1 day" + "1 hour" = 86400 + 3600 = 90000 seconds.
		 */
		public function testDateIntervalArithmetic(): void {
			$result = $this->em->executeQuery("retrieve(v = date('1 day') + date('1 hour'))");
			$this->assertSame(90000, $result[0]['v']);
		}
		
		/**
		 * date("now") added to an interval must produce a \\DateTime roughly one
		 * hour in the future. Before the fix, date("now") returned a "Y-m-d H:i:s"
		 * string that toNumber() coerced to 0, giving "1970-01-01 01:00:00".
		 */
		public function testDateNowPlusIntervalReturnsDateTime(): void {
			$before = new \DateTime('+1 hour -5 seconds');
			$result = $this->em->executeQuery("retrieve(v = date('now') + date('1 hours'))");
			$after = new \DateTime('+1 hour +5 seconds');
			$this->assertInstanceOf(\DateTime::class, $result[0]['v']);
			$this->assertGreaterThanOrEqual($before->getTimestamp(), $result[0]['v']->getTimestamp());
			$this->assertLessThanOrEqual($after->getTimestamp(), $result[0]['v']->getTimestamp());
		}
		
		/**
		 * date("now") minus an interval must produce a \\DateTime roughly one
		 * hour in the past.
		 */
		public function testDateNowMinusIntervalReturnsDateTime(): void {
			$before = new \DateTime('-1 hour -5 seconds');
			$result = $this->em->executeQuery("retrieve(v = date('now') - date('1 hours'))");
			$after = new \DateTime('-1 hour +5 seconds');
			$this->assertInstanceOf(\DateTime::class, $result[0]['v']);
			$this->assertGreaterThanOrEqual($before->getTimestamp(), $result[0]['v']->getTimestamp());
			$this->assertLessThanOrEqual($after->getTimestamp(), $result[0]['v']->getTimestamp());
		}
		
		/**
		 * A full datetime string added to an interval must produce a \\DateTime
		 * at the expected moment. Before the fix, the datetime string was passed
		 * through toNumber() as-is, which returned 0.
		 */
		public function testDateDatetimeStringPlusIntervalReturnsDateTime(): void {
			$result = $this->em->executeQuery("retrieve(v = date('2024-01-15 10:30:00') + date('1 hours'))");
			$this->assertInstanceOf(\DateTime::class, $result[0]['v']);
			$this->assertSame('2024-01-15 11:30:00', $result[0]['v']->format('Y-m-d H:i:s'));
		}
		
		/**
		 * A date-only string (padded to midnight) added to an interval must
		 * produce a \\DateTime at midnight + the interval.
		 */
		public function testDateDateOnlyStringPlusIntervalReturnsDateTime(): void {
			$result = $this->em->executeQuery("retrieve(v = date('2024-01-15') + date('2 hours'))");
			$this->assertInstanceOf(\DateTime::class, $result[0]['v']);
			$this->assertSame('2024-01-15 02:00:00', $result[0]['v']->format('Y-m-d H:i:s'));
		}
		
		/**
		 * Subtracting two datetime strings must produce an integer number of
		 * seconds (an interval), not a \\DateTime.
		 */
		public function testDateDatetimeStringMinusDatetimeStringReturnsSeconds(): void {
			$result = $this->em->executeQuery("retrieve(v = date('2024-01-15 11:30:00') - date('2024-01-15 10:30:00'))");
			$this->assertSame(3600, $result[0]['v']);
		}
		
		/**
		 * An unrecognised string that is neither a datetime nor a valid interval
		 * must throw at parse time, not silently produce zero.
		 */
		public function testDateInvalidStringThrows(): void {
			$this->expectException(QuelException::class);
			$this->em->executeQuery("retrieve(v = date('yesterday'))");
		}
		
		// -------------------------------------------------------------------------
		// Aliases — explicit
		// -------------------------------------------------------------------------
		
		public function testExplicitAliasIsUsedAsKey(): void {
			$result = $this->em->executeQuery("retrieve(my_key = 123)");
			$this->assertArrayHasKey('my_key', $result[0]);
			$this->assertSame(123, $result[0]['my_key']);
		}
		
		public function testMultipleExplicitAliasesAreUsedAsKeys(): void {
			$result = $this->em->executeQuery("retrieve(first = 1, second = 2)");
			$this->assertArrayHasKey('first', $result[0]);
			$this->assertSame(1, $result[0]['first']);
			$this->assertArrayHasKey('second', $result[0]);
			$this->assertSame(2, $result[0]['second']);
		}
		
		// -------------------------------------------------------------------------
		// Aliases — auto-generated (source text slice, trimmed)
		//
		// The auto-alias is derived from the raw source text of the expression,
		// trimmed of surrounding whitespace. The tests below pin that contract:
		// the key in the result row is exactly what was written in the query.
		// -------------------------------------------------------------------------
		
		public function testAutoAliasForIntegerLiteral(): void {
			$result = $this->em->executeQuery("retrieve(42)");
			$this->assertArrayHasKey('42', $result[0]);
			$this->assertSame(42, $result[0]['42']);
		}
		
		public function testAutoAliasForStringLiteral(): void {
			$result = $this->em->executeQuery("retrieve('hello')");
			$this->assertArrayHasKey("'hello'", $result[0]);
			$this->assertSame('hello', $result[0]["'hello'"]);
		}
		
		public function testAutoAliasForFloatLiteral(): void {
			$result = $this->em->executeQuery("retrieve(3.14)");
			$this->assertArrayHasKey('3.14', $result[0]);
			$this->assertEqualsWithDelta(3.14, $result[0]['3.14'], 0.0001);
		}
		
		/**
		 * 1.0 has no fractional digits after the dot, so (string)(float)1.0 in PHP
		 * produces "1", losing the dot entirely. The parser must preserve the source
		 * representation so AstNumber::getReturnType() still returns "float" and
		 * ConditionEvaluator casts it correctly.
		 */
		public function testAutoAliasForWholeNumberFloat(): void {
			$result = $this->em->executeQuery("retrieve(1.0)");
			$this->assertArrayHasKey('1.0', $result[0]);
			$this->assertIsFloat($result[0]['1.0']);
			$this->assertEqualsWithDelta(1.0, $result[0]['1.0'], 0.0001);
		}
		
		public function testAutoAliasForArithmeticExpression(): void {
			$result = $this->em->executeQuery("retrieve(3 + 4)");
			$this->assertArrayHasKey('3 + 4', $result[0]);
			$this->assertSame(7, $result[0]['3 + 4']);
		}
		
		public function testAutoAliasForParameter(): void {
			$result = $this->em->executeQuery("retrieve(:x)", ['x' => 5]);
			$this->assertArrayHasKey(':x', $result[0]);
			$this->assertSame(5, $result[0][':x']);
		}
		
		/**
		 * Multiple projections without aliases each get their own auto-generated key.
		 * All keys must be present and map to their correct evaluated values.
		 */
		public function testAutoAliasForMultipleProjections(): void {
			$result = $this->em->executeQuery("retrieve(1, 'two', 3 + 0)");
			$this->assertCount(1, $result);
			$this->assertArrayHasKey('1', $result[0]);
			$this->assertArrayHasKey("'two'", $result[0]);
			$this->assertArrayHasKey('3 + 0', $result[0]);
			$this->assertSame(1, $result[0]['1']);
			$this->assertSame('two', $result[0]["'two'"]);
			$this->assertSame(3, $result[0]['3 + 0']);
		}
		
		// -------------------------------------------------------------------------
		// Edge cases
		// -------------------------------------------------------------------------
		
		/**
		 * A parameter evaluating to zero must still produce one row — zero is a
		 * valid result, not an empty result set.
		 */
		public function testZeroResultIsNotTreatedAsEmpty(): void {
			$result = $this->em->executeQuery("retrieve(v = :x)", ['x' => 0]);
			$this->assertCount(1, $result);
			$this->assertSame(0, $result[0]['v']);
		}
		
		/**
		 * fetchRow() must work on a rangeless result the same way it does on
		 * any other QuelResult — the caller must not need to treat it specially.
		 */
		public function testFetchRowWorksOnRangelessResult(): void {
			$result = $this->em->executeQuery("retrieve(v = 7)");
			$row = $result->fetchRow();
			$this->assertNotNull($row);
			$this->assertSame(7, $row['v']);
			$this->assertNull($result->fetchRow()); // Only one row
		}
	}