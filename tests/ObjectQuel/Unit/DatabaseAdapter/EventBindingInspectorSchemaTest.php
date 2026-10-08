<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\DatabaseAdapter;

	use Cake\Database\StatementInterface;
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\EventBindingInspector;

	class EventBindingInspectorSchemaTest extends TestCase {

		/**
		 * @return list<array{string, string, string}>
		 */
		public static function schemaPredicates(): array {
			return [
				['pgsql', 'JOIN pg_namespace s ON s.oid = c.relnamespace', 's.nspname = :schema'],
				['sqlsrv', 'JOIN sys.schemas s ON s.schema_id = o.schema_id', 's.name = :schema'],
			];
		}

		/**
		 * Checks the trigger on the active schema's table when another schema may
		 * contain the same table and trigger names.
		 * @param string $databaseType Database engine
		 * @param string $schemaJoin Expected catalog join
		 * @param string $schemaPredicate Expected schema filter
		 * @return void
		 */
		#[DataProvider('schemaPredicates')]
		public function testTriggerLookupScopesTheParentTable(string $databaseType, string $schemaJoin, string $schemaPredicate): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'getRoutineSchema', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn($databaseType);
			$adapter->method('getRoutineSchema')->willReturn('app');
			$statement = $this->createStub(StatementInterface::class);
			$statement->method('fetch')->willReturn(['n' => 1]);
			$adapter->expects(self::once())->method('execute')->with(
				self::callback(fn(string $sql): bool => str_contains($sql, $schemaJoin) && str_contains($sql, $schemaPredicate)),
				['name' => 'eq_abc_audit', 'table' => 'users', 'schema' => 'app']
			)->willReturn($statement);

			self::assertTrue((new EventBindingInspector($adapter))->triggerExists('users', 'eq_abc_audit'));
		}
	}
