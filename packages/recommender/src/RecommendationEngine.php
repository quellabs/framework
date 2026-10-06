<?php
	
	namespace Quellabs\Recommender;
	
	use Cake\Database\Connection;
	use Quellabs\Recommender\Config\RecommendationConfig;
	use Quellabs\Recommender\Evaluation\EvaluationRecorder;
	use Quellabs\Recommender\Internal\Identifier;
	use Quellabs\Recommender\Internal\ImplicitRating;
	use Quellabs\Recommender\MemberId;
	use Quellabs\Recommender\ProductId;
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
		 * @param int $member The member ID
		 * @param RatingKind $kind Which ratings to count
		 * @param int|null $category Defaults to configured default
		 * @return int Number of matching ratings
		 * @throws \InvalidArgumentException When the member ID is outside the unsigned 32-bit range
		 */
		public function memberNumRatings(int $member, RatingKind $kind = RatingKind::Genuine, ?int $category = null): int {
			Identifier::assertId($member, 'Member ID');
			$resolvedCategory = $this->config->resolveCategory($category);
			
			$sql = '
				SELECT
					COUNT(*) AS number_of_ratings
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `category` = :category
			';
			
			$params = [
				'member_id' => $member,
				'category'  => $resolvedCategory,
			];
			
			$sql .= $this->ratingFilterSql($kind, $params);
	
			$row = $this->connection->execute($sql, $params)->fetchAssoc();
			return (int)$row['number_of_ratings'];
		}
		
		/**
		 * Return the average genuine rating a member has given, or null when the member has none.
		 * @param int $member The member ID
		 * @param int|null $category Defaults to configured default
		 * @return float|null Average rating, or null when the member has none
		 * @throws \InvalidArgumentException When the member ID is outside the unsigned 32-bit range
		 */
		public function memberAverageRating(int $member, ?int $category = null): ?float {
			Identifier::assertId($member, 'Member ID');
			$resolvedCategory = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute('
				SELECT
					AVG(`rating`) AS average
				FROM `vogoo_ratings`
				WHERE `member_id` = :member_id AND
				      `category` = :category AND
				      `rating` >= 0.0
			', [
				'member_id' => $member,
				'category'  => $resolvedCategory,
			])->fetchAssoc();
			
			return $row['average'] !== null ? (float)$row['average'] : null;
		}
		
		/**
		 * Return all ratings a member has given.
		 * @param int $member The member ID
		 * @param RatingKind $kind Which ratings to return
		 * @param RatingOrder|null $order Sort order, or null for storage order
		 * @param int|null $category Defaults to configured default
		 * @return array<int, Rating>
		 * @throws \InvalidArgumentException When the member ID is outside the unsigned 32-bit range
		 */
		public function memberRatings(int $member, RatingKind $kind = RatingKind::Genuine, ?RatingOrder $order = null,
			?int $category = null): array {
			Identifier::assertId($member, 'Member ID');
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
				'member_id' => $member,
				'category'  => $resolvedCategory,
			];
			
			$sql .= $this->ratingFilterSql($kind, $params);
			$sql .= $this->orderSql($order);
	
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			$ratings = [];

			foreach ($rows as $row) {
				$typed = $this->typedRatingRow($row, 'product_id');
				$ratings[] = new Rating($member, $typed['id'], $typed['rating'], $typed['ts']);
			}

			return $ratings;
		}
	
		/**
		 * Delete a member's ratings in one category. When incremental link updates are enabled,
		 * each rating is removed via deleteRating() to keep vogoo_links consistent.
		 * @param int $member The member ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \InvalidArgumentException When the member ID is outside the unsigned 32-bit range
		 * @throws \Exception When a database statement fails
		 */
		public function deleteMember(int $member, ?int $category = null): void {
			Identifier::assertId($member, 'Member ID');
			$this->deleteRatingsWhere('member_id', $member, $this->config->resolveCategory($category));
		}

		/**
		 * Erase everything stored about a member: their ratings in every category, and their evaluation
		 * history when a recorder is given. Pass null when the evaluation tables are not installed.
		 * @param int $member The member ID
		 * @param EvaluationRecorder|null $evaluations Recorder whose evaluation history for the member is erased, or null
		 * @return void
		 * @throws \InvalidArgumentException When the member ID is outside the unsigned 32-bit range
		 * @throws \Exception When a database statement fails
		 */
		public function deleteMemberData(int $member, ?EvaluationRecorder $evaluations = null): void {
			Identifier::assertId($member, 'Member ID');
			$evaluations?->deleteMemberEvaluations($member);
			$this->deleteRatingsWhere('member_id', $member, null);
		}
		
		// ----- Products -----
		
		/**
		 * Return the number of genuine ratings a product has received.
		 * @param int $product The product ID
		 * @param int|null $category Defaults to configured default
		 * @return int Number of matching ratings
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public function productNumRatings(int $product, ?int $category = null): int {
			Identifier::assertId($product, 'Product ID');
			$resolvedCategory = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute('
				SELECT
					COUNT(*) AS number_of_ratings
				FROM `vogoo_ratings`
				WHERE `product_id` = :product_id AND
				      `rating` >= 0.0 AND
				      `category` = :category
			', [
				'product_id' => $product,
				'category'   => $resolvedCategory,
			])->fetchAssoc();
			
			return (int)$row['number_of_ratings'];
		}
		
		/**
		 * Return the average genuine rating for a product, or null when no ratings exist.
		 * @param int $product The product ID
		 * @param int|null $category Defaults to configured default
		 * @return float|null Average rating, or null when the product has none
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public function productAverageRating(int $product, ?int $category = null): ?float {
			Identifier::assertId($product, 'Product ID');
			$resolvedCategory = $this->config->resolveCategory($category);
			
			$row = $this->connection->execute('
				SELECT
					AVG(`rating`) AS average
				FROM `vogoo_ratings`
				WHERE `product_id` = :product_id AND
				      `category` = :category AND
				      `rating` >= 0.0
			', [
				'product_id' => $product,
				'category'   => $resolvedCategory,
			])->fetchAssoc();
			
			return $row['average'] !== null ? (float)$row['average'] : null;
		}
		
		/**
		 * Return all genuine ratings a product has received.
		 * @param int $product The product ID
		 * @param RatingOrder|null $order Sort order, or null for storage order
		 * @param int|null $category Defaults to configured default
		 * @return array<int, Rating>
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 */
		public function productRatings(int $product, ?RatingOrder $order = null, ?int $category = null): array {
			Identifier::assertId($product, 'Product ID');
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
				'product_id' => $product,
				'category'   => $resolvedCategory,
			];
			
			$sql .= $this->orderSql($order);
	
			$rows = $this->connection->execute($sql, $params)->fetchAll('assoc');
			$ratings = [];

			foreach ($rows as $row) {
				$typed = $this->typedRatingRow($row, 'member_id');
				$ratings[] = new Rating($typed['id'], $product, $typed['rating'], $typed['ts']);
			}

			return $ratings;
		}
	
		/**
		 * Delete all ratings for a product. When incremental link updates are enabled,
		 * each rating is removed via deleteRating() to keep vogoo_links consistent.
		 * @param int $product The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \InvalidArgumentException When the product ID is outside the unsigned 32-bit range
		 * @throws \Exception When a database statement fails
		 */
		public function deleteProduct(int $product, ?int $category = null): void {
			Identifier::assertId($product, 'Product ID');
			$this->deleteRatingsWhere('product_id', $product, $this->config->resolveCategory($category));
		}
		
		// ----- Combined -----
		
		/**
		 * Return the rating a member gave a product, or null when none exists.
		 * @param MemberId $member The member ID
		 * @param ProductId $product The product ID
		 * @param RatingKind $kind Which ratings to match
		 * @param int|null $category Defaults to configured default
		 * @return Rating|null
		 * @throws \InvalidArgumentException When an ID is outside the unsigned 32-bit range
		 */
		public function memberRating(MemberId $member, ProductId $product, RatingKind $kind = RatingKind::Genuine, ?int $category = null): ?Rating {
			$memberId = $member->value;
			$productId = $product->value;
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

			return new Rating($memberId, $productId, (float)$row['rating'], (string)$row['ts']);
		}
		
		/**
		 * Set or update a rating for a member and product pair, with incremental link and slope updates when enabled.
		 * @param MemberId $member The member ID
		 * @param ProductId $product The product ID
		 * @param float $rating Must be in [0.0, 1.0] or equal RecommendationConfig::NOT_INTERESTED
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \InvalidArgumentException When an ID is negative or the rating is not in [0.0, 1.0] or the not-interested value
		 */
		public function setRating(MemberId $member, ProductId $product, float $rating, ?int $category = null): void {
			$memberId = $member->value;
			$productId = $product->value;
			$resolvedCategory = $this->config->resolveCategory($category);

			if (!RatingRule::isValid($rating, RecommendationConfig::NOT_INTERESTED)) {
				throw new \InvalidArgumentException("Rating must be in [0.0, 1.0] or the not-interested value, got {$rating}.");
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
		 * @param MemberId $member The member ID
		 * @param ProductId $product The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		public function recordPurchase(MemberId $member, ProductId $product, ?int $category = null): void {
			$this->setRating($member, $product, ImplicitRating::PURCHASE, $category);
		}

		/**
		 * Record a click as a rating of 0.7, or raise an existing rating by 0.01 up to 1.0.
		 * @param MemberId $member The member ID
		 * @param ProductId $product The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		public function recordClick(MemberId $member, ProductId $product, ?int $category = null): void {
			$resolvedCategory = $this->config->resolveCategory($category);
			$existing = $this->memberRating($member, $product, RatingKind::Genuine, $resolvedCategory);

			if ($existing === null || $existing->rating < ImplicitRating::PURCHASE) {
				$this->setRating($member, $product, ImplicitRating::afterClick($existing?->rating), $resolvedCategory);
			}
		}

		/**
		 * Mark a product as not interested for a member.
		 * @param MemberId $member The member ID
		 * @param ProductId $product The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \InvalidArgumentException When an ID is negative
		 */
		public function setNotInterested(MemberId $member, ProductId $product, ?int $category = null): void {
			$this->setRating($member, $product, RecommendationConfig::NOT_INTERESTED, $category);
		}
		
		/**
		 * Delete a single member and product rating, with incremental link and slope cleanup when enabled.
		 * @param MemberId $member The member ID
		 * @param ProductId $product The product ID
		 * @param int|null $category Defaults to configured default
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		public function deleteRating(MemberId $member, ProductId $product, ?int $category = null): void {
			$memberId = $member->value;
			$productId = $product->value;
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
		 * @param int|null $category Already-resolved category, or null for every category
		 * @return void
		 * @throws \Exception When a database statement fails
		 */
		private function deleteRatingsWhere(string $column, int $id, ?int $category): void {
			$categoryClause = $category === null ? '' : ' AND `category` = :category';
			$params = $category === null ? ['id' => $id] : ['id' => $id, 'category' => $category];

			if (!$this->config->isDirectLinks() && !$this->config->isDirectSlope()) {
				$this->connection->execute("DELETE FROM `vogoo_ratings` WHERE `{$column}` = :id{$categoryClause}", $params);
				return;
			}

			$otherColumn = $column === 'member_id' ? 'product_id' : 'member_id';
			$rows = $this->connection->execute("
				SELECT
					`{$otherColumn}`,
					`category`
				FROM `vogoo_ratings`
				WHERE `{$column}` = :id{$categoryClause}
			", $params)->fetchAll('assoc');

			foreach ($rows as $row) {
				$other = (int)$row[$otherColumn];
				$rowCategory = (int)$row['category'];

				if ($column === 'member_id') {
					$this->deleteRating(new MemberId($id), new ProductId($other), $rowCategory);
				} else {
					$this->deleteRating(new MemberId($other), new ProductId($id), $rowCategory);
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
