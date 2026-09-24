<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Datetime columns compared with date strings and routine variables, and written from date arithmetic, against the suite's MySQL connection.
	 */
	class DateTimeComparisonRoundTripTest extends TestCase {

		/** Title of the post this test inserts, created 2025-06-01 12:00 */
		private string $tag;

		/** Id of that post */
		private int $postId;

		/**
		 * @return EntityManager The suite's shared entity manager
		 */
		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		/**
		 * @return void
		 */
		protected function setUp(): void {
			$this->tag = 'datetime_test_' . getmypid();

			self::em()->getConnection()->execute(
				"INSERT INTO posts (title, content, published, created_at, test_enum, test_json, user_id) VALUES (:title, '', 0, '2025-06-01 12:00:00', 'pending', '{}', 1)",
				['title' => $this->tag]
			);

			$statement = self::em()->getConnection()->execute('SELECT id FROM posts WHERE title = :title', ['title' => $this->tag]);
			self::assertNotNull($statement);
			$row = $statement->fetch('assoc');
			self::assertIsArray($row);
			$this->postId = (int)$row['id'];
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			self::em()->executeQuery("destroy function {$this->tag} if exists");
			self::em()->getConnection()->execute('DELETE FROM posts WHERE title = :title', ['title' => $this->tag]);
		}

		/**
		 * @param string $condition QUEL condition over range `p`
		 * @return int Number of this test's posts matching it
		 */
		private function countPosts(string $condition): int {
			$result = self::em()->executeQuery("range of p is PostEntity retrieve (n = count(p.id)) where p.title = :title and {$condition}", ['title' => $this->tag]);
			self::assertNotNull($result);
			return (int)$result[0]['n'];
		}

		/**
		 * @return void
		 */
		public function testRetrieveComparesADateString(): void {
			self::assertSame(0, $this->countPosts('p.createdAt > "2099-01-01"'));
			self::assertSame(1, $this->countPosts('p.createdAt > "2025-01-01"'));
		}

		/**
		 * A JSON source makes the append run through the planner, which binds the fetched values.
		 * @return void
		 */
		public function testPlannerAppendStoresDateArithmeticAsADatetime(): void {
			$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->tag . '.json';
			file_put_contents($path, json_encode([['id' => $this->postId]]));

			try {
				self::em()->executeQuery("
					range of dst is PostEntity
					range of p is PostEntity
					range of j is json_source('" . addslashes($path) . "')
					append to dst (title, content, published, TestEnum, testJSON, createdAt, userId)
					retrieve (p.title, p.content, p.published, p.TestEnum, p.testJSON, at = p.createdAt + date(\"1 day\"), p.userId) where p.id = j.id
				");
			} finally {
				@unlink($path);
			}

			$statement = self::em()->getConnection()->execute('SELECT created_at FROM posts WHERE title = :title AND id <> :id', ['title' => $this->tag, 'id' => $this->postId]);
			self::assertNotNull($statement);
			$row = $statement->fetch('assoc');
			self::assertIsArray($row);
			self::assertSame('2025-06-02 12:00:00', $row['created_at']);
		}

		/**
		 * @return void
		 */
		public function testRoutineComparesADatetimeParameterWithAColumn(): void {
			self::em()->executeQuery("
				define function {$this->tag} (integer postId, datetime since) integer {
					integer total = 0
					range of p is PostEntity
					cursor posts = retrieve (p.id) where p.id = postId and p.createdAt > since
					foreach posts {
						total = total + 1
					}
					return total
				}
			");

			self::assertSame(0, $this->call('2099-01-01 00:00:00'));
			self::assertSame(1, $this->call('2025-01-01 00:00:00'));
		}

		/**
		 * @param string $since Datetime argument for the deployed function
		 * @return int The function's result
		 */
		private function call(string $since): int {
			$result = self::em()->executeQuery("{$this->tag}({$this->postId}, \"{$since}\")");
			self::assertNotNull($result);
			return (int)iterator_to_array($result)[0][$this->tag];
		}

		/**
		 * @return void
		 */
		public function testReplaceStoresDateArithmeticAsADatetime(): void {
			self::em()->executeQuery('range of p is PostEntity replace p (createdAt = p.createdAt + date("1 day")) where p.id = :id', ['id' => $this->postId]);

			$statement = self::em()->getConnection()->execute('SELECT created_at FROM posts WHERE id = :id', ['id' => $this->postId]);
			self::assertNotNull($statement);
			$row = $statement->fetch('assoc');
			self::assertIsArray($row);
			self::assertSame('2025-06-02 12:00:00', $row['created_at']);
		}

		/**
		 * @return void
		 */
		public function testRoutineStoresAndReturnsDateArithmeticAsADatetime(): void {
			self::em()->executeQuery("
				define function {$this->tag} (datetime since) datetime {
					datetime next = since + date(\"1 day\")
					return next + date(\"1 hour\")
				}
			");

			$result = self::em()->executeQuery("{$this->tag}(\"2025-01-01 00:00:00\")");
			self::assertNotNull($result);
			$value = iterator_to_array($result)[0][$this->tag];
			self::assertInstanceOf(\DateTime::class, $value);
			self::assertSame('2025-01-02 01:00:00', $value->format('Y-m-d H:i:s'));
		}
	}
