<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;

/**
 * Catalogue-level statistics about the ratings store.
 *
 * Methods throw on database failure.
 */
readonly class Statistics {
	
	/** @var Connection Database connection */
	private Connection $connection;
	
	/** @var RecommendationConfig Recommendation settings used to resolve categories */
	private RecommendationConfig $config;
	
	/**
	 * Build the statistics service.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
	}
	
	/**
	 * Return the number of distinct members who have given at least one rating.
	 * @param int|null $category Defaults to configured default
	 * @return int
	 */
	public function numMembers(?int $category = null): int {
		return $this->fetchCount('
			SELECT
				COUNT(DISTINCT `member_id`) AS cnter
			FROM `vogoo_ratings`
			WHERE `category` = :category
		', $this->config->resolveCategory($category));
	}
	
	/**
	 * Return all distinct member IDs that have given at least one rating.
	 * @param int|null $category Defaults to configured default
	 * @return array<int, int>
	 */
	public function members(?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$rows = $this->connection->execute('
			SELECT DISTINCT
				`member_id`
			FROM `vogoo_ratings`
			WHERE `category` = :category
		', [
			'category' => $resolvedCategory,
		])->fetchAll('assoc');
		
		return array_map('intval', array_column($rows, 'member_id'));
	}
	
	/**
	 * Return the number of distinct products that have received at least one rating.
	 * @param int|null $category Defaults to configured default
	 * @return int
	 */
	public function numProducts(?int $category = null): int {
		return $this->fetchCount('
			SELECT
				COUNT(DISTINCT `product_id`) AS cnter
			FROM `vogoo_ratings`
			WHERE `category` = :category AND
			      `rating` >= 0.0
		', $this->config->resolveCategory($category));
	}
	
	/**
	 * Return the total number of genuine ratings stored.
	 * @param int|null $category Defaults to configured default
	 * @return int
	 */
	public function numRatings(?int $category = null): int {
		return $this->fetchCount('
			SELECT
				COUNT(*) AS cnter
			FROM `vogoo_ratings`
			WHERE `category` = :category AND
			      `rating` >= 0.0
		', $this->config->resolveCategory($category));
	}
	
	/**
	 * Return the most-rated products, ordered by rating count descending.
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, array{product_id: int, num_ratings: int}>
	 */
	public function mostRatedProducts(int $limit = 10, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$limit = max(0, $limit);
		
		$sql = '
			SELECT
				`product_id`,
				COUNT(*) AS num_ratings
			FROM `vogoo_ratings`
			WHERE `category` = :category AND
			      `rating` >= 0.0
			GROUP BY `product_id`
		ORDER BY num_ratings DESC, `product_id` ASC
		';
		
		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}
		
		$rows = $this->connection->execute($sql, ['category' => $resolvedCategory])->fetchAll('assoc');
		
		return array_map(
			fn($row) => [
				'product_id'  => (int)$row['product_id'],
				'num_ratings' => (int)$row['num_ratings'],
			],
			$rows
		);
	}
	
	/**
	 * Return the highest-rated products, ordered by average rating descending.
	 * Products with fewer than $minRatings ratings are excluded.
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int $minRatings Minimum number of ratings to qualify
	 * @param int|null $category Defaults to configured default
	 * @return array<int, array{product_id: int, avg_rating: float}>
	 */
	public function topRatedProducts(int $limit = 10, int $minRatings = 1, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$limit = max(0, $limit);
		$minRatings = max(1, $minRatings);
		
		$sql = '
			SELECT
				`product_id`,
				AVG(`rating`) AS avg_rating
			FROM `vogoo_ratings`
			WHERE `category` = :category AND
			      `rating` >= 0.0
			GROUP BY `product_id`
	        HAVING COUNT(*) >= :min_ratings
			ORDER BY avg_rating DESC, `product_id` ASC
		';
		
		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}
		
		$rows = $this->connection->execute($sql, [
			'category'    => $resolvedCategory,
			'min_ratings' => $minRatings,
		])->fetchAll('assoc');
		
		return array_map(
			fn($row) => [
				'product_id' => (int)$row['product_id'],
				'avg_rating' => (float)$row['avg_rating'],
			],
			$rows
		);
	}
	
	/**
	 * Return the number of link rows in the category, useful for monitoring link table growth.
	 * @param int|null $category Defaults to configured default
	 * @return int Number of link rows in the category
	 */
	public function numLinks(?int $category = null): int {
		return $this->fetchCount('
			SELECT
				COUNT(*) AS cnter
			FROM `vogoo_links`
			WHERE `category` = :category
		', $this->config->resolveCategory($category));
	}
	
	/**
	 * Run a counting query that selects a single "cnter" column for one category.
	 * @param string $sql Query with a :category placeholder and a cnter alias
	 * @param int $category Already-resolved category
	 * @return int The counted value
	 */
	private function fetchCount(string $sql, int $category): int {
		$row = $this->connection->execute($sql, ['category' => $category])->fetchAssoc();
		return (int)$row['cnter'];
	}
}
