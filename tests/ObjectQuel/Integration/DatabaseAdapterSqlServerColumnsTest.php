<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeForeignKeyStatement;

	/**
	 * DatabaseAdapter::getColumns()'s SQL Server branch (getSqlServerColumns()).
	 * No sqlsrv driver is available here, so DatabaseAdapter is
	 * partial-mocked with execute()/getDatabaseType() stubbed to return
	 * canned rows shaped like the real INFORMATION_SCHEMA.COLUMNS join
	 * (including the COLUMNPROPERTY(...,'IsIdentity') column), letting the
	 * real row-parsing/type-mapping logic run — same precedent as
	 * DatabaseAdapterForeignKeyPostgresTest.
	 *
	 * Deliberately covers the two behavior changes over the old Phinx-backed
	 * getColumns() called out in objectquel-phinx-removal-plan.md: 'datetime2'
	 * now maps to 'datetime', and MAX-length 'nvarchar' now maps to 'text' —
	 * neither of which Phinx's own SQL Server adapter recognized.
	 */
	class DatabaseAdapterSqlServerColumnsTest extends TestCase {

		/**
		 * @param array<int, array<string, mixed>> $columnRows Rows for the INFORMATION_SCHEMA.COLUMNS query
		 */
		private function makeAdapter(array $columnRows): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType', 'getSchemaCollection'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn('sqlsrv');
			$adapter->method('execute')->willReturn(new FakeForeignKeyStatement($columnRows));

			$schema = $this->createMock(\Cake\Database\Schema\TableSchemaInterface::class);
			$schema->method('constraints')->willReturn([]);
			$collection = $this->createMock(\Cake\Database\Schema\CollectionInterface::class);
			$collection->method('describe')->willReturn($schema);
			$adapter->method('getSchemaCollection')->willReturn($collection);

			return $adapter;
		}

		public function testDatetime2MapsToDatetime(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'created_at', 'data_type' => 'datetime2', 'character_maximum_length' => null, 'numeric_precision' => null, 'numeric_scale' => null, 'column_default' => null, 'is_nullable' => 'NO', 'is_identity' => 0],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('datetime', $columns['created_at']['type']);
		}

		public function testMaxLengthNvarcharMapsToTextButOrdinaryNvarcharMapsToString(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'payload', 'data_type' => 'nvarchar', 'character_maximum_length' => '-1', 'numeric_precision' => null, 'numeric_scale' => null, 'column_default' => null, 'is_nullable' => 'YES', 'is_identity' => 0],
				['column_name' => 'name', 'data_type' => 'nvarchar', 'character_maximum_length' => '255', 'numeric_precision' => null, 'numeric_scale' => null, 'column_default' => null, 'is_nullable' => 'YES', 'is_identity' => 0],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('text', $columns['payload']['type']);
			self::assertSame('string', $columns['name']['type']);
			self::assertSame(255, $columns['name']['limit']);
		}

		public function testIdentityColumnIsDetectedViaColumnProperty(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'id', 'data_type' => 'int', 'character_maximum_length' => null, 'numeric_precision' => '10', 'numeric_scale' => '0', 'column_default' => null, 'is_nullable' => 'NO', 'is_identity' => 1],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertTrue($columns['id']['identity']);
		}

		public function testDecimalPrecisionAndScaleAreReadBackButRealHasNone(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'price', 'data_type' => 'decimal', 'character_maximum_length' => null, 'numeric_precision' => '10', 'numeric_scale' => '2', 'column_default' => null, 'is_nullable' => 'YES', 'is_identity' => 0],
				['column_name' => 'ratio', 'data_type' => 'real', 'character_maximum_length' => null, 'numeric_precision' => '24', 'numeric_scale' => null, 'column_default' => null, 'is_nullable' => 'YES', 'is_identity' => 0],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('decimal', $columns['price']['type']);
			self::assertSame(10, $columns['price']['precision']);
			self::assertSame(2, $columns['price']['scale']);

			self::assertSame('float', $columns['ratio']['type']);
			self::assertNull($columns['ratio']['precision']);
		}

		public function testDefaultValueParenAndQuoteWrappingIsStripped(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'status', 'data_type' => 'varchar', 'character_maximum_length' => '20', 'numeric_precision' => null, 'numeric_scale' => null, 'column_default' => "('pending')", 'is_nullable' => 'NO', 'is_identity' => 0],
				['column_name' => 'count', 'data_type' => 'int', 'character_maximum_length' => null, 'numeric_precision' => '10', 'numeric_scale' => '0', 'column_default' => '((0))', 'is_nullable' => 'NO', 'is_identity' => 0],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('pending', $columns['status']['default']);
			self::assertSame(0, $columns['count']['default']);
		}

		public function testUuidAndBitMapCorrectly(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'id', 'data_type' => 'uniqueidentifier', 'character_maximum_length' => null, 'numeric_precision' => null, 'numeric_scale' => null, 'column_default' => null, 'is_nullable' => 'NO', 'is_identity' => 0],
				['column_name' => 'active', 'data_type' => 'bit', 'character_maximum_length' => null, 'numeric_precision' => null, 'numeric_scale' => null, 'column_default' => null, 'is_nullable' => 'NO', 'is_identity' => 0],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('uuid', $columns['id']['type']);
			self::assertSame('boolean', $columns['active']['type']);
		}
	}
