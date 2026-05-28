<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\ObjectQuel\ParserException;
	use Quellabs\ObjectQuel\ObjectQuel\Rules\IntervalParser;
	use Quellabs\ObjectQuel\Serialization\Normalizer\DatetimeNormalizer;
	
	/**
	 * Pure unit tests for the date() feature — no database required.
	 *
	 * Two components under test:
	 *
	 *   $this->intervalParser->parse()
	 *     The static parser that converts interval strings like "6 days" or
	 *     "4 years 20 minutes" to integer seconds at parse time. Tested in
	 *     full isolation with no AST construction needed.
	 *
	 *   DatetimeNormalizer::normalize()
	 *     The hydration normalizer that converts raw database values to
	 *     \DateTime objects. Extended in this release to accept Unix timestamp
	 *     integers returned by date() expressions in the SELECT list.
	 */
	class DateUnitTest extends TestCase {
		
		// =========================================================================
		// $this->intervalParser->parse — single unit
		// =========================================================================
		
		/**
		 * A single well-formed "N unit" pair must return the correct second count.
		 */
		public function testSingleDayInterval(): void {
			$this->assertSame(86400, $this->intervalParser->parse('1 day'));
		}
		
		public function testSingleDaysInterval(): void {
			$this->assertSame(86400 * 6, $this->intervalParser->parse('6 days'));
		}
		
		public function testSingleHoursInterval(): void {
			$this->assertSame(3600 * 2, $this->intervalParser->parse('2 hours'));
		}
		
		public function testSingleMinutesInterval(): void {
			$this->assertSame(60 * 30, $this->intervalParser->parse('30 minutes'));
		}
		
		public function testSingleSecondsInterval(): void {
			$this->assertSame(45, $this->intervalParser->parse('45 seconds'));
		}
		
		public function testSingleWeeksInterval(): void {
			$this->assertSame(604800 * 3, $this->intervalParser->parse('3 weeks'));
		}
		
		public function testSingleMonthsInterval(): void {
			// 1 month = 30 days = 2592000 seconds
			$this->assertSame(2592000, $this->intervalParser->parse('1 month'));
		}
		
		public function testSingleYearsInterval(): void {
			// 1 year = 365 days = 31536000 seconds
			$this->assertSame(31536000, $this->intervalParser->parse('1 year'));
		}
		
		// =========================================================================
		// $this->intervalParser->parse — singular forms
		// =========================================================================
		
		/**
		 * Singular unit names must be accepted alongside their plural equivalents.
		 */
		public function testSingularSecond(): void {
			$this->assertSame(1, $this->intervalParser->parse('1 second'));
		}
		
		public function testSingularMinute(): void {
			$this->assertSame(60, $this->intervalParser->parse('1 minute'));
		}
		
		public function testSingularHour(): void {
			$this->assertSame(3600, $this->intervalParser->parse('1 hour'));
		}
		
		public function testSingularWeek(): void {
			$this->assertSame(604800, $this->intervalParser->parse('1 week'));
		}
		
		// =========================================================================
		// $this->intervalParser->parse — composite intervals (QUEL-style)
		// =========================================================================
		
		/**
		 * "4 years 20 minutes" must sum the two components correctly.
		 */
		public function testCompositeYearsAndMinutes(): void {
			$expected = 4 * 31536000 + 20 * 60;
			$this->assertSame($expected, $this->intervalParser->parse('4 years 20 minutes'));
		}
		
		public function testCompositeDaysAndHours(): void {
			$expected = 2 * 86400 + 6 * 3600;
			$this->assertSame($expected, $this->intervalParser->parse('2 days 6 hours'));
		}
		
		public function testCompositeThreeComponents(): void {
			$expected = 1 * 86400 + 2 * 3600 + 30 * 60;
			$this->assertSame($expected, $this->intervalParser->parse('1 day 2 hours 30 minutes'));
		}
		
		public function testCompositeWeeksAndDays(): void {
			$expected = 1 * 604800 + 3 * 86400;
			$this->assertSame($expected, $this->intervalParser->parse('1 week 3 days'));
		}
		
		// =========================================================================
		// $this->intervalParser->parse — case insensitivity
		// =========================================================================
		
		public function testCaseInsensitiveUnits(): void {
			$this->assertSame(86400 * 6, $this->intervalParser->parse('6 DAYS'));
			$this->assertSame(86400 * 6, $this->intervalParser->parse('6 Days'));
		}
		
		// =========================================================================
		// $this->intervalParser->parse — negative intervals
		// =========================================================================
		
		/**
		 * Negative amounts must produce negative second counts.
		 * Used internally when date("now") - date("30 days") is evaluated.
		 */
		public function testNegativeDays(): void {
			$this->assertSame(-86400 * 30, $this->intervalParser->parse('-30 days'));
		}
		
		// =========================================================================
		// IntervalParser — "now" and empty string (return null, not throws)
		// =========================================================================
		
		/**
		 * "now" is a timestamp keyword, not an interval — must return null so the
		 * SQL generator emits the platform's current-timestamp function instead.
		 */
		public function testNowReturnsNull(): void {
			$this->assertNull($this->intervalParser->parse('now'));
		}
		
		public function testNowCaseInsensitiveReturnsNull(): void {
			$this->assertNull($this->intervalParser->parse('NOW'));
			$this->assertNull($this->intervalParser->parse('Now'));
		}
		
		public function testEmptyStringReturnsNull(): void {
			$this->assertNull($this->intervalParser->parse(''));
		}
		
		// =========================================================================
		// IntervalParser — invalid input must throw ParserException
		// =========================================================================
		
		public function testUnrecognisedStringThrows(): void {
			$this->expectException(ParserException::class);
			$this->intervalParser->parse('yesterday');
		}
		
		public function testBareNumberThrows(): void {
			$this->expectException(ParserException::class);
			$this->intervalParser->parse('42');
		}
		
		public function testUnknownUnitThrows(): void {
			$this->expectException(ParserException::class);
			$this->intervalParser->parse('1 bananas');
		}
		
		public function testExtraTokensThrow(): void {
			$this->expectException(ParserException::class);
			$this->intervalParser->parse('6 days ago');
		}
		
		public function testLeadingGarbageThrows(): void {
			$this->expectException(ParserException::class);
			$this->intervalParser->parse('about 6 days');
		}
		
		// =========================================================================
		// DatetimeNormalizer — existing string path (must not regress)
		// =========================================================================
		
		private IntervalParser $intervalParser;
		private DatetimeNormalizer $normalizer;
		
		protected function setUp(): void {
			$this->intervalParser = new IntervalParser();
			$this->normalizer = new DatetimeNormalizer([]);
		}
		
		public function testNullInputReturnsNull(): void {
			$this->assertNull($this->normalizer->normalize(null));
		}
		
		public function testZeroDatetimeStringReturnsNull(): void {
			$this->assertNull($this->normalizer->normalize('0000-00-00 00:00:00'));
		}
		
		public function testValidDatetimeStringReturnsDateTime(): void {
			$result = $this->normalizer->normalize('2024-01-15 10:30:00');
			
			$this->assertInstanceOf(\DateTime::class, $result);
			$this->assertSame('2024-01-15 10:30:00', $result->format('Y-m-d H:i:s'));
		}
		
		public function testMalformedStringReturnsNull(): void {
			$this->assertNull($this->normalizer->normalize('not-a-date'));
		}
		
		// =========================================================================
		// DatetimeNormalizer — Unix timestamp path (new behaviour)
		// =========================================================================
		
		/**
		 * An integer Unix timestamp must be converted to a \DateTime object.
		 * This is the value returned by UNIX_TIMESTAMP() / strftime('%s') when
		 * a date() expression appears in the SELECT list.
		 */
		public function testIntegerTimestampReturnsDateTime(): void {
			$ts = mktime(12, 0, 0, 6, 15, 2023); // 2023-06-15 12:00:00 local time
			$result = $this->normalizer->normalize($ts);
			
			$this->assertInstanceOf(\DateTime::class, $result);
			$this->assertSame('2023-06-15', $result->format('Y-m-d'));
		}
		
		/**
		 * A numeric string (as PDO may return BIGINT columns) must also be accepted.
		 */
		public function testNumericStringTimestampReturnsDateTime(): void {
			$ts = mktime(0, 0, 0, 1, 1, 2000);
			$result = $this->normalizer->normalize((string) $ts);
			
			$this->assertInstanceOf(\DateTime::class, $result);
			$this->assertSame('2000-01-01', $result->format('Y-m-d'));
		}
		
		/**
		 * Integer zero (Unix epoch) is treated the same as the zero datetime
		 * string — both represent "no value" and must return null.
		 */
		public function testZeroIntegerTimestampReturnsNull(): void {
			$this->assertNull($this->normalizer->normalize(0));
		}
		
		/**
		 * The timezone of the returned \DateTime must match date_default_timezone_get(),
		 * not UTC. PHP sets '@timestamp' datetimes to UTC internally; the normalizer
		 * must correct this.
		 */
		public function testTimestampReturnsDateTimeInLocalTimezone(): void {
			$ts = mktime(12, 0, 0, 3, 20, 2024);
			$result = $this->normalizer->normalize($ts);
			
			$this->assertInstanceOf(\DateTime::class, $result);
			$this->assertSame(
				date_default_timezone_get(),
				$result->getTimezone()->getName()
			);
		}
		
		/**
		 * A non-numeric, non-string value (e.g. a float) must not crash —
		 * the normalizer should return null for values it cannot interpret.
		 */
		public function testNonNumericNonStringReturnsNull(): void {
			$this->assertNull($this->normalizer->normalize(3.14));
		}
		
		/**
		 * An array value must return null, not throw.
		 */
		public function testArrayInputReturnsNull(): void {
			$this->assertNull($this->normalizer->normalize([]));
		}
		
		// =========================================================================
		// DatetimeNormalizer — denormalize (must not regress)
		// =========================================================================
		
		public function testDenormalizeNullReturnsNull(): void {
			$this->assertNull($this->normalizer->denormalize(null));
		}
		
		public function testDenormalizeDateTimeReturnsFormattedString(): void {
			$dt = new \DateTime('2024-06-01 08:00:00');
			$this->assertSame('2024-06-01 08:00:00', $this->normalizer->denormalize($dt));
		}
		
		public function testDenormalizeNonDateTimeReturnsNull(): void {
			$this->assertNull($this->normalizer->denormalize('not a datetime'));
		}
	}