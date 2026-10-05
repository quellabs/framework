<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;

/**
 * Item-based collaborative filtering and Slope One recommendations.
 *
 * Two recommendation strategies are available per method, controlled by which
 * vogoo_links columns are populated:
 *
 *  - Links (liked_count): item co-occurrence, "people who liked A also liked B"
 *  - Slope One (slope_count + diff_slope): weighted predicted rating for unseen items
 *
 * Both strategies require vogoo_links to be pre-populated, either via a
 * batch rebuild or via incremental updates through LinkUpdater.
 *
 * Methods throw on database failure.
 *
 * @phpstan-import-type RatingList from VisitorContext
 */
readonly class ItemRecommender {
	
	/** @var Connection Database connection */
	private Connection $connection;
	
	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;
	
	/**
	 * Build the recommender.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
	}
	
	// ----- Links (co-occurrence) -----
	
	/**
	 * Return items that co-occur with the given product, ordered by co-occurrence count descending.
	 * @param int $productId The product ID
	 * @param array<int> $filter When non-empty, only return product IDs in this set
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, int> List of product IDs
	 */
	public function getLinkedItems(int $productId, array $filter = [], int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$sql = '
			SELECT
				`item_id2`,
				`liked_count`
			FROM `vogoo_links`
			WHERE `item_id1` = :product_id AND
			      `category` = :category AND `liked_count` > 0
        ';
		$params = ['product_id' => $productId, 'category' => $resolvedCategory];
		$sql .= $this->allowedSql($filter, '`item_id2`', $params);
		$sql .= ' ORDER BY `liked_count` DESC, `item_id2` ASC';
		
		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}
		
		try {
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
		} finally {
			$this->clearAllowedTable($filter);
		}
		
		$result = $this->filterAndExtract($rows, 'item_id2', $filter);
		return $limit > 0 ? array_slice($result, 0, $limit) : $result;
	}
	
	/**
	 * Return recommended items for a member using item-based CF, ordered by weighted co-occurrence score descending.
	 * Only returns items the member has not already rated.
	 * @param int $memberId The member ID
	 * @param array<int> $filter When non-empty, only return product IDs in this set
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, int> List of product IDs
	 */
	public function memberGetRecommendedItems(int $memberId, array $filter = [], int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$limit = max(0, $limit);
		$threshold = $this->config->getThresholdRating();
		
		$sql = '
			SELECT
				l.`item_id2`,
				SUM(l.`liked_count` * (r.`rating` - :threshold)) AS cnter
			FROM `vogoo_links` l
			INNER JOIN `vogoo_ratings` r ON r.`member_id` = :member_id AND
			                               l.`item_id1` = r.`product_id` AND
			                               r.`rating` >= 0.0 AND
			                               l.`category` = r.`category` AND
			                               r.`category` = :category
	        WHERE NOT EXISTS (
				SELECT 1 FROM `vogoo_ratings` vr
				WHERE vr.`member_id` = :member_id2 AND
				      vr.`category` = :category2 AND
				      vr.`product_id` = l.`item_id2`
	        )
		';
		$params = [
			'threshold'  => $threshold,
			'member_id'  => $memberId,
			'category'   => $resolvedCategory,
			'member_id2' => $memberId,
			'category2'  => $resolvedCategory,
		];
		$sql .= $this->allowedSql($filter, 'l.`item_id2`', $params);
		$sql .= '
			GROUP BY l.`item_id2`
	        HAVING cnter > 0
			ORDER BY cnter DESC, l.`item_id2` ASC
		';
		
		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}
		
		try {
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
		} finally {
			$this->clearAllowedTable($filter);
		}
		
		$result = $this->filterAndExtract($rows, 'item_id2', $filter);
		return $limit > 0 ? array_slice($result, 0, $limit) : $result;
	}
	
	/**
	 * Return the products this member has rated that are linked to the given product, the "why we recommend this" list.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, int> List of product IDs
	 */
	public function memberGetReasons(int $memberId, int $productId, int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$limit = max(0, $limit);
		$threshold = $this->config->getThresholdRating();
		
		$sql = '
			SELECT r.`product_id`
			FROM `vogoo_ratings` r
			INNER JOIN `vogoo_links` l ON l.`item_id1` = :product_id AND
			                             r.`product_id` = l.`item_id2` AND
			                             l.`liked_count` > 0 AND
			                             l.`category` = r.`category`
			WHERE r.`member_id` = :member_id AND
			      r.`category` = :category AND
			      r.`rating` >= :threshold
		';
		
		$params = [
			'product_id' => $productId,
			'member_id'  => $memberId,
			'category'   => $resolvedCategory,
			'threshold'  => $threshold,
		];
		
		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}
		
		$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
		return array_map('intval', array_column($rows, 'product_id'));
	}
	
	/**
	 * Return recommended items for an anonymous visitor using item-based CF, with ratings read from the visitor context.
	 * @param VisitorContext $visitor The visitor context holding the current session's ratings
	 * @param array<int> $filter When non-empty, only return product IDs in this set
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, int> List of product IDs
	 */
	public function visitorGetRecommendedItems(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$ratings = $visitor->getRatings($resolvedCategory);
		
		if (empty($ratings)) {
			return [];
		}
		
		$scores = $this->scoreVisitorCandidates($ratings, $filter, $resolvedCategory);
		$scores = array_filter($scores, fn($score) => $score > 0);
		uksort($scores, fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
		
		$result = array_keys($scores);
		return $limit > 0 ? array_slice($result, 0, $limit) : $result;
	}
	
	/**
	 * Return the visitor's rated products that are linked to the given product, the "why we recommend this" list for visitors.
	 * @param VisitorContext $visitor The visitor context holding the current session's ratings
	 * @param int $productId The product ID
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, int> List of product IDs
	 */
	public function visitorGetReasons(VisitorContext $visitor, int $productId, int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$threshold = $this->config->getThresholdRating();
		$ratings = $visitor->getRatings($resolvedCategory);
		
		$likedIds = array_column(
			array_filter($ratings, fn($entry) => $entry['rating'] >= $threshold),
			'product_id'
		);
		
		if (empty($likedIds)) {
			return [];
		}
		
		$placeholders = implode(',', array_fill(0, count($likedIds), '?'));
		
		$sql = "
			SELECT `item_id2`
			FROM `vogoo_links`
			WHERE `category` = ? AND
			      `item_id1` = ? AND
			      `item_id2` IN ({$placeholders}) AND
			      `liked_count` > 0
		";
		
		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}
		
		$rows = $this->connection->execute($sql, array_merge([$resolvedCategory, $productId], $likedIds))->fetchAll('assoc');
		return array_map('intval', array_column($rows, 'item_id2'));
	}
	
	// ----- Slope One -----
	
	/**
	 * Return items sorted by their average Slope One diff relative to the given product, best match first.
	 * @param int $productId The product ID
	 * @param int $minLinks Minimum co-occurrence count to include a pair
	 * @param array<int> $filter When non-empty, only return product IDs in this set
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, array{product_id: int, diff: float}>
	 */
	public function getSlopeItems(int $productId, int $minLinks = 1, array $filter = [], int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$minLinks = max(1, $minLinks);
		$limit = max(0, $limit);
		
		$sql = '
			SELECT
				`item_id2`,
				(`diff_slope` / `slope_count`) AS avg_diff
			FROM `vogoo_links`
			WHERE `item_id1` = :product_id AND
			      `category` = :category AND
			      `slope_count` >= :min_links
		';
		
		$params = [
			'product_id' => $productId,
			'category'   => $resolvedCategory,
			'min_links'  => $minLinks,
		];
		$sql .= $this->allowedSql($filter, '`item_id2`', $params);
		$sql .= ' ORDER BY avg_diff DESC, `item_id2` ASC';
		
		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}
		
		try {
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
		} finally {
			$this->clearAllowedTable($filter);
		}
		
		$result = [];
		
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['item_id2'], $row['avg_diff']) || !is_scalar($row['item_id2'])) {
				continue;
			}
			
			$id = (int)$row['item_id2'];
			
			if (!empty($filter) && !in_array($id, $filter, true)) {
				continue;
			}
			
			$result[] = ['product_id' => $id, 'diff' => (float)$row['avg_diff']];
		}
		
		return $limit > 0 ? array_slice($result, 0, $limit) : $result;
	}
	
	/**
	 * Predict a member's rating for a single product using Slope One.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param int|null $category Defaults to configured default
	 * @return float|null Predicted rating in [0.0, 1.0], or null when there is insufficient data
	 */
	public function memberPredict(int $memberId, int $productId, ?int $category = null): ?float {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$row = $this->connection->execute('
			SELECT
				SUM(l.`slope_count`) AS cnter,
				SUM(r.`rating` * l.`slope_count` - l.`diff_slope`) AS diff
			FROM `vogoo_links` l
			INNER JOIN `vogoo_ratings` r ON r.`member_id` = :member_id AND
			                               r.`product_id` = l.`item_id2` AND
			                               r.`category` = l.`category` AND
			                               r.`rating` >= 0.0
			WHERE l.`item_id1` = :product_id AND
			      l.`category` = :category AND l.`slope_count` > 0
		', [
			'member_id'  => $memberId,
			'product_id' => $productId,
			'category'   => $resolvedCategory,
		])->fetchAssoc();
		
		if ((int)$row['cnter'] === 0) {
			return null;
		}
		
		return $this->clampRating((float)$row['diff'] / (float)$row['cnter']);
	}
	
	/**
	 * Predict ratings for all unrated items for a member using Slope One, sorted by predicted rating descending.
	 * @param int $memberId The member ID
	 * @param array<int> $filter When non-empty, only return product IDs in this set
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, array{product_id: int, rating: float}>
	 */
	public function memberPredictAll(int $memberId, array $filter = [], int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$limit = max(0, $limit);
		
		$rows = $this->connection->execute('
			SELECT
				l.`item_id2`,
				SUM(l.`slope_count`) AS cnter,
				SUM(r.`rating` * l.`slope_count` + l.`diff_slope`) AS diff
			FROM `vogoo_links` l
			INNER JOIN `vogoo_ratings` r ON r.`member_id` = :member_id AND
			                               r.`rating` >= 0.0 AND
			                               l.`item_id1` = r.`product_id` AND
			                               l.`slope_count` > 0 AND
			                               r.`category` = :category AND
			                               l.`category` = r.`category`
			 WHERE NOT EXISTS (
					SELECT 1 FROM `vogoo_ratings` vr
					WHERE vr.`member_id` = :member_id2 AND
					      vr.`category` = :category2 AND
					      vr.`product_id` = l.`item_id2`
			 )
			GROUP BY l.`item_id2`
		', [
			'member_id'  => $memberId,
			'category'   => $resolvedCategory,
			'member_id2' => $memberId,
			'category2'  => $resolvedCategory,
		])->fetchAll('assoc');
		
		$result = [];
		
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['item_id2'], $row['cnter'], $row['diff']) || !is_scalar($row['item_id2'])) {
				continue;
			}
			
			$id = (int)$row['item_id2'];
			
			if (!empty($filter) && !in_array($id, $filter, true)) {
				continue;
			}
			
			$result[] = [
				'product_id' => $id,
				'rating'     => $this->clampRating((float)$row['diff'] / (float)$row['cnter']),
			];
		}
		
		usort($result, fn($a, $b) => ($b['rating'] <=> $a['rating']) ?: ($a['product_id'] <=> $b['product_id']));
		return $limit > 0 ? array_slice($result, 0, $limit) : $result;
	}
	
	/**
	 * Predict a rating for a single product for an anonymous visitor using Slope One.
	 * @param VisitorContext $visitor The visitor context holding the current session's ratings
	 * @param int $productId The product ID
	 * @param int|null $category Defaults to configured default
	 * @return float|null Predicted rating in [0.0, 1.0], or null when there is insufficient data
	 */
	public function visitorPredict(VisitorContext $visitor, int $productId, ?int $category = null): ?float {
		$resolvedCategory = $this->config->resolveCategory($category);
		$products = $this->collectGenuineRatings($visitor->getRatings($resolvedCategory));
		
		if (empty($products)) {
			return null;
		}
		
		$rows = $this->connection->execute('
			SELECT
				`item_id2`,
				`slope_count`,
				`diff_slope`
			FROM `vogoo_links`
			WHERE `item_id1` = :product_id AND
			      `category` = :category AND
			      `slope_count` > 0
		', [
			'product_id' => $productId,
			'category'   => $resolvedCategory,
		])->fetchAll('assoc');
		
		$numerator = 0.0;
		$denominator = 0;
		
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['item_id2'], $row['slope_count'], $row['diff_slope']) || !is_scalar($row['item_id2'])) {
				continue;
			}
			
			$id = (int)$row['item_id2'];
			
			if (isset($products[$id])) {
				$numerator += $products[$id] * (int)$row['slope_count'] - (float)$row['diff_slope'];
				$denominator += (int)$row['slope_count'];
			}
		}
		
		if ($denominator === 0) {
			return null;
		}
		
		return $this->clampRating($numerator / $denominator);
	}
	
	/**
	 * Predict ratings for all unrated items for an anonymous visitor using Slope One, sorted by predicted rating descending.
	 * @param VisitorContext $visitor The visitor context holding the current session's ratings
	 * @param array<int> $filter When non-empty, only return product IDs in this set
	 * @param int $limit Maximum number of results (0 = unlimited)
	 * @param int|null $category Defaults to configured default
	 * @return array<int, array{product_id: int, rating: float}>
	 */
	public function visitorPredictAll(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$products = $this->collectGenuineRatings($visitor->getRatings($resolvedCategory));
		
		if (empty($products)) {
			return [];
		}
		
		$accumulated = $this->accumulateSlopePredictions($products, $filter, $resolvedCategory);
		$ratedIds = $visitor->getRatedProductIds($resolvedCategory);
		$result = [];
		
		foreach ($accumulated as $id => [$cnter, $diff]) {
			if (in_array($id, $ratedIds, true)) {
				continue;
			}
			
			$result[] = [
				'product_id' => $id,
				'rating'     => $this->clampRating($diff / $cnter),
			];
		}
		
		usort($result, fn($a, $b) => ($b['rating'] <=> $a['rating']) ?: ($a['product_id'] <=> $b['product_id']));
		return $limit > 0 ? array_slice($result, 0, $limit) : $result;
	}
	
	/**
	 * Predict a single member rating with directed-pair support.
	 * @param int $memberId Member ID
	 * @param int $productId Candidate ID
	 * @param int $minSupport Minimum summed pair support
	 * @param int|null $category Category override
	 * @return PredictionResult|null
	 * @throws \InvalidArgumentException When the minimum support is not positive
	 */
	public function memberPredictDetailed(int $memberId, int $productId, int $minSupport = 1, ?int $category = null): ?PredictionResult {
		$this->validateSupport($minSupport);
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$row = $this->connection->execute('SELECT SUM(l.slope_count) AS support,
			SUM(r.rating * l.slope_count - l.diff_slope) AS numerator
			FROM vogoo_links l JOIN vogoo_ratings r ON r.product_id = l.item_id2
			AND r.category = l.category AND r.member_id = :member AND r.rating >= 0.0
			WHERE l.item_id1 = :product AND l.category = :category AND l.slope_count > 0',
			['member' => $memberId, 'product' => $productId, 'category' => $resolvedCategory])->fetchAssoc();
			
		$support = (int)$row['support'];
		
		if ($support < $minSupport) {
			return null;
		}
		
		return new PredictionResult($productId, $this->clampRating((float)$row['numerator'] / $support), $support);
	}
	
	/**
	 * Predict all unseen member ratings with directed-pair support.
	 * @param int $memberId Member ID
	 * @param array<int> $filter Allowed IDs, or empty for all
	 * @param int $limit Maximum results, or zero for all
	 * @param int $minSupport Minimum summed pair support
	 * @param int|null $category Category override
	 * @return array<int, PredictionResult>
	 * @throws \InvalidArgumentException When the minimum support is not positive
	 */
	public function memberPredictAllDetailed(int $memberId, array $filter = [], int $limit = 0,
		int $minSupport = 1, ?int $category = null): array {
		$this->validateSupport($minSupport);
		$resolvedCategory = $this->config->resolveCategory($category);
		$params = ['member' => $memberId, 'category' => $resolvedCategory, 'seen_member' => $memberId, 'seen_category' => $resolvedCategory];
		$sql = 'SELECT l.item_id2, SUM(l.slope_count) AS support,
			SUM(r.rating * l.slope_count + l.diff_slope) AS numerator,
			LEAST(1.0, GREATEST(0.0,
				SUM(r.rating * l.slope_count + l.diff_slope) / SUM(l.slope_count))) AS predicted
			FROM vogoo_links l JOIN vogoo_ratings r ON r.product_id = l.item_id1
			AND r.category = l.category AND r.member_id = :member AND r.rating >= 0.0
			WHERE l.category = :category AND l.slope_count > 0
			AND NOT EXISTS (SELECT 1 FROM vogoo_ratings seen WHERE seen.member_id = :seen_member
			AND seen.category = :seen_category AND seen.product_id = l.item_id2)';
		$sql .= $this->allowedSql($filter, 'l.item_id2', $params);
		$sql .= ' GROUP BY l.item_id2 HAVING support >= :min_support
			ORDER BY predicted DESC, support DESC, l.item_id2 ASC';
		$params['min_support'] = $minSupport;
		
		if ($limit > 0) {
			$sql .= ' LIMIT ' . $limit;
		}
		
		try {
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
		} finally {
			$this->clearAllowedTable($filter);
		}
		
		return $this->detailedPredictions($rows, $filter, $limit);
	}
	
	/**
	 * Predict one visitor rating with directed-pair support.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param int $productId Candidate ID
	 * @param int $minSupport Minimum summed pair support
	 * @param int|null $category Category override
	 * @return PredictionResult|null
	 * @throws \InvalidArgumentException When the minimum support is not positive
	 */
	public function visitorPredictDetailed(VisitorContext $visitor, int $productId, int $minSupport = 1, ?int $category = null): ?PredictionResult {
		$this->validateSupport($minSupport);
		$resolvedCategory = $this->config->resolveCategory($category);
		$ratings = $this->collectGenuineRatings($visitor->getRatings($resolvedCategory));
		
		if ($ratings === []) {
			return null;
		}
		
		$rows = $this->withVisitorRatingTable($ratings, fn($table) => $this->connection->execute(
			"SELECT SUM(l.slope_count) AS support,
			SUM(v.rating * l.slope_count - l.diff_slope) AS numerator
			FROM vogoo_links l JOIN {$table} v ON v.product_id = l.item_id2
			WHERE l.item_id1 = :product AND l.category = :category AND l.slope_count > 0",
			['product' => $productId, 'category' => $resolvedCategory])->fetchAssoc());
			
		if (!is_array($rows) || !isset($rows['support'], $rows['numerator'])) {
			return null;
		}
		
		$support = (int)$rows['support'];
		
		if ($support < $minSupport) {
			return null;
		}
		
		return new PredictionResult($productId, $this->clampRating((float)$rows['numerator'] / $support), $support);
	}
	
	/**
	 * Predict unseen visitor ratings with a batched temporary input table.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param array<int> $filter Allowed IDs, or empty for all
	 * @param int $limit Maximum results, or zero for all
	 * @param int $minSupport Minimum summed pair support
	 * @param int|null $category Category override
	 * @return array<int, PredictionResult>
	 * @throws \InvalidArgumentException When the minimum support is not positive
	 * @throws \UnexpectedValueException When the query does not return an array
	 */
	public function visitorPredictAllDetailed(VisitorContext $visitor, array $filter = [], int $limit = 0,
		int $minSupport = 1, ?int $category = null): array {
		$this->validateSupport($minSupport);
		$resolvedCategory = $this->config->resolveCategory($category);
		$ratings = $this->collectGenuineRatings($visitor->getRatings($resolvedCategory));
		
		if ($ratings === []) {
			return [];
		}
		
		$seen = array_fill_keys($visitor->getRatedProductIds($resolvedCategory), true);
		$rows = $this->withVisitorRatingTable($ratings, fn($table) => $this->withIdTable(
			'recommender_visitor_seen_',
			array_keys($seen),
			fn($seenTable) => $this->queryUnseenVisitorPredictions($table, $seenTable, $resolvedCategory, $minSupport, $filter, $limit)
		));
		
		if (!is_array($rows)) {
			throw new \UnexpectedValueException('Visitor prediction query did not return an array of rows.');
		}
		
		$rows = array_values(array_filter($rows, fn($row) => is_array($row)
			&& isset($row['item_id2']) && is_numeric($row['item_id2'])
			&& !isset($seen[(int)$row['item_id2']])));
		return $this->detailedPredictions($rows, $filter, $limit);
	}
	
	/**
	 * Run the visitor slope query against the rating and seen temporary tables.
	 * @param string $ratingTable Temporary table holding the visitor's genuine ratings
	 * @param string $seenTable Temporary table holding the visitor's rated IDs
	 * @param int $category Already-resolved category
	 * @param int $minSupport Minimum summed pair support
	 * @param array<int> $filter Allowed IDs, or empty for all
	 * @param int $limit Maximum results, or zero for all
	 * @return mixed Raw result rows
	 */
	private function queryUnseenVisitorPredictions(string $ratingTable, string $seenTable, int $category,
		int $minSupport, array $filter, int $limit): mixed {
		try {
			$params = ['category' => $category, 'min_support' => $minSupport];
			$sql = "SELECT l.item_id2, SUM(l.slope_count) AS support,
				SUM(v.rating * l.slope_count + l.diff_slope) AS numerator,
				LEAST(1.0, GREATEST(0.0,
					SUM(v.rating * l.slope_count + l.diff_slope) / SUM(l.slope_count))) AS predicted
				FROM vogoo_links l JOIN {$ratingTable} v ON v.product_id = l.item_id1
				WHERE l.category = :category AND l.slope_count > 0
				AND NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = l.item_id2)";
			$sql .= $this->allowedSql($filter, 'l.item_id2', $params);
			$sql .= ' GROUP BY l.item_id2 HAVING support >= :min_support
				ORDER BY predicted DESC, support DESC, l.item_id2 ASC';
				
			if ($limit > 0) {
				$sql .= ' LIMIT ' . $limit;
			}
			
			return $this->connection->execute($sql, $params)->fetchAll('assoc');
		} finally {
			$this->clearAllowedTable($filter);
		}
	}
	
	/**
	 * Validate that the minimum pair support is positive.
	 * @param int $minSupport Minimum support
	 * @return void
	 * @throws \InvalidArgumentException When the minimum support is not positive
	 */
	private function validateSupport(int $minSupport): void {
		if ($minSupport < 1) {
			throw new \InvalidArgumentException("Minimum support must be positive, got {$minSupport}.");
		}
	}
	
	/**
	 * Convert aggregated rows into predictions, keeping only allowed IDs and ordering by rating then support.
	 * @param array<mixed> $rows Aggregated rows
	 * @param array<int> $filter Allowed IDs
	 * @param int $limit Maximum results
	 * @return array<int, PredictionResult>
	 * @throws \UnexpectedValueException When a row is missing a required field
	 */
	private function detailedPredictions(array $rows, array $filter, int $limit): array {
		$allowed = $filter === [] ? null : array_fill_keys($filter, true);
		$results = [];
		
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['item_id2'], $row['support'], $row['numerator'])
				|| !is_numeric($row['item_id2']) || !is_numeric($row['support'])
				|| !is_numeric($row['numerator'])) {
				throw new \UnexpectedValueException('Prediction row must contain numeric item_id2, support, and numerator values.');
			}
			
			$id = (int)$row['item_id2'];
			
			if ($allowed !== null && !isset($allowed[$id])) {
				continue;
			}
			
			$support = (int)$row['support'];
			$results[] = new PredictionResult($id, $this->clampRating((float)$row['numerator'] / $support), $support);
		}
		
		usort($results, fn($a, $b) => ($b->predictedRating <=> $a->predictedRating)
			?: ($b->supportCount <=> $a->supportCount) ?: ($a->itemId <=> $b->itemId));
		return $limit > 0 ? array_slice($results, 0, $limit) : $results;
	}
	
	/**
	 * Load the visitor's genuine ratings into a temporary table for the duration of one operation.
	 * @template T
	 * @param array<int, float> $ratings Genuine visitor ratings
	 * @param callable(string): T $operation Query using the temporary table
	 * @return T Query result
	 */
	private function withVisitorRatingTable(array $ratings, callable $operation): mixed {
		$table = 'recommender_visitor_prediction_input_' . bin2hex(random_bytes(6));
		$this->connection->execute("CREATE TEMPORARY TABLE {$table}
			(product_id INT UNSIGNED NOT NULL PRIMARY KEY, rating DOUBLE NOT NULL)");
			
		try {
			foreach (array_chunk($ratings, 500, true) as $batch) {
				$values = [];
				$params = [];
				
				foreach ($batch as $id => $rating) {
					$values[] = '(?, ?)';
					$params[] = $id;
					$params[] = $rating;
				}
				
				$this->connection->execute("INSERT INTO {$table} (product_id, rating) VALUES "
					. implode(',', $values), $params);
			}
			
			return $operation($table);
		} finally {
			$this->connection->execute("DROP TEMPORARY TABLE {$table}");
		}
	}
	
	/**
	 * Load IDs into a temporary table for the duration of one operation.
	 * @template T
	 * @param string $prefix Table name prefix, followed by a random suffix
	 * @param array<int, int> $ids IDs to load
	 * @param callable(string): T $operation Query receiving the temporary table name
	 * @return T Query result
	 */
	private function withIdTable(string $prefix, array $ids, callable $operation): mixed {
		$table = $prefix . bin2hex(random_bytes(6));
		$this->connection->execute("CREATE TEMPORARY TABLE {$table} (product_id INT UNSIGNED PRIMARY KEY)");
		
		try {
			foreach (array_chunk($ids, 500) as $batch) {
				$holders = implode(',', array_fill(0, count($batch), '(?)'));
				$this->connection->execute("INSERT INTO {$table} (product_id) VALUES {$holders}", $batch);
			}
			
			return $operation($table);
		} finally {
			$this->connection->execute("DROP TEMPORARY TABLE {$table}");
		}
	}
	
	// ----- Helpers -----
	
	/**
	 * Return scored member recommendations, falling back to top-rated items for short histories.
	 * item_links scores sum liked_count multiplied by (member rating minus threshold).
	 * @param int $memberId Member ID
	 * @param array<int> $filter Allowed product IDs, or empty for all
	 * @param int $limit Maximum results, or zero for all
	 * @param int|null $category Category override
	 * @param int $minHistory Minimum genuine ratings before collaborative scoring
	 * @param int $minRatings Minimum ratings for a fallback item
	 * @return array<int, RecommendationResult>
	 */
	public function memberRecommendationsDetailed(int $memberId, array $filter = [], int $limit = 0, ?int $category = null,
		int $minHistory = 1, int $minRatings = 2): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$history = (int)$this->connection->execute('SELECT COUNT(*) AS total FROM vogoo_ratings WHERE member_id = :member AND category = :category AND rating >= 0.0',
			['member' => $memberId, 'category' => $resolvedCategory])->fetchAssoc()['total'];
			
		if ($history < max(1, $minHistory)) {
			$excluded = array_map('intval', array_column($this->connection->execute(
				'SELECT product_id FROM vogoo_ratings WHERE member_id = :member AND category = :category',
				['member' => $memberId, 'category' => $resolvedCategory])->fetchAll('assoc'), 'product_id'));
			return $this->fallbackResults($excluded, $filter, $limit, $resolvedCategory, $minRatings);
		}
		
		$params = ['member' => $memberId, 'category' => $resolvedCategory, 'threshold' => $this->config->getThresholdRating()];
		$sql = 'SELECT l.item_id2, SUM(l.liked_count * (r.rating - :threshold)) AS score,
			JSON_ARRAYAGG(r.product_id) AS contributors
			FROM vogoo_links l JOIN vogoo_ratings r ON r.product_id = l.item_id1
				AND r.category = l.category AND r.member_id = :member AND r.rating >= 0.0
			WHERE l.category = :category AND l.liked_count > 0
				AND NOT EXISTS (SELECT 1 FROM vogoo_ratings seen WHERE seen.member_id = :member2
					AND seen.category = :category2 AND seen.product_id = l.item_id2)';
		$params['member2'] = $memberId;
		$params['category2'] = $resolvedCategory;
		$sql .= $this->allowedSql($filter, 'l.item_id2', $params);
		$sql .= ' GROUP BY l.item_id2 HAVING score > 0 ORDER BY score DESC, l.item_id2 ASC';
		
		try {
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
		} finally {
			$this->clearAllowedTable($filter);
		}
		
		$results = [];
		
		foreach ($rows as $row) {
			$id = (int)$row['item_id2'];
			
			if ($filter !== [] && !in_array($id, $filter, true)) {
				continue;
			}
			
			$contributors = $this->decodeContributorIds((string)$row['contributors']);
			$results[] = new RecommendationResult($id, (float)$row['score'], 'item_links', $contributors);
		}
		
		return $limit > 0 ? array_slice($results, 0, $limit) : $results;
	}
	
	/**
	 * Decode the JSON contributor list returned for an item-links recommendation.
	 * @param string $json JSON array of product IDs
	 * @return array<int, int> Unique contributing product IDs in ascending order
	 * @throws \UnexpectedValueException When the JSON is not an array of integers
	 */
	private function decodeContributorIds(string $json): array {
		$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		
		if (!is_array($decoded)) {
			throw new \UnexpectedValueException('Recommendation contributors returned by the database must be a JSON array.');
		}
		
		$contributors = [];
		
		foreach ($decoded as $value) {
			if (!is_int($value)) {
				throw new \UnexpectedValueException('Contributing product ID returned by the database must be an integer, got ' . var_export($value, true) . '.');
			}
			
			$contributors[] = $value;
		}
		
		$contributors = array_values(array_unique($contributors));
		sort($contributors);
		return $contributors;
	}
	
	/**
	 * Return scored visitor recommendations, with the same cold-start rule as members.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param array<int> $filter Allowed product IDs, or empty for all
	 * @param int $limit Maximum results, or zero for all
	 * @param int|null $category Category override
	 * @param int $minHistory Minimum genuine ratings before collaborative scoring
	 * @param int $minRatings Minimum ratings for a fallback item
	 * @return array<int, RecommendationResult>
	 */
	public function visitorRecommendationsDetailed(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null,
		int $minHistory = 1, int $minRatings = 2): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		$ratings = $visitor->getRatings($resolvedCategory);
		$history = count(array_filter($ratings, fn($row) => $row['rating'] >= 0.0));
		
		if ($history < max(1, $minHistory)) {
			return $this->fallbackResults(array_column($ratings, 'product_id'), $filter, $limit, $resolvedCategory, $minRatings);
		}
		
		$reasons = [];
		$scores = array_filter($this->scoreVisitorCandidates($ratings, $filter, $resolvedCategory, $reasons), fn($score) => $score > 0);
		uksort($scores, fn($a, $b) => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
		
		$results = [];
		
		foreach ($scores as $id => $score) {
			$contributors = array_values(array_unique($reasons[$id] ?? []));
			sort($contributors);
			$results[] = new RecommendationResult((int)$id, (float)$score, 'item_links', $contributors);
		}
		
		return $limit > 0 ? array_slice($results, 0, $limit) : $results;
	}
	
	/**
	 * Rank popular high-rated items while excluding every rated or rejected item.
	 * @param array<int> $excluded Items already seen
	 * @param array<int> $filter Allowed IDs, or empty for all
	 * @param int $limit Maximum results
	 * @param int $category Category
	 * @param int $minRatings Minimum rating count
	 * @return array<int, RecommendationResult>
	 */
	private function fallbackResults(array $excluded, array $filter, int $limit, int $category, int $minRatings): array {
		$stats = new Statistics($this->connection, $this->config);
		$results = [];
		
		foreach ($stats->topRatedProducts(0, max(1, $minRatings), $category) as $row) {
			$id = $row['product_id'];
			
			if (in_array($id, $excluded, true) || ($filter !== [] && !in_array($id, $filter, true))) {
				continue;
			}
			
			$results[] = new RecommendationResult($id, $row['avg_rating'], 'top_rated', []);
			
			if ($limit > 0 && count($results) >= $limit) {
				break;
			}
		}
		
		return $results;
	}
	
	/**
	 * Build a parameterized allowlist predicate for a SQL query.
	 * Large lists use a temporary indexed table populated in bounded batches.
	 * @param array<int> $filter Allowed product IDs
	 * @param string $column SQL column selected by the caller
	 * @param array<string, int|float> $params Bound query parameters, extended in place
	 * @return string SQL predicate, empty when the filter is empty
	 * @throws \InvalidArgumentException When an allowed ID is not an unsigned 32-bit integer
	 */
	private function allowedSql(array $filter, string $column, array &$params): string {
		if ($filter === []) {
			return '';
		}
		
		foreach ($filter as $id) {
			if (!is_int($id) || $id < 0 || $id > 4294967295) {
				throw new \InvalidArgumentException('Allowed product ID must be an unsigned 32-bit integer, got ' . var_export($id, true) . '.');
			}
		}
		
		if (count($filter) > 500) {
			$this->connection->execute('DROP TEMPORARY TABLE IF EXISTS recommender_allowed_items');
			$this->connection->execute('CREATE TEMPORARY TABLE recommender_allowed_items (id INT UNSIGNED PRIMARY KEY)');
			
			try {
				foreach (array_chunk(array_values(array_unique($filter)), 500) as $chunk) {
					$values = implode(',', array_fill(0, count($chunk), '(?)'));
					$this->connection->execute('INSERT INTO recommender_allowed_items (id) VALUES ' . $values, $chunk);
				}
			} catch (\Throwable $exception) {
				$this->connection->execute('DROP TEMPORARY TABLE IF EXISTS recommender_allowed_items');
				throw $exception;
			}
			
			return ' AND ' . $column . ' IN (SELECT id FROM recommender_allowed_items)';
		}
		
		$names = [];
		
		foreach (array_values($filter) as $index => $id) {
			$name = 'allowed_' . $index;
			$params[$name] = $id;
			$names[] = ':' . $name;
		}
		
		return ' AND ' . $column . ' IN (' . implode(',', $names) . ')';
	}
	
	/**
	 * Release the temporary table used for a large allowlist.
	 * @param array<int> $filter Allowed IDs
	 * @return void
	 */
	private function clearAllowedTable(array $filter): void {
		if (count($filter) > 500) {
			$this->connection->execute('DROP TEMPORARY TABLE IF EXISTS recommender_allowed_items');
		}
	}
	
	/**
	 * Clamp a predicted rating to the valid [0.0, 1.0] range.
	 * @param float $value The raw predicted rating to clamp into the valid range
	 * @return float
	 */
	private function clampRating(float $value): float {
		return max(0.0, min(1.0, $value));
	}
	
	/**
	 * Extract a product ID column from rows, optionally keeping only IDs in a whitelist.
	 * @param array<int, mixed> $rows Rows from the query
	 * @param string $column Name of the result column holding the product ID
	 * @param array<int> $filter When non-empty, only return product IDs in this set
	 * @return array<int, int>
	 */
	private function filterAndExtract(array $rows, string $column, array $filter): array {
		$result = [];
		
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row[$column]) || !is_scalar($row[$column])) {
				continue;
			}
			
			$id = (int)$row[$column];
			
			if (empty($filter) || in_array($id, $filter, true)) {
				$result[] = $id;
			}
		}
		
		return $result;
	}
	
	/**
	 * Build a product-to-rating map of genuine ratings (>= 0.0, excluding not interested) for Slope One input.
	 * @param RatingList $ratings Visitor rating entries
	 * @return array<int, float> Map of product_id to rating
	 */
	private function collectGenuineRatings(array $ratings): array {
		$products = [];
		
		foreach ($ratings as $entry) {
			if ($entry['rating'] >= 0.0) {
				$products[$entry['product_id']] = $entry['rating'];
			}
		}
		
		return $products;
	}
	
	/**
	 * Accumulate weighted co-occurrence scores for every candidate item linked to the visitor's rated products.
	 * Skips not-interested entries, zero-count links, and items the visitor has already rated.
	 * @param RatingList $ratings Visitor rating entries
	 * @param array<int> $filter When non-empty, only score product IDs in this set
	 * @param int $category Already-resolved category
	 * @param array<int, array<int, int>>|null $reasons Optional contributing IDs by candidate, filled in place
	 * @return array<int, float> Map of candidate product_id to raw score
	 */
	private function scoreVisitorCandidates(array $ratings, array $filter, int $category, ?array &$reasons = null): array {
		$threshold = $this->config->getThresholdRating();
		$ratedIds = array_column($ratings, 'product_id');
		$scores = [];
		
		foreach ($ratings as $entry) {
			if ($entry['rating'] === $this->config->getNotInterested()) {
				continue;
			}
			
			$rows = $this->connection->execute('
				SELECT
					`item_id2`,
					`liked_count`
				FROM `vogoo_links`
				WHERE `category` = :category AND
				      `item_id1` = :product_id
			', [
				'category'   => $category,
				'product_id' => $entry['product_id'],
			])->fetchAll('assoc');
			
			foreach ($rows as $row) {
				if (!is_array($row) || !isset($row['item_id2'], $row['liked_count']) || !is_scalar($row['item_id2'])) {
					continue;
				}
				
				$id = (int)$row['item_id2'];
				
				if ((!empty($filter) && !in_array($id, $filter, true)) || in_array($id, $ratedIds, true)) {
					continue;
				}
				
				if ((int)$row['liked_count'] === 0) {
					continue;
				}
				
				$scores[$id] = ($scores[$id] ?? 0.0) + ($entry['rating'] - $threshold) * (int)$row['liked_count'];
				
				if ($reasons !== null) {
					$reasons[$id][] = $entry['product_id'];
				}
			}
		}
		
		return $scores;
	}
	
	/**
	 * Accumulate Slope One (count, diff) totals per candidate item across all of the visitor's rated products.
	 * Runs one query per rated product.
	 * @param array<int, float> $products Map of rated product_id to rating
	 * @param array<int> $filter When non-empty, only accumulate product IDs in this set
	 * @param int $category Already-resolved category
	 * @return array<int, array{0: float, 1: float}> Map of candidate id to [count, diff]
	 */
	private function accumulateSlopePredictions(array $products, array $filter, int $category): array {
		$accumulated = [];
		
		foreach ($products as $ratedProductId => $ratedRating) {
			$rows = $this->connection->execute('
				SELECT
					`item_id2`,
					SUM(`slope_count`) AS cnter,
					SUM(:rating * `slope_count` + `diff_slope`) AS diff
				FROM `vogoo_links`
				WHERE `item_id1` = :product_id AND
				      `slope_count` > 0 AND
				      `category` = :category
				GROUP BY `item_id2`
			', [
				'rating'     => $ratedRating,
				'product_id' => $ratedProductId,
				'category'   => $category,
			])->fetchAll('assoc');
			
			foreach ($rows as $row) {
				if (!is_array($row) || !isset($row['item_id2'], $row['cnter'], $row['diff']) || !is_scalar($row['item_id2'])) {
					continue;
				}
				
				$id = (int)$row['item_id2'];
				
				if (!empty($filter) && !in_array($id, $filter, true)) {
					continue;
				}
				
				if (isset($accumulated[$id])) {
					$accumulated[$id][0] += (float)$row['cnter'];
					$accumulated[$id][1] += (float)$row['diff'];
				} else {
					$accumulated[$id] = [(float)$row['cnter'], (float)$row['diff']];
				}
			}
		}
		
		return $accumulated;
	}
}
