<?php

	namespace Quellabs\Recommender;

	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Identifier;
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
		 * @phpstan-import-type ProductRating from SlopeOneRecommender
	 */
	readonly class ItemRecommender {

		/** @var Connection Database connection */
		private Connection $connection;

		/** @var RecommendationConfig Recommendation settings */
		private RecommendationConfig $config;

		/** @var SlopeOneRecommender Slope One predictions and rankings */
		private SlopeOneRecommender $slopeOne;

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
			$this->eligibilityFilter = new EligibilityFilter($config);
		}

		/**
		 * Return items that co-occur with the given product, ordered by co-occurrence count descending.
		 * Score is the liked count. With eligibility, fewer than $limit results are returned when the depth cap is reached.
		 * @param ProductId $product The product ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Co-occurring products, scored by liked count
		 */
		public function linkedProducts(ProductId $product, ?EligibilityProvider $eligibility = null, int $limit = 10, ?int $category = null): array {
			$productId = $product->value;
			$resolvedCategory = $this->config->resolveCategory($category);

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (int $depth) use ($productId, $resolvedCategory): array {
					return $this->linkedRows($productId, $depth, $resolvedCategory);
				},
				function (RecommendationResult $row): int {
					return $row->productId;
				});
		}

		/**
		 * Return the products this member has rated that are linked to the given product, the "why we recommend this" list.
		 * Score is the liked count of the link to the given product.
		 * @param MemberId $member The member ID
		 * @param ProductId $product The product ID
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Rated products linked to the given product, scored by liked count
		 * @throws \UnexpectedValueException When a reason row from the database is malformed
		 */
		public function memberReasons(MemberId $member, ProductId $product, int $limit = 10, ?int $category = null): array {
			$memberId = $member->value;
			$productId = $product->value;
			$resolvedCategory = $this->config->resolveCategory($category);
			$limit = max(0, $limit);
			$threshold = $this->config->thresholdRating();
			$sql = '
				SELECT
					r.`product_id` AS reason_id,
					l.`liked_count`
				FROM `vogoo_ratings` r
				INNER JOIN `vogoo_links` l ON l.`item_id1` = :product_id AND
					r.`product_id` = l.`item_id2` AND
					l.`liked_count` > 0 AND
					l.`category` = r.`category`
				WHERE r.`member_id` = :member_id AND
					r.`category` = :category AND
					r.`rating` >= :threshold
				ORDER BY l.`liked_count` DESC, r.`product_id` ASC
			';
			$params = [
				'product_id' => $productId,
				'member_id'  => $memberId,
				'category'   => $resolvedCategory,
				'threshold'  => $threshold,
			];
			$sql .= Results::limitSql($limit);
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');

			return $this->reasonResults($rows);
		}

		/**
		 * Return the visitor's rated products that are linked to the given product, the "why we recommend this" list for visitors.
		 * Score is the liked count of the link to the given product.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param ProductId $product The product ID
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Rated products linked to the given product, scored by liked count
		 * @throws \UnexpectedValueException When a reason row from the database is malformed
		 */
		public function visitorReasons(VisitorContext $visitor, ProductId $product, int $limit = 10, ?int $category = null): array {
			$productId = $product->value;
			$resolvedCategory = $this->config->resolveCategory($category);
			$threshold = $this->config->thresholdRating();
			$ratings = $visitor->ratings($resolvedCategory);
			$likedIds = array_map(
				fn(VisitorRating $rating): int => $rating->productId,
				array_filter($ratings, fn(VisitorRating $rating): bool => $rating->rating >= $threshold)
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
					`item_id2` AS reason_id,
					`liked_count`
				FROM `vogoo_links`
				WHERE `category` = :category AND
				      `item_id1` = :product_id AND
				      `item_id2` IN ({$inList}) AND
				      `liked_count` > 0
				ORDER BY `liked_count` DESC, `item_id2` ASC
			";
			$sql .= Results::limitSql($limit);
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');

			return $this->reasonResults($rows);
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
		 * Return items sorted by their average Slope One diff relative to the given product, best match first.
		 * Score is the average diff, which can be negative. With eligibility, fewer than $limit results may be returned.
		 * @param ProductId $product The product ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param MinSupport $minSupport Minimum co-occurrence count to include a pair
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Products scored by their average Slope One diff
		 */
		public function slopeProducts(ProductId $product, ?EligibilityProvider $eligibility = null, int $limit = 10,
			MinSupport $minSupport = new MinSupport(), ?int $category = null): array {
			$productId = $product->value;
			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (int $depth) use ($productId, $minSupport, $category): array {
					$diffs = $this->slopeOne->getSlopeItems($productId, $minSupport->value, $depth, $category);

					return array_map(function (array $diff): RecommendationResult {
						return new RecommendationResult($diff['product_id'], $diff['diff'], RecommendationSource::SlopeOne, []);
					}, $diffs);
				},
				function (RecommendationResult $row): int {
					return $row->productId;
				});
		}

		/**
		 * Predict a single member rating with directed-pair support.
		 * @param MemberId $member Member ID
		 * @param ProductId $product Candidate ID
		 * @param MinSupport $minSupport Minimum summed pair support
		 * @param int|null $category Category override
		 * @return PredictionResult|null
		 */
		public function memberPrediction(MemberId $member, ProductId $product, MinSupport $minSupport = new MinSupport(), ?int $category = null): ?PredictionResult {
			$memberId = $member->value;
			$productId = $product->value;
			return $this->slopeOne->memberPredictDetailed($memberId, $productId, $minSupport->value, $category);
		}

		/**
		 * Predict unseen member ratings with directed-pair support, best prediction first.
		 * With eligibility, fewer than $limit results may be returned when the depth cap is reached.
		 * @param MemberId $member Member ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param MinSupport $minSupport Minimum summed pair support
		 * @param int|null $category Category override
		 * @return array<int, PredictionResult>
		 */
		public function memberPredictions(MemberId $member, ?EligibilityProvider $eligibility = null, int $limit = 10,
			MinSupport $minSupport = new MinSupport(), ?int $category = null): array {
			$memberId = $member->value;
			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (int $depth) use ($memberId, $minSupport, $category): array {
					return $this->slopeOne->memberPredictAllDetailed($memberId, $depth, $minSupport->value, $category);
				},
				function (PredictionResult $row): int {
					return $row->productId;
				});
		}

		/**
		 * Predict one visitor rating with directed-pair support.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param ProductId $product Candidate ID
		 * @param MinSupport $minSupport Minimum summed pair support
		 * @param int|null $category Category override
		 * @return PredictionResult|null
		 */
		public function visitorPrediction(VisitorContext $visitor, ProductId $product, MinSupport $minSupport = new MinSupport(), ?int $category = null): ?PredictionResult {
			$productId = $product->value;
			return $this->slopeOne->visitorPredictDetailed($visitor, $productId, $minSupport->value, $category);
		}

		/**
		 * Predict unseen visitor ratings with a batched temporary input table, best prediction first.
		 * With eligibility, fewer than $limit results may be returned when the depth cap is reached.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param MinSupport $minSupport Minimum summed pair support
		 * @param int|null $category Category override
		 * @return array<int, PredictionResult>
		 */
		public function visitorPredictions(VisitorContext $visitor, ?EligibilityProvider $eligibility = null, int $limit = 10,
			MinSupport $minSupport = new MinSupport(), ?int $category = null): array {
			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (int $depth) use ($visitor, $minSupport, $category): array {
					return $this->slopeOne->visitorPredictAllDetailed($visitor, $depth, $minSupport->value, $category);
				},
				function (PredictionResult $row): int {
					return $row->productId;
				});
		}

		/**
		 * Return scored member recommendations, falling back to top-rated items for short histories.
		 * item_links scores sum liked_count multiplied by (member rating minus threshold). Fallback scores are average ratings.
		 * With eligibility, fewer than $limit results may be returned when the depth cap is reached.
		 * @param MemberId $member Member ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param ColdStartPolicy $coldStart Fallback thresholds for short histories
		 * @param int|null $category Category override
		 * @return array<int, RecommendationResult>
		 * @throws \UnexpectedValueException When a link score row or its contributors are malformed
		 */
		public function memberRecommendations(MemberId $member, ?EligibilityProvider $eligibility = null, int $limit = 10,
			ColdStartPolicy $coldStart = new ColdStartPolicy(), ?int $category = null): array {
			$memberId = $member->value;
			$resolvedCategory = $this->config->resolveCategory($category);

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (int $depth) use ($memberId, $resolvedCategory, $coldStart): array {
					return $this->memberRecommendationRows($memberId, $depth, $resolvedCategory,
						$coldStart->minHistory, $coldStart->topRatedMinRatings);
				},
				function (RecommendationResult $row): int {
					return $row->productId;
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
		 * Return scored visitor recommendations, with the same cold-start rule and score meanings as members.
		 * With eligibility, fewer than $limit results may be returned when the depth cap is reached.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param ColdStartPolicy $coldStart Fallback thresholds for short histories
		 * @param int|null $category Category override
		 * @return array<int, RecommendationResult>
		 */
		public function visitorRecommendations(VisitorContext $visitor, ?EligibilityProvider $eligibility = null, int $limit = 10,
			ColdStartPolicy $coldStart = new ColdStartPolicy(), ?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (int $depth) use ($visitor, $resolvedCategory, $coldStart): array {
					return $this->visitorRecommendationRows($visitor, $depth, $resolvedCategory,
						$coldStart->minHistory, $coldStart->topRatedMinRatings);
				},
				function (RecommendationResult $row): int {
					return $row->productId;
				});
		}

		/**
		 * Return the linked products of one product, scored by liked count, with a limit.
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
		 * Return the scored collaborative or fallback member recommendations, with a limit.
		 * @param int $memberId Member ID
		 * @param int $limit Maximum results, or zero for all
		 * @param int $category Already-resolved category
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring
		 * @param int $topRatedMinRatings Minimum ratings for a fallback item
		 * @return array<int, RecommendationResult>
		 * @throws \UnexpectedValueException When a link score row or its contributors are malformed
		 */
		private function memberRecommendationRows(int $memberId, int $limit, int $category,
			int $minHistory, int $topRatedMinRatings): array {
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
				return $this->fallbackResults($excluded, $limit, $category, $topRatedMinRatings);
			}

			$results = [];

			foreach ($this->memberLinkScoreRows($memberId, $category) as $row) {
				if (!is_numeric($row['item_id2']) || !is_numeric($row['score']) || !is_scalar($row['contributors'])) {
					throw new \UnexpectedValueException('Link score row must contain numeric item_id2 and score and a contributors value.');
				}

				$contributors = $this->decodeContributorIds((string)$row['contributors']);
				$results[] = new RecommendationResult((int)$row['item_id2'], (float)$row['score'], RecommendationSource::ItemLinks, $contributors);
			}

			return Results::limit($results, $limit);
		}

		/**
		 * Return the scored collaborative or fallback visitor recommendations, with a limit.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param int $limit Maximum results, or zero for all
		 * @param int $category Already-resolved category
		 * @param int $minHistory Minimum genuine ratings before collaborative scoring
		 * @param int $topRatedMinRatings Minimum ratings for a fallback item
		 * @return array<int, RecommendationResult>
		 */
		private function visitorRecommendationRows(VisitorContext $visitor, int $limit, int $category,
			int $minHistory, int $topRatedMinRatings): array {
			$ratings = $visitor->ratings($category);
			$history = count(array_filter($ratings, fn(VisitorRating $rating): bool => $rating->rating >= 0.0));

			if ($history < max(1, $minHistory)) {
				return $this->fallbackResults(array_map(fn(VisitorRating $rating): int => $rating->productId, $ratings),
					$limit, $category, $topRatedMinRatings);
			}

			$reasons = [];
			$scores = array_filter($this->scoreVisitorCandidates($ratings, $category, $reasons), function ($score): bool {
				return $score > 0;
			});
			$scores = Results::sortByScore($scores);

			$results = [];

			foreach ($scores as $id => $score) {
				$contributors = array_values(array_unique($reasons[$id] ?? []));
				sort($contributors);
				$results[] = new RecommendationResult((int)$id, (float)$score, RecommendationSource::ItemLinks, $contributors);
			}

			return Results::limit($results, $limit);
		}

		/**
		 * Rank popular high-rated items while excluding every rated or rejected item.
		 * @param array<int> $excluded Items already seen
		 * @param int $limit Maximum results, or zero for all
		 * @param int $category Category
		 * @param int $topRatedMinRatings Minimum rating count
		 * @return array<int, RecommendationResult>
		 */
		private function fallbackResults(array $excluded, int $limit, int $category, int $topRatedMinRatings): array {
			$stats = new Statistics($this->connection, $this->config);
			$results = [];

			foreach ($stats->topRatedProducts(0, new MinRatings($topRatedMinRatings), $category) as $row) {
				$id = $row->productId;

				if (in_array($id, $excluded, true)) {
					continue;
				}

				$results[] = new RecommendationResult($id, $row->averageRating, RecommendationSource::TopRated, []);

				if ($limit > 0 && count($results) >= $limit) {
					break;
				}
			}

			return $results;
		}

		/**
		 * Accumulate weighted co-occurrence scores for every candidate item linked to the visitor's rated products.
		 * Skips not-interested entries, zero-count links, and items the visitor has already rated.
		 * @param array<int, VisitorRating> $ratings Visitor ratings
		 * @param int $category Already-resolved category
		 * @param array<int, array<int, int>>|null $reasons Optional contributing IDs by candidate, filled in place
		 * @return array<int, float> Map of candidate product_id to raw score
		 */
		private function scoreVisitorCandidates(array $ratings, int $category, ?array &$reasons = null): array {
			$threshold = $this->config->thresholdRating();
			$ratedIds = array_map(fn(VisitorRating $rating): int => $rating->productId, $ratings);
			$scores = [];

			foreach ($ratings as $entry) {
				if ($entry->rating === RecommendationConfig::NOT_INTERESTED) {
					continue;
				}

				foreach ($this->linkedCandidateRows($entry->productId, $category) as $row) {
					$fields = self::linkScoreFields($row);

					if ($fields === null) {
						continue;
					}

					[$id, $likedCount] = $fields;

					if (in_array($id, $ratedIds, true) || $likedCount === 0) {
						continue;
					}

					$scores[$id] = ($scores[$id] ?? 0.0) + ($entry->rating - $threshold) * $likedCount;

					if ($reasons !== null) {
						$reasons[$id][] = $entry->productId;
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
		 * @param int $category Already-resolved category
		 * @return array<int, array<string, mixed>> Rows with item_id2, score and contributors
		 */
		private function memberLinkScoreRows(int $memberId, int $category): array {
			$params = [
				'member' => $memberId,
				'category' => $category,
				'threshold' => $this->config->thresholdRating(),
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

			$sql .= ' GROUP BY l.item_id2 HAVING score > 0 ORDER BY score DESC, l.item_id2 ASC';

			return $this->connection->execute($sql, $params)->fetchAll('assoc');
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
