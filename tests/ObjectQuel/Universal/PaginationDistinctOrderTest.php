<?php

	namespace Quellabs\ObjectQuel\Tests\Universal;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Checks sorted, joined pagination in the SQLite, MySQL, and PostgreSQL suites.
	 */
	class PaginationDistinctOrderTest extends TestCase {

		/** @var list<int> Users created by this test. */
		private array $userIds = [];

		/**
		 * Return the suite's shared entity manager.
		 * @return EntityManager
		 */
		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		/**
		 * Remove test rows, including rows from an incomplete test run.
		 * @return void
		 */
		protected function tearDown(): void {
			$adapter = self::em()->getConnection();

			foreach ($this->userIds as $id) {
				$adapter->execute('DELETE FROM posts WHERE user_id = :id', ['id' => $id]);
				$adapter->execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
			}

			self::em()->getUnitOfWork()->clear();
			parent::tearDown();
		}

		/**
		 * Insert a user with a distinct test name and return its generated ID.
		 * @param string $name Username to insert
		 * @return int Generated user ID
		 */
		private function insertUser(string $name): int {
			$adapter = self::em()->getConnection();
			$adapter->execute(
				'INSERT INTO users (username, password, banned) VALUES (:name, :password, FALSE)',
				['name' => $name, 'password' => 'test']
			);

			$row = $adapter->execute('SELECT id FROM users WHERE username = :name', ['name' => $name])->fetchAssoc();
			self::assertArrayHasKey('id', $row);
			$id = (int)$row['id'];
			$this->userIds[] = $id;
			return $id;
		}

		/**
		 * Keep the first sorted occurrence of each joined primary key on its page.
		 * @return void
		 */
		public function testJoinedRowsAreDeduplicatedAfterSortingByAnotherColumn(): void {
			$suffix = bin2hex(random_bytes(8));
			$first = $this->insertUser('pagination_distinct_first_' . $suffix);
			$second = $this->insertUser('pagination_distinct_second_' . $suffix);
			$third = $this->insertUser('pagination_distinct_third_' . $suffix);
			$adapter = self::em()->getConnection();

			// The first user's low sort value is inserted before its high one.
			// DISTINCT on only the user ID can choose the low value before sorting.
			foreach ([[$first, 'a'], [$first, 'z'], [$second, 'm'], [$third, 'h']] as [$id, $title]) {
				self::assertNotNull($adapter->execute(
					"INSERT INTO posts (title, content, published, created_at, test_enum, test_json, user_id) VALUES (:title, :content, FALSE, '2024-01-01 00:00:00', 'pending', '{}', :id)",
					['title' => $title . $suffix, 'content' => '', 'id' => $id]
				));
			}

			$query = '
				range of u is App\\Entities\\UserEntity
				range of p is App\\Entities\\PostEntity via u.posts
				retrieve (u.id, p.title)
				where u.id = :first or u.id = :second or u.id = :third
				sort by p.title desc
				window %d, 2
			';
			$parameters = ['first' => $first, 'second' => $second, 'third' => $third];

			$page0 = self::em()->getAll(sprintf($query, 0), $parameters);
			$page1 = self::em()->getAll(sprintf($query, 1), $parameters);

			self::assertSame([$first, $second], array_values(array_unique(array_column($page0, 'u.id'))));
			self::assertSame([$third], array_values(array_unique(array_column($page1, 'u.id'))));
		}

		/**
		 * Restrict the entire original predicate, including every OR branch, to the page.
		 * @return void
		 */
		public function testExistingPrimaryKeyInInsideOrCannotLeakOtherPages(): void {
			$suffix = bin2hex(random_bytes(8));
			$first = $this->insertUser('pagination_or_first_' . $suffix);
			$second = $this->insertUser('pagination_or_second_' . $suffix);
			$third = $this->insertUser('pagination_or_third_' . $suffix);
			$query = '
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where u.id in (%d, %d) or u.id = :__pagination_pk_0
				sort by u.id
				window %d, 1
			';
			$parameters = ['__pagination_pk_0' => $third];

			foreach ([$first, $second, $third] as $page => $expected) {
				self::assertSame([$expected], array_column(self::em()->getAll(sprintf($query, $first, $second, $page), $parameters), 'u.id'));
			}
		}

		/**
		 * Keep all joined children of the selected parent when a page ends mid-collection.
		 * @return void
		 */
		public function testPageBoundaryDoesNotTruncateEagerCollection(): void {
			$suffix = bin2hex(random_bytes(8));
			$first = $this->insertUser('pagination_collection_first_' . $suffix);
			$second = $this->insertUser('pagination_collection_second_' . $suffix);
			$adapter = self::em()->getConnection();
			foreach ([$first, $first, $second] as $index => $id) {
				self::assertNotNull($adapter->execute(
					"INSERT INTO posts (title, content, published, created_at, test_enum, test_json, user_id) VALUES (:title, :content, FALSE, '2024-01-01 00:00:00', 'pending', '{}', :id)",
					['title' => 'pagination_collection_' . $index . '_' . $suffix, 'content' => '', 'id' => $id]
				));
			}

			$query = '
				range of u is App\\Entities\\UserEntity
				range of p is App\\Entities\\PostEntity via u.posts
				retrieve (u, p)
				where u.id = :first or u.id = :second
				sort by u.id
				window %d, 1
			';
			$parameters = ['first' => $first, 'second' => $second];
			$page0 = self::em()->getCol(sprintf($query, 0), $parameters);
			$page1 = self::em()->getCol(sprintf($query, 1), $parameters);

			self::assertCount(1, $page0);
			self::assertSame($first, $page0[0]->getId());
			self::assertCount(2, $page0[0]->posts);
			self::assertCount(1, $page1);
			self::assertSame($second, $page1[0]->getId());
			self::assertCount(1, $page1[0]->posts);
		}

		/**
		 * Limit the parent-key pass in SQL while leaving the hydration join unbounded.
		 * @return void
		 */
		public function testRootSortedEagerJoinUsesBoundedKeyQuery(): void {
			$suffix = bin2hex(random_bytes(8));
			$first = $this->insertUser('pagination_sql_first_' . $suffix);
			$second = $this->insertUser('pagination_sql_second_' . $suffix);
			$adapter = self::em()->getConnection();
			foreach ([$first, $second] as $id) {
				self::assertNotNull($adapter->execute(
					"INSERT INTO posts (title, content, published, created_at, test_enum, test_json, user_id) VALUES (:title, '', FALSE, '2024-01-01 00:00:00', 'pending', '{}', :id)",
					['title' => 'pagination_sql_' . $id . '_' . $suffix, 'id' => $id]
				));
			}

			$logger = new class extends \Psr\Log\AbstractLogger {
				/** @var list<string> */
				public array $queries = [];

				/**
				 * Capture executed SQL from Cake's query context.
				 * @param mixed $level Log level
				 * @param string|\Stringable $message Log message
				 * @param array<string, mixed> $context Log context
				 * @return void
				 */
				public function log($level, string|\Stringable $message, array $context = []): void {
					$this->queries[] = $context['query']->getContext()['query'];
				}
			};
			$driver = $adapter->getConnection()->getDriver();
			$previousLogger = $driver->getLogger();
			$driver->setLogger($logger);
			try {
				$rows = self::em()->getCol('
					range of u is App\\Entities\\UserEntity
					range of p is App\\Entities\\PostEntity via u.posts
					retrieve (u, p)
					where u.id = :first or u.id = :second
					sort by u.id
					window 1, 1
				', ['first' => $first, 'second' => $second]);
			} finally {
				if ($previousLogger !== null) {
					$driver->setLogger($previousLogger);
				} else {
					$driver->disableQueryLogging();
				}
			}

			self::assertCount(1, $rows);
			self::assertSame($second, $rows[0]->getId());
			self::assertCount(1, $rows[0]->posts);
			$keyQueries = array_values(array_filter($logger->queries, static fn(string $sql): bool => str_contains($sql, 'LIMIT 1 OFFSET 1')));
			$hydrationQueries = array_values(array_filter($logger->queries, static fn(string $sql): bool => str_contains($sql, 'JOIN') && str_contains($sql, 'posts')));
			self::assertCount(1, $keyQueries);
			self::assertCount(1, $hydrationQueries);
			self::assertStringNotContainsString('JOIN', $keyQueries[0]);
			self::assertStringNotContainsString('LIMIT', $hydrationQueries[0]);
			self::assertSame([], self::em()->getCol('
				range of u is App\\Entities\\UserEntity
				range of p is App\\Entities\\PostEntity via u.posts
				retrieve (u, p)
				where u.id = :first or u.id = :second
				sort by u.id
				window 3, 1
			', ['first' => $first, 'second' => $second]));
		}

		/**
		 * Keep DISTINCT only on the final projection and order tied keys by primary key.
		 * @return void
		 */
		public function testUniqueProjectionAndTiedSortPaginateStableParentKeys(): void {
			$suffix = bin2hex(random_bytes(8));
			$first = $this->insertUser('pagination_unique_first_' . $suffix);
			$second = $this->insertUser('pagination_unique_second_' . $suffix);
			$third = $this->insertUser('pagination_unique_third_' . $suffix);
			$query = '
				range of u is App\\Entities\\UserEntity
				retrieve unique (u.id, u.username, u.banned)
				where u.id in (%d, %d, %d)
				sort by u.banned
				window %d, 1
			';
			foreach ([$first, $second, $third] as $page => $expected) {
				self::assertSame([$expected], array_column(self::em()->getAll(sprintf($query, $first, $second, $third, $page)), 'u.id'));
			}

			$unsortedQuery = '
				range of u is App\\Entities\\UserEntity
				retrieve (u.id)
				where u.id in (%d, %d, %d)
				window %d, 1
			';
			foreach ([$first, $second, $third] as $page => $expected) {
				self::assertSame([$expected], array_column(self::em()->getAll(sprintf($unsortedQuery, $third, $first, $second, $page)), 'u.id'));
			}
		}

		/**
		 * @InValuesAreFinal pages an existing primary-key IN(...) list by position, in the
		 * list's own order, with no separate key query — a non-matching id in the list
		 * costs that page a row instead of being silently skipped over.
		 * @return void
		 */
		public function testInValuesAreFinalPagesExistingInClauseInListOrderWithoutKeyQuery(): void {
			$suffix = bin2hex(random_bytes(8));
			$first = $this->insertUser('pagination_ivaf_first_' . $suffix);
			$second = $this->insertUser('pagination_ivaf_second_' . $suffix);
			$third = $this->insertUser('pagination_ivaf_third_' . $suffix);
			$missing = $third + 1000000;
			$adapter = self::em()->getConnection();

			$logger = new class extends \Psr\Log\AbstractLogger {
				/** @var list<string> */
				public array $queries = [];

				/**
				 * Capture executed SQL from Cake's query context.
				 * @param mixed $level Log level
				 * @param string|\Stringable $message Log message
				 * @param array<string, mixed> $context Log context
				 * @return void
				 */
				public function log($level, string|\Stringable $message, array $context = []): void {
					$this->queries[] = $context['query']->getContext()['query'];
				}
			};
			$driver = $adapter->getConnection()->getDriver();
			$previousLogger = $driver->getLogger();
			$driver->setLogger($logger);

			try {
				// List order deliberately differs from id order and has a non-existent
				// id at position 2, so the page split is purely positional, not DB-matched.
				$query = '
					@InValuesAreFinal true
					range of u is App\\Entities\\UserEntity
					retrieve (u.id)
					where u.id in (%d, %d, %d, %d)
					window %d, 2
				';
				$page0 = self::em()->getAll(sprintf($query, $third, $first, $missing, $second, 0));
				$page1 = self::em()->getAll(sprintf($query, $third, $first, $missing, $second, 1));
			} finally {
				if ($previousLogger !== null) {
					$driver->setLogger($previousLogger);
				} else {
					$driver->disableQueryLogging();
				}
			}

			self::assertSame([$third, $first], array_column($page0, 'u.id'));
			self::assertSame([$second], array_column($page1, 'u.id'));

			// No key-fetch pass: exactly one query per page, each ordered by IN-list position.
			self::assertCount(2, $logger->queries);

			foreach ($logger->queries as $sql) {
				self::assertTrue(
					str_contains($sql, 'ORDER BY FIELD') || str_contains($sql, 'ORDER BY CASE'),
					"Expected an IN-list order clause, got: {$sql}"
				);
			}
		}
	}
