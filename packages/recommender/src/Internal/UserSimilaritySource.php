<?php

namespace Quellabs\Recommender\Internal;

use Cake\Database\Connection;
use Quellabs\Recommender\CandidateSource;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
use Quellabs\Recommender\Internal\Query\Results;
use Quellabs\Recommender\RecommendationResult;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;

/** User-similarity source: products that similar members rated well and the member has not seen. Members only. */
readonly class UserSimilaritySource implements CandidateSource {

	/** @var Connection Ratings database connection */
	private Connection $connection;

	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;

	/** @var UserSimilarity Neighbour lookup */
	private UserSimilarity $similarity;

	/** @var TemporaryTable Temporary tables for neighbour and candidate sets */
	private TemporaryTable $temporary;

	/** @var EligibilityFilter Applies eligibility providers with bounded backfill */
	private EligibilityFilter $eligibilityFilter;

	/**
	 * Build the user-similarity source.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 * @param UserSimilarity $similarity Neighbour lookup
	 */
	public function __construct(Connection $connection, RecommendationConfig $config, UserSimilarity $similarity) {
		$this->connection = $connection;
		$this->config = $config;
		$this->similarity = $similarity;
		$this->temporary = new TemporaryTable($connection);
		$this->eligibilityFilter = new EligibilityFilter($config);
	}

	/**
	 * Report whether this source answers for a subject kind.
	 * @param SubjectKind $kind Subject kind
	 * @return bool True for member subjects only
	 */
	public function supports(SubjectKind $kind): bool {
		return $kind === SubjectKind::Member;
	}

	/**
	 * Return the products similar members rated well that the member has not seen, best score first.
	 * @param Subject $subject Member subject
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $limit Maximum results, or zero for all
	 * @param SourceSettings $settings Neighbour similarity and neighbour count limits
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Products scored by similarity-weighted rating
	 * @throws \InvalidArgumentException When the subject is not a member
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $limit,
		SourceSettings $settings, ?int $category = null): array {
		$memberId = $this->memberOf($subject);
		$resolved = $this->config->resolveCategory($category);

		return $this->eligibilityFilter->withEligibility($eligibility, $limit,
			fn(int $depth): array => $this->results($this->scoredRows($memberId, $settings, $resolved,
				$depth === 0 ? null : $depth, null)),
			fn(RecommendationResult $row): int => $row->productId);
	}

	/**
	 * Score the given products by similarity-weighted rating, without depth or eligibility.
	 * @param Subject $subject Member subject
	 * @param array<int, int> $productIds Product IDs to score
	 * @param SourceSettings $settings Neighbour similarity and neighbour count limits
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Scored products, in no particular order
	 * @throws \InvalidArgumentException When the subject is not a member
	 */
	public function scores(Subject $subject, array $productIds, SourceSettings $settings, ?int $category = null): array {
		$memberId = $this->memberOf($subject);

		if ($productIds === []) {
			return [];
		}

		return $this->results($this->scoredRows($memberId, $settings, $this->config->resolveCategory($category), null, $productIds));
	}

	/**
	 * Return the member ID of a member subject.
	 * @param Subject $subject Subject to check
	 * @return int Member ID
	 * @throws \InvalidArgumentException When the subject is not a member
	 */
	private function memberOf(Subject $subject): int {
		if (!$this->supports($subject->kind)) {
			throw new \InvalidArgumentException("User similarity does not support {$subject->kind->value} subjects.");
		}

		return $subject->id ?? throw new \LogicException('A member subject always has an ID.');
	}

	/**
	 * Convert neighbour recommendation rows into results.
	 * @param array<int, array{itemId: int, score: float}> $rows Rows with itemId and score
	 * @return array<int, RecommendationResult>
	 */
	private function results(array $rows): array {
		return array_map(fn(array $row): RecommendationResult => new RecommendationResult($row['itemId'], $row['score'],
			RecommendationSource::UserSimilarity, []), $rows);
	}

	/**
	 * Score unseen products through the member's neighbours, optionally restricted to a product list.
	 * @param int $memberId Member
	 * @param SourceSettings $settings Neighbour similarity and neighbour count limits
	 * @param int $category Resolved category
	 * @param int|null $limit Maximum rows, or null for all
	 * @param array<int, int>|null $productIds Restricts the products to these, or null for all
	 * @return array<int, array{itemId: int, score: float}> Rows with itemId and score
	 */
	private function scoredRows(int $memberId, SourceSettings $settings, int $category, ?int $limit, ?array $productIds): array {
		$neighbours = $this->similarity->memberNeighbours($memberId, $settings->minNeighbourSimilarity, $settings->maxNeighbours, $category);

		if ($neighbours === []) {
			return [];
		}

		return $this->temporary->withNeighbourTable('vogoo_neighbours_', $neighbours,
			function (string $neighbourTable) use ($memberId, $productIds, $category, $limit): array {
				if ($productIds === null) {
					return $this->queryNeighbourRecommendations($memberId, $neighbourTable, null, $category, $limit);
				}

				return $this->temporary->withIdTable('vogoo_neighbour_candidates_', $productIds,
					function (string $candidateTable) use ($memberId, $neighbourTable, $category, $limit): array {
						return $this->queryNeighbourRecommendations($memberId, $neighbourTable, $candidateTable, $category, $limit);
					});
			});
	}

	/**
	 * Aggregate the neighbours' ratings into unseen product scores.
	 * @param int $memberId Member receiving recommendations
	 * @param string $neighbourTable Temporary table holding the neighbours
	 * @param string|null $candidateTable Temporary table restricting candidates, or null for all products
	 * @param int $category Already-resolved category
	 * @param int|null $limit Maximum rows, or null for all
	 * @return array<int, array{itemId: int, score: float}>
	 */
	private function queryNeighbourRecommendations(int $memberId, string $neighbourTable, ?string $candidateTable,
		int $category, ?int $limit): array {
		$candidateJoin = $candidateTable === null
			? ''
			: "JOIN {$candidateTable} candidates ON candidates.product_id = r.product_id";

		$rows = $this->connection->execute("
			SELECT
				r.product_id AS item_id,
				SUM(r.rating * n.similarity) / SUM(n.similarity) AS score
			FROM {$neighbourTable} n
			JOIN vogoo_ratings r ON r.member_id = n.member_id
			{$candidateJoin}
			WHERE r.category = :category AND
			      r.rating >= :threshold AND
			      NOT EXISTS (SELECT 1 FROM vogoo_ratings seen
			                  WHERE seen.member_id = :member AND
			                        seen.category = :seen_category AND
			                        seen.product_id = r.product_id)
			GROUP BY r.product_id
			ORDER BY score DESC, r.product_id ASC
			" . Results::limitSql($limit ?? 0) . "
		", [
			'category' => $category,
			'threshold' => $this->config->thresholdRating(),
			'member' => $memberId,
			'seen_category' => $category,
		])->fetchAll('assoc');

		return array_map(function ($row): array {
			return ['itemId' => (int)$row['item_id'], 'score' => (float)$row['score']];
		}, $rows);
	}
}
