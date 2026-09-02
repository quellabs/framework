<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use App\Entities\PostEntity;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\Execution\Executors\TempTableExecutor;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlias;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstIdentifier;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstNumber;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabase;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRangeDatabaseTempTable;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstRetrieve;
	use Quellabs\ObjectQuel\ObjectQuel\IdentifierType;
	use Quellabs\ObjectQuel\Planner\ExecutionPlan;
	use Quellabs\ObjectQuel\Planner\TempTableStage;

	/**
	 * TempTableExecutor::createTable() resolves each projected column's SQL type
	 * from the source entity's declared @Column metadata instead of hardcoding
	 * VARCHAR(255) for every column (see objectquel-temp-table-indexing.md,
	 * "second real gap"). These tests run the generated DDL against a live
	 * MySQL connection and inspect SHOW COLUMNS, since the risk is in the
	 * generated SQL fragments themselves (e.g. DECIMAL(p,s), VARBINARY(n)),
	 * not just the PHP-side type-name mapping.
	 *
	 * Exercises TempTableExecutor directly rather than through a full
	 * ObjectQuel query — building the AST by hand for a mixed-source query
	 * that triggers a real TempTableStage would pull in the whole JSON/planner
	 * pipeline for no extra coverage of the logic under test. The inner
	 * query's row producer is stubbed with a plain closure since
	 * TempTableExecutor only ever calls it and uses the returned rows.
	 *
	 * Uses the suite's shared EntityManager/EntityStore ($GLOBALS['test_em'])
	 * — see CascadeStrategyTest for why only one EntityManager may exist per
	 * PHPUnit process — and the App\Entities\PostEntity fixture, which already
	 * declares unsigned integer, string/limit, text, boolean, and datetime
	 * columns.
	 */
	class TempTableExecutorTest extends TestCase {

		private static int $tableCounter = 0;

		private TempTableExecutor $executor;

		private static function em(): EntityManager {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em;
		}

		protected function setUp(): void {
			$this->executor = new TempTableExecutor(
				self::em()->getConnection(),
				self::em()->getEntityStore()
			);
		}

		protected function tearDown(): void {
			// Drops every temp table created across all tests in this instance,
			// not just the last one — cleanup() clears its own registry, and
			// each test gets a fresh TempTableExecutor, so this only ever
			// touches tables the current test created.
			$this->executor->cleanup();
		}

		/**
		 * A unique table name per call, so tests never collide on a leftover
		 * temp table from a previous run in the same session.
		 */
		private function nextTableName(): string {
			return 'tmp_ttx_test_' . getmypid() . '_' . (++self::$tableCounter);
		}

		/**
		 * Builds a `<range>.<property>` projected value: an AstAlias whose
		 * expression is the base AstIdentifier of the chain with the range
		 * attached directly to it — exactly how the parser wires a resolved
		 * property reference (see ResolveIdentifierRange).
		 */
		private function propertyValue(string $outputName, string $property, AstRangeDatabase $range): AstAlias {
			$base = new AstIdentifier($range->getName(), IdentifierType::EntityRoot);
			$base->setRange($range);
			$base->setNext(new AstIdentifier($property));

			return new AstAlias($outputName, $base);
		}

		/**
		 * Runs a query expected to succeed and returns its rows. DatabaseAdapter::execute()
		 * swallows the underlying exception and returns null on failure instead of
		 * throwing, so a null result here means the query itself is broken — fail
		 * loudly rather than pass a null into fetchAll().
		 * @return array<int, array<string, mixed>>
		 */
		private function fetchAllAssoc(string $sql): array {
			$statement = self::em()->getConnection()->execute($sql);

			if ($statement === null) {
				throw new \RuntimeException("Query failed: {$sql}");
			}

			return $statement->fetchAll('assoc');
		}

		/**
		 * Narrows a single database result-set value (always scalar or null for
		 * the columns these tests read) to a string for comparison.
		 */
		private function scalarToString(mixed $value): string {
			return match (true) {
				is_string($value) => $value,
				is_int($value), is_float($value) => (string)$value,
				is_bool($value) => $value ? '1' : '0',
				$value === null => '',
				default => throw new \RuntimeException('Unexpected non-scalar value from query result: ' . get_debug_type($value)),
			};
		}

		/**
		 * @return array<string, string> Column name => lowercased SHOW COLUMNS type
		 */
		private function describeColumns(string $tableName): array {
			$columns = [];

			foreach ($this->fetchAllAssoc("SHOW COLUMNS FROM `{$tableName}`") as $row) {
				$columns[$this->scalarToString($row['Field'])] = strtolower($this->scalarToString($row['Type']));
			}

			return $columns;
		}

		public function testResolvesDeclaredColumnTypesFromEntityMetadata(): void {
			$range = new AstRangeDatabase('p', PostEntity::class);
			$innerQuery = new AstRetrieve([], [$range], false);
			$innerQuery->addValue($this->propertyValue('id', 'id', $range));
			$innerQuery->addValue($this->propertyValue('title', 'title', $range));
			$innerQuery->addValue($this->propertyValue('content', 'content', $range));
			$innerQuery->addValue($this->propertyValue('published', 'published', $range));
			$innerQuery->addValue($this->propertyValue('created_at', 'createdAt', $range));

			$tableName = $this->nextTableName();
			$tempTableRange = new AstRangeDatabaseTempTable('p_tmp', $innerQuery, $tableName);
			$stage = new TempTableStage('stage', $tempTableRange, new ExecutionPlan());

			$rows = [[
				'id'         => 1,
				'title'      => 'Hello',
				'content'    => 'World',
				'published'  => 1,
				'created_at' => '2024-01-01 12:00:00',
			]];

			$this->executor->execute($stage, fn() => $rows);

			$columns = $this->describeColumns($tableName);

			self::assertSame('int unsigned', $columns['id']);
			self::assertSame('varchar(255)', $columns['title']);
			self::assertSame('text', $columns['content']);
			self::assertSame('tinyint(1)', $columns['published']);
			self::assertSame('datetime', $columns['created_at']);

			// The typed columns must still be usable for their declared purpose,
			// not just correctly labelled.
			$inserted = $this->fetchAllAssoc("SELECT * FROM `{$tableName}`");

			self::assertSame('1', $this->scalarToString($inserted[0]['id']));
			self::assertSame('Hello', $this->scalarToString($inserted[0]['title']));
		}

		public function testFallsBackToVarcharForExpressionsThatArentEntityProperties(): void {
			$range = new AstRangeDatabase('p', PostEntity::class);
			$innerQuery = new AstRetrieve([], [$range], false);
			$innerQuery->addValue($this->propertyValue('id', 'id', $range));
			$innerQuery->addValue(new AstAlias('literal_col', new AstNumber('42')));

			$tableName = $this->nextTableName();
			$tempTableRange = new AstRangeDatabaseTempTable('p_tmp', $innerQuery, $tableName);
			$stage = new TempTableStage('stage', $tempTableRange, new ExecutionPlan());

			$rows = [[
				'id'          => 1,
				'literal_col' => '42',
			]];

			$this->executor->execute($stage, fn() => $rows);

			$columns = $this->describeColumns($tableName);

			// Resolvable column still gets its real type...
			self::assertSame('int unsigned', $columns['id']);

			// ...while the computed expression falls back, rather than guessing.
			self::assertSame('varchar(255)', $columns['literal_col']);
		}

		/**
		 * Covers the empty-LEFT-JOIN case the design doc calls out as the
		 * reason PHP-value inference was rejected: with zero rows to sample,
		 * Metadata-based resolution still produces a correctly typed table.
		 */
		public function testEmptyLeftJoinResultStillProducesTypedTable(): void {
			$range = new AstRangeDatabase('p', PostEntity::class);
			$innerQuery = new AstRetrieve([], [$range], false);
			$innerQuery->addValue($this->propertyValue('id', 'id', $range));
			$innerQuery->addValue($this->propertyValue('published', 'published', $range));

			$tableName = $this->nextTableName();

			// $required = false reproduces a LEFT JOIN range: PlanExecutor must
			// still create the (empty) temp table so the outer query's LEFT
			// JOIN sees a real, correctly-typed table instead of failing.
			$tempTableRange = new AstRangeDatabaseTempTable('p_tmp', $innerQuery, $tableName, null, false);
			$stage = new TempTableStage('stage', $tempTableRange, new ExecutionPlan());

			$this->executor->execute($stage, fn() => []);

			$columns = $this->describeColumns($tableName);

			self::assertSame('int unsigned', $columns['id']);
			self::assertSame('tinyint(1)', $columns['published']);
		}

		public function testRequiredInnerJoinWithNoRowsSkipsTableCreationEntirely(): void {
			$range = new AstRangeDatabase('p', PostEntity::class);
			$innerQuery = new AstRetrieve([], [$range], false);
			$innerQuery->addValue($this->propertyValue('id', 'id', $range));

			$tableName = $this->nextTableName();

			// $required = true reproduces an INNER JOIN range: no table should
			// be created at all when the inner query returns no rows.
			$tempTableRange = new AstRangeDatabaseTempTable('p_tmp', $innerQuery, $tableName, null, true);
			$stage = new TempTableStage('stage', $tempTableRange, new ExecutionPlan());

			$this->executor->execute($stage, fn() => []);

			// SHOW TABLES never lists TEMPORARY tables regardless of whether one
			// was created, so it can't tell us anything here. DatabaseAdapter::execute()
			// swallows the underlying "table doesn't exist" exception and returns
			// null instead, which is the observable signal that no table exists.
			$result = self::em()->getConnection()->execute("SELECT 1 FROM `{$tableName}` LIMIT 1");

			self::assertNull($result);
		}
	}
