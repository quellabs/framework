<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Exception\QuelException;

	/**
	 * Integration coverage for QUEL's `alter Name (op {, op})` statement,
	 * exercised end-to-end via EntityManager::executeQuery() against the
	 * suite's shared MySQL connection. Verifies the resulting schema via
	 * DatabaseAdapter::getColumns()/getPrimaryKeyColumns()/getIndexes(), not
	 * just that no exception was thrown — same rationale as CreateTableTest.
	 */
	class AlterTableTest extends TestCase {

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
			return 'alt_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		private function createTargetTable(string $tableName, string $columns = 'id = integer identity, message = string(100) not null, primary key (id)'): void {
			$this->createdTables[] = $tableName;

			self::em()->executeQuery("create {$tableName} ({$columns})");
		}

		public function testAddsAColumn(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			$result = self::em()->executeQuery("alter {$tableName} (add view_count = integer not null)");

			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);
			$this->assertSame('integer', $columns['view_count']['type']);
			$this->assertFalse($columns['view_count']['nullable']);
		}

		public function testDropsAColumn(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer identity, legacy_flag = boolean, primary key (id)');

			$result = self::em()->executeQuery("alter {$tableName} (drop legacy_flag)");

			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);
			$this->assertArrayNotHasKey('legacy_flag', $columns);
		}

		public function testRenamesAColumn(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer identity, old_name = string(50), primary key (id)');

			$result = self::em()->executeQuery("alter {$tableName} (rename old_name to new_name)");

			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);
			$this->assertArrayNotHasKey('old_name', $columns);
			$this->assertArrayHasKey('new_name', $columns);
		}

		public function testRetypesAColumn(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer identity, price = integer, primary key (id)');

			$result = self::em()->executeQuery("alter {$tableName} (retype price = decimal(10,2) not null)");

			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);
			$this->assertSame('decimal', $columns['price']['type']);
			$this->assertSame(10, $columns['price']['precision']);
			$this->assertSame(2, $columns['price']['scale']);
			$this->assertFalse($columns['price']['nullable']);
		}

		public function testCombinesMultipleColumnOperationsInOneStatement(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer identity, legacy_flag = boolean, old_name = string(50), primary key (id)');

			$result = self::em()->executeQuery("
				alter {$tableName} (
					add view_count = integer not null,
					drop legacy_flag,
					rename old_name to new_name
				)
			");

			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);
			$this->assertArrayHasKey('view_count', $columns);
			$this->assertArrayNotHasKey('legacy_flag', $columns);
			$this->assertArrayHasKey('new_name', $columns);
		}

		public function testReplacesThePrimaryKey(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer, tenant_id = integer, primary key (id)');

			$result = self::em()->executeQuery("alter {$tableName} (primary key (tenant_id))");

			$this->assertNull($result);

			$this->assertSame(['tenant_id'], self::em()->getConnection()->getPrimaryKeyColumns($tableName));
		}

		public function testDropsThePrimaryKey(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer, primary key (id)');

			$result = self::em()->executeQuery("alter {$tableName} (drop primary key)");

			$this->assertNull($result);

			$this->assertSame([], self::em()->getConnection()->getPrimaryKeyColumns($tableName));
		}

		public function testRejectsDroppingAPrimaryKeyThatDoesNotExist(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer');

			$this->expectException(QuelException::class);

			self::em()->executeQuery("alter {$tableName} (drop primary key)");
		}

		public function testAddsAnIndex(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer identity, tenant_id = integer not null, primary key (id)');

			$result = self::em()->executeQuery("alter {$tableName} (add index idx_tenant (tenant_id))");

			$this->assertNull($result);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey('idx_tenant', $indexes);
			$this->assertSame(['tenant_id'], $indexes['idx_tenant']['columns']);
		}

		public function testDropsAnIndex(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName, 'id = integer identity, tenant_id = integer not null, primary key (id)');
			self::em()->executeQuery("alter {$tableName} (add index idx_tenant (tenant_id))");

			$result = self::em()->executeQuery("alter {$tableName} (drop index idx_tenant)");

			$this->assertNull($result);

			$this->assertArrayNotHasKey('idx_tenant', self::em()->getConnection()->getIndexes($tableName));
		}

		public function testAddsAColumnAndIndexesItInTheSameStatement(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			$result = self::em()->executeQuery("
				alter {$tableName} (
					add view_count = integer not null,
					add index idx_view_count (view_count)
				)
			");

			$this->assertNull($result);

			$columns = self::em()->getConnection()->getColumns($tableName);
			$this->assertArrayHasKey('view_count', $columns);

			$indexes = self::em()->getConnection()->getIndexes($tableName);
			$this->assertArrayHasKey('idx_view_count', $indexes);
		}

		public function testRejectsAddingAColumnThatAlreadyExists(): void {
			$tableName = $this->nextTableName();
			$this->createTargetTable($tableName);

			$this->expectException(QuelException::class);

			self::em()->executeQuery("alter {$tableName} (add message = string(100))");
		}
	}
