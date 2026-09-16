<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Migration\MigrationRepository;

	/**
	 * Integration coverage for MigrationRepository, exercised end-to-end
	 * against the suite's shared MySQL connection (see Phase 2 of
	 * objectquel-migrations-implementation-plan.md). The tracking table
	 * itself is created via real ObjectQuel DDL; rows are read/written via
	 * the underlying connection's query builder — both verified against the
	 * real database, not just "no exception was thrown".
	 */
	class MigrationRepositoryTest extends TestCase {

		private static int $tableCounter = 0;

		/** @var string[] Tables created by the current test, dropped in tearDown() */
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
			return 'mig_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		private function makeRepository(): MigrationRepository {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;

			return new MigrationRepository(self::em(), $tableName);
		}

		public function testEnsureTableExistsCreatesTheTrackingTable(): void {
			$tableName = $this->nextTableName();
			$this->createdTables[] = $tableName;
			$repository = new MigrationRepository(self::em(), $tableName);

			$this->assertNotContains($tableName, self::em()->getConnection()->getTables());

			$repository->ensureTableExists();

			$this->assertContains($tableName, self::em()->getConnection()->getTables());

			$columns = self::em()->getConnection()->getColumns($tableName);
			$this->assertSame('biginteger', $columns['version']['type']);
			$this->assertTrue($columns['version']['primary_key']);
			$this->assertSame('string', $columns['migration_name']['type']);
			$this->assertSame(255, $columns['migration_name']['limit']);
			$this->assertSame('datetime', $columns['executed_at']['type']);
		}

		public function testEnsureTableExistsIsIdempotent(): void {
			$repository = $this->makeRepository();

			$repository->ensureTableExists();
			$repository->ensureTableExists();

			$this->assertSame([], $repository->getAppliedVersions());
		}

		public function testRecordAppliedThenGetAppliedVersions(): void {
			$repository = $this->makeRepository();
			$repository->ensureTableExists();

			$repository->recordApplied(20260101120000, 'CreateFoo');

			$this->assertSame([20260101120000], $repository->getAppliedVersions());
		}

		public function testGetAppliedVersionsReturnsAscendingOrderRegardlessOfInsertOrder(): void {
			$repository = $this->makeRepository();
			$repository->ensureTableExists();

			$repository->recordApplied(20260301000000, 'Third');
			$repository->recordApplied(20260101000000, 'First');
			$repository->recordApplied(20260201000000, 'Second');

			$this->assertSame(
				[20260101000000, 20260201000000, 20260301000000],
				$repository->getAppliedVersions()
			);
		}

		public function testGetAppliedRecordsMapsVersionToItsAppliedTimestamp(): void {
			$repository = $this->makeRepository();
			$repository->ensureTableExists();

			$repository->recordApplied(20260101120000, 'CreateFoo');

			$records = $repository->getAppliedRecords();

			$this->assertArrayHasKey(20260101120000, $records);
			$this->assertNotNull($records[20260101120000]);
		}

		public function testRecordRevertedRemovesTheAppliedRecord(): void {
			$repository = $this->makeRepository();
			$repository->ensureTableExists();

			$repository->recordApplied(20260101120000, 'CreateFoo');
			$repository->recordApplied(20260102120000, 'CreateBar');

			$repository->recordReverted(20260102120000);

			$this->assertSame([20260101120000], $repository->getAppliedVersions());
		}

		/**
		 * `version` is a 14-digit YmdHis timestamp (e.g. 20260916153000),
		 * which overflows a 32-bit integer — this is exactly why the
		 * tracking table declares it `biginteger`, not `integer` (see the
		 * plan doc). A round trip through a value this large pins that
		 * choice.
		 */
		public function testHandlesAFullSizeYmdHisVersionWithoutOverflow(): void {
			$repository = $this->makeRepository();
			$repository->ensureTableExists();

			$repository->recordApplied(20991231235959, 'FarFuture');

			$this->assertSame([20991231235959], $repository->getAppliedVersions());
		}
	}
