<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeForeignKeyStatement;

	/**
	 * DatabaseAdapter::getForeignKeys()'s SQL Server branch (getSqlServerForeignKeys()).
	 * No sqlsrv driver is available here, so DatabaseAdapter is partial-mocked
	 * with execute()/getDatabaseType() stubbed to return canned rows shaped like
	 * sys.foreign_keys/sys.foreign_key_columns, letting the real parsing logic run.
	 */
	class DatabaseAdapterForeignKeySqlServerTest extends TestCase {

		private function makeAdapter(array $rows): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn('sqlsrv');
			$adapter->method('execute')->willReturn(new FakeForeignKeyStatement($rows));

			return $adapter;
		}

		public function testReadsBackASingleColumnConstraintWithItsRulesNormalized(): void {
			// SQL Server's *_referential_action_desc columns use underscores.
			$adapter = $this->makeAdapter([
				[
					'constraint_name'   => 'fk_orders_customer_id',
					'column_name'       => 'customer_id',
					'ordinal_position'  => 1,
					'referenced_table'  => 'customers',
					'referenced_column' => 'id',
					'delete_rule'       => 'CASCADE',
					'update_rule'       => 'NO_ACTION',
				],
			]);

			$foreignKeys = $adapter->getForeignKeys('orders');

			self::assertArrayHasKey('fk_orders_customer_id', $foreignKeys);

			$fk = $foreignKeys['fk_orders_customer_id'];
			self::assertSame(['customer_id'], $fk['columns']);
			self::assertSame('customers', $fk['referencedTable']);
			self::assertSame(['id'], $fk['referencedColumns']);
			self::assertSame('CASCADE', $fk['onDelete']);
			self::assertSame('NO ACTION', $fk['onUpdate']);
		}

		public function testReturnsEmptyArrayForATableWithNoConstraints(): void {
			self::assertSame([], $this->makeAdapter([])->getForeignKeys('standalone'));
		}

		/**
		 * sys.foreign_key_columns stores one row per column pair natively
		 * (constraint_column_id gives the correct ordinal), so a composite
		 * constraint round-trips correctly here with no ambiguity to guard against.
		 */
		public function testCompositeConstraintColumnsRoundTripInTheCorrectOrder(): void {
			$adapter = $this->makeAdapter([
				['constraint_name' => 'fk_orders_shipment', 'column_name' => 'shipment_id', 'ordinal_position' => 1, 'referenced_table' => 'shipments', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO_ACTION'],
				['constraint_name' => 'fk_orders_shipment', 'column_name' => 'shipment_line', 'ordinal_position' => 2, 'referenced_table' => 'shipments', 'referenced_column' => 'line', 'delete_rule' => 'CASCADE', 'update_rule' => 'NO_ACTION'],
			]);

			$foreignKeys = $adapter->getForeignKeys('orders');

			self::assertArrayHasKey('fk_orders_shipment', $foreignKeys);

			$fk = $foreignKeys['fk_orders_shipment'];
			self::assertSame(['shipment_id', 'shipment_line'], $fk['columns']);
			self::assertSame('shipments', $fk['referencedTable']);
			self::assertSame(['id', 'line'], $fk['referencedColumns']);
		}

		public function testSupportsForeignKeyIntrospectionIsTrueForSqlServer(): void {
			$adapter = $this->makeAdapter([]);
			$platform = new PlatformCapabilities($adapter);

			self::assertTrue($platform->supportsForeignKeyIntrospection());
		}

		public function testSupportsNamedForeignKeysIsTrueForSqlServer(): void {
			$adapter = $this->makeAdapter([]);
			$platform = new PlatformCapabilities($adapter);

			self::assertTrue($platform->supportsNamedForeignKeys());
		}
	}
