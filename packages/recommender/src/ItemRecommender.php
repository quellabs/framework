<?php

	namespace Quellabs\Recommender;

	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Eligibility\EligibilityFilter;
use Quellabs\Recommender\Internal\Identifier;
	use Quellabs\Recommender\Internal\Query\Results;
	use Quellabs\Recommender\Internal\SlopeOne\SlopeOneSource;
use Quellabs\Recommender\Internal\Links\ItemLinksSource;
use Quellabs\Recommender\Reconciliation\SourceSettings;

	/**
	 * Item-based collaborative filtering and Slope One recommendations.
	 *
	 * Co-occurrence methods read the liked_count column of vogoo_links. Slope One methods
	 * are delegated to SlopeOneSource and read slope_count and diff_slope.
	 *
	 * Both strategies require vogoo_links to be pre-populated, either via a
	 * batch rebuild or via incremental updates through LinkUpdater.
	 *
	 * Methods throw on database failure.
	 *
		 * @phpstan-import-type ProductRating from SlopeOneSource
	 */
	readonly class ItemRecommender {

		/** @var Connection Database connection */
		private Connection $connection;

		/** @var RecommendationConfig Recommendation settings */
		private RecommendationConfig $config;

		/** @var SlopeOneSource Slope One predictions and rankings */
		private SlopeOneSource $slopeOne;

		/** @var ItemLinksSource Item-links candidates */
		private ItemLinksSource $itemLinks;

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
			$this->slopeOne = new SlopeOneSource($connection, $config);
			$this->itemLinks = new ItemLinksSource($connection, $config);
			$this->eligibilityFilter = new EligibilityFilter($config);
		}

		/**
		 * Return items that co-occur with the given product, ordered by co-occurrence count descending.
		 * Score is the liked count. With eligibility, fewer than $limit results are returned when the depth cap is reached.
		 * @param int $product The product ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Co-occurring products, scored by liked count
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public function linkedProducts(int $product, ?EligibilityProvider $eligibility = null, int $limit = 10, ?int $category = null): array {
			$subject = Subject::product($product);
			$resolvedCategory = $this->config->resolveCategory($category);

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				fn(int $depth): array => $this->itemLinks->candidates($subject, null, $depth, new SourceSettings(), $resolvedCategory),
				fn(RecommendationResult $row): int => $row->productId);
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
		 * @param int $product The product ID
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Rated products linked to the given product, scored by liked count
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 * @throws \UnexpectedValueException When a reason row from the database is malformed
		 */
		public function visitorReasons(VisitorContext $visitor, int $product, int $limit = 10, ?int $category = null): array {
			Identifier::assertId($product, 'Product ID');
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

			$params = ['category' => $resolvedCategory, 'product_id' => $product];
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
		 * @param int $product The product ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int $minSupport Minimum co-occurrence count to include a pair, at least 1
		 * @param int|null $category Defaults to configured default
		 * @return array<int, RecommendationResult> Products scored by their average Slope One diff
		 * @throws \InvalidArgumentException When the product ID or minimum support is invalid
		 */
		public function slopeProducts(int $product, ?EligibilityProvider $eligibility = null, int $limit = 10,
			int $minSupport = 1, ?int $category = null): array {
			$subject = Subject::product($product);
			$settings = new SourceSettings(minSupport: $minSupport);

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				fn(int $depth): array => $this->slopeOne->candidates($subject, null, $depth, $settings, $category),
				fn(RecommendationResult $row): int => $row->productId);
		}

		/**
		 * Predict a single member rating with directed-pair support.
		 * @param MemberId $member Member ID
		 * @param ProductId $product Candidate ID
		 * @param int $minSupport Minimum summed pair support, at least 1
		 * @param int|null $category Category override
		 * @return PredictionResult|null
		 * @throws \InvalidArgumentException When the minimum support is below 1
		 */
		public function memberPrediction(MemberId $member, ProductId $product, int $minSupport = 1, ?int $category = null): ?PredictionResult {
			Identifier::assertAtLeast($minSupport, 1, 'Minimum support');
			$memberId = $member->value;
			$productId = $product->value;
			return $this->slopeOne->memberPredictDetailed($memberId, $productId, $minSupport, $category);
		}

		/**
		 * Predict unseen member ratings with directed-pair support, best prediction first.
		 * With eligibility, fewer than $limit results may be returned when the depth cap is reached.
		 * @param int $member Member ID
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int $minSupport Minimum summed pair support, at least 1
		 * @param int|null $category Category override
		 * @return array<int, PredictionResult>
		 * @throws \InvalidArgumentException When the member ID or minimum support is invalid
		 */
		public function memberPredictions(int $member, ?EligibilityProvider $eligibility = null, int $limit = 10,
			int $minSupport = 1, ?int $category = null): array {
			Identifier::assertId($member, 'Member ID');
			Identifier::assertAtLeast($minSupport, 1, 'Minimum support');

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (int $depth) use ($member, $minSupport, $category): array {
					return $this->slopeOne->memberPredictAllDetailed($member, $depth, $minSupport, $category);
				},
				function (PredictionResult $row): int {
					return $row->productId;
				});
		}

		/**
		 * Predict one visitor rating with directed-pair support.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param int $product Candidate ID
		 * @param int $minSupport Minimum summed pair support, at least 1
		 * @param int|null $category Category override
		 * @return PredictionResult|null
		 * @throws \InvalidArgumentException When the product ID or minimum support is invalid
		 */
		public function visitorPrediction(VisitorContext $visitor, int $product, int $minSupport = 1, ?int $category = null): ?PredictionResult {
			Identifier::assertId($product, 'Product ID');
			Identifier::assertAtLeast($minSupport, 1, 'Minimum support');
			return $this->slopeOne->visitorPredictDetailed($visitor, $product, $minSupport, $category);
		}

		/**
		 * Predict unseen visitor ratings with a batched temporary input table, best prediction first.
		 * With eligibility, fewer than $limit results may be returned when the depth cap is reached.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param EligibilityProvider|null $eligibility Restricts results to eligible products, or null for all
		 * @param int $limit Maximum results, or zero for all
		 * @param int $minSupport Minimum summed pair support, at least 1
		 * @param int|null $category Category override
		 * @return array<int, PredictionResult>
		 * @throws \InvalidArgumentException When the minimum support is below 1
		 */
		public function visitorPredictions(VisitorContext $visitor, ?EligibilityProvider $eligibility = null, int $limit = 10,
			int $minSupport = 1, ?int $category = null): array {
			Identifier::assertAtLeast($minSupport, 1, 'Minimum support');

			return $this->eligibilityFilter->withEligibility($eligibility, $limit,
				function (int $depth) use ($visitor, $minSupport, $category): array {
					return $this->slopeOne->visitorPredictAllDetailed($visitor, $depth, $minSupport, $category);
				},
				function (PredictionResult $row): int {
					return $row->productId;
				});
		}

	}
