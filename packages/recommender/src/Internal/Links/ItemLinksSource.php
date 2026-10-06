<?php

namespace Quellabs\Recommender\Internal\Links;

use Cake\Database\Connection;
use Quellabs\Recommender\CandidateSource;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\EligibilityProvider;
use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Query\Results;
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

	/**
	 * Build the item-links source.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
		$this->eligibilityFilter = new EligibilityFilter($config);
	}

	/**
	 * Report whether this source answers for a subject kind. Only product subjects are supported so far.
	 * @param SubjectKind $kind Subject kind
	 * @return bool True for product subjects
	 */
	public function supports(SubjectKind $kind): bool {
		return $kind === SubjectKind::Product;
	}

	/**
	 * Return the products that co-occur with a product, scored by liked count. Settings are not used for product subjects.
	 * @param Subject $subject Product subject
	 * @param EligibilityProvider|null $eligibility Restricts candidates, or null for all
	 * @param int $depth Number of top candidates to consider before eligibility, or zero for all
	 * @param SourceSettings $settings Source settings, unused for product subjects
	 * @param int|null $category Category override
	 * @return array<int, RecommendationResult> Co-occurring products, scored by liked count
	 * @throws \InvalidArgumentException When the subject is not a product
	 */
	public function candidates(Subject $subject, ?EligibilityProvider $eligibility, int $depth,
		SourceSettings $settings, ?int $category = null): array {
		if (!$this->supports($subject->kind)) {
			throw new \InvalidArgumentException("Item links does not support {$subject->kind->value} subjects.");
		}

		$productId = $subject->id ?? throw new \LogicException('A product subject always has an ID.');
		$rows = $this->linkedRows($productId, $depth, $this->config->resolveCategory($category));

		return $eligibility === null ? $rows : $this->eligibilityFilter->keepEligible($eligibility, $rows,
			fn(RecommendationResult $row): int => $row->productId);
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
	public function candidateRows(string $ratingJoin, string $restriction, array $params, int $category, ?int $limit): array {
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
