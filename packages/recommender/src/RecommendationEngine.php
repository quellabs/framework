<?php

namespace Quellabs\Recommender;

use Cake\Database\Connection;
use Quellabs\Recommender\Config\RecommendationConfig;
use Quellabs\Recommender\Internal\Links\LinkUpdater;

/**
 * Core ratings engine. Reads and writes member ratings, and maintains the
 * vogoo_links table via LinkUpdater when incremental updates are enabled.
 *
 * Ratings are normalised floats in [0.0, 1.0]. The special value
 * RecommendationConfig::getNotInterested() (-1.0) marks explicit disinterest.
 *
 * Methods throw on database failure (CakePHP 5 execute() throws rather
 * than returning false).
 */
readonly class RecommendationEngine {
	
	/** @var Connection Database connection */
	private Connection $connection;
	
	/** @var RecommendationConfig Recommendation settings */
	private RecommendationConfig $config;
	
	/** @var LinkUpdater Maintains the vogoo_links table incrementally */
	private LinkUpdater $linkUpdater;
	
	/** @var ItemRecommender Item-based recommendations and predictions */
	private ItemRecommender $itemRecommender;
	
	/**
	 * Build the engine and its collaborators.
	 * @param Connection $connection The CakePHP database connection
	 * @param RecommendationConfig $config The recommendation configuration
	 */
	public function __construct(Connection $connection, RecommendationConfig $config) {
		$this->connection = $connection;
		$this->config = $config;
		$this->linkUpdater = new LinkUpdater($connection, $config);
		$this->itemRecommender = new ItemRecommender($connection, $config);
	}
	
	/**
	 * Return the detailed Slope One prediction for a member and product.
	 * @param int $memberId Member ID
	 * @param int $productId Candidate ID
	 * @param int $minSupport Minimum summed Slope One pair support
	 * @param int|null $category Category override
	 * @return PredictionResult|null Detailed prediction
	 */
	public function memberPredictDetailed(int $memberId, int $productId, int $minSupport = 1, ?int $category = null): ?PredictionResult {
		return $this->itemRecommender->memberPredictDetailed($memberId, $productId, $minSupport, $category);
	}
	
	/**
	 * Return detailed Slope One predictions for every unseen product of a member.
	 * @param int $memberId Member ID
	 * @param array<int> $filter Allowed IDs, or empty for all
	 * @param int $limit Maximum results, or zero for all
	 * @param int $minSupport Minimum summed Slope One pair support
	 * @param int|null $category Category override
	 * @return array<int, PredictionResult> Detailed predictions
	 */
	public function memberPredictAllDetailed(int $memberId, array $filter = [], int $limit = 0,
		int $minSupport = 1, ?int $category = null): array {
		return $this->itemRecommender->memberPredictAllDetailed($memberId, $filter, $limit, $minSupport, $category);
	}
	
	/**
	 * Return the detailed Slope One prediction for a visitor and product.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param int $productId Candidate ID
	 * @param int $minSupport Minimum summed Slope One pair support
	 * @param int|null $category Category override
	 * @return PredictionResult|null Detailed prediction
	 */
	public function visitorPredictDetailed(VisitorContext $visitor, int $productId, int $minSupport = 1,
		?int $category = null): ?PredictionResult {
		return $this->itemRecommender->visitorPredictDetailed($visitor, $productId, $minSupport, $category);
	}
	
	/**
	 * Return detailed Slope One predictions for every unseen product of a visitor.
	 * @param VisitorContext $visitor Visitor ratings
	 * @param array<int> $filter Allowed IDs, or empty for all
	 * @param int $limit Maximum results, or zero for all
	 * @param int $minSupport Minimum summed Slope One pair support
	 * @param int|null $category Category override
	 * @return array<int, PredictionResult> Detailed predictions
	 */
	public function visitorPredictAllDetailed(VisitorContext $visitor, array $filter = [], int $limit = 0,
		int $minSupport = 1, ?int $category = null): array {
		return $this->itemRecommender->visitorPredictAllDetailed($visitor, $filter, $limit, $minSupport, $category);
	}
	
	// ----- Members -----
	
	/**
	 * Return the number of ratings a member has given.
	 * @param int $memberId The member ID
	 * @param bool $realRatings When true, count genuine ratings (>= 0.0)
	 * @param bool $notInterested When true, count not-interested ratings instead
	 * @param int|null $category Defaults to configured default
	 * @return int Number of matching ratings
	 */
	public function memberNumRatings(int $memberId, bool $realRatings = true, bool $notInterested = false, ?int $category = null): int {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$sql = '
			SELECT
				COUNT(*) AS number_of_ratings
			FROM `vogoo_ratings`
			WHERE `member_id` = :member_id AND
			      `category` = :category
		';
		
		$params = [
			'member_id' => $memberId,
			'category'  => $resolvedCategory,
		];
		
		if ($realRatings) {
			if (!$notInterested) {
				$sql .= ' AND `rating` >= 0.0';
			}
		} else {
			$sql .= ' AND `rating` = :not_interested';
			$params['not_interested'] = $this->config->getNotInterested();
		}
		
		$row = $this->connection->execute($sql, $params)->fetchAssoc();
		return (int)$row['number_of_ratings'];
	}
	
	/**
	 * Return the average genuine rating a member has given, or 0.0 when the member has none.
	 * @param int $memberId The member ID
	 * @param int|null $category Defaults to configured default
	 * @return float Average rating, or 0.0 when the member has none
	 */
	public function memberAverageRating(int $memberId, ?int $category = null): float {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$row = $this->connection->execute('
			SELECT
				AVG(`rating`) AS average
			FROM `vogoo_ratings`
			WHERE `member_id` = :member_id
			AND `category` = :category
			AND `rating` >= 0.0
		', [
			'member_id' => $memberId,
			'category'  => $resolvedCategory,
		])->fetchAssoc();
		
		return $row['average'] !== null ? (float)$row['average'] : 0.0;
	}
	
	/**
	 * Return all ratings for a member as ['product_id' => int, 'rating' => float, 'ts' => string] rows.
	 * @param int $memberId The member ID
	 * @param bool $orderByDate Order by timestamp
	 * @param bool $orderByRating Order by rating value
	 * @param bool $ascending Sort direction
	 * @param bool $realRatings Include genuine ratings
	 * @param bool $notInterested Include not-interested ratings
	 * @param int|null $category Defaults to configured default
	 * @return array<int, array{product_id: int, rating: float, ts: string}>
	 */
	public function memberRatings(int $memberId, bool $orderByDate = false, bool $orderByRating = false,
		bool $ascending = true, bool $realRatings = true, bool $notInterested = false, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$sql = '
			SELECT
				`product_id`,
				`rating`,
				`ts`
			FROM `vogoo_ratings`
			WHERE `member_id` = :member_id AND
			      `category` = :category
		';
		
		$params = [
			'member_id' => $memberId,
			'category'  => $resolvedCategory,
		];
		
		if ($realRatings) {
			if (!$notInterested) {
				$sql .= ' AND `rating` >= 0.0';
			}
		} else {
			$sql .= ' AND `rating` = :not_interested';
			$params['not_interested'] = $this->config->getNotInterested();
		}
		
		if ($orderByDate || $orderByRating) {
			$sql .= ' ORDER BY ' . ($orderByDate ? '`ts`' : '`rating`');
			$sql .= $ascending ? ' ASC' : ' DESC';
		}
		
		return $this->connection->execute($sql, $params)->fetchAll('assoc');
	}
	
	/**
	 * Delete all ratings for a member. When incremental link updates are enabled,
	 * each rating is removed via deleteRating() to keep vogoo_links consistent.
	 * @param int $memberId The member ID
	 * @param int|null $category Defaults to configured default
	 * @return void
	 * @throws \Exception
	 */
	public function deleteMember(int $memberId, ?int $category = null): void {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		if ($this->config->isDirectLinks() || $this->config->isDirectSlope()) {
			$rows = $this->connection->execute('
				SELECT `product_id`
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `category` = :category
			', [
				'member_id' => $memberId,
				'category'  => $resolvedCategory,
			])->fetchAll('assoc');
			
			foreach ($rows as $row) {
				$this->deleteRating($memberId, (int)$row['product_id'], $resolvedCategory);
			}
			
			return;
		}
		
		$this->connection->execute('
			DELETE
			FROM `vogoo_ratings`
			WHERE `member_id` = :member_id AND
			      `category` = :category
		', [
			'member_id' => $memberId,
			'category'  => $resolvedCategory,
		]);
	}
	
	// ----- Products -----
	
	/**
	 * Return the number of genuine ratings a product has received.
	 * @param int $productId The product ID
	 * @param int|null $category Defaults to configured default
	 * @return int Number of matching ratings
	 */
	public function productNumRatings(int $productId, ?int $category = null): int {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$row = $this->connection->execute('
			SELECT
				COUNT(*) AS number_of_ratings
			FROM `vogoo_ratings`
			WHERE `product_id` = :product_id AND
			      `rating` >= 0.0 AND
			      `category` = :category
		', [
			'product_id' => $productId,
			'category'   => $resolvedCategory,
		])->fetchAssoc();
		
		return (int)$row['number_of_ratings'];
	}
	
	/**
	 * Return the average genuine rating for a product, or 0.0 when no ratings exist.
	 * @param int $productId The product ID
	 * @param int|null $category Defaults to configured default
	 * @return float Average rating, or 0.0 when the product has none
	 */
	public function productAverageRating(int $productId, ?int $category = null): float {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$row = $this->connection->execute('
			SELECT
				AVG(`rating`) AS average
			FROM `vogoo_ratings`
			WHERE `product_id` = :product_id AND
			      `category` = :category AND
			      `rating` >= 0.0
		', [
			'product_id' => $productId,
			'category'   => $resolvedCategory,
		])->fetchAssoc();
		
		return $row['average'] !== null ? (float)$row['average'] : 0.0;
	}
	
	/**
	 * Return all ratings for a product as ['member_id' => int, 'rating' => float, 'ts' => string] rows.
	 * @param int $productId The product ID
	 * @param bool $orderByDate Order by timestamp
	 * @param bool $orderByRating Order by rating value
	 * @param bool $ascending Sort direction
	 * @param int|null $category Defaults to configured default
	 * @return array<int, array{member_id: int, rating: float, ts: string}>
	 */
	public function productRatings(int $productId, bool $orderByDate = false, bool $orderByRating = false,
		bool $ascending = true, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$sql = '
			SELECT
				`member_id`,
				`rating`,
				`ts`
			FROM `vogoo_ratings`
			WHERE `product_id` = :product_id
			AND `rating` >= 0.0
			AND `category` = :category
		';
		
		$params = [
			'product_id' => $productId,
			'category'   => $resolvedCategory,
		];
		
		if ($orderByDate || $orderByRating) {
			$sql .= ' ORDER BY ' . ($orderByDate ? '`ts`' : '`rating`');
			$sql .= $ascending ? ' ASC' : ' DESC';
		}
		
		return $this->connection->execute($sql, $params)->fetchAll('assoc');
	}
	
	/**
	 * Delete all ratings for a product. When incremental link updates are enabled,
	 * each rating is removed via deleteRating() to keep vogoo_links consistent.
	 * @param int $productId The product ID
	 * @param int|null $category Defaults to configured default
	 * @return void
	 * @throws \Exception
	 */
	public function deleteProduct(int $productId, ?int $category = null): void {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		if ($this->config->isDirectLinks() || $this->config->isDirectSlope()) {
			$rows = $this->connection->execute('
				SELECT `member_id`
				FROM `vogoo_ratings`
				WHERE `product_id` = :product_id
				AND `category` = :category
			', [
				'product_id' => $productId,
				'category'   => $resolvedCategory,
			])->fetchAll('assoc');
			
			foreach ($rows as $row) {
				$this->deleteRating((int)$row['member_id'], $productId, $resolvedCategory);
			}
			
			return;
		}
		
		$this->connection->execute('
			DELETE
			FROM `vogoo_ratings`
			WHERE `product_id` = :product_id AND
			      `category` = :category
		', [
			'product_id' => $productId,
			'category'   => $resolvedCategory,
		]);
	}
	
	// ----- Combined -----
	
	/**
	 * Return the rating and timestamp for a member and product pair, or an empty array when none exists.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param bool $notInterested Include not-interested ratings
	 * @param int|null $category Defaults to configured default
	 * @return array{rating: float, ts: string}|array{}
	 */
	public function getRating(int $memberId, int $productId, bool $notInterested = false, ?int $category = null): array {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		$sql = '
			SELECT
				`rating`,
				`ts`
			FROM `vogoo_ratings`
			WHERE `member_id` = :member_id AND
			      `product_id` = :product_id AND
			      `category` = :category
		';
		
		$params = [
			'member_id'  => $memberId,
			'product_id' => $productId,
			'category'   => $resolvedCategory,
		];
		
		if (!$notInterested) {
			$sql .= ' AND `rating` >= 0.0';
		}
		
		$row = $this->connection->execute($sql, $params)->fetchAssoc();
		
		if (empty($row)) {
			return [];
		}
		
		return ['rating' => (float)$row['rating'], 'ts' => $row['ts']];
	}
	
	/**
	 * Set or update a rating for a member and product pair, with incremental link and slope updates when enabled.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param float $rating Must be in [0.0, 1.0] or equal getNotInterested()
	 * @param int|null $category Defaults to configured default
	 * @return bool True when the rating was written, false when the input is invalid
	 */
	public function setRating(int $memberId, int $productId, float $rating, ?int $category = null): bool {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		if ($memberId < 0 || $productId < 0 || !is_finite($rating)
			|| ($rating < 0.0 && $rating !== $this->config->getNotInterested()) || $rating > 1.0) {
			return false;
		}
		
		// One transaction keeps vogoo_links consistent with vogoo_ratings if a statement fails.
		// transactional() returns mixed, so the result is cast to satisfy the bool return type.
		return (bool)$this->connection->transactional(function () use ($memberId, $productId, $resolvedCategory, $rating): bool {
			$previous = $this->fetchExistingRating($memberId, $productId, $resolvedCategory);
			
			// -1.0 marks "no previous rating" for the link and slope updates
			$this->triggerIncrementalUpdates($memberId, $productId, $resolvedCategory, $rating, $previous ?? -1.0);
			
			return $previous !== null
				? $this->updateRatingRow($memberId, $productId, $resolvedCategory, $rating)
				: $this->insertRatingRow($memberId, $productId, $resolvedCategory, $rating);
		});
	}
	
	/**
	 * Record an implicit rating from a purchase (1.0) or a click (0.7, or increased by 0.01 when already rated below 1.0).
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param bool $purchase True for a purchase, false for a click
	 * @param int|null $category Defaults to configured default
	 * @return bool
	 * @throws \Exception
	 */
	public function automaticRating(int $memberId, int $productId, bool $purchase, ?int $category = null): bool {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		if ($purchase) {
			return $this->setRating($memberId, $productId, 1.0, $resolvedCategory);
		}
		
		$existing = $this->getRating($memberId, $productId, false, $resolvedCategory);
		
		if (empty($existing)) {
			return $this->setRating($memberId, $productId, 0.7, $resolvedCategory);
		}
		
		if ($existing['rating'] < 1.0) {
			return $this->setRating($memberId, $productId, min(1.0, $existing['rating'] + 0.01), $resolvedCategory);
		}
		
		return true;
	}
	
	/**
	 * Mark a product as not interested for a member.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param int|null $category Defaults to configured default
	 * @return bool
	 * @throws \Exception
	 */
	public function setNotInterested(int $memberId, int $productId, ?int $category = null): bool {
		return $this->setRating($memberId, $productId, $this->config->getNotInterested(), $category);
	}
	
	/**
	 * Delete a single member and product rating, with incremental link and slope cleanup when enabled.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param int|null $category Defaults to configured default
	 * @return void
	 * @throws \Exception
	 */
	public function deleteRating(int $memberId, int $productId, ?int $category = null): void {
		$resolvedCategory = $this->config->resolveCategory($category);
		
		// One transaction keeps vogoo_links consistent with vogoo_ratings if a statement fails.
		$this->connection->transactional(function () use ($memberId, $productId, $resolvedCategory): void {
			if ($this->config->isDirectLinks() || $this->config->isDirectSlope()) {
				$previous = $this->fetchExistingRating($memberId, $productId, $resolvedCategory);
				
				if ($previous !== null) {
					$this->triggerIncrementalUpdates($memberId, $productId, $resolvedCategory, -1.0, $previous);
				}
			}
			
			$this->connection->execute('
				DELETE
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `product_id` = :product_id AND
				      `category` = :category
			', [
				'member_id'  => $memberId,
				'product_id' => $productId,
				'category'   => $resolvedCategory,
			]);
		});
	}
	
	// ----- Internal helpers -----
	
	/**
	 * Return the member's current rating for a product, or null when no rating row exists.
	 * Drives the INSERT or UPDATE choice and the incremental updates.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param int $category Already-resolved category
	 * @return float|null
	 */
	private function fetchExistingRating(int $memberId, int $productId, int $category): ?float {
		$row = $this->connection->execute('
			SELECT `rating`
			FROM `vogoo_ratings`
			WHERE `member_id` = :member_id AND
			      `product_id` = :product_id AND
			      `category` = :category
		', [
			'member_id'  => $memberId,
			'product_id' => $productId,
			'category'   => $category,
		])->fetchAssoc();
		
		return !empty($row) ? (float)$row['rating'] : null;
	}
	
	/**
	 * Run the link and slope updates that are enabled in the configuration.
	 * A -1.0 value in $rating or $previous means the rating is being created or deleted respectively.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param int $category Already-resolved category
	 * @param float $rating The rating value
	 * @param float $previous The previous rating, or -1.0 when there was none
	 * @return void
	 * @throws \Exception
	 */
	private function triggerIncrementalUpdates(int $memberId, int $productId, int $category, float $rating, float $previous): void {
		if ($this->config->isDirectLinks()) {
			$this->linkUpdater->updateLinks($memberId, $productId, $category, $rating, $previous);
		}
		
		if ($this->config->isDirectSlope()) {
			$this->linkUpdater->updateSlope($memberId, $productId, $category, $rating, $previous);
		}
	}
	
	/**
	 * Update an existing rating row and refresh its timestamp.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param int $category Already-resolved category
	 * @param float $rating The rating value
	 * @return bool True when exactly one row was updated
	 */
	private function updateRatingRow(int $memberId, int $productId, int $category, float $rating): bool {
		return $this->connection->execute('
			UPDATE `vogoo_ratings`
			SET
				`rating` = :rating,
				`ts` = NOW()
			WHERE `member_id` = :member_id AND
			      `product_id` = :product_id AND
			      `category` = :category
		', [
			'rating'     => $rating,
			'member_id'  => $memberId,
			'product_id' => $productId,
			'category'   => $category,
		])->rowCount() === 1;
	}
	
	/**
	 * Insert a new rating row.
	 * @param int $memberId The member ID
	 * @param int $productId The product ID
	 * @param int $category Already-resolved category
	 * @param float $rating The rating value
	 * @return bool True when exactly one row was inserted
	 */
	private function insertRatingRow(int $memberId, int $productId, int $category, float $rating): bool {
		return $this->connection->execute('
			INSERT INTO `vogoo_ratings` (`member_id`, `product_id`, `category`, `rating`, `ts`)
			VALUES (:member_id, :product_id, :category, :rating, NOW())
		', [
			'member_id'  => $memberId,
			'product_id' => $productId,
			'category'   => $category,
			'rating'     => $rating,
		])->rowCount() === 1;
	}
}
