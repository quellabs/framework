<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\DatabaseAdapter;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\RoutineSchemaIntrospector;

	class RoutineSchemaIntrospectorTest extends TestCase {

		/**
		 * @return list<array{string, string}>
		 */
		public static function schemaQueries(): array {
			return [
				['pgsql', 'SELECT current_schema() AS routine_schema'],
				['sqlsrv', 'SELECT SCHEMA_NAME() AS routine_schema'],
			];
		}

		/**
		 * Reads and caches the connected schema used to qualify routines and bindings.
		 * @param string $databaseType Database engine
		 * @param string $query Expected schema lookup
		 * @return void
		 */
		#[DataProvider('schemaQueries')]
		public function testReadsConnectedSchema(string $databaseType, string $query): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn($databaseType);
			$statement = $this->createStub(StatementInterface::class);
			$statement->method('fetch')->willReturn(['routine_schema' => 'app']);
			$adapter->expects(self::once())->method('execute')->with($query)->willReturn($statement);

			$inspector = new RoutineSchemaIntrospector($adapter);
			self::assertSame('app', $inspector->getRoutineSchema());
			self::assertSame('app', $inspector->getRoutineSchema());
		}

		/**
		 * Engines without named schemas do not perform a lookup.
		 * @return void
		 */
		public function testMysqlUsesUnqualifiedNames(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');
			$adapter->expects(self::never())->method('execute');

			self::assertNull((new RoutineSchemaIntrospector($adapter))->getRoutineSchema());
		}
	}
