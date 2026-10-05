<?php
	
	namespace Quellabs\Recommender;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
	use Quellabs\Recommender\Internal\Query\CandidateAllowlist;
	use Quellabs\Recommender\Internal\Query\Results;
	use Quellabs\Recommender\Internal\SlopeOne\SlopeOneRecommender;
	
	/**
	 * Item-based collaborative filtering and Slope One recommendations.
	 *
	 * Co-occurrence methods read the liked_count column of vogoo_links. Slope One methods
	 * are delegated to SlopeOneRecommender and read slope_count and diff_slope.
	 *
	 * Both strategies require vogoo_links to be pre-populated, either via a
	 * batch rebuild or via incremental updates through LinkUpdater.
	 *
	 * Methods throw on database failure.
	 *
	 * @phpstan-import-type RatingList from VisitorContext
	 * @phpstan-import-type ProductRating from SlopeOneRecommender
	 * @phpstan-import-type ProductDiff from SlopeOneRecommender
	 */
	readonly class ItemRecommender {
	
		/** @var Connection Database connection */
		private Connection $connection;
	
		/** @var RecommendationConfig Recommendation settings */
		private RecommendationConfig $config;
	
		/** @var SlopeOneRecommender Slope One predictions and rankings */
		private SlopeOneRecommender $slopeOne;
	
		/** @var TemporaryTable Temporary tables for visitor rating and seen inputs */
		private TemporaryTable $temporary;
	
		/** @var CandidateAllowlist Product ID allowlist predicates */
		private CandidateAllowlist $allowlist;
	
		/**
		 * Build the recommender.
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 */
		public function __construct(Connection $connection, RecommendationConfig $config) {
			$this->connection = $connection;
			$this->config = $config;
			$this->slopeOne = new SlopeOneRecommender($connection, $config);
			$this->temporary = new TemporaryTable($connection);
			$this->allowlist = new CandidateAllowlist($this->temporary);
		}
	
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
					`category` = :category AND
					`liked_count` > 0
	        ';
			$params = ['product_id' => $productId, 'category' => $resolvedCategory];
			$sql .= $this->allowlist->predicate($filter, '`item_id2`', $params);
			$sql .= ' ORDER BY `liked_count` DESC, `item_id2` ASC';
			
			$sql .= Results::limitSql($limit);
			
			try {
				$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			} finally {
				$this->allowlist->release($filter);
			}
			
			$result = $this->filterAndExtract($rows, 'item_id2', $filter);
			return Results::limit($result, $limit);
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
			$rows = $this->linkScoreRows(
				'JOIN vogoo_ratings r ON r.member_id = :member AND
					l.item_id1 = r.product_id AND
					l.category = r.category AND
					r.rating >= 0.0',
				'NOT EXISTS (SELECT 1 FROM vogoo_ratings vr
					WHERE vr.member_id = :member2 AND
						vr.category = :category2 AND
						vr.product_id = l.item_id2
				)',
				[
					'member' => $memberId,
					'member2' => $memberId,
					'category2' => $resolvedCategory,
				],
				$filter, $resolvedCategory, $limit);
	
			return $this->rowProductIds($rows);
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
				SELECT
					r.`product_id`
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
			
			$sql .= Results::limitSql($limit);
			
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
			$genuine = [];
	
			foreach ($visitor->getRatings($resolvedCategory) as $entry) {
				if ($entry['rating'] >= 0.0) {
					$genuine[$entry['product_id']] = $entry['rating'];
				}
			}
	
			if ($genuine === []) {
				return [];
			}
	
			$seenIds = $visitor->getRatedProductIds($resolvedCategory);
			$rows = $this->temporary->withRatingTable('vogoo_visitor_link_input_', $genuine,
				fn(string $table) => $this->temporary->withIdTable('vogoo_visitor_seen_', $seenIds,
					fn(string $seenTable) => $this->linkScoreRows("JOIN {$table} r ON r.product_id = l.item_id1",
						"NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = l.item_id2)",
						[], $filter, $resolvedCategory, $limit)));
	
			return $this->rowProductIds($rows);
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
			
			$params = ['category' => $resolvedCategory, 'product_id' => $productId];
			$names = [];
	
			foreach (array_values($likedIds) as $index => $likedId) {
				$params['liked_' . $index] = $likedId;
				$names[] = ':liked_' . $index;
			}
	
			$inList = implode(',', $names);
			$sql = "
				SELECT
					`item_id2`
				FROM `vogoo_links`
				WHERE `category` = :category AND
				      `item_id1` = :product_id AND
				      `item_id2` IN ({$inList}) AND
				      `liked_count` > 0
			";
	
			$sql .= Results::limitSql($limit);
	
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			return array_map('intval', array_column($rows, 'item_id2'));
		}
	
		/**
		 * Return items sorted by their average Slope One diff relative to the given product, best match first.
		 * @param int $productId The product ID
		 * @param int $minLinks Minimum co-occurrence count to include a pair
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, ProductDiff>
		 */
		public function getSlopeItems(int $productId, int $minLinks = 1, array $filter = [], int $limit = 0, ?int $category = null): array {
			return $this->slopeOne->getSlopeItems($productId, $minLinks, $filter, $limit, $category);
		}
	
		/**
		 * Predict a member's rating for a single product using Slope One.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return float|null Predicted rating in [0.0, 1.0], or null when there is insufficient data
		 */
		public function memberPredict(int $memberId, int $productId, ?int $category = null): ?float {
			return $this->slopeOne->memberPredict($memberId, $productId, $category);
		}
	
		/**
		 * Predict ratings for all unrated items for a member using Slope One, sorted by predicted rating descending.
		 * @param int $memberId The member ID
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, ProductRating>
		 */
		public function memberPredictAll(int $memberId, array $filter = [], int $limit = 0, ?int $category = null): array {
			return $this->slopeOne->memberPredictAll($memberId, $filter, $limit, $category);
		}
	
		/**
		 * Predict a rating for a single product for an anonymous visitor using Slope One.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return float|null Predicted rating in [0.0, 1.0], or null when there is insufficient data
		 */
		public function visitorPredict(VisitorContext $visitor, int $productId, ?int $category = null): ?float {
			return $this->slopeOne->visitorPredict($visitor, $productId, $category);
		}
	
		/**
		 * Predict ratings for all unrated items for an anonymous visitor using Slope One, sorted by predicted rating descending.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param array<int> $filter When non-empty, only return product IDs in this set
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, ProductRating>
		 */
		public function visitorPredictAll(VisitorContext $visitor, array $filter = [], int $limit = 0, ?int $category = null): array {
			return $this->slopeOne->visitorPredictAll($visitor, $filter, $limit, $category);
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
			return $this->slopeOne->memberPredictDetailed($memberId, $productId, $minSupport, $category);
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
			return $this->slopeOne->memberPredictAllDetailed($memberId, $filter, $limit, $minSupport, $category);
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
			return $this->slopeOne->visitorPredictDetailed($visitor, $productId, $minSupport, $category);
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
		 */
		public function visitorPredictAllDetailed(VisitorContext $visitor, array $filter = [], int $limit = 0,
			int $minSupport = 1, ?int $category = null): array {
			return $this->slopeOne->visitorPredictAllDetailed($visitor, $filter, $limit, $minSupport, $category);
		}
	
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
		 * @throws \UnexpectedValueException When a link score row or its contributors are malformed
		 */
		public function memberRecommendationsDetailed(int $memberId, array $filter = [], int $limit = 0, ?int $category = null,
			int $minHistory = 1, int $minRatings = 2): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			$history = (int)$this->connection->execute('
				SELECT
					COUNT(*) AS total
				FROM vogoo_ratings
				WHERE member_id = :member AND
				      category = :category AND
				      rating >= 0.0
			', [
				'member' => $memberId,
				'category' => $resolvedCategory,
			])->fetchAssoc()['total'];
	
			if ($history < max(1, $minHistory)) {
				$excluded = array_map('intval', array_column($this->connection->execute('
					SELECT
						product_id
					FROM vogoo_ratings
					WHERE member_id = :member AND
					      category = :category
				', [
					'member' => $memberId,
					'category' => $resolvedCategory,
				])->fetchAll('assoc'), 'product_id'));
				return $this->fallbackResults($excluded, $filter, $limit, $resolvedCategory, $minRatings);
			}
	
			$results = [];
	
			foreach ($this->memberLinkScoreRows($memberId, $filter, $resolvedCategory) as $row) {
				if (!is_numeric($row['item_id2']) || !is_numeric($row['score']) || !is_scalar($row['contributors'])) {
					throw new \UnexpectedValueException('Link score row must contain numeric item_id2 and score and a contributors value.');
				}
	
				$id = (int)$row['item_id2'];
	
				if (!Results::allows($filter, $id)) {
					continue;
				}
	
				$contributors = $this->decodeContributorIds((string)$row['contributors']);
				$results[] = new RecommendationResult($id, (float)$row['score'], 'item_links', $contributors);
			}
	
			return Results::limit($results, $limit);
		}
	
		/**
		 * Decode the JSON contributor list returned for an item-links recommendation.
		 * @param string $json JSON array of product IDs
		 * @return array<int, int> Unique contributing product IDs in ascending order
		 * @throws \UnexpectedValueException|\JsonException When the JSON is not an array of integers
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
			$scores = Results::sortByScore($scores);
			
			$results = [];
			
			foreach ($scores as $id => $score) {
				$contributors = array_values(array_unique($reasons[$id] ?? []));
				sort($contributors);
				$results[] = new RecommendationResult((int)$id, (float)$score, 'item_links', $contributors);
			}
			
			return Results::limit($results, $limit);
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
				
				if (in_array($id, $excluded, true) || !Results::allows($filter, $id)) {
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
				
				if (Results::allows($filter, $id)) {
					$result[] = $id;
				}
			}
			
			return $result;
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
	
				foreach ($this->linkedCandidateRows($entry['product_id'], $category) as $row) {
					if (
						!is_array($row) ||
						!isset($row['item_id2'], $row['liked_count']) ||
						!is_numeric($row['item_id2']) ||
						!is_numeric($row['liked_count'])
					) {
						continue;
					}
	
					$id = (int)$row['item_id2'];
	
					if (!Results::allows($filter, $id) || in_array($id, $ratedIds, true) || (int)$row['liked_count'] === 0) {
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
		 * Extract the product ID column from link score rows, in row order.
		 * @param array<int, array<string, mixed>> $rows Rows with an item_id2 column
		 * @return array<int, int> Product IDs
		 * @throws \UnexpectedValueException When a row has no numeric product ID
		 */
		private function rowProductIds(array $rows): array {
			$ids = [];
	
			foreach ($rows as $row) {
				if (!is_numeric($row['item_id2'])) {
					throw new \UnexpectedValueException('Link score row must contain a numeric item_id2.');
				}
	
				$ids[] = (int)$row['item_id2'];
			}
	
			return $ids;
		}
	
		/**
		 * Return the co-occurrence score of every candidate linked to the rated items, best first.
		 * @param string $ratingJoin Join from vogoo_links to the rating source, aliased r on l.item_id1
		 * @param string $seenPredicate Predicate excluding candidates already rated, referring to l.item_id2
		 * @param array<string, int|float> $params Parameters referenced by the join and predicate
		 * @param array<int> $filter Allowed IDs, or empty for all
		 * @param int $category Already-resolved category
		 * @param int $limit Maximum results, or zero for all
		 * @return array<int, array<string, mixed>> Rows with item_id2 and score
		 */
		private function linkScoreRows(string $ratingJoin, string $seenPredicate, array $params, array $filter, int $category, int $limit): array {
			$params += ['threshold' => $this->config->getThresholdRating(), 'category' => $category];
			
			$sql = "
				SELECT
					l.item_id2,
					SUM(l.liked_count * (r.rating - :threshold)) AS score
				FROM vogoo_links l {$ratingJoin}
				WHERE l.category = :category AND
					l.liked_count > 0 AND
					{$seenPredicate}";

			$sql .= $this->allowlist->predicate($filter, 'l.item_id2', $params);
			$sql .= ' GROUP BY l.item_id2 HAVING score > 0 ORDER BY score DESC, l.item_id2 ASC' . Results::limitSql($limit);
	
			try {
				return $this->connection->execute($sql, $params)->fetchAll('assoc');
			} finally {
				$this->allowlist->release($filter);
			}
		}
	
		/**
		 * Return the co-occurrence score and contributing product IDs of each candidate linked to the member's rated items.
		 * @param int $memberId Member ID
		 * @param array<int> $filter Allowed product IDs, or empty for all
		 * @param int $category Already-resolved category
		 * @return array<int, array<string, mixed>> Rows with item_id2, score and contributors
		 */
		private function memberLinkScoreRows(int $memberId, array $filter, int $category): array {
			$params = [
				'member' => $memberId,
				'category' => $category,
				'threshold' => $this->config->getThresholdRating(),
				'member2' => $memberId,
				'category2' => $category,
			];

			$sql = '
				SELECT
					l.item_id2,
					SUM(l.liked_count * (r.rating - :threshold)) AS score,
					JSON_ARRAYAGG(r.product_id) AS contributors
				FROM vogoo_links l
				JOIN vogoo_ratings r ON r.product_id = l.item_id1 AND
					r.category = l.category AND
					r.member_id = :member AND
					r.rating >= 0.0
				WHERE l.category = :category AND
					l.liked_count > 0 AND
					NOT EXISTS (
						SELECT 1 FROM vogoo_ratings seen
						WHERE seen.member_id = :member2 AND
							seen.category = :category2 AND
							seen.product_id = l.item_id2
					)
			';
			
			$sql .= $this->allowlist->predicate($filter, 'l.item_id2', $params);
			$sql .= ' GROUP BY l.item_id2 HAVING score > 0 ORDER BY score DESC, l.item_id2 ASC';
	
			try {
				return $this->connection->execute($sql, $params)->fetchAll('assoc');
			} finally {
				$this->allowlist->release($filter);
			}
		}
	
		/**
		 * Return the linked candidates of one rated product with their co-occurrence counts.
		 * @param int $productId Rated product ID
		 * @param int $category Already-resolved category
		 * @return array<int, array<string, mixed>> Rows with item_id2 and liked_count
		 */
		private function linkedCandidateRows(int $productId, int $category): array {
			return $this->connection->execute('
				SELECT
					`item_id2`,
					`liked_count`
				FROM `vogoo_links`
				WHERE `category` = :category AND
				      `item_id1` = :product_id
			', [
				'category'   => $category,
				'product_id' => $productId,
			])->fetchAll('assoc');
		}
	}
