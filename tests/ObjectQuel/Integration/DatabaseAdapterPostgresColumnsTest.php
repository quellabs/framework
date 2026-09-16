<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeForeignKeyStatement;

	/**
	 * DatabaseAdapter::getColumns()'s PostgreSQL branch (getPostgresColumns()).
	 * No pdo_pgsql driver is available here, so DatabaseAdapter is
	 * partial-mocked with execute()/getDatabaseType() stubbed to return
	 * canned rows shaped like the real information_schema.columns result,
	 * letting the real row-parsing/type-mapping logic run — same precedent
	 * as DatabaseAdapterForeignKeyPostgresTest. Also covers
	 * getPrimaryKeyColumns()/execute() since getColumns() calls that too;
	 * both go through the same stubbed execute(), so the canned rows must
	 * cover both queries (see makeAdapter()).
	 */
	class DatabaseAdapterPostgresColumnsTest extends TestCase {

		/**
		 * @param array<int, array<string, mixed>> $columnRows Rows for the information_schema.columns query
		 */
		private function makeAdapter(array $columnRows): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType', 'getSchemaCollection'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn('pgsql');
			$adapter->method('execute')->willReturn(new FakeForeignKeyStatement($columnRows));

			// getColumns() also calls getPrimaryKeyColumns(), which goes
			// through getSchemaCollection() rather than execute() — stub it
			// to report no primary key, since these tests aren't exercising
			// that path.
			$schema = $this->createMock(\Cake\Database\Schema\TableSchemaInterface::class);
			$schema->method('constraints')->willReturn([]);
			$collection = $this->createMock(\Cake\Database\Schema\CollectionInterface::class);
			$collection->method('describe')->willReturn($schema);
			$adapter->method('getSchemaCollection')->willReturn($collection);

			return $adapter;
		}

		public function testCharacterVaryingMapsToStringWithLimit(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'name', 'data_type' => 'character varying', 'is_identity' => 'NO', 'is_nullable' => 'YES', 'column_default' => null, 'character_maximum_length' => '120', 'numeric_precision' => null, 'numeric_scale' => null],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('string', $columns['name']->type);
			self::assertSame(120, $columns['name']->limit);
			self::assertTrue($columns['name']->nullable);
		}

		public function testJsonbNormalizesToJson(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'payload', 'data_type' => 'jsonb', 'is_identity' => 'NO', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'numeric_precision' => null, 'numeric_scale' => null],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('json', $columns['payload']->type);
		}

		public function testDecimalPrecisionAndScaleAreReadBackButFloatHasNone(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'price', 'data_type' => 'numeric', 'is_identity' => 'NO', 'is_nullable' => 'YES', 'column_default' => null, 'character_maximum_length' => null, 'numeric_precision' => '10', 'numeric_scale' => '2'],
				['column_name' => 'ratio', 'data_type' => 'real', 'is_identity' => 'NO', 'is_nullable' => 'YES', 'column_default' => null, 'character_maximum_length' => null, 'numeric_precision' => '24', 'numeric_scale' => null],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('decimal', $columns['price']->type);
			self::assertSame(10, $columns['price']->precision);
			self::assertSame(2, $columns['price']->scale);

			self::assertSame('float', $columns['ratio']->type);
			self::assertNull($columns['ratio']->precision);
		}

		public function testIdentityColumnDefaultIsAlwaysNullRegardlessOfColumnDefault(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'id', 'data_type' => 'integer', 'is_identity' => 'YES', 'is_nullable' => 'NO', 'column_default' => "nextval('orders_id_seq'::regclass)", 'character_maximum_length' => null, 'numeric_precision' => '32', 'numeric_scale' => '0'],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertTrue($columns['id']->identity);
			self::assertNull($columns['id']->default);
		}

		public function testStringDefaultHasCastAndQuotesStripped(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'status', 'data_type' => 'character varying', 'is_identity' => 'NO', 'is_nullable' => 'NO', 'column_default' => "'pending'::character varying", 'character_maximum_length' => '255', 'numeric_precision' => null, 'numeric_scale' => null],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('pending', $columns['status']->default);
		}

		public function testTimestampAndTimeVariantsMapCorrectly(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'created_at', 'data_type' => 'timestamp without time zone', 'is_identity' => 'NO', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'numeric_precision' => null, 'numeric_scale' => null],
				['column_name' => 'opens_at', 'data_type' => 'time without time zone', 'is_identity' => 'NO', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'numeric_precision' => null, 'numeric_scale' => null],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('datetime', $columns['created_at']->type);
			self::assertSame('time', $columns['opens_at']->type);
		}

		public function testUuidAndBooleanMapCorrectly(): void {
			$adapter = $this->makeAdapter([
				['column_name' => 'id', 'data_type' => 'uuid', 'is_identity' => 'NO', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'numeric_precision' => null, 'numeric_scale' => null],
				['column_name' => 'active', 'data_type' => 'boolean', 'is_identity' => 'NO', 'is_nullable' => 'NO', 'column_default' => null, 'character_maximum_length' => null, 'numeric_precision' => null, 'numeric_scale' => null],
			]);

			$columns = $adapter->getColumns('orders');

			self::assertSame('uuid', $columns['id']->type);
			self::assertSame('boolean', $columns['active']->type);
		}
	}
