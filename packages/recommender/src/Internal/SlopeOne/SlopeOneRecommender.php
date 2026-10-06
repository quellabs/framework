<?php
	
	namespace Quellabs\Recommender\Internal\SlopeOne;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Persistence\TemporaryTable;
	use Quellabs\Recommender\Internal\Query\Results;
	use Quellabs\Recommender\PredictionResult;
	use Quellabs\Recommender\VisitorContext;
	
	/**
	 * Slope One predictions and rankings from the diff_slope and slope_count columns of vogoo_links.
	 *
	 * Methods throw on database failure.
	 *
	 * @phpstan-type ProductRating array{product_id: int, rating: float}
	 * @phpstan-type ProductDiff array{product_id: int, diff: float}
	 * @phpstan-import-type RatingList from VisitorContext
	 */
	readonly class SlopeOneRecommender {
	
		/** @var Connection Database connection */
		private Connection $connection;
	
		/** @var RecommendationConfig Recommendation settings */
		private RecommendationConfig $config;
	
		/** @var TemporaryTable Temporary tables for candidate sets and rating inputs */
		private TemporaryTable $temporary;

		/**
		 * Build the Slope One recommender.
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 */
		public function __construct(Connection $connection, RecommendationConfig $config) {
			$this->connection = $connection;
			$this->config = $config;
			$this->temporary = new TemporaryTable($connection);
		}

		/**
		 * Return items sorted by their average Slope One diff relative to the given product, best match first.
		 * @param int $productId The product ID
		 * @param int $minSupport Minimum co-occurrence count to include a pair
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, ProductDiff>
		 */
		public function getSlopeItems(int $productId, int $minSupport = 1, int $limit = 0, ?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			$limit = max(0, $limit);
			$result = [];

			foreach ($this->slopeItemRows($productId, max(1, $minSupport), $resolvedCategory, $limit) as $row) {
				if (
					!is_array($row) ||
					!isset($row['item_id2'], $row['avg_diff']) ||
					!is_numeric($row['item_id2']) ||
					!is_numeric($row['avg_diff'])
				) {
					continue;
				}

				$result[] = ['product_id' => (int)$row['item_id2'], 'diff' => (float)$row['avg_diff']];
			}

			return Results::limit($result, $limit);
		}

		/**
		 * Return the linked candidates of a product ordered by average Slope One diff, best first.
		 * @param int $productId The product ID
		 * @param int $minSupport Minimum co-occurrence count, at least one
		 * @param int $category Already-resolved category
		 * @param int $limit Maximum results, or zero for all
		 * @return array<int, array<string, mixed>> Rows with item_id2 and avg_diff
		 */
		private function slopeItemRows(int $productId, int $minSupport, int $category, int $limit): array {
			$sql = '
				SELECT
					`item_id2`,
					(`diff_slope` / `slope_count`) AS avg_diff
				FROM `vogoo_links`
				WHERE `item_id1` = :product_id AND
				      `category` = :category AND
				      `slope_count` >= :min_support
			';
	
			$params = [
				'product_id' => $productId,
				'category'   => $category,
				'min_support' => $minSupport,
			];
			$sql .= ' ORDER BY avg_diff DESC, `item_id2` ASC';
	
			$sql .= Results::limitSql($limit);
	
			return $this->connection->execute($sql, $params)->fetchAll('assoc');
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
			
			$prediction = $this->detailedPrediction($productId, 1, $resolvedCategory,
				'JOIN vogoo_ratings r ON r.product_id = l.item_id2 AND
					r.category = l.category AND
					r.member_id = :member AND
					r.rating >= 0.0',
				['member' => $memberId]);

			return $prediction?->predictedRating;
		}
	
		/**
		 * Predict ratings for all unrated items for a member using Slope One, sorted by predicted rating descending.
		 * @param int $memberId The member ID
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, ProductRating>
		 */
		public function memberPredictAll(int $memberId, int $limit = 0, ?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			$rows = $this->unseenSlopeRows(
				'JOIN vogoo_ratings r ON r.product_id = l.item_id1 AND
					r.category = l.category AND
					r.member_id = :member AND
					r.rating >= 0.0',
				'NOT EXISTS (SELECT 1 FROM vogoo_ratings seen
					WHERE seen.member_id = :seen_member AND
						seen.category = :seen_category AND
						seen.product_id = l.item_id2
				)',
				[
					'member' => $memberId,
					'seen_member' => $memberId,
					'seen_category' => $resolvedCategory,
				],
				$resolvedCategory, '');
	
			return $this->rankPredictions($rows, $limit);
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
			$products = $this->collectGenuineRatings($visitor->ratings($resolvedCategory));
			
			if (empty($products)) {
				return null;
			}
			
			$prediction = $this->temporary->withRatingTable('vogoo_visitor_prediction_input_', $products,
				fn(string $table) => $this->detailedPrediction($productId, 1, $resolvedCategory,
					"JOIN {$table} r ON r.product_id = l.item_id2", []));
	
			return $prediction?->predictedRating;
		}
	
		/**
		 * Predict ratings for all unrated items for an anonymous visitor using Slope One, sorted by predicted rating descending.
		 * @param VisitorContext $visitor The visitor context holding the current session's ratings
		 * @param int $limit Maximum number of results (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, ProductRating>
		 */
		public function visitorPredictAll(VisitorContext $visitor, int $limit = 0, ?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			$products = $this->collectGenuineRatings($visitor->ratings($resolvedCategory));
	
			if (empty($products)) {
				return [];
			}
	
			$seenIds = $visitor->ratedProductIds($resolvedCategory);
			$rows = $this->temporary->withRatingTable('vogoo_visitor_prediction_input_', $products,
				fn(string $table) => $this->temporary->withIdTable('vogoo_visitor_seen_', $seenIds,
					fn(string $seenTable) => $this->unseenSlopeRows("JOIN {$table} r ON r.product_id = l.item_id1",
						"NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = l.item_id2)",
						[], $resolvedCategory, '')));
	
			return $this->rankPredictions($rows, $limit);
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
	
			return $this->detailedPrediction($productId, $minSupport, $resolvedCategory,
				'JOIN vogoo_ratings r ON r.product_id = l.item_id2 AND
					r.category = l.category AND
					r.member_id = :member AND
					r.rating >= 0.0',
				['member' => $memberId]);
		}
	
		/**
		 * Predict all unseen member ratings with directed-pair support.
		 * @param int $memberId Member ID
		 * @param int $limit Maximum results, or zero for all
		 * @param int $minSupport Minimum summed pair support
		 * @param int|null $category Category override
		 * @return array<int, PredictionResult>
		 * @throws \InvalidArgumentException When the minimum support is not positive
		 */
		public function memberPredictAllDetailed(int $memberId, int $limit = 0,
			int $minSupport = 1, ?int $category = null): array {
			$this->validateSupport($minSupport);
			$resolvedCategory = $this->config->resolveCategory($category);
	
			return $this->unseenDetailedPredictions(
				'JOIN vogoo_ratings r ON r.product_id = l.item_id1 AND
					r.category = l.category AND
					r.member_id = :member AND
					r.rating >= 0.0',
				'NOT EXISTS (SELECT 1 FROM vogoo_ratings seen
					WHERE seen.member_id = :seen_member AND
						seen.category = :seen_category AND
						seen.product_id = l.item_id2
				)',
				[
					'member' => $memberId,
					'seen_member' => $memberId,
					'seen_category' => $resolvedCategory,
				],
				$limit, $resolvedCategory, $minSupport);
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
			$ratings = $this->collectGenuineRatings($visitor->ratings($resolvedCategory));
			
			if ($ratings === []) {
				return null;
			}
			
			return $this->temporary->withRatingTable('vogoo_visitor_prediction_input_', $ratings,
				fn(string $table) => $this->detailedPrediction($productId, $minSupport, $resolvedCategory,
					"JOIN {$table} r ON r.product_id = l.item_id2", []));
		}
	
		/**
		 * Predict unseen visitor ratings with a batched temporary input table.
		 * @param VisitorContext $visitor Visitor ratings
		 * @param int $limit Maximum results, or zero for all
		 * @param int $minSupport Minimum summed pair support
		 * @param int|null $category Category override
		 * @return array<int, PredictionResult>
		 * @throws \InvalidArgumentException When the minimum support is not positive
		 */
		public function visitorPredictAllDetailed(VisitorContext $visitor, int $limit = 0,
			int $minSupport = 1, ?int $category = null): array {
			$this->validateSupport($minSupport);
			$resolvedCategory = $this->config->resolveCategory($category);
			$ratings = $this->collectGenuineRatings($visitor->ratings($resolvedCategory));
			
			if ($ratings === []) {
				return [];
			}
			
			$seenIds = $visitor->ratedProductIds($resolvedCategory);
	
			return $this->temporary->withRatingTable('vogoo_visitor_prediction_input_', $ratings,
				fn(string $table) => $this->temporary->withIdTable('vogoo_visitor_seen_', $seenIds,
					fn(string $seenTable) => $this->unseenDetailedPredictions(
						"JOIN {$table} r ON r.product_id = l.item_id1",
						"NOT EXISTS (SELECT 1 FROM {$seenTable} s WHERE s.product_id = l.item_id2)",
						[], $limit, $resolvedCategory, $minSupport)));
		}
	
		/**
		 * Predict one product's rating from the rated items it is linked to, or null when support is below the minimum.
		 * @param int $productId Candidate product ID
		 * @param int $minSupport Minimum summed pair support
		 * @param int $category Already-resolved category
		 * @param string $ratingJoin Join from vogoo_links to the rating source, aliased r on l.item_id2
		 * @param array<string, int|float> $params Parameters referenced by the join
		 * @return PredictionResult|null
		 */
		private function detailedPrediction(int $productId, int $minSupport, int $category,
			string $ratingJoin, array $params): ?PredictionResult {
			$row = $this->connection->execute("
				SELECT
					SUM(l.slope_count) AS support,
					SUM(r.rating * l.slope_count - l.diff_slope) AS numerator
				FROM vogoo_links l
				{$ratingJoin}
				WHERE l.item_id1 = :product AND
				      l.category = :category AND
				      l.slope_count > 0
			", $params + ['product' => $productId, 'category' => $category])->fetchAssoc();
	
			if (!isset($row['support'], $row['numerator'])) {
				return null;
			}
	
			$support = (int)$row['support'];
	
			if ($support < $minSupport) {
				return null;
			}
	
			return new PredictionResult($productId, Results::clampRating((float)$row['numerator'] / $support), $support);
		}
	
		/**
		 * Predict every unseen candidate's rating from the rated items it is linked to, best prediction first.
		 * @param string $ratingJoin Join from vogoo_links to the rating source, aliased r on l.item_id1
		 * @param string $seenPredicate Predicate excluding candidates already rated, referring to l.item_id2
		 * @param array<string, int|float> $params Parameters referenced by the join and predicate
		 * @param int $limit Maximum results, or zero for all
		 * @param int $category Already-resolved category
		 * @param int $minSupport Minimum summed pair support
		 * @return array<int, PredictionResult>
		 */
		private function unseenDetailedPredictions(string $ratingJoin, string $seenPredicate, array $params,
			int $limit, int $category, int $minSupport): array {
			$rows = $this->unseenSlopeRows($ratingJoin, $seenPredicate, $params + ['min_support' => $minSupport],
				$category, ' HAVING support >= :min_support ORDER BY predicted DESC, support DESC, l.item_id2 ASC'
				. Results::limitSql($limit));
	
			return $this->detailedPredictions($rows, $limit);
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
		 * Convert aggregated rows into predictions, ordering by rating then support.
		 * @param array<mixed> $rows Aggregated rows
		 * @param int $limit Maximum results
		 * @return array<int, PredictionResult>
		 * @throws \UnexpectedValueException When a row is missing a required field
		 */
		private function detailedPredictions(array $rows, int $limit): array {
				$results = [];
			
			foreach ($rows as $row) {
				$fields = self::predictionFields($row);

				if ($fields === null) {
					throw new \UnexpectedValueException('Prediction row must contain numeric item_id2, support, and numerator values.');
				}

				[$id, $support, $numerator] = $fields;

				$results[] = new PredictionResult($id, Results::clampRating($numerator / $support), $support);
			}
			
			usort($results, fn($a, $b) => ($b->predictedRating <=> $a->predictedRating)
				?: ($b->supportCount <=> $a->supportCount) ?: ($a->itemId <=> $b->itemId));
			return Results::limit($results, $limit);
		}

		/**
		 * Read the item ID, support and numerator from a prediction row.
		 * @param mixed $row Prediction row
		 * @return array{int, int, float}|null [item_id2, support, numerator], or null when the row is unusable
		 */
		private static function predictionFields(mixed $row): ?array {
			if (
				!is_array($row) ||
				!isset($row['item_id2'], $row['support'], $row['numerator']) ||
				!is_numeric($row['item_id2']) ||
				!is_numeric($row['support']) ||
				!is_numeric($row['numerator'])
			) {
				return null;
			}

			return [(int)$row['item_id2'], (int)$row['support'], (float)$row['numerator']];
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
		 * Return the grouped support and numerator of every unseen candidate linked to the rated items.
		 * @param string $ratingJoin Join from vogoo_links to the rating source, aliased r on l.item_id1
		 * @param string $seenPredicate Predicate excluding candidates already rated, referring to l.item_id2
		 * @param array<string, int|float> $params Parameters referenced by the join and predicate
		 * @param int $category Already-resolved category
		 * @param string $tail Clause appended after GROUP BY, such as HAVING or ORDER BY
		 * @return array<int, array<string, mixed>> Rows with item_id2, support and numerator
		 */
		private function unseenSlopeRows(string $ratingJoin, string $seenPredicate, array $params,
			int $category, string $tail): array {
			$params += ['category' => $category];
			$sql = "
				SELECT
					l.item_id2,
					SUM(l.slope_count) AS support,
					SUM(r.rating * l.slope_count + l.diff_slope) AS numerator,
					LEAST(1.0, GREATEST(0.0, SUM(r.rating * l.slope_count + l.diff_slope) / SUM(l.slope_count))) AS predicted
				FROM vogoo_links l {$ratingJoin}
				WHERE l.category = :category AND
					l.slope_count > 0 AND
					{$seenPredicate}";
			$sql .= ' GROUP BY l.item_id2' . $tail;
	
			return $this->connection->execute($sql, $params)->fetchAll('assoc');
		}
	
		/**
		 * Convert grouped slope rows into clamped predictions, best first with ties broken by ascending product ID.
		 * @param array<int, array<string, mixed>> $rows Rows with item_id2, support and numerator
		 * @param int $limit Maximum number of results, or zero for all
		 * @return array<int, ProductRating>
		 */
		private function rankPredictions(array $rows, int $limit): array {
			$result = [];
	
			foreach ($rows as $row) {
				if (!is_numeric($row['item_id2']) || !is_numeric($row['support']) || !is_numeric($row['numerator'])) {
					throw new \UnexpectedValueException('Slope One row must contain numeric item_id2, support, and numerator values.');
				}
	
				$result[] = [
					'product_id' => (int)$row['item_id2'],
					'rating'     => Results::clampRating((float)$row['numerator'] / (float)$row['support']),
				];
			}
	
			usort($result, fn($a, $b) => ($b['rating'] <=> $a['rating']) ?: ($a['product_id'] <=> $b['product_id']));
			return Results::limit($result, $limit);
		}
	}