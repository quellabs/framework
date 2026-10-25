<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\DatabaseAdapter;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\SqlServerSchemaIntrospector;

	class SqlServerSchemaIntrospectorSchemaTest extends TestCase {
		/**
		 * Column and foreign-key catalogs target the table in the active schema.
		 * @return void
		 */
		public function testColumnAndForeignKeyLookupsUseActiveSchema(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getRoutineSchema', 'getPrimaryKeyColumns', 'execute'])->getMock();
			$adapter->method('getRoutineSchema')->willReturn('app');
			$adapter->expects(self::once())->method('getPrimaryKeyColumns')->with('app.users')->willReturn([]);
			$statement = $this->createStub(StatementInterface::class);
			$statement->method('fetchAll')->willReturn([]);
			$calls = 0;
			$adapter->expects(self::exactly(2))->method('execute')->willReturnCallback(
				function (string $sql, array $parameters) use ($statement, &$calls): StatementInterface {
					self::assertSame(['tableName' => 'users', 'schema' => 'app'], $parameters);
					if ($calls === 0) {
						self::assertStringContainsString('c.TABLE_SCHEMA = :schema', $sql);
						self::assertStringContainsString("OBJECT_ID(QUOTENAME(c.TABLE_SCHEMA) + '.' + QUOTENAME(c.TABLE_NAME))", $sql);
					} else {
						self::assertStringContainsString('JOIN sys.schemas s ON s.schema_id = t.schema_id', $sql);
						self::assertStringContainsString('s.name = :schema AND t.name = :tableName', $sql);
					}
					$calls++;
					return $statement;
				}
			);

			$inspector = new SqlServerSchemaIntrospector($adapter);
			self::assertSame([], $inspector->getColumns('users'));
			self::assertSame([], $inspector->getForeignKeys('users'));
		}
	}
