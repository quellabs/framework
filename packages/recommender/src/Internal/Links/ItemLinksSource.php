<?php

namespace Quellabs\Recommender\Internal\Links;

use Cake\Database\Connection;
use Quellabs\Recommender\CandidateSource;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Identifier;
use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
use Quellabs\Recommender\Internal\Query\Results;
use Quellabs\Recommender\Internal\Query\SubjectRatings;
use Quellabs\Recommender\RecommendationResult;
use Quellabs\Recommender\RecommendationSource;
use Quellabs\Recommender\Reconciliation\SourceSettings;
use Quellabs\Recommender\Subject;
use Quellabs\Recommender\SubjectKind;

/** Item-links source: scores products by the liked pairs they share with a subject's liked items. */
readonly class ItemLinksSource implements CandidateSource {

	/** @var Connection Database connection */
	private Connection $connection;

	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;

	/** @var EligibilityFilter Applies eligibility providers with bounded backfill */
	private EligibilityFilter $eligibilityFilter;

	/** @var LinkCandidates Member and visitor candidates from the subject's ratings */
	private LinkCandidates $linkCandidates;

	/** @var SubjectRatings Seen ratings of member and visitor subjects */
	private SubjectRatings $ratings;

	/** @var TemporaryTable Temporary table of liked product IDs */
	private TemporaryTable $temporary;

	/**
	 * Build the item-links source.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
		$this->eligibilityFilter = new EligibilityFilter($config);
		$this->linkCandidates = new LinkCandidates($connection);
		$this->ratings = new SubjectRatings($connection);
		$this->temporary = new TemporaryTable($connection);
	}

	/**
	 * Report whether this source answers for a subject kind.
	 * @param SubjectKind $kind Subject kind
	 * @return bool True for every subject kind
	 */
	public function supports(SubjectKind $kind): bool {
		return true;
	}

	/**
	 * Return the products linked to a subject, scored by liked count. Settings are not used.
	 * Product subjects use the liked pairs of the product. Member and visitor subjects use the links of their genuine ratings.
	 * @param Subject $subject Subject the candidates are for
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $limit Maximum results, or zero for all
	 * @param SourceSettings $settings Source settings, unused
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Linked products, scored by liked count
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $limit,
		SourceSettings $settings, ?int $category = null): array {
		$resolved = $this->config->resolveCategory($category);

		return $this->eligibilityFilter->withEligibility($eligibility, $limit,
			fn(int $depth): array => match ($subject->kind) {
				SubjectKind::Product => $this->linkedRows($subject->id ?? throw new \LogicException('A product subject always has an ID.'), $depth, $resolved),
				SubjectKind::Member, SubjectKind::Visitor => $this->ratedCandidates($subject, $resolved, $depth, null),
			},
			fn(RecommendationResult $row): int => $row->productId);
	}

	/**
	 * Score the given products for a subject by liked count, without depth or eligibility.
	 * @param Subject $subject Subject the scores are for
	 * @param array<int, int> $productIds Product IDs to score
	 * @param SourceSettings $settings Source settings, unused
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Scored products, in no particular order
	 */
	public function scores(Subject $subject, array $productIds, SourceSettings $settings, ?int $category = null): array {
		$resolved = $this->config->resolveCategory($category);

		if ($subject->kind === SubjectKind::Product) {
			return array_values(array_filter($this->candidates($subject, null, 0, $settings, $category),
				fn(RecommendationResult $row): bool => in_array($row->productId, $productIds, true)));
		}

		return $this->ratedCandidates($subject, $resolved, 0, $productIds);
	}

	/**
	 * Return the products linked to a member's or visitor's genuine ratings that they have not seen.
	 * @param Subject $subject Member or visitor subject
	 * @param int $category Resolved category
	 * @param int $depth Number of top candidates, or zero for all
	 * @param array<int, int>|null $productIds Restricts the products to these, or null for all
	 * @return array<int, RecommendationResult>
	 */
	private function ratedCandidates(Subject $subject, int $category, int $depth, ?array $productIds): array {
		return $this->linkCandidates->candidates($subject, $category, $depth, RecommendationSource::ItemLinks,
			fn(string $join, string $restriction, ?int $limit): array => $this->candidateRows($join, $restriction, [], $category, $limit),
			$productIds);
	}

	/**
	 * Return the subject's liked products that link to a product, scored by the liked count of that link.
	 * @param Subject $subject Member or visitor subject
	 * @param int $product Product the reasons explain
	 * @param int $limit Maximum results, or zero for all
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Liked products, scored by the liked count of their link to the product
	 * @throws \InvalidArgumentException When the subject is a product, or the product ID is invalid
	 * @throws \UnexpectedValueException When a reason row from the database is malformed
	 */
	public function reasons(Subject $subject, int $product, int $limit = 10, ?int $category = null): array {
		if ($subject->kind === SubjectKind::Product) {
			throw new \InvalidArgumentException('Reasons need a member or visitor subject.');
		}

		Identifier::assertId($product, 'Product ID');
		$resolved = $this->config->resolveCategory($category);
		$threshold = $this->config->thresholdRating();
		$liked = [];

		foreach ($this->ratings->seen($subject, $resolved) as $productId => $rating) {
			if ($rating >= $threshold) {
				$liked[] = $productId;
			}
		}

		if ($liked === []) {
			return [];
		}

		return $this->temporary->withIdTable('vogoo_liked_', $liked, function (string $likedTable) use ($product, $resolved, $limit): array {
			$sql = "
				SELECT
					l.`item_id2` AS reason_id,
					l.`liked_count`
				FROM `vogoo_links` l
				INNER JOIN {$likedTable} r ON r.product_id = l.`item_id2`
				WHERE l.`item_id1` = :product_id AND
					l.`category` = :category AND
					l.`liked_count` > 0
				ORDER BY l.`liked_count` DESC, l.`item_id2` ASC
			";
			$sql .= Results::limitSql($limit);

			return $this->reasonResults($this->connection->execute($sql,
				['product_id' => $product, 'category' => $resolved])->fetchAll('assoc'));
		});
	}

	/**
	 * Map reason rows to recommendation results, scored by the link's liked count.
	 * @param array<int, array<string, mixed>> $rows Rows with reason_id and liked_count
	 * @return array<int, RecommendationResult>
	 * @throws \UnexpectedValueException When a row has a non-numeric reason ID or liked count
	 */
	private function reasonResults(array $rows): array {
		$results = [];

		foreach ($rows as $row) {
			if (!is_numeric($row['reason_id']) || !is_numeric($row['liked_count'])) {
				throw new \UnexpectedValueException('Reason rows returned by the database must have numeric reason_id and liked_count.');
			}

			$results[] = new RecommendationResult((int)$row['reason_id'], (float)$row['liked_count'], RecommendationSource::ItemLinks, []);
		}

		return $results;
	}

	/**
	 * Read the products linked to one product, ordered by liked count.
	 * @param int $productId The product ID
	 * @param int $limit Maximum results, or zero for all
	 * @param int $category Already-resolved category
	 * @return array<int, RecommendationResult>
	 */
	private function linkedRows(int $productId, int $limit, int $category): array {
		$sql = '
			SELECT
				`item_id2`,
				`liked_count`
			FROM `vogoo_links`
			WHERE `item_id1` = :product_id AND
				`category` = :category AND
				`liked_count` > 0
			ORDER BY `liked_count` DESC, `item_id2` ASC
		';
		$sql .= Results::limitSql($limit);

		$rows = $this->connection->execute($sql, ['product_id' => $productId, 'category' => $category])->fetchAll('assoc');

		$results = [];

		foreach ($rows as $row) {
			$results[] = new RecommendationResult((int)$row['item_id2'], (float)$row['liked_count'], RecommendationSource::ItemLinks, []);
		}

		return Results::limit($results, $limit);
	}

	/**
	 * Build the item-links candidate query for ratings joined into the link table.
	 * @param string $ratingJoin Join from vogoo_links to the rating source, aliased r on l.item_id1
	 * @param string $restriction Extra condition starting with AND and referring to l.item_id2, or empty
	 * @param array<string, int|float|string> $params Parameters referenced by the join and restriction
	 * @param int $category Already-resolved category
	 * @param int|null $limit Maximum rows, or null for all
	 * @return array<int, array<string, mixed>> Rows with id, score and contributors, a JSON array of product IDs
	 */
	private function candidateRows(string $ratingJoin, string $restriction, array $params, int $category, ?int $limit): array {
		$sql = "
			SELECT
				l.item_id2 AS id,
				SUM(l.liked_count * (r.rating - :threshold)) AS score,
				JSON_ARRAYAGG(r.product_id) AS contributors
			FROM vogoo_links l {$ratingJoin}
			WHERE l.category = :category AND
				l.liked_count > 0 {$restriction}
			GROUP BY l.item_id2
			HAVING score > 0
			ORDER BY score DESC, id ASC";
		$sql .= $limit === null ? '' : ' LIMIT ' . $limit;

		return $this->connection->execute($sql, $params + [
			'threshold' => $this->config->thresholdRating(),
			'category' => $category,
		])->fetchAll('assoc');
	}
}
