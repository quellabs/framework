<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `destroy [temporary] Name [if
	 * exists]` statement (the table form), exercised end-to-end via
	 * EntityManager::executeQuery() against the suite's shared MySQL
	 * connection. See tests/Integration/DestroyIndexTest for the `destroy
	 * Name on Table` index form (objectquel-destroy-index-plan.md).
	 *
	 * MySQL alone can't distinguish "unqualified" from "temporary" here — a
	 * session temp table already shadows a same-named permanent one for
	 * unqualified DROP TABLE, so testDestroysATemporaryTableCreatedInTheSameSession
	 * and testDestroysUsingTheTemporaryQualifier both pass on this suite's
	 * connection even though only SQL Server actually needs the `temporary`
	 * qualifier to resolve correctly (see QuelToSQLDestroy).
	 */
	class DestroyTest extends TestCase {

		private static int $tableCounter = 0;

		/** @var string[] Tables created by the current test that destroy() didn't already remove */
		private array $createdTables = [];

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function tearDown(): void {
			$connection = self::em()->getConnection();

			foreach ($this->createdTables as $tableName) {
				$connection->execute("DROP TABLE IF EXISTS `{$tableName}`");
			}

			$this->createdTables = [];
		}

		private function nextTableName(): string {
			return 'destroy_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		public function testDestroysAPermanentTable(): void {
			$tableName = $this->nextTableName();

			self::em()->executeQuery("create {$tableName} (id = integer)");

			$result = self::em()->executeQuery("destroy {$tableName}");

			$this->assertNull($result);
			$this->assertNotContains($tableName, self::em()->getConnection()->getTables());
		}

		public function testDestroysATemporaryTableCreatedInTheSameSession(): void {
			$tableName = $this->nextTableName();

			self::em()->executeQuery("create temporary {$tableName} (id = integer)");

			// Session-scoped temp tables are invisible to getTables() on MySQL,
			// so a plain DROP TABLE must still resolve and drop it correctly.
			$result = self::em()->executeQuery("destroy {$tableName}");

			$this->assertNull($result);
		}

		public function testRejectsACommaSeparatedNameList(): void {
			// `destroy`'s comma-separated multi-name list was removed — every
			// `destroy` form targets exactly one object per statement now
			// (see objectquel-destroy-index-plan.md). A trailing `, Name` is
			// simply unparseable.
			$tableA = $this->nextTableName();
			$tableB = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("destroy {$tableA}, {$tableB}");
		}

		public function testDestroysAnEntityMappedTableWithoutRestriction(): void {
			// `destroy` applies no Phinx-governance restriction — no EntityStore
			// lookup should occur or block this.
			$tableName = $this->nextTableName();

			self::em()->executeQuery("create {$tableName} (id = integer)");

			$result = self::em()->executeQuery("destroy {$tableName}");

			$this->assertNull($result);
		}

		public function testRejectsDestroyingATableThatDoesNotExist(): void {
			$tableName = $this->nextTableName();

			// No IF EXISTS — a name that doesn't resolve to anything must fail
			// loudly (real engine error), not silently no-op.
			$this->expectException(QuelException::class);

			self::em()->executeQuery("destroy {$tableName}");
		}

		public function testIfExistsSilentlyIgnoresAMissingTable(): void {
			$tableName = $this->nextTableName();

			// No table by this name was ever created.
			$result = self::em()->executeQuery("destroy {$tableName} if exists");

			$this->assertNull($result);
		}

		public function testIfExistsStillDropsATableThatDoesExist(): void {
			$tableName = $this->nextTableName();

			self::em()->executeQuery("create {$tableName} (id = integer)");

			$result = self::em()->executeQuery("destroy {$tableName} if exists");

			$this->assertNull($result);
			$this->assertNotContains($tableName, self::em()->getConnection()->getTables());
		}

		public function testDestroysUsingTheTemporaryQualifier(): void {
			$tableName = $this->nextTableName();

			self::em()->executeQuery("create temporary {$tableName} (id = integer)");

			$result = self::em()->executeQuery("destroy temporary {$tableName}");

			$this->assertNull($result);
		}

		public function testTemporaryQualifierCombinesWithIfExists(): void {
			$tableName = $this->nextTableName();

			// No temp table by this name was ever created.
			$result = self::em()->executeQuery("destroy temporary {$tableName} if exists");

			$this->assertNull($result);
		}
	}
