<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use PHPUnit\Framework\Attributes\Group;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Exception\SemanticException;

	/**
	 * `define function` and `destroy function` through EntityManager::executeQuery(),
	 * against the suite's MySQL or PostgreSQL connection.
	 */
	class RoutineDeployTest extends TestCase {

		private string $name;
		/** @var list<int> User rows created by this test */
		private array $userIds = [];

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
			if (self::em()->getConnection()->getDatabaseType() === 'pgsql') {
				self::em()->executeQuery("destroy function {$this->name} if exists");
			} else {
				self::em()->getConnection()->execute("DROP FUNCTION IF EXISTS `{$this->name}`");
				self::em()->getConnection()->execute("DROP PROCEDURE IF EXISTS `{$this->name}`");
			}
			foreach ($this->userIds as $id) {
				self::em()->executeQuery('range of u is UserEntity delete u where u.id = :id', ['id' => $id]);
			}
		}

		/**
		 * Inserts a user owned by this test and returns its id.
		 * @param string $username Username to insert
		 * @return int Generated user id
		 */
		private function seedUser(string $username): int {
			$seeded = self::em()->executeQuery(
				'range of u is UserEntity append to u (username = :username, password = :password, banned = false)',
				['username' => $username, 'password' => 'pw']
			);
			self::assertNotNull($seeded);
			self::assertIsInt($seeded->getGeneratedId());
			$id = $seeded->getGeneratedId();
			$this->userIds[] = $id;
			return $id;
		}

		/**
		 * @return int Number of functions and procedures named $this->name
		 */
		private function routineCount(): int {
			if (self::em()->getConnection()->getDatabaseType() === 'pgsql') {
				$statement = self::em()->getConnection()->execute(
					'SELECT COUNT(*) AS n FROM pg_proc WHERE proname = :name AND pg_function_is_visible(oid)',
					['name' => $this->name]
				);
				self::assertNotNull($statement);
				return (int)$statement->fetch('assoc')['n'];
			}
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
			$quoted = self::em()->getConnection()->getDatabaseType() === 'pgsql' ? '"' . $this->name . '"' : '`' . $this->name . '`';
			$statement = self::em()->getConnection()->execute("SELECT {$quoted}(:minId) AS result", ['minId' => $minId]);
			self::assertNotNull($statement);
			$row = $statement->fetch('assoc');
			self::assertIsArray($row);
			return (int)$row['result'];
		}

		/**
		 * A deployed function runs on the server and matches the same count done in ObjectQuel;
		 * defining it again fails without changing the existing routine.
		 * @return void
		 */
		public function testDefinesAFunctionAndRejectsDuplicate(): void {
			$this->seedUser("{$this->name}_user");
			$source = "
				define function {$this->name} (int minId) integer {
					integer total = 0
					range of u is UserEntity
					cursor users = retrieve (u.id, name = u.username) where u.id > minId
					foreach (users as row) {
						if (row.name != \"\") {
							total = total + 1
						}
					}
					return total
				}
			";

			self::assertNull(self::em()->executeQuery($source));
			try {
				self::em()->executeQuery($source);
				self::fail('Defining an existing routine must fail');
			} catch (QuelException $exception) {
				self::assertSame('routine_definition_error', $exception->type);
				self::assertStringContainsString('already exists', $exception->getMessage());
			}

			$expected = self::em()->executeQuery('range of u is UserEntity retrieve (n = count(u.id)) where u.id > 0 and u.username != ""');
			self::assertNotNull($expected);
			self::assertSame((int)$expected[0]['n'], $this->callFunction(0));
		}

		/**
		 * With the column's collation configured, a string parameter compares with the column whatever the database default is.
		 * @return void
		 */
		#[Group('objectquel-mysql')]
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
						foreach (users as row) {
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
			$this->seedUser("{$this->name}_first");
			$this->seedUser("{$this->name}_second");
			self::em()->executeQuery("
				define function {$this->name} (int minId) integer {
					integer bound = -1
					integer total = 0
					range of u is UserEntity
					cursor users = retrieve (u.id) where u.id > bound
					bound = minId
					foreach (users as row) {
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
			$this->seedUser('');
			$this->seedUser("{$this->name}_first");
			$this->seedUser("{$this->name}_second");
			self::em()->executeQuery("
				define function {$this->name} (int maxCount) integer {
					integer total = 0
					range of u is UserEntity
					cursor users = retrieve (u.id, name = u.username) where u.id > 0
					foreach (users as row) {
						if (row.name = \"\") {
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
		 * ++, --, +=, -=, *= and /= run on the server as the assignments they stand for.
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
					total *= step + 1
					total /= step * 2
					return total
				}
			");

			self::assertSame(intdiv((55 * 3 - 1 - 2) * (3 + 1), 3 * 2), $this->callFunction(3));
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
					cursor users = retrieve (u.id, u.username) where u.username = who
					foreach (users as row) {
						replace u (banned = true) where u.id = row.id
						delete u where u.id = row.id
					}
					atomic {
						replace u (banned = false) where u.username = who
						if (who = \"\") {
							rollback
						}
					}
					retrieve (p.title) where p.userId = 5
				}
			");

			self::assertSame(1, $this->routineCount());
		}

		/**
		 * A bare `return` (void routines only) exits before the write that follows it,
		 * without affecting a call where the guard doesn't trigger.
		 * @return void
		 */
		public function testBareReturnSkipsWriteOnGuardClause(): void {
			$skipId = $this->seedUser("{$this->name}_skip");
			$updateId = $this->seedUser("{$this->name}_update");

			self::em()->executeQuery("
				define function {$this->name} (integer targetId, integer skip) void {
					range of u is UserEntity
					if (skip = 1) {
						return
					}
					replace u (banned = true) where u.id = targetId
				}
			");

			self::em()->executeQuery("{$this->name}(:id, :skip)", ['id' => $skipId, 'skip' => 1]);
			self::em()->executeQuery("{$this->name}(:id, :skip)", ['id' => $updateId, 'skip' => 0]);

			$connection = self::em()->getConnection();
			$skipRow = $connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $skipId])?->fetch('assoc');
			$updateRow = $connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $updateId])?->fetch('assoc');

			self::assertSame(0, (int)$skipRow['banned']);
			self::assertSame(1, (int)$updateRow['banned']);
		}

		/**
		 * An atomic block rolls back only its own writes and leaves the caller's transaction open.
		 * @return void
		 */
		public function testAtomicBlockPreservesCallerTransaction(): void {
			$id = $this->seedUser("{$this->name}_atomic");
			$connection = self::em()->getConnection();
			$row = $connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc');
			self::assertIsArray($row);
			$original = (int)$row['banned'];

			self::em()->executeQuery("
				define function {$this->name} (integer uid, integer cancel) void {
					range of u is UserEntity
					atomic {
						replace u (banned = true) where u.id = uid
						if (cancel = 1) { rollback }
					}
				}
			");

			$connection->beginTrans();

			try {
				$connection->execute('UPDATE users SET banned = FALSE WHERE id = :id', ['id' => $id]);
				self::em()->executeQuery("{$this->name}(:id, 1)", ['id' => $id]);
				self::assertSame(0, (int)$connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc')['banned']);
				self::em()->executeQuery("{$this->name}(:id, 0)", ['id' => $id]);
				self::assertSame(1, (int)$connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc')['banned']);
			} finally {
				$connection->rollbackTrans();
			}

			self::assertSame($original, (int)$connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc')['banned']);
		}

		/**
		 * `c = retrieve (...)` rebinds a cursor to a new query; a second `foreach` after the
		 * rebind reads the new query, not the one the first `foreach` already consumed.
		 * @return void
		 */
		public function testCursorRebindDrivesASecondLoopWithANewQuery(): void {
			$firstId = $this->seedUser("{$this->name}_first");
			$secondId = $this->seedUser("{$this->name}_second");

			self::em()->executeQuery("
				define function {$this->name} () void {
					range of u is UserEntity
					cursor c = retrieve (u.id) where u.username = \"{$this->name}_first\"
					foreach (c as row) {
						replace u (banned = true) where u.id = row.id
					}
					c = retrieve (u.id) where u.username = \"{$this->name}_second\"
					foreach (c as row) {
						replace u (banned = true) where u.id = row.id
					}
				}
			");

			self::em()->executeQuery("{$this->name}()");

			$connection = self::em()->getConnection();
			$firstRow = $connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $firstId])?->fetch('assoc');
			$secondRow = $connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $secondId])?->fetch('assoc');

			self::assertSame(1, (int)$firstRow['banned']);
			self::assertSame(1, (int)$secondRow['banned']);
		}

		/**
		 * An ObjectQuel call supplies the outer transaction; an unwrapped SQL call fails before writes.
		 * @return void
		 */
		#[Group('objectquel-mysql')]
		public function testAtomicBlockRequiresTransactionForDirectCall(): void {
			$id = $this->seedUser("{$this->name}_direct");
			$connection = self::em()->getConnection();
			$row = $connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc');
			self::assertIsArray($row);
			$original = (int)$row['banned'];

			self::em()->executeQuery("
				define function {$this->name} (integer uid) void {
					range of u is UserEntity
					atomic { replace u (banned = true) where u.id = uid }
				}
			");

			self::assertNull($connection->execute("CALL `{$this->name}`(:id)", ['id' => $id]));
			self::assertSame($original, (int)$connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc')['banned']);

			try {
				self::em()->executeQuery("{$this->name}(:id)", ['id' => $id]);
				self::assertSame(1, (int)$connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc')['banned']);
			} finally {
				$connection->execute('UPDATE users SET banned = :banned WHERE id = :id', ['banned' => $original, 'id' => $id]);
			}
		}

		/**
		 * A database error inside the block restores its savepoint without discarding prior caller writes.
		 * @return void
		 */
		#[Group('objectquel-mysql')]
		public function testAtomicBlockRollsBackOnDatabaseError(): void {
			$id = $this->seedUser("{$this->name}_rollback");
			$connection = self::em()->getConnection();
			$row = $connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc');
			self::assertIsArray($row);

			self::em()->executeQuery("
				define function {$this->name} (integer uid) void {
					range of u is UserEntity
					atomic {
						replace u (banned = true) where u.id = uid
						replace u (username = (string)null) where u.id = uid
					}
				}
			");

			$connection->beginTrans();

			try {
				$connection->execute('UPDATE users SET banned = FALSE WHERE id = :id', ['id' => $id]);

				try {
					self::em()->executeQuery("{$this->name}(:id)", ['id' => $id]);
					self::fail('Expected a NOT NULL violation');
				} catch (QuelException $exception) {
					self::assertSame('routine_call_error', $exception->type);
				}

				self::assertSame(0, (int)$connection->execute('SELECT banned FROM users WHERE id = :id', ['id' => $id])?->fetch('assoc')['banned']);
			} finally {
				$connection->rollbackTrans();
			}
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
		 * Destruction accepts a name shared by a function and a procedure, then drops both MySQL namespace matches.
		 * @return void
		 */
		#[Group('objectquel-mysql')]
		public function testDestroyAcceptsNameSharedByFunctionAndProcedure(): void {
			self::em()->executeQuery("define function {$this->name} () integer { return 1 }");
			self::em()->getConnection()->execute("CREATE PROCEDURE `{$this->name}`() BEGIN END");
			self::assertSame(2, $this->routineCount());

			self::em()->executeQuery("destroy function {$this->name}");
			self::assertSame(0, $this->routineCount());
		}

		/**
		 * A void function cannot be defined when a value-returning function has its name.
		 * @return void
		 */
		public function testVoidFunctionCannotReuseValueReturningFunctionName(): void {
			self::em()->executeQuery("define function {$this->name} () integer { return 1 }");

			try {
				self::em()->executeQuery("define function {$this->name} () void { }");
				self::fail('Defining a void function with an existing function name must fail');
			} catch (QuelException $exception) {
				self::assertSame('routine_definition_error', $exception->type);
				self::assertStringContainsString('already exists', $exception->getMessage());
			}

			self::assertSame(1, $this->routineCount());
			$quoted = self::em()->getConnection()->getDatabaseType() === 'pgsql' ? '"' . $this->name . '"' : '`' . $this->name . '`';
			$statement = self::em()->getConnection()->execute("SELECT {$quoted}() AS result");
			self::assertNotNull($statement);
			self::assertSame(1, (int)$statement->fetch('assoc')['result']);
		}

		/**
		 * A value-returning definition cannot replace a void function with the same name.
		 * @return void
		 */
		public function testValueReturningFunctionCannotReuseVoidFunctionName(): void {
			self::em()->executeQuery("define function {$this->name} () void { }");

			try {
				self::em()->executeQuery("define function {$this->name} () integer { return 1 }");
				self::fail('Defining a value-returning function with an existing void function name must fail');
			} catch (QuelException $exception) {
				self::assertSame('routine_definition_error', $exception->type);
				self::assertStringContainsString('already exists', $exception->getMessage());
			}

			self::assertSame(1, $this->routineCount());
			self::assertNull(self::em()->executeQuery("{$this->name}()"));
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
			$this->expectExceptionMessage(self::em()->getConnection()->getDatabaseType() === 'pgsql'
				? "Failed to destroy routine '{$this->name}':"
				: "Failed to destroy routine '{$this->name}': it doesn't exist");
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
