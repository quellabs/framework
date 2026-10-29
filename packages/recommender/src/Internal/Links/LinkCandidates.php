<?php
	
	namespace Quellabs\Recommender\Internal\Links;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
	use Quellabs\Recommender\Internal\Query\CandidateRows;
	use Quellabs\Recommender\Internal\Query\SubjectRatings;
	use Quellabs\Recommender\RecommendationResult;
	use Quellabs\Recommender\RecommendationSource;
	use Quellabs\Recommender\Subject;
	
	/** Runs a link-table candidate query from the genuine ratings of a member or visitor, excluding seen products. */
	final class LinkCandidates {
		
		/** @var TemporaryTable Temporary tables for rating and seen inputs */
		private TemporaryTable $temporary;
		
		/** @var SubjectRatings Seen ratings of the subject */
		private SubjectRatings $ratings;
		
		/**
		 * Build the helper.
		 * @param Connection $connection The CakePHP database connection
		 * @param SubjectRatings $ratings Seen ratings loader
		 */
		public function __construct(Connection $connection, SubjectRatings $ratings) {
			$this->temporary = new TemporaryTable($connection);
			$this->ratings = $ratings;
		}
		
		/**
		 * Return the candidates linked to the subject's genuine ratings that the subject has not seen.
		 * @param Subject $subject Member or visitor subject
		 * @param int $category Resolved category
		 * @param int $depth Number of top candidates to keep, or zero for all
		 * @param RecommendationSource $source Source the rows are reported under
		 * @param callable(string, string, ?int): array<mixed> $query Runs the source's candidate query, given the rating join, the restriction and the row limit
		 * @param array<int, int>|null $productIds Restricts the candidates to these products, or null for all
		 * @return array<int, RecommendationResult>
		 */
		public function candidates(Subject $subject, int $category, int $depth, RecommendationSource $source, callable $query, ?array $productIds = null): array {
			$seen = $this->ratings->seen($subject, $category);
			$genuine = array_filter($seen, fn(float $rating): bool => $rating >= 0.0);
			
			if ($genuine === [] || $productIds === []) {
				return [];
			}
			
			$limit = $depth === 0 ? null : $depth;
			
			return $this->temporary->withRatingTable('vogoo_source_input_', $genuine,
				fn(string $ratingsTable) => $this->temporary->withIdTable('vogoo_seen_', array_keys($seen),
					fn(string $seenTable) => $this->restrictToProducts(
						"AND NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = l.item_id2)",
						$productIds,
						fn(string $restriction): array => CandidateRows::fromSql(
							$query("JOIN {$ratingsTable} r ON r.product_id = l.item_id1", $restriction, $limit),
							$source,
							$seen
						)
					)
				)
			);
		}
		
		/**
		 * Narrow a restriction to the given products through a temporary table, or pass it through unchanged.
		 * @template T
		 * @param string $restriction SQL restriction on the candidate row's item_id2
		 * @param array<int, int>|null $productIds Products to restrict to, or null for all
		 * @param callable(string): T $operation Receives the final restriction
		 * @return T Operation result
		 */
		private function restrictToProducts(string $restriction, ?array $productIds, callable $operation): mixed {
			if ($productIds === null) {
				return $operation($restriction);
			}
			
			return $this->temporary->withIdTable('vogoo_scored_', $productIds,
				fn(string $idTable) => $operation("{$restriction} AND EXISTS (SELECT 1 FROM {$idTable} c WHERE c.product_id = l.item_id2)"));
		}
	}
