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
				$adapter->execute(
					'INSERT INTO posts (title, content, published, user_id) VALUES (:title, :content, FALSE, :id)',
					['title' => $title . $suffix, 'content' => '', 'id' => $id]
				);
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
	}
