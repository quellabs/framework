<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `destroy Name {, Name}` statement,
	 * exercised end-to-end through EntityManager::executeQuery() against a
	 * live MySQL connection (the suite's shared connection — see
	 * bootstrap.php). Table-only — index destroy is not implemented yet, see
	 * objectquel-destroy-plan.md.
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

			// Session-scoped temp tables are invisible to getTables() on MySQL —
			// this is exactly the reason DestroyExecutor doesn't pre-check
			// existence itself. A plain DROP TABLE must still resolve and drop
			// it correctly.
			$result = self::em()->executeQuery("destroy {$tableName}");

			$this->assertNull($result);
		}

		public function testDestroysMultipleTablesInOneStatement(): void {
			$tableA = $this->nextTableName();
			$tableB = $this->nextTableName();

			self::em()->executeQuery("create {$tableA} (id = integer)");
			self::em()->executeQuery("create {$tableB} (id = integer)");

			$result = self::em()->executeQuery("destroy {$tableA}, {$tableB}");

			$this->assertNull($result);

			$tables = self::em()->getConnection()->getTables();
			$this->assertNotContains($tableA, $tables);
			$this->assertNotContains($tableB, $tables);
		}

		public function testDestroysAnEntityMappedTableWithoutRestriction(): void {
			// `destroy` applies no Phinx-governance restriction (see
			// objectquel-create-table-plan.md's already-decided answer, which
			// this plan explicitly inherits) — it's just as capable of dropping
			// a real, Entity-mapped, already-populated table as any other name.
			// Prove it without touching the suite's shared `posts`/`users`
			// fixture tables: create a throwaway table, then destroy it — no
			// EntityStore lookup should occur or block this.
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

		public function testRejectsDuplicateNameInSameStatement(): void {
			$tableName = $this->nextTableName();

			$this->expectException(QuelException::class);

			self::em()->executeQuery("destroy {$tableName}, {$tableName}");
		}
	}
