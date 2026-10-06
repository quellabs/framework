<?php
	
	namespace Quellabs\Recommender\Internal\Persistence;
	
	use DateTimeImmutable;
	use DateTimeZone;
	
	/** Formats timestamps for MySQL DATETIME(6) columns. */
	final class MysqlTimestamp {
		
		/**
		 * Return the UTC MySQL microsecond timestamp for a point in time.
		 * @param DateTimeImmutable $time Caller time
		 * @return string UTC timestamp in Y-m-d H:i:s.u form
		 */
		public static function utc(DateTimeImmutable $time): string {
			return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
		}
	}
