<?php

	namespace Quellabs\ObjectQuel\Tests\MySQL;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Executes EQUEL routine windows against the suite's live MySQL database.
	 */
	class RoutineWindowMySqlTest extends TestCase {

		private string $name;

		/** @var list<int> */
		private array $userIds = [];

		/**
		 * @return EntityManager
		 */
		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];
			if (!$em instanceof EntityManager) {
				throw new \RuntimeException('Test bootstrap did not initialize the entity manager.');
			}

			return $em;
		}

		/**
		 * @return void
		 */
		protected function setUp(): void {
			$this->name = 'equel_window_' . getmypid();

			for ($index = 1; $index <= 3; ++$index) {
				$result = self::em()->executeQuery(
					'range of u is UserEntity append to u (username = :username, password = :password, banned = false)',
					['username' => "{$this->name}_{$index}", 'password' => 'test']
				);
				self::assertNotNull($result);
				$this->userIds[] = (int)$result->getGeneratedId();
			}
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			foreach (['first', 'default', 'empty', 'past', 'standalone'] as $suffix) {
				self::em()->executeQuery("destroy function {$this->name}_{$suffix} if exists");
			}

			if ($this->userIds !== []) {
				$ids = implode(', ', $this->userIds);
				self::em()->executeQuery("range of u is UserEntity delete u where u.id in ({$ids})");
			}
		}

		/**
		 * Defines a function that sums the IDs returned by a windowed cursor.
		 * @param string $suffix Function suffix
		 * @param string $condition Retrieve condition
		 * @param string $window Window clause
		 * @return string Routine name
		 */
		private function deployCursorSum(string $suffix, string $condition, string $window): string {
			$routine = "{$this->name}_{$suffix}";
			self::em()->executeQuery("define function {$routine} () integer {
				integer total = 0
				range of u is UserEntity
				cursor rows = retrieve (u.id) where {$condition} sort by u.banned, u.id window {$window}
				foreach (rows as row) { total = total + row.id }
				return total
			}");

			return $routine;
		}

		/**
		 * Calls a zero-argument scalar routine and returns its result.
		 * @param string $routine Routine name
		 * @return int
		 */
		private function callInteger(string $routine): int {
			$result = self::em()->executeQuery("{$routine}()");
			self::assertNotNull($result);
			return (int)$result[0][$routine];
		}

		/**
		 * Checks page zero, nonzero page, default size, empty results, and an out-of-range page.
		 * The shared banned value creates ties in the first explicit sort expression.
		 * @return void
		 */
		public function testCursorWindowsReturnTheRequestedRows(): void {
			$ids = implode(', ', $this->userIds);
			$condition = "u.id in ({$ids})";

			$first = $this->deployCursorSum('first', $condition, '0, 2');
			$default = $this->deployCursorSum('default', $condition, '1');
			$empty = $this->deployCursorSum('empty', "u.username = \"{$this->name}_missing\"", '0, 2');
			$past = $this->deployCursorSum('past', $condition, '20, 2');

			$orderedIds = $this->userIds;
			sort($orderedIds, SORT_NUMERIC);
			self::assertSame($orderedIds[0] + $orderedIds[1], $this->callInteger($first));
			self::assertSame($orderedIds[1], $this->callInteger($default));
			self::assertSame(0, $this->callInteger($empty));
			self::assertSame(0, $this->callInteger($past));
		}

		/**
		 * A standalone windowed retrieve executes on the server with its rows discarded.
		 * @return void
		 */
		public function testStandaloneWindowedRetrieveExecutes(): void {
			$routine = "{$this->name}_standalone";
			$ids = implode(', ', $this->userIds);
			self::em()->executeQuery("define function {$routine} () void {
				range of u is UserEntity
				retrieve (u.id) where u.id in ({$ids}) sort by u.id window 0, 2
			}");

			self::assertNull(self::em()->executeQuery("{$routine}()"));
		}
	}
