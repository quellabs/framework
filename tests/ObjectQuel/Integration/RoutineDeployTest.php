<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;

	/**
	 * `define function` and `destroy function` through EntityManager::executeQuery(),
	 * against the suite's MySQL connection.
	 */
	class RoutineDeployTest extends TestCase {

		private string $name;

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
			$this->name = 'routine_test_' . getmypid();
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			self::em()->getConnection()->execute("DROP FUNCTION IF EXISTS `{$this->name}`");
			self::em()->getConnection()->execute("DROP PROCEDURE IF EXISTS `{$this->name}`");
		}

		/**
		 * @return int Number of functions and procedures named $this->name
		 */
		private function routineCount(): int {
			$statement = self::em()->getConnection()->execute(
				'SELECT COUNT(*) AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = :name',
				['name' => $this->name]
			);

			self::assertNotNull($statement);
			$row = $statement->fetch('assoc');
			self::assertIsArray($row);
			return (int)$row['n'];
		}

		/**
		 * @param int $minId Argument for the deployed function
		 * @return int The function's result
		 */
		private function callFunction(int $minId): int {
			$statement = self::em()->getConnection()->execute("SELECT `{$this->name}`(:minId) AS result", ['minId' => $minId]);
			self::assertNotNull($statement);
			$row = $statement->fetch('assoc');
			self::assertIsArray($row);
			return (int)$row['result'];
		}

		/**
		 * A deployed function runs on the server and matches the same count done in ObjectQuel;
		 * defining it again replaces it.
		 * @return void
		 */
		public function testDefinesAndRedefinesAFunction(): void {
			$source = "
				define function {$this->name} (int minId) integer {
					integer total = 0
					range of u is UserEntity
					cursor users = retrieve (u.id, name = u.username) where u.id > minId
					foreach users {
						if (users.name != \"\") {
							total = total + 1
						}
					}
					return total
				}
			";

			self::assertNull(self::em()->executeQuery($source));
			self::em()->executeQuery($source);

			$expected = self::em()->executeQuery('range of u is UserEntity retrieve (n = count(u.id)) where u.id > 0 and u.username != ""');
			self::assertNotNull($expected);
			self::assertSame((int)$expected[0]['n'], $this->callFunction(0));
		}

		/**
		 * With the column's collation configured, a string parameter compares with the column whatever the database default is.
		 * @return void
		 */
		public function testConfiguredCollationComparesWithColumn(): void {
			$statement = self::em()->getConnection()->execute(
				"SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'username'"
			);

			self::assertNotNull($statement);
			$row = $statement->fetch('assoc');
			self::assertIsArray($row);

			$configuration = self::em()->getConfiguration();
			$previous = $configuration->getCollation();
			$configuration->setCollation($row['c']);

			try {
				self::em()->executeQuery("
					define function {$this->name} (string who) integer {
						integer total = 0
						range of u is UserEntity
						cursor users = retrieve (u.id) where u.username = who
						foreach users {
							total = total + 1
						}
						return total
					}
				");
			} finally {
				$configuration->setCollation($previous);
			}

			$statement = self::em()->getConnection()->execute("SELECT `{$this->name}`(:who) AS result", ['who' => 'no such user']);
			self::assertNotNull($statement, self::em()->getConnection()->getLastErrorMessage());
			$row = $statement->fetch('assoc');
			self::assertIsArray($row);
			self::assertSame(0, (int)$row['result']);
		}

		/**
		 * A cursor query reads its variables when the foreach starts, not at the hoisted declaration or per row.
		 * @return void
		 */
		public function testCursorQueryReadsVariablesWhenTheLoopStarts(): void {
			self::em()->executeQuery("
				define function {$this->name} (int minId) integer {
					integer bound = -1
					integer total = 0
					range of u is UserEntity
					cursor users = retrieve (u.id) where u.id > bound
					bound = minId
					foreach users {
						total = total + 1
						bound = -1
					}
					return total
				}
			");

			// Passing the lowest id makes the result one less than counting from the declared bound would.
			$lowest = self::em()->executeQuery('range of u is UserEntity retrieve (m = min(u.id))');
			self::assertNotNull($lowest);
			$minId = (int)$lowest[0]['m'];

			$expected = self::em()->executeQuery('range of u is UserEntity retrieve (n = count(u.id)) where u.id > :minId', ['minId' => $minId]);
			self::assertNotNull($expected);
			self::assertSame((int)$expected[0]['n'], $this->callFunction($minId));
		}

		/**
		 * continue skips a row and break closes the cursor early; the count matches a plain query capped one below its total.
		 * @return void
		 */
		public function testForeachWithContinueAndBreak(): void {
			self::em()->executeQuery("
				define function {$this->name} (int maxCount) integer {
					integer total = 0
					range of u is UserEntity
					cursor users = retrieve (u.id, name = u.username) where u.id > 0
					foreach users {
						if (users.name = \"\") {
							continue
						}
						if (total >= maxCount) {
							break
						}
						total = total + 1
					}
					return total
				}
			");

			$expected = self::em()->executeQuery('range of u is UserEntity retrieve (n = count(u.id)) where u.id > 0 and u.username != ""');
			self::assertNotNull($expected);
			$named = (int)$expected[0]['n'];
			self::assertGreaterThan(0, $named, 'Fixture needs a user with a username for break to cut the loop short');

			self::assertSame($named - 1, $this->callFunction($named - 1));
			self::assertSame($named, $this->callFunction($named + 1));
		}

		/**
		 * A labelled WHILE accepts ITERATE, which re-checks the condition.
		 * @return void
		 */
		public function testWhileWithContinue(): void {
			self::em()->executeQuery("
				define function {$this->name} (int skip) integer {
					integer i = 0
					integer total = 0
					while (i < 10) {
						i = i + 1
						if (i = skip) {
							continue
						}
						total = total + i
					}
					return total
				}
			");

			self::assertSame(55 - 3, $this->callFunction(3));
		}

		/**
		 * ++, --, += and -= run on the server as the assignments they stand for.
		 * @return void
		 */
		public function testIncrementAndCompoundAssignment(): void {
			self::em()->executeQuery("
				define function {$this->name} (int step) integer {
					integer i = 0
					integer total = 0
					while (i < 10) {
						i++
						total += i * step
					}
					total--
					total -= step - 1
					return total
				}
			");

			self::assertSame(55 * 3 - 1 - 2, $this->callFunction(3));
		}

		/**
		 * An elseif / else if chain runs on the server and takes the first matching branch.
		 * @return void
		 */
		public function testElseifChain(): void {
			self::em()->executeQuery("
				define function {$this->name} (int n) integer {
					if (n < 0) {
						return -1
					} elseif (n = 0) {
						return 0
					} else if (n < 10) {
						return 1
					} else {
						return 2
					}
				}
			");

			self::assertSame(-1, $this->callFunction(-5));
			self::assertSame(0, $this->callFunction(0));
			self::assertSame(1, $this->callFunction(5));
			self::assertSame(2, $this->callFunction(50));
		}

		/**
		 * A writing procedure is accepted by the server; it isn't called.
		 * @return void
		 */
		public function testDefinesAWritingProcedure(): void {
			self::em()->executeQuery("
				define function {$this->name} (string who) void {
					range of u is UserEntity
					range of p is PostEntity
					cursor users = retrieve (u.username) where u.username = who
					foreach users {
						replace users (banned = true)
						delete users
					}
					begin transaction {
						replace u (banned = false) where u.username = who
						if (who = \"\") {
							abort
						}
					}
					retrieve (p.title) where p.userId = 5
				}
			");

			self::assertSame(1, $this->routineCount());
		}

		/**
		 * @return void
		 */
		public function testDestroysARoutine(): void {
			self::em()->executeQuery("define function {$this->name} () void { }");

			self::assertNull(self::em()->executeQuery("destroy function {$this->name}"));
			self::assertSame(0, $this->routineCount());
		}

		/**
		 * @return void
		 */
		public function testDestroyIfExistsIgnoresAMissingRoutine(): void {
			self::em()->executeQuery("destroy function {$this->name} if exists");
			self::assertSame(0, $this->routineCount());
		}

		/**
		 * @return void
		 */
		public function testDestroyingAMissingRoutineFails(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Failed to destroy routine '{$this->name}': it doesn't exist");
			self::em()->executeQuery("destroy function {$this->name}");
		}

		/**
		 * @return void
		 */
		public function testInvalidRoutineIsASemanticError(): void {
			try {
				self::em()->executeQuery("define function {$this->name} () integer { return missing }");
				self::fail('Expected a QuelException');
			} catch (QuelException $e) {
				self::assertSame(0, $this->routineCount());
				self::assertInstanceOf(SemanticException::class, $e->getPrevious());
			}
		}
	}
