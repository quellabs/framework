<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Cake\Database\StatementInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\RoutineInspector;

	/**
	 * Verifies routine catalog lookup and native return-type mapping.
	 */
	class RoutineInspectorTest extends TestCase {

		/**
		 * Provides native return types and their normalized equivalents.
		 * @return array<string, array{string, string, string|null, int|null, string|null}>
		 */
		public static function returnTypes(): array {
			return [
				'mysql int'                 => ['mysql', 'int', 'int', null, 'integer'],
				'mysql tinyint(1)'          => ['mysql', 'tinyint', 'tinyint(1)', null, 'boolean'],
				'mariadb datetime'          => ['mariadb', 'datetime', 'datetime', null, 'datetime'],
				'mysql char(36)'            => ['mysql', 'char', 'char(36)', 36, 'uuid'],
				'mysql enum'                => ['mysql', 'enum', "enum('a','b')", 1, 'string'],
				'pgsql timestamp'           => ['pgsql', 'timestamp without time zone', null, null, 'datetime'],
				'pgsql boolean'             => ['pgsql', 'boolean', null, null, 'boolean'],
				'sqlsrv nvarchar(max)'      => ['sqlsrv', 'nvarchar', null, -1, 'text'],
				'sqlsrv datetime2'          => ['sqlsrv', 'datetime2', null, 8, 'datetime'],
				'sqlsrv legacy datetime'    => ['sqlsrv', 'datetime', null, 8, null],
				'pgsql type ObjectQuel lacks' => ['pgsql', 'money', null, null, null],
			];
		}

		/**
		 * Verifies native return-type normalization.
		 * @param string $databaseType Engine
		 * @param string $dataType Catalog type name
		 * @param string|null $typeDetail MySQL DTD_IDENTIFIER
		 * @param int|null $maxLength Catalog length
		 * @param string|null $expected Expected abstract type
		 * @return void
		 */
		#[DataProvider('returnTypes')]
		public function testReturnType(string $databaseType, string $dataType, ?string $typeDetail, ?int $maxLength, ?string $expected): void {
			self::assertSame($expected, RoutineInspector::returnType($databaseType, $dataType, $typeDetail, $maxLength));
		}

		/**
		 * Verifies catalog selection and safe name binding through the adapter.
		 * @param string $engine Database engine
		 * @param string $source Expected catalog SQL fragment
		 * @param string $boundName Expected bound routine name
		 * @return void
		 */
		#[DataProvider('catalogEngines')]
		public function testAdapterReadsCatalog(string $engine, string $source, string $boundName): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'getRoutineSchema', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn($engine);
			$adapter->method('getRoutineSchema')->willReturn('d]bo');
			$statement = $this->createMock(StatementInterface::class);
			$statement->method('fetchAll')->with('assoc')->willReturn([self::row(0, $engine === 'pgsql' ? 'integer' : 'int')]);
			$adapter->expects(self::once())->method('execute')->with(
				self::callback(fn(string $sql): bool => str_contains($sql, $source) && str_contains($sql, ' AS is_procedure, ')),
				['name' => $boundName]
			)->willReturn($statement);
			$signature = $adapter->getRoutineSignature('f]x');
			self::assertFalse($signature->isProcedure);
			self::assertSame('integer', $signature->returnType);
		}

		/**
		 * Provides catalog queries and bound names for supported engines.
		 * @return list<array{string, string, string}>
		 */
		public static function catalogEngines(): array {
			return [
				['mysql', 'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', 'f]x'],
				['mariadb', 'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', 'f]x'],
				['pgsql', 'FROM pg_proc WHERE proname = :name AND pg_function_is_visible(oid)', 'f]x'],
				['sqlsrv', 'WHERE o.object_id = OBJECT_ID(:name)', '[d]]bo].[f]]x]'],
			];
		}

		/**
		 * Builds a catalog row for a routine.
		 * @param int $procedure Procedure flag: 1 for procedures, 0 for functions
		 * @param string|null $type Native return type
		 * @return array{is_procedure: int, data_type: string|null, type_detail: null, max_length: null}
		 */
		private static function row(int $procedure, ?string $type): array {
			return ['is_procedure' => $procedure, 'data_type' => $type, 'type_detail' => null, 'max_length' => null];
		}

		/**
		 * Verifies routine kinds, overload return types, and lookup errors.
		 * @param list<array{is_procedure: int, data_type: string|null, type_detail: null, max_length: null}> $rows Catalog rows
		 * @param bool $procedure Expected procedure flag
		 * @param string|null $type Expected normalized return type
		 * @param string|null $error Expected error message fragment
		 * @return void
		 */
		#[DataProvider('catalogResults')]
		public function testCatalogResults(array $rows, bool $procedure, ?string $type, ?string $error): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('pgsql');
			$statement = $this->createMock(StatementInterface::class);
			$statement->method('fetchAll')->willReturn($rows);
			$adapter->method('execute')->willReturn($statement);
			if ($error !== null) {
				$this->expectException(QuelException::class);
				$this->expectExceptionMessage($error);
			}
			$signature = $adapter->getRoutineSignature('f');
			self::assertSame($procedure, $signature->isProcedure);
			self::assertSame($type, $signature->returnType);
		}

		/**
		 * Provides routine metadata and expected lookup outcomes.
		 * @return array<string, array{list<array{is_procedure: int, data_type: string|null, type_detail: null, max_length: null}>, bool, string|null, string|null}>
		 */
		public static function catalogResults(): array {
			return [
				'missing' => [[], false, null, 'no routine by that name exists'],
				'ambiguous' => [[self::row(0, 'integer'), self::row(1, null)], false, null, 'both a function and a procedure'],
				'procedure' => [[self::row(1, null)], true, null, null],
				'same return types' => [[self::row(0, 'integer'), self::row(0, 'integer')], false, 'integer', null],
				'different return types' => [[self::row(0, 'integer'), self::row(0, 'text')], false, null, null],
				'unknown return type' => [[self::row(0, 'money')], false, null, null],
			];
		}

		/**
		 * Verifies failed lookups retain the database error.
		 * @return void
		 */
		public function testLookupFailureIsDistinctFromMissingRoutine(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute', 'getLastErrorMessage'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');
			$adapter->method('execute')->willReturn(null);
			$adapter->method('getLastErrorMessage')->willReturn('catalog unavailable');
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Failed to look up routine 'f': catalog unavailable");
			$adapter->getRoutineSignature('f');
		}

		/**
		 * Verifies unsupported engines fail before querying the catalog.
		 * @return void
		 */
		public function testUnsupportedEngine(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('sqlite');
			$adapter->expects(self::never())->method('execute');
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Routines can't be called on 'sqlite'.");
			$adapter->getRoutineSignature('f');
		}

		/**
		 * Verifies the adapter does not retain stale routine metadata.
		 * @return void
		 */
		public function testMetadataIsRefreshedBetweenLookups(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('pgsql');
			$statement = $this->createMock(StatementInterface::class);
			$statement->method('fetchAll')->willReturnOnConsecutiveCalls([self::row(0, 'integer')], [self::row(0, 'text')]);
			$adapter->expects(self::exactly(2))->method('execute')->willReturn($statement);
			self::assertSame('integer', $adapter->getRoutineSignature('f')->returnType);
			self::assertSame('text', $adapter->getRoutineSignature('f')->returnType);
		}
	}
