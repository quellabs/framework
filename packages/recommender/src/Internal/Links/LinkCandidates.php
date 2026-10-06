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
			function (string $ratingsTable) use ($seen, $source, $query, $limit, $productIds): array {
				$join = "JOIN {$ratingsTable} r ON r.product_id = l.item_id1";

				return $this->temporary->withIdTable('vogoo_seen_', array_keys($seen),
					function (string $seenTable) use ($join, $seen, $source, $query, $limit, $productIds): array {
						$restriction = "AND NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = l.item_id2)";

						if ($productIds === null) {
							return CandidateRows::fromSql($query($join, $restriction, $limit), $source, $seen);
						}

						return $this->temporary->withIdTable('vogoo_scored_', $productIds,
							function (string $idTable) use ($join, $seen, $source, $query, $limit, $restriction): array {
								$only = "{$restriction} AND EXISTS (SELECT 1 FROM {$idTable} c WHERE c.product_id = l.item_id2)";

								return CandidateRows::fromSql($query($join, $only, $limit), $source, $seen);
							});
					});
			});
	}
}
