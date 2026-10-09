<?php
	
	namespace Quellabs\Recommender\Internal;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Query\Results;
	
	
	use Quellabs\Recommender\Neighbour;
	use Quellabs\Recommender\RecommendationEngine;
	use Quellabs\Recommender\RatingKind;
	
	/**
	 * User-based collaborative filtering: member similarity scoring and
	 * neighbour-based recommendations.
	 *
	 * The similarity algorithm is the original Vogoo weighted mean-squared-error
	 * approach, which penalises pairs with few common ratings relative to the
	 * member's total ratings.
	 *
	 * Methods throw on database failure.
	 *
	 * @phpstan-type ItemScore array{itemId: int, score: float}
	 */
	readonly class UserSimilarity {
		
		/** @var Connection Database connection */
		private Connection $connection;
		
		/** @var RecommendationConfig Recommendation settings */
		private RecommendationConfig $config;
		
		/** @var RecommendationEngine Engine used for rating lookups */
		private RecommendationEngine $engine;
		
		
		/**
		 * Build the similarity service.
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 * @param RecommendationEngine $engine The recommendation engine used for rating lookups
		 */
		public function __construct(Connection $connection, RecommendationConfig $config, RecommendationEngine $engine) {
			$this->connection = $connection;
			$this->config = $config;
			$this->engine = $engine;
		}
		
		/**
		 * Return a similarity score in [0, 100] between two members.
		 * 0 means no overlap or completely different taste; 100 means identical.
		 * @param int $memberId1 The first member ID
		 * @param int $memberId2 The second member, compared against the first
		 * @param int|null $category Defaults to configured default
		 * @return int Similarity score in [0, 100]
		 */
		public function memberSimilarity(int $memberId1, int $memberId2, ?int $category = null): int {
			$resolvedCategory = $this->config->resolveCategory($category);
			$ownRatingCount = $this->engine->memberNumRatings($memberId1, RatingKind::Genuine, $resolvedCategory);
			
			if ($ownRatingCount === 0) {
				return 0;
			}
			
			$row = $this->connection->execute('
				SELECT
					COUNT(r2.`product_id`) AS c2,
					SUM((r2.`rating` - r1.`rating`) * (r2.`rating` - r1.`rating`)) AS s
				FROM `vogoo_ratings` r1
				INNER JOIN `vogoo_ratings` r2 ON r2.`member_id` = :member_id2 AND
					r2.`product_id` = r1.`product_id` AND
					r2.`category` = r1.`category` AND
					r2.`rating` >= 0.0
				WHERE r1.`member_id` = :member_id1 AND
					r1.`category` = :category AND
					r1.`rating` >= 0.0
			', [
				'member_id1' => $memberId1,
				'member_id2' => $memberId2,
				'category'   => $resolvedCategory,
			])->fetchAssoc();
			
			$commonCount = (int)$row['c2'];
			
			if ($commonCount === 0) {
				return 0;
			}
			
			return $this->scoreSimilarity($commonCount, (float)$row['s'], $ownRatingCount);
		}
		
		/**
		 * Return neighbours of a member, sorted by similarity descending and filtered by a minimum similarity.
		 * This calculates similarity for every candidate neighbour. For large member sets, pre-compute and cache the lists.
		 * @param int $memberId The member ID
		 * @param int $minSimilarity Minimum score to include (0 to 100)
		 * @param int $limit Maximum number of neighbours (0 = unlimited)
		 * @param int|null $category Defaults to configured default
		 * @return array<int, Neighbour>
		 * @throws \UnexpectedValueException When a neighbour row is malformed
		 */
		public function memberNeighbours(int $memberId, int $minSimilarity = 1, int $limit = 0, ?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			$minSimilarity = max(0, min(100, $minSimilarity));
			$limit = max(0, $limit);
			
			$ownRatingCount = $this->engine->memberNumRatings($memberId, RatingKind::Genuine, $resolvedCategory);
			
			if ($ownRatingCount === 0) {
				return [];
			}
			
			$neighbours = [];
			
			foreach ($this->neighbourRows($memberId, $resolvedCategory) as $row) {
				if (!is_numeric($row['member_id']) || !is_numeric($row['common_count']) || !is_numeric($row['squared_diff'])) {
					throw new \UnexpectedValueException('Neighbour row must contain numeric member_id, common_count and squared_diff.');
				}
				
				$otherId = (int)$row['member_id'];
				$similarity = $this->scoreSimilarity((int)$row['common_count'], (float)$row['squared_diff'], $ownRatingCount);
				
				if ($similarity === 0 || $similarity < $minSimilarity) {
					continue;
				}
				
				$neighbours[] = new Neighbour($otherId, $similarity);
			}
			
			usort($neighbours, function ($a, $b): int {
				return ($b->similarity <=> $a->similarity) ?: ($a->memberId <=> $b->memberId);
			});
			return Results::limit($neighbours, $limit);
		}
		
		/**
		 * Return the common-rating count and squared rating difference between a member and every other member who rated the same products.
		 * @param int $memberId The member ID
		 * @param int $category Already-resolved category
		 * @return array<int, array<string, mixed>> Rows with member_id, common_count and squared_diff
		 */
		private function neighbourRows(int $memberId, int $category): array {
			return $this->connection->execute('
				SELECT
					r2.`member_id`,
					COUNT(*) AS common_count,
					SUM((r2.`rating` - r1.`rating`) * (r2.`rating` - r1.`rating`)) AS squared_diff
				FROM `vogoo_ratings` r1
				INNER JOIN `vogoo_ratings` r2 ON r2.`product_id` = r1.`product_id` AND
					r2.`category` = r1.`category` AND
					r2.`member_id` <> :member_id
				WHERE r1.`member_id` = :member_id2 AND
					r1.`category` = :category AND
					r1.`rating` >= 0.0 AND
					r2.`rating` >= 0.0
				GROUP BY r2.`member_id`
			', [
				'member_id'  => $memberId,
				'member_id2' => $memberId,
				'category'   => $category,
			])->fetchAll('assoc');
		}
		
		/**
		 * Convert the raw sum of squared rating differences into a 0 to 100 similarity score.
		 * Applies the Vogoo confidence penalty when the number of common ratings is small relative to the member's total.
		 * @param int $nrCommonRatings Number of products both members rated
		 * @param float $sumSquaredDiff Sum of squared rating differences over those products
		 * @param int $ownRatingCount Total genuine ratings of the first member
		 * @return int Similarity score in [0, 100]
		 */
		private function scoreSimilarity(int $nrCommonRatings, float $sumSquaredDiff, int $ownRatingCount): int {
			$cost = $this->config->cost();
			$spread = $sumSquaredDiff * $cost * $cost * 20.0;
			$spreadPerCommonRating = $spread / $nrCommonRatings;
			
			if ($spreadPerCommonRating > 100) {
				return 0;
			}
			
			$thresholdNr = $this->config->thresholdNrCommonRatings();
			$thresholdMult = $this->config->thresholdMult();
			
			// Enough common ratings: return the direct score
			if ($nrCommonRatings > $thresholdNr || ($nrCommonRatings * $thresholdMult) >= $ownRatingCount) {
				return 100 - (int)$spreadPerCommonRating;
			}
			
			// Fewer common ratings: apply a confidence penalty
			if ($ownRatingCount < ($thresholdNr * $thresholdMult)) {
				$confidenceRatio = ($nrCommonRatings * $thresholdMult) / $ownRatingCount;
			} else {
				$confidenceRatio = ($nrCommonRatings * $thresholdMult) / ($thresholdNr * $thresholdMult);
			}
			
			$squaredConfidence = $confidenceRatio * $confidenceRatio;
			
			return (int)((100.0 - $spreadPerCommonRating) * (0.1 + 0.9 * $squaredConfidence));
		}
		
	}
