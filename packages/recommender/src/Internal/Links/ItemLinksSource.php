<?php

namespace Quellabs\Recommender\Internal\Links;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;

/** Item-links candidate query: scores products by the liked pairs they share with a subject's liked items. */
readonly class ItemLinksSource {

	/** @var Connection Database connection */
	private Connection $connection;

	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;

	/**
	 * Build the item-links source.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
	}

	/**
	 * Score candidates from the liked items they are linked to, best first.
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
