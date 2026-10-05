<?php

	namespace Quellabs\Recommender;

	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
	use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
	use Quellabs\Recommender\Internal\Query\CandidateAllowlist;
	use Quellabs\Recommender\Internal\Query\Results;
	use Quellabs\Recommender\Internal\SlopeOne\SlopeOneRecommender;
	use Quellabs\Recommender\Reconciliation\EligibilityProvider;


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

		/** @var EligibilityFilter Applies eligibility providers to ranked results */
		private EligibilityFilter $eligibilityFilter;

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
			$this->eligibilityFilter = new EligibilityFilter($config);
		}

		/**
		 * Return items that co-occur with the given product, ordered by co-occurrence count descending.
		 * @param int $productId The product ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Co-occurring products, scored by liked count
		 */
		public function linkedItems(int $productId, ?EligibilityProvider $eligibility = null, int $limit = 0, ?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (array $filter, int $depth) use ($productId, $resolvedCategory): array {
					return $this->linkedRows($productId, $filter, $depth, $resolvedCategory);
				},
				function (RecommendationResult $row): int {
					return $row->itemId;
				});
		}

		/**
		 * Return the products this member has rated that are linked to the given product, the "why we recommend this" list.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function memberReasons(int $memberId, int $productId, int $limit = 0, ?int $category = null): array {
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
		 * Return the visitor's rated products that are linked to the given product, the "why we recommend this" list for visitors.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param int $productId The product ID
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, int> List of product IDs
		 */
		public function visitorReasons(VisitorContext $visitor, int $productId, int $limit = 0, ?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			$threshold = $this->config->getThresholdRating();
			$ratings = $visitor->ratings($resolvedCategory);

			$likedIds = array_column(
				array_filter($ratings, function ($entry) use ($threshold): bool {
					return $entry['rating'] >= $threshold;
				}),
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
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Products scored by their average Slope One diff
		 */
		public function slopeItems(int $productId, int $minLinks = 1, ?EligibilityProvider $eligibility = null,
			int $limit = 0, ?int $category = null): array {
			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (array $filter, int $depth) use ($productId, $minLinks, $category): array {
					$diffs = $this->slopeOne->getSlopeItems($productId, $minLinks, $filter, $depth, $category);

					return array_map(function (array $diff): RecommendationResult {
						return new RecommendationResult($diff['product_id'], $diff['diff'], 'slope_one', []);
					}, $diffs);
				},
				function (RecommendationResult $row): int {
					return $row->itemId;
				});
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
		public function memberPrediction(int $memberId, int $productId, int $minSupport = 1, ?int $category = null): ?PredictionResult {
			return $this->slopeOne->memberPredictDetailed($memberId, $productId, $minSupport, $category);
		}

		/**
		 * Predict unseen member ratings with directed-pair support, best prediction first.
		 * @param int $memberId Member ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int $minSupport Minimum summed pair support
		 * @param int|null $category Category override
		 * @return array<int, PredictionResult>
		 * @throws \InvalidArgumentException When the minimum support is not positive
		 */
		public function memberPredictions(int $memberId, ?EligibilityProvider $eligibility = null, int $limit = 0,
			int $minSupport = 1, ?int $category = null): array {
			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (array $filter, int $depth) use ($memberId, $minSupport, $category): array {
					return $this->slopeOne->memberPredictAllDetailed($memberId, $filter, $depth, $minSupport, $category);
				},
				function (PredictionResult $row): int {
					return $row->itemId;
				});
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
		public function visitorPrediction(VisitorContext $visitor, int $productId, int $minSupport = 1, ?int $category = null): ?PredictionResult {
			return $this->slopeOne->visitorPredictDetailed($visitor, $productId, $minSupport, $category);
		}

		/**
		 * Predict unseen visitor ratings with a batched temporary input table, best prediction first.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int $minSupport Minimum summed pair support
		 * @param int|null $category Category override
		 * @return array<int, PredictionResult>
		 * @throws \InvalidArgumentException When the minimum support is not positive
		 */
		public function visitorPredictions(VisitorContext $visitor, ?EligibilityProvider $eligibility = null, int $limit = 0,
			int $minSupport = 1, ?int $category = null): array {
			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (array $filter, int $depth) use ($visitor, $minSupport, $category): array {
					return $this->slopeOne->visitorPredictAllDetailed($visitor, $filter, $depth, $minSupport, $category);
				},
				function (PredictionResult $row): int {
					return $row->itemId;
				});
		}

		/**
		 * Return scored member recommendations, falling back to top-rated items for short histories.
		 * item_links scores sum liked_count multiplied by (member rating minus threshold).
		 * @param int $memberId Member ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int|null $category Category override
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring
		 * @param int $minRatings Minimum ratings for a fallback item
		 * @return array<int, RecommendationResult>
		 * @throws \UnexpectedValueException When a link score row or its contributors are malformed
		 */
		public function memberRecommendations(int $memberId, ?EligibilityProvider $eligibility = null, int $limit = 0,
			?int $category = null, int $minHistory = 1, int $minRatings = 2): array {
			$resolvedCategory = $this->config->resolveCategory($category);

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (array $filter, int $depth) use ($memberId, $resolvedCategory, $minHistory, $minRatings): array {
					return $this->memberRecommendationRows($memberId, $filter, $depth, $resolvedCategory, $minHistory, $minRatings);
				},
				function (RecommendationResult $row): int {
					return $row->itemId;
				});
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
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int|null $category Category override
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring
		 * @param int $minRatings Minimum ratings for a fallback item
		 * @return array<int, RecommendationResult>
		 */
		public function visitorRecommendations(VisitorContext $visitor, ?EligibilityProvider $eligibility = null, int $limit = 0,
			?int $category = null, int $minHistory = 1, int $minRatings = 2): array {
			$resolvedCategory = $this->config->resolveCategory($category);

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (array $filter, int $depth) use ($visitor, $resolvedCategory, $minHistory, $minRatings): array {
					return $this->visitorRecommendationRows($visitor, $filter, $depth, $resolvedCategory, $minHistory, $minRatings);
				},
				function (RecommendationResult $row): int {
					return $row->itemId;
				});
		}

		/**
		 * Return the linked products of one product, scored by liked count, with an optional allowlist and limit.
		 * @param int $productId The product ID
		 * @param array<int> $filter Allowed product IDs, or empty for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int $category Already-resolved category
		 * @return array<int, RecommendationResult>
		 */
		private function linkedRows(int $productId, array $filter, int $limit, int $category): array {
			$sql = '
				SELECT
					`item_id2`,
					`liked_count`
				FROM `vogoo_links`
				WHERE `item_id1` = :product_id AND
					`category` = :category AND
					`liked_count` > 0
	        ';
			$params = ['product_id' => $productId, 'category' => $category];
			$sql .= $this->allowlist->predicate($filter, '`item_id2`', $params);
			$sql .= ' ORDER BY `liked_count` DESC, `item_id2` ASC';

			$sql .= Results::limitSql($limit);

			try {
				$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			} finally {
				$this->allowlist->release($filter);
			}

			$results = [];

			foreach ($rows as $row) {
				$id = (int)$row['item_id2'];

				if (Results::allows($filter, $id)) {
					$results[] = new RecommendationResult($id, (float)$row['liked_count'], 'item_links', []);
				}
			}

			return Results::limit($results, $limit);
		}

		/**
		 * Return the scored collaborative or fallback member recommendations, with an optional allowlist and limit.
		 * @param int $memberId Member ID
		 * @param array<int> $filter Allowed product IDs, or empty for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int $category Already-resolved category
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring
		 * @param int $minRatings Minimum ratings for a fallback item
		 * @return array<int, RecommendationResult>
		 * @throws \UnexpectedValueException When a link score row or its contributors are malformed
		 */
		private function memberRecommendationRows(int $memberId, array $filter, int $limit, int $category,
			int $minHistory, int $minRatings): array {
			$history = (int)$this->connection->execute('
				SELECT
					COUNT(*) AS total
				FROM vogoo_ratings
				WHERE member_id = :member AND
				      category = :category AND
				      rating >= 0.0
			', [
				'member' => $memberId,
				'category' => $category,
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
					'category' => $category,
				])->fetchAll('assoc'), 'product_id'));
				return $this->fallbackResults($excluded, $filter, $limit, $category, $minRatings);
			}

			$results = [];

			foreach ($this->memberLinkScoreRows($memberId, $filter, $category) as $row) {
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
		 * Return the scored collaborative or fallback visitor recommendations, with an optional allowlist and limit.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param array<int> $filter Allowed product IDs, or empty for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int $category Already-resolved category
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring
		 * @param int $minRatings Minimum ratings for a fallback item
		 * @return array<int, RecommendationResult>
		 */
		private function visitorRecommendationRows(VisitorContext $visitor, array $filter, int $limit, int $category,
			int $minHistory, int $minRatings): array {
			$ratings = $visitor->ratings($category);
			$history = count(array_filter($ratings, function ($row): bool {
				return $row['rating'] >= 0.0;
			}));

			if ($history < max(1, $minHistory)) {
				return $this->fallbackResults(array_column($ratings, 'product_id'), $filter, $limit, $category, $minRatings);
			}

			$reasons = [];
			$scores = array_filter($this->scoreVisitorCandidates($ratings, $filter, $category, $reasons), function ($score): bool {
				return $score > 0;
			});
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
		 * @param int $limit Maximum results, or zero for all
		 * @param int $category Category
		 * @param int $minRatings Minimum rating count
		 * @return array<int, RecommendationResult>
		 */
		private function fallbackResults(array $excluded, array $filter, int $limit, int $category, int $minRatings): array {
			$stats = new Statistics($this->connection, $this->config);
			$results = [];

			foreach ($stats->topRatedProducts(0, max(1, $minRatings), $category) as $row) {
				$id = $row->productId;

				if (in_array($id, $excluded, true) || !Results::allows($filter, $id)) {
					continue;
				}

				$results[] = new RecommendationResult($id, $row->averageRating, 'top_rated', []);

				if ($limit > 0 && count($results) >= $limit) {
					break;
				}
			}

			return $results;
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
					$fields = self::linkScoreFields($row);

					if ($fields === null) {
						continue;
					}

					[$id, $likedCount] = $fields;

					if (!Results::allows($filter, $id) || in_array($id, $ratedIds, true) || $likedCount === 0) {
						continue;
					}

					$scores[$id] = ($scores[$id] ?? 0.0) + ($entry['rating'] - $threshold) * $likedCount;

					if ($reasons !== null) {
						$reasons[$id][] = $entry['product_id'];
					}
				}
			}

			return $scores;
		}

		/**
		 * Read the item ID and liked count from a link score row.
		 * @param mixed $row Link score row
		 * @return array{int, int}|null [item_id2, liked_count], or null when the row is unusable
		 */
		private static function linkScoreFields(mixed $row): ?array {
			if (
				!is_array($row) ||
				!isset($row['item_id2'], $row['liked_count']) ||
				!is_numeric($row['item_id2']) ||
				!is_numeric($row['liked_count'])
			) {
				return null;
			}

			return [(int)$row['item_id2'], (int)$row['liked_count']];
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
