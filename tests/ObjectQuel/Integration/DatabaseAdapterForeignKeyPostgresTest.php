<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyComparator;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarEntity;
	use Quellabs\ObjectQuel\Tests\Support\FakeForeignKeyStatement;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * DatabaseAdapter::getForeignKeys()'s PostgreSQL branch (getPostgresForeignKeys()),
	 * exercised without a live server — this environment has no pdo_pgsql driver, so
	 * DatabaseAdapter is partial-mocked with execute()/getDatabaseType() stubbed to
	 * return canned rows shaped exactly like the real information_schema join would
	 * produce, letting the real (unmocked) row-parsing logic run against them.
	 */
	class DatabaseAdapterForeignKeyPostgresTest extends TestCase {
		use FkTestSupport;

		private function makeAdapter(array $rows): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn('pgsql');
			$adapter->method('execute')->willReturn(new FakeForeignKeyStatement($rows));

			return $adapter;
		}

		public function testReadsBackASingleColumnConstraintWithItsRules(): void {
			$adapter = $this->makeAdapter([
				[
					'constraint_name'   => 'fk_orders_customer_id',
					'column_name'       => 'customer_id',
					'referenced_table'  => 'customers',
					'referenced_column' => 'id',
					'delete_rule'       => 'CASCADE',
					'update_rule'       => 'RESTRICT',
				],
			]);

			$foreignKeys = $adapter->getForeignKeys('orders');

			self::assertArrayHasKey('fk_orders_customer_id', $foreignKeys);

			$fk = $foreignKeys['fk_orders_customer_id'];
			self::assertSame(['customer_id'], $fk['columns']);
			self::assertSame('customers', $fk['referencedTable']);
			self::assertSame(['id'], $fk['referencedColumns']);
			self::assertSame('CASCADE', $fk['onDelete']);
			self::assertSame('RESTRICT', $fk['onUpdate']);
		}

		public function testReturnsEmptyArrayForATableWithNoConstraints(): void {
			self::assertSame([], $this->makeAdapter([])->getForeignKeys('standalone'));
		}

		/**
		 * A composite foreign key's local/referenced columns join into a cross
		 * product here (2 local x 2 referenced = 4 rows) since information_schema
		 * has no ordinal linking the two sides for composite constraints. Since
		 * @Orm\ForeignKey only ever declares single-column constraints, this
		 * ambiguous constraint is simply omitted rather than reported with a
		 * possibly-wrong column pairing — while an unrelated single-column
		 * constraint in the same result set is still read back correctly.
		 */
		public function testCompositeConstraintIsOmittedButSingleColumnSiblingSurvives(): void {
			$adapter = $this->makeAdapter([
				// fk_orders_shipment (composite: shipment_id + shipment_line -> shipments(id, line))
				['constraint_name' => 'fk_orders_shipment', 'column_name' => 'shipment_id', 'referenced_table' => 'shipments', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO ACTION'],
				['constraint_name' => 'fk_orders_shipment', 'column_name' => 'shipment_id', 'referenced_table' => 'shipments', 'referenced_column' => 'line', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO ACTION'],
				['constraint_name' => 'fk_orders_shipment', 'column_name' => 'shipment_line', 'referenced_table' => 'shipments', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO ACTION'],
				['constraint_name' => 'fk_orders_shipment', 'column_name' => 'shipment_line', 'referenced_table' => 'shipments', 'referenced_column' => 'line', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO ACTION'],
				// fk_orders_customer_id (single-column, unaffected)
				['constraint_name' => 'fk_orders_customer_id', 'column_name' => 'customer_id', 'referenced_table' => 'customers', 'referenced_column' => 'id', 'delete_rule' => 'RESTRICT', 'update_rule' => 'NO ACTION'],
			]);

			$foreignKeys = $adapter->getForeignKeys('orders');

			self::assertArrayNotHasKey('fk_orders_shipment', $foreignKeys);
			self::assertArrayHasKey('fk_orders_customer_id', $foreignKeys);
			self::assertSame(['customer_id'], $foreignKeys['fk_orders_customer_id']['columns']);
		}

		public function testSupportsForeignKeyIntrospectionIsTrueForPostgres(): void {
			$adapter = $this->makeAdapter([]);
			$platform = new PlatformCapabilities($adapter);

			self::assertTrue($platform->supportsForeignKeyIntrospection());
		}

		public function testSupportsNamedForeignKeysIsTrueForPostgres(): void {
			$adapter = $this->makeAdapter([]);
			$platform = new PlatformCapabilities($adapter);

			self::assertTrue($platform->supportsNamedForeignKeys());
		}

		/**
		 * Proves the capability actually changes behavior end to end, not just
		 * in isolation: with a real PlatformCapabilities reporting Postgres
		 * support, ForeignKeyComparator must perform a REAL diff against the
		 * (faked) live schema — not silently fall back to an empty result the
		 * way it would for a genuinely unsupported engine. The live table has no
		 * constraint yet, so the entity's declared foreign key must show up as
		 * "added".
		 */
		public function testForeignKeyComparatorPerformsARealDiffOnPostgresNotJustASkip(): void {
			$adapter = $this->makeAdapter([]);
			$entityStore = $this->makeFkEntityStore();
			$comparator = new ForeignKeyComparator($adapter, $entityStore, new PlatformCapabilities($adapter));

			$diff = $comparator->compareForeignKeys(FkOrderScalarEntity::class);

			self::assertArrayHasKey('fk_fk_orders_scalar_customer_id', $diff['added']);
			self::assertSame([], $diff['modified']);
			self::assertSame([], $diff['deleted']);
		}
	}
