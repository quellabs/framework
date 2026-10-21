<?php

	namespace Quellabs\ObjectQuel\Tests\PostgreSQL;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyComparator;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarEntity;
	use Quellabs\ObjectQuel\Tests\Support\FakeForeignKeyStatement;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * DatabaseAdapter::getForeignKeys()'s PostgreSQL branch (getPostgresForeignKeys()).
	 * No pdo_pgsql driver is available here, so DatabaseAdapter is partial-mocked
	 * with execute()/getDatabaseType() stubbed to return canned rows shaped like
	 * the real pg_constraint/conkey/confkey query, letting the real
	 * row-parsing logic run.
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
			self::assertSame(['customer_id'], $fk->columns);
			self::assertSame('customers', $fk->referencedTable);
			self::assertSame(['id'], $fk->referencedColumns);
			self::assertSame('CASCADE', $fk->onDelete);
			self::assertSame('RESTRICT', $fk->onUpdate);
		}

		public function testReturnsEmptyArrayForATableWithNoConstraints(): void {
			self::assertSame([], $this->makeAdapter([])->getForeignKeys('standalone'));
		}

		/**
		 * The real query pairs each composite constraint's local/referenced
		 * columns via pg_constraint's conkey/confkey ordinality, so the rows
		 * fed to the parser here are already one-row-per-position, correctly
		 * paired — never the every-combination cross product the old
		 * information_schema join produced. An unrelated single-column
		 * constraint in the same result set still reads back correctly.
		 */
		public function testCompositeConstraintReadsBackWithOrderedPairingAndSingleColumnSiblingSurvives(): void {
			$adapter = $this->makeAdapter([
				// fk_orders_shipment (composite: shipment_id, shipment_line -> shipments(id, line))
				['constraint_name' => 'fk_orders_shipment', 'column_name' => 'shipment_id', 'referenced_table' => 'shipments', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO ACTION'],
				['constraint_name' => 'fk_orders_shipment', 'column_name' => 'shipment_line', 'referenced_table' => 'shipments', 'referenced_column' => 'line', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO ACTION'],
				// fk_orders_customer_id (single-column, unaffected)
				['constraint_name' => 'fk_orders_customer_id', 'column_name' => 'customer_id', 'referenced_table' => 'customers', 'referenced_column' => 'id', 'delete_rule' => 'RESTRICT', 'update_rule' => 'NO ACTION'],
			]);

			$foreignKeys = $adapter->getForeignKeys('orders');

			self::assertArrayHasKey('fk_orders_shipment', $foreignKeys);

			$composite = $foreignKeys['fk_orders_shipment'];
			self::assertSame(['shipment_id', 'shipment_line'], $composite->columns);
			self::assertSame('shipments', $composite->referencedTable);
			self::assertSame(['id', 'line'], $composite->referencedColumns);

			self::assertArrayHasKey('fk_orders_customer_id', $foreignKeys);
			self::assertSame(['customer_id'], $foreignKeys['fk_orders_customer_id']->columns);
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
		 * With a real PlatformCapabilities reporting Postgres support,
		 * ForeignKeyComparator must perform a real diff, not fall back to the
		 * empty result an unsupported engine gets — the live table has no
		 * constraint yet, so the entity's FK must show up as "added".
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
