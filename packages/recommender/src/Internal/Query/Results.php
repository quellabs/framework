<?php
	
	namespace Quellabs\Recommender\Internal\Query;
	
	/** Shared result shaping for recommendation queries: limits, rating clamping and score ordering. */
	final class Results {
		
		/**
		 * Keep the first items up to the limit, or every item when the limit is zero.
		 * @template T
		 * @param array<int, T> $items Ordered items
		 * @param int $limit Maximum number of items, or zero for all
		 * @return array<int, T>
		 */
		public static function limit(array $items, int $limit): array {
			return $limit > 0 ? array_slice($items, 0, $limit) : $items;
		}
		
		/**
		 * Return a SQL LIMIT clause, or an empty string when the limit is zero.
		 * @param int $limit Maximum number of rows, or zero for all
		 * @return string Clause with a leading space, or an empty string
		 */
		public static function limitSql(int $limit): string {
			return $limit > 0 ? ' LIMIT ' . $limit : '';
		}
		
		/**
		 * Clamp a predicted rating to the valid [0.0, 1.0] range.
		 * @param float $value The raw predicted rating
		 * @return float
		 */
		public static function clampRating(float $value): float {
			return max(0.0, min(1.0, $value));
		}
		
		/**
		 * Order product IDs by score descending, breaking ties by ascending product ID.
		 * @param array<int, float> $scores Score per product ID
		 * @return array<int, float> The same map, reordered
		 */
		public static function sortByScore(array $scores): array {
			uksort($scores, fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
			return $scores;
		}
	}
