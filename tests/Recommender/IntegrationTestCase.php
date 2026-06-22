<?php
	
	namespace Quellabs\Recommender\Tests;
	
	use Cake\Database\Connection;
	use PHPUnit\Framework\TestCase;
	use Quellabs\Recommender\Config\RecommendationConfig;
	
	/**
	 * Base class for integration tests that require a live database connection.
	 *
	 * Reads the connection from $GLOBALS['test_connection'] (set by bootstrap.php)
	 * and truncates both tables before each test so every test starts clean.
	 */
	abstract class IntegrationTestCase extends TestCase {
		
		protected Connection $connection;
		protected RecommendationConfig $config;
		
		protected function setUp(): void {
			$this->connection = $GLOBALS['test_connection'];
			$this->config     = new RecommendationConfig(directLinks: false, directSlope: false);
			
			$this->connection->execute('TRUNCATE TABLE vogoo_ratings');
			$this->connection->execute('TRUNCATE TABLE vogoo_links');
		}
		
		/**
		 * Insert a rating row directly, bypassing the engine (for test setup).
		 */
		protected function insertRating(int $memberId, int $productId, float $rating, int $category = 1): void {
			$this->connection->execute(
				'INSERT INTO vogoo_ratings (member_id, product_id, category, rating, ts) VALUES (:m, :p, :c, :r, NOW())',
				['m' => $memberId, 'p' => $productId, 'c' => $category, 'r' => $rating],
			);
		}
		
		/**
		 * Insert a link row directly, bypassing LinkUpdater (for test setup).
		 */
		protected function insertLink(int $itemId1, int $itemId2, int $cnt, float $diffSlope = 0.0, int $category = 1): void {
			$this->connection->execute(
				'INSERT INTO vogoo_links (item_id1, item_id2, category, cnt, diff_slope) VALUES (:i1, :i2, :c, :cnt, :d)',
				['i1' => $itemId1, 'i2' => $itemId2, 'c' => $category, 'cnt' => $cnt, 'd' => $diffSlope],
			);
		}
		
		/**
		 * Return the raw rating row for a member/product pair, or null if absent.
		 * @return array<string, mixed>|null
		 */
		protected function fetchRatingRow(int $memberId, int $productId, int $category = 1): ?array {
			$stmt = $this->connection->execute(
				'SELECT * FROM vogoo_ratings WHERE member_id = :m AND product_id = :p AND category = :c',
				['m' => $memberId, 'p' => $productId, 'c' => $category],
			);
			
			$row = $stmt->fetchAssoc();
			return $row !== [] ? $row : null;
		}
		
		/**
		 * Return the raw link row for an item pair, or null if absent.
		 * @return array<string, mixed>|null
		 */
		protected function fetchLinkRow(int $itemId1, int $itemId2, int $category = 1): ?array {
			$stmt = $this->connection->execute(
				'SELECT * FROM vogoo_links WHERE item_id1 = :i1 AND item_id2 = :i2 AND category = :c',
				['i1' => $itemId1, 'i2' => $itemId2, 'c' => $category],
			);
			
			$row = $stmt->fetchAssoc();
			return $row !== [] ? $row : null;
		}
	}