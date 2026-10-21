<?php

	namespace Quellabs\ObjectQuel\Tests\PostgreSQL;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * DatabaseAdapter::getForeignKeys() against a real PostgreSQL server —
	 * the live counterpart to DatabaseAdapterForeignKeyPostgresTest's mocked
	 * row-parsing coverage. Exercises the actual pg_constraint/conkey/confkey
	 * catalog query (PostgresSchemaIntrospector::getForeignKeys()), since a
	 * mocked statement can't catch a syntax error or a wrong join in the SQL
	 * itself.
	 */
	class ForeignKeyIntrospectionLiveTest extends TestCase {

		private static function connection(): DatabaseAdapter {
			$em = $GLOBALS['test_em'];

			if (!$em instanceof EntityManager) {
				throw new \RuntimeException("Test bootstrap did not initialize \$GLOBALS['test_em']");
			}

			return $em->getConnection();
		}

		protected function setUp(): void {
			$this->dropFixtureTables();
		}

		protected function tearDown(): void {
			$this->dropFixtureTables();
		}

		private function dropFixtureTables(): void {
			self::connection()->execute('DROP TABLE IF EXISTS oq_fk_live_orders');
			self::connection()->execute('DROP TABLE IF EXISTS oq_fk_live_shipments');
		}

		public function testGetForeignKeysReadsBackASingleColumnConstraintWithItsRules(): void {
			$connection = self::connection();
			$connection->execute('CREATE TABLE oq_fk_live_shipments (id INTEGER PRIMARY KEY)');
			$connection->execute(
				'CREATE TABLE oq_fk_live_orders (' .
				'id INTEGER PRIMARY KEY, shipment_id INTEGER NOT NULL, ' .
				'CONSTRAINT fk_oq_fk_live_orders_shipment_id FOREIGN KEY (shipment_id) ' .
				'REFERENCES oq_fk_live_shipments(id) ON DELETE CASCADE ON UPDATE RESTRICT' .
				')'
			);

			$foreignKeys = $connection->getForeignKeys('oq_fk_live_orders');

			self::assertArrayHasKey('fk_oq_fk_live_orders_shipment_id', $foreignKeys);

			$fk = $foreignKeys['fk_oq_fk_live_orders_shipment_id'];
			self::assertSame(['shipment_id'], $fk->columns);
			self::assertSame('oq_fk_live_shipments', $fk->referencedTable);
			self::assertSame(['id'], $fk->referencedColumns);
			self::assertSame('CASCADE', $fk->onDelete);
			self::assertSame('RESTRICT', $fk->onUpdate);
		}

		/**
		 * The real target of this file: a composite constraint whose local
		 * and referenced columns are declared in a non-alphabetical order
		 * ('tenant_id' before 'line'). information_schema's
		 * key_column_usage/constraint_column_usage join has no ordinal link
		 * between the two sides and would pair every local column against
		 * every referenced one; pg_constraint's conkey/confkey ordinality
		 * must read the four columns back as exactly two correctly paired
		 * positions.
		 */
		public function testGetForeignKeysReadsBackACompositeConstraintWithCorrectlyPairedColumns(): void {
			$connection = self::connection();
			$connection->execute('CREATE TABLE oq_fk_live_shipments (tenant_id INTEGER, line INTEGER, PRIMARY KEY (tenant_id, line))');
			$connection->execute(
				'CREATE TABLE oq_fk_live_orders (' .
				'shipment_tenant_id INTEGER NOT NULL, shipment_line INTEGER NOT NULL, ' .
				'CONSTRAINT fk_oq_fk_live_orders_shipment_tenant_id_shipment_line FOREIGN KEY (shipment_tenant_id, shipment_line) ' .
				'REFERENCES oq_fk_live_shipments(tenant_id, line) ON DELETE CASCADE ON UPDATE RESTRICT' .
				')'
			);

			$foreignKeys = $connection->getForeignKeys('oq_fk_live_orders');

			self::assertArrayHasKey('fk_oq_fk_live_orders_shipment_tenant_id_shipment_line', $foreignKeys);

			$fk = $foreignKeys['fk_oq_fk_live_orders_shipment_tenant_id_shipment_line'];
			self::assertSame(['shipment_tenant_id', 'shipment_line'], $fk->columns);
			self::assertSame('oq_fk_live_shipments', $fk->referencedTable);
			self::assertSame(['tenant_id', 'line'], $fk->referencedColumns);
			self::assertSame('CASCADE', $fk->onDelete);
			self::assertSame('RESTRICT', $fk->onUpdate);
		}

		public function testGetForeignKeysReturnsEmptyArrayForATableWithNoConstraints(): void {
			self::connection()->execute('CREATE TABLE oq_fk_live_shipments (id INTEGER PRIMARY KEY)');

			self::assertSame([], self::connection()->getForeignKeys('oq_fk_live_shipments'));
		}
	}
