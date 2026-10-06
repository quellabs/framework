<?php
	
	namespace Quellabs\Recommender\Internal;
	
	/** Shared bounds and validators for the recommender's identifiers and keys. */
	final class Identifier {
	
		/** @var int Largest valid member, product or category ID */
		public const MAX = 4294967295;

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
