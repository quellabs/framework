<?php

namespace Quellabs\Recommender\Sources;

use Cake\Database\Connection;
use Quellabs\Recommender\CandidateSource;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
use Quellabs\Recommender\Internal\Query\CandidateRows;
use Quellabs\Recommender\Internal\Query\SubjectRatings;
use Quellabs\Recommender\RecommendationResult;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;

/** Top-rated source: the products with the highest average genuine rating that the subject has not seen. */
readonly class TopRatedSource implements CandidateSource {

	/** @var Connection Ratings database connection */
	private Connection $connection;

	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;

	/** @var TemporaryTable Temporary tables for the seen and candidate sets */
	private TemporaryTable $temporary;

	/** @var EligibilityFilter Applies eligibility providers with bounded backfill */
	private EligibilityFilter $eligibilityFilter;

	/** @var SubjectRatings Seen ratings of the subject */
	private SubjectRatings $ratings;

	/**
	 * Build the top-rated source.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 * @param SubjectRatings|null $ratings Ratings loader shared within one request, or null to create one
	 */
	public function __construct(Connection $connection, RecommendationConfig $config, ?SubjectRatings $ratings = null) {
		$this->connection = $connection;
		$this->config = $config;
		$this->temporary = new TemporaryTable($connection);
		$this->eligibilityFilter = new EligibilityFilter($config);
		$this->ratings = $ratings ?? new SubjectRatings($connection);
	}

	/**
	 * Report whether this source answers for a subject kind. Product subjects have no top-rated list.
	 * @param SubjectKind $kind Subject kind
	 * @return bool True for member and visitor subjects
	 */
	public function supports(SubjectKind $kind): bool {
		return $kind !== SubjectKind::Product;
	}

	/**
	 * Return the top-rated products the subject has not seen, highest average rating first.
	 * @param Subject $subject Member or visitor subject
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $limit Maximum results, or zero for all
	 * @param SourceSettings $settings Source settings; the minimum rating count applies
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Products scored by their average rating
	 * @throws \InvalidArgumentException When the subject is a product
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $limit,
		SourceSettings $settings, ?int $category = null): array {
		$this->assertSupported($subject);
		$resolved = $this->config->resolveCategory($category);
		$seen = $this->ratings->seen($subject, $resolved);

		return $this->eligibilityFilter->withEligibility($eligibility, $limit,
			function (int $depth) use ($resolved, $settings, $seen): array {
				$rows = $this->temporary->withIdTable('vogoo_seen_', array_keys($seen),
					function (string $seenTable) use ($resolved, $settings, $depth): array {
						return $this->rows($resolved, $settings, "AND NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = r.product_id)", $depth === 0 ? null : $depth);
					});

				return CandidateRows::fromSql($rows, RecommendationSource::TopRated, $seen);
			},
			fn(RecommendationResult $row): int => $row->productId);
	}

	/**
	 * Score the given products by their average rating, without depth or eligibility. Products the subject has seen are omitted.
	 * @param Subject $subject Member or visitor subject
	 * @param array<int, int> $productIds Product IDs to score
	 * @param SourceSettings $settings Source settings; the minimum rating count applies
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Scored products, in no particular order
	 * @throws \InvalidArgumentException When the subject is a product
	 */
	public function scores(Subject $subject, array $productIds, SourceSettings $settings, ?int $category = null): array {
		$this->assertSupported($subject);
		$resolved = $this->config->resolveCategory($category);

		if ($productIds === []) {
			return [];
		}

		$rows = $this->temporary->withIdTable('vogoo_audit_candidates_', $productIds,
			function (string $candidateTable) use ($resolved, $settings): array {
				return $this->rows($resolved, $settings,
					"AND EXISTS (SELECT 1 FROM {$candidateTable} candidates WHERE candidates.product_id = r.product_id)", null);
			});

		return CandidateRows::fromSql($rows, RecommendationSource::TopRated, $this->ratings->seen($subject, $resolved));
	}

	/**
	 * Reject subjects this source does not support.
	 * @param Subject $subject Subject to check
	 * @return void
	 * @throws \InvalidArgumentException When the subject kind is not supported
	 */
	private function assertSupported(Subject $subject): void {
		if (!$this->supports($subject->kind)) {
			throw new \InvalidArgumentException("Top rated does not support {$subject->kind->value} subjects.");
		}
	}

	/**
	 * Run the average-rating aggregate under a restriction, keeping the top rows when a limit is given.
	 * @param int $category Resolved category
	 * @param SourceSettings $settings Source settings
	 * @param string $restriction Extra predicate limiting the products, referring to r.product_id
	 * @param int|null $limit Keeps the top rows by score up to this limit, or all rows when null
	 * @return array<mixed> Rows with id, score and support_count
	 */
	private function rows(int $category, SourceSettings $settings, string $restriction, ?int $limit): array {
		$sql = "
			SELECT
				r.product_id AS id,
				AVG(r.rating) AS score,
				COUNT(*) AS support_count
			FROM vogoo_ratings r
			WHERE r.category = :category AND
				r.rating >= 0 {$restriction}
			GROUP BY r.product_id HAVING support_count >= :minimum";

		if ($limit !== null) {
			$sql .= " ORDER BY score DESC, id ASC LIMIT {$limit}";
		}

		return $this->connection->execute($sql, ['category' => $category, 'minimum' => $settings->topRatedMinRatings])->fetchAll('assoc');
	}
}
