<?php
	
	namespace Quellabs\Recommender\Internal;
	
	/** Shared bounds and validators for the recommender's identifiers and keys. */
	final class Identifier {
		
		/** @var int Largest valid member, product or category ID */
		public const MAX = 4294967295;
		
		/**
		 * Validate that an ID is an unsigned 32-bit integer.
		 * @param int $id ID to validate
		 * @param string $name Error context, such as "Member ID"
		 * @return void
		 * @throws \InvalidArgumentException When the ID is outside the unsigned 32-bit range
		 */
		public static function assertId(int $id, string $name): void {
			if ($id < 0 || $id > self::MAX) {
				throw new \InvalidArgumentException("{$name} must be an unsigned 32-bit integer, got {$id}.");
			}
		}
		
		/**
		 * Validate that a threshold or limit is at least a minimum.
		 * @param int $value Value to validate
		 * @param int $min Smallest allowed value
		 * @param string $name Error context, such as "Minimum support"
		 * @return void
		 * @throws \InvalidArgumentException When the value is below the minimum
		 */
		public static function assertAtLeast(int $value, int $min, string $name): void {
			if ($value < $min) {
				throw new \InvalidArgumentException("{$name} must be at least {$min}, got {$value}.");
			}
		}
		
		/**
		 * Validate that a threshold is within an inclusive range.
		 * @param int $value Value to validate
		 * @param int $min Smallest allowed value
		 * @param int $max Largest allowed value
		 * @param string $name Error context, such as "Minimum neighbour similarity"
		 * @return void
		 * @throws \InvalidArgumentException When the value is outside the range
		 */
		public static function assertInRange(int $value, int $min, int $max, string $name): void {
			if ($value < $min || $value > $max) {
				throw new \InvalidArgumentException("{$name} must be between {$min} and {$max}, got {$value}.");
			}
		}
		
		/**
		 * Validate a key as a non-empty printable ASCII string within a byte limit.
		 * @param string $value Key to validate
		 * @param int $maxLength Maximum byte length
		 * @param string $name Error context
		 * @return void
		 * @throws \InvalidArgumentException When the key is empty, too long, or not printable ASCII
		 */
		public static function validateKey(string $value, int $maxLength, string $name): void {
			if ($value === '' || strlen($value) > $maxLength || preg_match('/^[\x20-\x7e]+$/D', $value) !== 1) {
				throw new \InvalidArgumentException("Invalid {$name} key '{$value}': expected 1 to {$maxLength} printable ASCII characters.");
			}
		}
		
		/**
		 * Return the candidate IDs with duplicates removed, keeping first occurrences.
		 * @param array<int, int> $ids Candidate IDs
		 * @return array<int, int> First occurrences in input order
		 * @throws \InvalidArgumentException When an ID is not an unsigned 32-bit integer or there are too many distinct IDs
		 */
		public static function distinctIds(array $ids): array {
			$unique = [];
			
			foreach ($ids as $id) {
				if (!is_int($id) || $id < 0 || $id > self::MAX) {
					throw new \InvalidArgumentException('Candidate ID must be an unsigned 32-bit integer, got ' . var_export($id, true) . '.');
				}
				
				$unique[$id] = $id;
			}
			
			$count = count($unique);
			
			if ($count > 500) {
				throw new \InvalidArgumentException("At most 500 distinct caller candidate IDs are allowed, got {$count}.");
			}
			
			return array_values($unique);
		}
		
	}
