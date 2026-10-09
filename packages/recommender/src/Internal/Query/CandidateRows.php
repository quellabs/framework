<?php
	
	namespace Quellabs\Recommender\Internal\Query;
	
	use Quellabs\Recommender\RecommendationResult;
	use Quellabs\Recommender\RecommendationSource;
	
	/** Converts SQL candidate rows into recommendation results. */
	final class CandidateRows {
		
		/** @var string Error message */
		private const string MALFORMED_ROW = 'Source candidate row must have a numeric id and score, and a numeric support_count when present.';
		
		/**
		 * Convert SQL rows into results, dropping IDs that were already seen.
		 * @param array<mixed> $rows SQL rows with id and score, and optionally support_count and a JSON contributors list
		 * @param RecommendationSource $source Source that produced the rows
		 * @param array<int, float> $seen Seen rating per product ID, excluded from the results
		 * @return array<int, RecommendationResult>
		 * @throws \UnexpectedValueException When a row or its contributors are malformed
		 */
		public static function fromSql(array $rows, RecommendationSource $source, array $seen): array {
			$results = [];
			
			foreach ($rows as $row) {
				if (!is_array($row)) {
					throw new \UnexpectedValueException(self::MALFORMED_ROW);
				}
				
				if (!isset($row['id'], $row['score']) || !is_numeric($row['id']) || !is_numeric($row['score']) ||
					(isset($row['support_count']) && !is_numeric($row['support_count']))) {
					throw new \UnexpectedValueException(self::MALFORMED_ROW);
				}
				
				$id = (int)$row['id'];
				
				if (array_key_exists($id, $seen)) {
					continue;
				}
				
				$results[] = new RecommendationResult($id, (float)$row['score'], $source, self::contributors($row),
					isset($row['support_count']) ? (int)$row['support_count'] : null);
			}
			
			return $results;
		}
		
		/**
		 * Decode the JSON contributor list of a row.
		 * @param array<mixed> $row SQL row, which may hold a JSON "contributors" string
		 * @return array<int, int> Contributing product IDs, empty when the row has none
		 * @throws \UnexpectedValueException When the contributors are not a JSON array of IDs
		 */
		private static function contributors(array $row): array {
			if (!isset($row['contributors'])) {
				return [];
			}
			
			if (!is_string($row['contributors'])) {
				throw new \UnexpectedValueException('Source contributors must be a JSON string, got ' . get_debug_type($row['contributors']) . '.');
			}
			
			$decoded = json_decode($row['contributors'], true, 512, JSON_THROW_ON_ERROR);
			
			if (!is_array($decoded)) {
				throw new \UnexpectedValueException('Source contributors must decode to a JSON array.');
			}
			
			$contributors = [];
			
			foreach ($decoded as $contributor) {
				if (!is_int($contributor) && (!is_string($contributor) || !ctype_digit($contributor))) {
					throw new \UnexpectedValueException('Source contributor ID must be an unsigned integer, got ' . var_export($contributor, true) . '.');
				}
				
				$contributors[] = (int)$contributor;
			}
			
			return $contributors;
		}
	}
