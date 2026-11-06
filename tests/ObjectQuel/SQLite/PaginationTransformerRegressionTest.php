<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Regression coverage for a PaginationTransformer bug found auditing it for
	 * composite-key readiness, pre-existing for a single-column key too:
	 * addInConditionForPagination() wrapped every page-filter value in
	 * AstNumber, so a non-numeric primary key (e.g. a natural string key)
	 * compiled to an unquoted, invalid SQL literal.
	 *
	 * Uses the suite's shared $GLOBALS['test_em'] — see
	 * RelationshipCascadeForeignKeyTest for why a second EntityManager can't
	 * be constructed in this process.
	 */
	class PaginationTransformerRegressionTest extends TestCase {

		private static function em(): EntityManager {
			return $GLOBALS['test_em'];
		}

		protected function setUp(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute('DELETE FROM string_pk_entities');
			self::em()->getUnitOfWork()->clear();
		}

		protected function tearDown(): void {
			// This test's shared EntityManager is reused by the rest of the suite.
			self::em()->getConfiguration()->setDefaultWindowSize(null);
		}

		public function testWindowedPaginationOverAStringPrimaryKeyDoesNotCompileAnInvalidLiteral(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('B', 20)");
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('A', 10)");
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('C', 30)");

			$rows = self::em()->getAll('
				range of s is App\\Entities\\StringPkEntity
				retrieve (s.code, s.position)
				sort by s.position
				window 0, 2
			');

			$this->assertSame(
				[['s.code' => 'A', 's.position' => 10], ['s.code' => 'B', 's.position' => 20]],
				$rows
			);
		}

		/**
		 * A window clause without an explicit size falls back to a page size of 1.
		 */
		public function testWindowWithoutExplicitSizeDefaultsToOne(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('B', 20)");
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('A', 10)");
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('C', 30)");

			$rows = self::em()->getAll('
				range of s is App\\Entities\\StringPkEntity
				retrieve (s.code, s.position)
				sort by s.position
				window 1
			');

			$this->assertSame(
				[['s.code' => 'B', 's.position' => 20]],
				$rows
			);
		}

		/**
		 * A window clause without an explicit size uses Configuration::$defaultWindowSize when set.
		 */
		public function testConfiguredDefaultWindowSizeAppliesWhenSizeIsOmitted(): void {
			$adapter = self::em()->getConnection();
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('B', 20)");
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('A', 10)");
			$adapter->execute("INSERT INTO string_pk_entities (code, position) VALUES ('C', 30)");

			self::em()->getConfiguration()->setDefaultWindowSize(2);

			$rows = self::em()->getAll('
				range of s is App\\Entities\\StringPkEntity
				retrieve (s.code, s.position)
				sort by s.position
				window 1
			');

			$this->assertSame(
				[['s.code' => 'C', 's.position' => 30]],
				$rows
			);
		}
	}
