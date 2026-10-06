<?php
	
	namespace Quellabs\Recommender;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Internal\Links\LinkUpdater;
	use Quellabs\Recommender\Internal\RatingRule;
	
	/**
	 * Core ratings engine. Reads and writes member ratings, and maintains the
	 * vogoo_links table via LinkUpdater when incremental updates are enabled.
	 *
	 * Ratings are normalised floats in [0.0, 1.0]. The special value
	 * RecommendationConfig::NOT_INTERESTED (-1.0) marks explicit disinterest.
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
		
		/**
		 * Build the engine and its collaborators.
		 * @param Connection $connection The CakePHP database connection
		 * @param RecommendationConfig $config The recommendation configuration
		 */
		public function __construct(Connection $connection, RecommendationConfig $config) {
			$this->connection = $connection;
			$this->config = $config;
			$this->linkUpdater = new LinkUpdater($connection, $config);
		}
		
		// ----- Members -----
		
		/**
		 * Return the number of ratings a member has given.
		 * @param int $memberId The member ID
		 * @param RatingKind $kind Which ratings to count
		 * @param int|null $category Defaults to configured default
		 * @return int Number of matching ratings
		 */
		public function memberNumRatings(int $memberId, RatingKind $kind = RatingKind::Genuine, ?int $category = null): int {
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
			
			$sql .= $this->ratingFilterSql($kind, $params);
	
			$row = $this->connection->execute($sql, $params)->fetchAssoc();
			return (int)$row['number_of_ratings'];
		}
		
		/**
		 * Return the average genuine rating a member has given, or null when the member has none.
		 * @param int $memberId The member ID
		 * @param int|null $category Defaults to configured default
		 * @return float|null Average rating, or null when the member has none
		 */
		public function memberAverageRating(int $memberId, ?int $category = null): ?float {
			$resolvedCategory = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute('
				SELECT
					AVG(`rating`) AS average
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `category` = :category AND
				      `rating` >= 0.0
			', [
				'member_id' => $memberId,
				'category'  => $resolvedCategory,
			])->fetchAssoc();
			
			return $row['average'] !== null ? (float)$row['average'] : null;
		}
		
		/**
		 * Return all ratings for a member as ['product_id' => int, 'rating' => float, 'ts' => string] rows.
		 * @param int $memberId The member ID
		 * @param RatingKind $kind Which ratings to return
		 * @param RatingOrder|null $order Sort order, or null for storage order
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{product_id: int, rating: float, ts: string}>
		 */
		public function memberRatings(int $memberId, RatingKind $kind = RatingKind::Genuine, ?RatingOrder $order = null,
			?int $category = null): array {
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
			
			$sql .= $this->ratingFilterSql($kind, $params);
			$sql .= $this->orderSql($order);
	
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			$ratings = [];

			foreach ($rows as $row) {
				$typed = $this->typedRatingRow($row, 'product_id');
				$ratings[] = ['product_id' => $typed['id'], 'rating' => $typed['rating'], 'ts' => $typed['ts']];
			}

			return $ratings;
		}
	
		/**
		 * Delete all ratings for a member. When incremental link updates are enabled,
		 * each rating is removed via deleteRating() to keep vogoo_links consistent.
		 * @param int $memberId The member ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		public function deleteMember(int $memberId, ?int $category = null): void {
			$this->deleteRatingsWhere('member_id', $memberId, $this->config->resolveCategory($category));
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
		 * Return the average genuine rating for a product, or null when no ratings exist.
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return float|null Average rating, or null when the product has none
		 */
		public function productAverageRating(int $productId, ?int $category = null): ?float {
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
			
			return $row['average'] !== null ? (float)$row['average'] : null;
		}
		
		/**
		 * Return all ratings for a product as ['member_id' => int, 'rating' => float, 'ts' => string] rows.
		 * @param int $productId The product ID
		 * @param RatingOrder|null $order Sort order, or null for storage order
		 * @param int|null $category Defaults to configured default
		 * @return array<int, array{member_id: int, rating: float, ts: string}>
		 */
		public function productRatings(int $productId, ?RatingOrder $order = null, ?int $category = null): array {
			$resolvedCategory = $this->config->resolveCategory($category);
			
			$sql = '
				SELECT
					`member_id`,
					`rating`,
					`ts`
				FROM `vogoo_ratings`
				WHERE `product_id` = :product_id AND
				      `rating` >= 0.0 AND
				      `category` = :category
			';
			
			$params = [
				'product_id' => $productId,
				'category'   => $resolvedCategory,
			];
			
			$sql .= $this->orderSql($order);
	
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			$ratings = [];

			foreach ($rows as $row) {
				$typed = $this->typedRatingRow($row, 'member_id');
				$ratings[] = ['member_id' => $typed['id'], 'rating' => $typed['rating'], 'ts' => $typed['ts']];
			}

			return $ratings;
		}
	
		/**
		 * Delete all ratings for a product. When incremental link updates are enabled,
		 * each rating is removed via deleteRating() to keep vogoo_links consistent.
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		public function deleteProduct(int $productId, ?int $category = null): void {
			$this->deleteRatingsWhere('product_id', $productId, $this->config->resolveCategory($category));
		}
		
		// ----- Combined -----
		
		/**
		 * Return the rating and timestamp for a member and product pair, or null when none exists.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param RatingKind $kind Which ratings to match
		 * @param int|null $category Defaults to configured default
		 * @return array{rating: float, ts: string}|null
		 */
		public function memberRating(int $memberId, int $productId, RatingKind $kind = RatingKind::Genuine, ?int $category = null): ?array {
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
			
			$sql .= $this->ratingFilterSql($kind, $params);
			
			$row = $this->connection->execute($sql, $params)->fetchAssoc();
			
			if (empty($row)) {
				return null;
			}

			return ['rating' => (float)$row['rating'], 'ts' => $row['ts']];
		}
		
		/**
		 * Set or update a rating for a member and product pair, with incremental link and slope updates when enabled.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param float $rating Must be in [0.0, 1.0] or equal RecommendationConfig::NOT_INTERESTED
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \InvalidArgumentException When an ID is negative or the rating is not in [0.0, 1.0] or the not-interested value
		 */
		public function setRating(int $memberId, int $productId, float $rating, ?int $category = null): void {
			$resolvedCategory = $this->config->resolveCategory($category);

			if ($memberId < 0 || $productId < 0 || !RatingRule::isValid($rating, RecommendationConfig::NOT_INTERESTED)) {
				throw new \InvalidArgumentException('Member and product IDs must not be negative, and the rating must be in [0.0, 1.0] or the not-interested value.');
			}

			// One transaction keeps vogoo_links consistent with vogoo_ratings if a statement fails.
			$this->connection->transactional(function () use ($memberId, $productId, $resolvedCategory, $rating): void {
				$previous = $this->fetchExistingRating($memberId, $productId, $resolvedCategory);

				// -1.0 marks "no previous rating" for the link and slope updates
				$this->triggerIncrementalUpdates($memberId, $productId, $resolvedCategory, $rating, $previous ?? -1.0);

				if ($previous !== null) {
					$this->updateRatingRow($memberId, $productId, $resolvedCategory, $rating);
				} else {
					$this->insertRatingRow($memberId, $productId, $resolvedCategory, $rating);
				}
			});
		}
		
		/**
		 * Record a purchase as a rating of 1.0.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		public function recordPurchase(int $memberId, int $productId, ?int $category = null): void {
			$this->setRating($memberId, $productId, 1.0, $category);
		}

		/**
		 * Record a click as a rating of 0.7, or raise an existing rating by 0.01 up to 1.0.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		public function recordClick(int $memberId, int $productId, ?int $category = null): void {
			$resolvedCategory = $this->config->resolveCategory($category);
			$existing = $this->memberRating($memberId, $productId, RatingKind::Genuine, $resolvedCategory);

			if ($existing === null) {
				$this->setRating($memberId, $productId, 0.7, $resolvedCategory);
			} elseif ($existing['rating'] < 1.0) {
				$this->setRating($memberId, $productId, min(1.0, $existing['rating'] + 0.01), $resolvedCategory);
			}
		}

		/**
		 * Mark a product as not interested for a member.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \InvalidArgumentException When an ID is negative
		 */
		public function setNotInterested(int $memberId, int $productId, ?int $category = null): void {
			$this->setRating($memberId, $productId, RecommendationConfig::NOT_INTERESTED, $category);
		}
		
		/**
		 * Delete a single member and product rating, with incremental link and slope cleanup when enabled.
		 * @param int $memberId The member ID
		 * @param int $productId The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \Exception When a database statement fails
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
		 * Validate one raw vogoo_ratings row and return its ID, rating and timestamp in typed form.
		 * @param array<string, mixed> $row Row selecting the ID column, rating and ts
		 * @param string $idColumn Name of the ID column, product_id or member_id
		 * @return array{id: int, rating: float, ts: string} Typed ID, rating and timestamp
		 * @throws \UnexpectedValueException When the ID or rating is not numeric, or the timestamp is not a string
		 */
		private function typedRatingRow(array $row, string $idColumn): array {
			if (!is_numeric($row[$idColumn]) || !is_numeric($row['rating']) || !is_string($row['ts'])) {
				throw new \UnexpectedValueException("Rating row must have a numeric {$idColumn} and rating and a string ts.");
			}

			return ['id' => (int)$row[$idColumn], 'rating' => (float)$row['rating'], 'ts' => $row['ts']];
		}

		/**
		 * Build the rating filter for a count or listing query, binding the sentinel when filtering on it.
		 * @param RatingKind $kind Which ratings to match
		 * @param array<string, mixed> $params Bound parameters, extended in place
		 * @return string Filter clause with a leading space, or an empty string
		 */
		private function ratingFilterSql(RatingKind $kind, array &$params): string {
			if ($kind === RatingKind::NotInterested) {
				$params['not_interested'] = RecommendationConfig::NOT_INTERESTED;
				return ' AND `rating` = :not_interested';
			}

			return $kind === RatingKind::All ? '' : ' AND `rating` >= 0.0';
		}

		/**
		 * Build the ORDER BY clause for a ratings listing.
		 * @param RatingOrder|null $order Sort order, or null when no order is requested
		 * @return string Clause with a leading space, or an empty string when no order is requested
		 */
		private function orderSql(?RatingOrder $order): string {
			return match ($order) {
				null => '',
				RatingOrder::DateAscending => ' ORDER BY `ts` ASC',
				RatingOrder::DateDescending => ' ORDER BY `ts` DESC',
				RatingOrder::RatingAscending => ' ORDER BY `rating` ASC',
				RatingOrder::RatingDescending => ' ORDER BY `rating` DESC',
			};
		}

		/**
		 * Delete the ratings of one member or one product in a category.
		 * With incremental link updates enabled, each rating is removed via deleteRating().
		 * @param string $column Ratings column holding the ID, either member_id or product_id
		 * @param int $id Member or product ID
		 * @param int $category Already-resolved category
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		private function deleteRatingsWhere(string $column, int $id, int $category): void {
			if (!$this->config->isDirectLinks() && !$this->config->isDirectSlope()) {
				$this->connection->execute("
					DELETE FROM `vogoo_ratings`
					WHERE `{$column}` = :id AND
						`category` = :category
				", [
					'id'       => $id,
					'category' => $category,
				]);
				return;
			}
	
			$otherColumn = $column === 'member_id' ? 'product_id' : 'member_id';
			$rows = $this->connection->execute("
				SELECT
					`{$otherColumn}`
				FROM `vogoo_ratings`
				WHERE `{$column}` = :id AND
				      `category` = :category
			", [
				'id'       => $id,
				'category' => $category,
			])->fetchAll('assoc');
	
			foreach ($rows as $row) {
				$other = (int)$row[$otherColumn];
	
				if ($column === 'member_id') {
					$this->deleteRating($id, $other, $category);
				} else {
					$this->deleteRating($other, $id, $category);
				}
			}
		}
	
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
				SELECT
					`rating`
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
		 * @throws \Exception When a database statement fails
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
