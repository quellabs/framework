<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Cake\Database\StatementInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\RoutineDefinitionInspector;

	/**
	 * Verifies routine catalog lookup and native return-type mapping.
	 */
	class RoutineDefinitionInspectorTest extends TestCase {

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
			self::assertSame($expected, RoutineDefinitionInspector::returnType($databaseType, $dataType, $typeDetail, $maxLength));
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
		 * Checks whether a name exists without rejecting a function/procedure name collision.
		 * @param string $engine Database engine
		 * @param string $source Expected catalog SQL fragment
		 * @param string $boundName Expected bound routine name
		 * @param int $count Number of matching kinds in the catalog
		 * @param bool $expected Expected existence result
		 * @return void
		 */
		#[DataProvider('existenceResults')]
		public function testAdapterChecksRoutineExistence(string $engine, string $source, string $boundName, int $count, bool $expected): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'getRoutineSchema', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn($engine);
			$adapter->method('getRoutineSchema')->willReturn('d]bo');
			$statement = $this->createMock(StatementInterface::class);
			$statement->method('fetch')->with('assoc')->willReturn(['routine_count' => $count]);
			$adapter->expects(self::once())->method('execute')->with(
				self::callback(fn(string $sql): bool => str_contains($sql, $source) && str_contains($sql, 'COUNT(*) AS routine_count')),
				['name' => $boundName]
			)->willReturn($statement);

			self::assertSame($expected, $adapter->routineExists('f]x'));
		}

		/**
		 * Provides existence catalog queries and counts for absent, present and colliding routine kinds.
		 * @return list<array{string, string, string, int, bool}>
		 */
		public static function existenceResults(): array {
			return [
				['mysql', 'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', 'f]x', 0, false],
				['mysql', 'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', 'f]x', 1, true],
				['mysql', 'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', 'f]x', 2, true],
				['mariadb', 'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', 'f]x', 1, true],
				['pgsql', 'FROM pg_proc WHERE proname = :name AND pg_function_is_visible(oid)', 'f]x', 1, true],
				['sqlsrv', "OBJECT_ID(:name) AND type IN ('P', 'PC', 'FN', 'FS', 'IF', 'TF', 'FT')", '[d]]bo].[f]]x]', 1, true],
			];
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
				'ambiguous' => [[self::row(0, 'integer'), self::row(1, null)], false, null, 'both a void and a value-returning function'],
				'procedure' => [[self::row(1, null)], true, null, null],
				'same return types' => [[self::row(0, 'integer'), self::row(0, 'integer')], false, 'integer', null],
				'different return types' => [[self::row(0, 'integer'), self::row(0, 'text')], false, null, null],
				'unknown return type' => [[self::row(0, 'money')], false, null, null],
			];
		}

		/**
		 * Provides a MySQL ROUTINE_COMMENT and whether it marks the routine as needing a
		 * caller-side transaction: the legacy sentinel, current JSON metadata with `atomic`
		 * true/false, and unrelated comment content.
		 * @return array<string, array{string|null, bool}>
		 */
		public static function atomicComments(): array {
			return [
				'no comment'               => [null, false],
				'legacy sentinel'          => ['ObjectQuel:atomic-block', true],
				'json atomic true'         => ['{"objectQuel":1,"returnType":"void","atomic":true,"parameters":[],"safety":{"calls":[],"reads":[],"writes":[],"features":[]}}', true],
				'json atomic false'        => ['{"objectQuel":1,"returnType":"void","atomic":false,"parameters":[],"safety":{"calls":[],"reads":[],"writes":[],"features":[]}}', false],
				'unrelated comment'        => ['not ObjectQuel metadata at all', false],
			];
		}

		/**
		 * @param string|null $comment MySQL ROUTINE_COMMENT, legacy sentinel or JSON metadata
		 * @param bool $expected Expected needsTransaction
		 * @return void
		 */
		#[DataProvider('atomicComments')]
		public function testNeedsTransactionFromRoutineComment(?string $comment, bool $expected): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');
			$statement = $this->createMock(StatementInterface::class);
			$row = self::row(1, null);
			$row['routine_comment'] = $comment;
			$statement->method('fetchAll')->willReturn([$row]);
			$adapter->method('execute')->willReturn($statement);

			self::assertSame($expected, $adapter->getRoutineSignature('f')->needsTransaction);
		}

		/**
		 * JSON that isn't ObjectQuel's own versioned shape (no `objectQuel` key) falls back to
		 * the legacy exact-match check rather than being misread as metadata.
		 * @return void
		 */
		public function testNeedsTransactionIgnoresForeignJsonComment(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');
			$statement = $this->createMock(StatementInterface::class);
			$row = self::row(1, null);
			$row['routine_comment'] = '{"unrelated":"json"}';
			$statement->method('fetchAll')->willReturn([$row]);
			$adapter->method('execute')->willReturn($statement);

			self::assertFalse($adapter->getRoutineSignature('f')->needsTransaction);
		}

		/**
		 * Provides a routine comment/extended-property value and whether it marks the routine
		 * as `trigger`-declared. Unlike `atomic`, there is no legacy sentinel for `trigger` — it
		 * did not exist before this metadata did.
		 * @return array<string, array{string|null, bool}>
		 */
		public static function triggerComments(): array {
			return [
				'no comment'            => [null, false],
				'void metadata'         => ['{"objectQuel":1,"returnType":"void","atomic":false,"parameters":[],"safety":{"calls":[],"reads":[],"writes":[],"features":[]}}', false],
				'trigger metadata'      => ['{"objectQuel":1,"returnType":"trigger","atomic":false,"parameters":[{"kind":"entity","type":"App\\\\Entities\\\\UserEntity"}],"safety":{"calls":[],"reads":[],"writes":[],"features":[]}}', true],
				'legacy sentinel'       => ['ObjectQuel:atomic-block', false],
				'unrelated comment'     => ['not ObjectQuel metadata at all', false],
			];
		}

		/**
		 * @param string|null $comment Routine comment or extended-property value
		 * @param bool $expected Expected isTrigger
		 * @return void
		 */
		#[DataProvider('triggerComments')]
		public function testIsTriggerFromRoutineComment(?string $comment, bool $expected): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('pgsql');
			$statement = $this->createMock(StatementInterface::class);
			$row = self::row(1, null);
			$row['routine_comment'] = $comment;
			$statement->method('fetchAll')->willReturn([$row]);
			$adapter->method('execute')->willReturn($statement);

			self::assertSame($expected, $adapter->getRoutineSignature('f')->isTrigger);
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
		 * Verifies failed existence checks keep lookup errors distinct from missing routines.
		 * @return void
		 */
		public function testExistenceLookupFailureRetainsDatabaseError(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute', 'getLastErrorMessage'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');
			$adapter->method('execute')->willReturn(null);
			$adapter->method('getLastErrorMessage')->willReturn('catalog unavailable');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Failed to look up routine 'f': catalog unavailable");
			$adapter->routineExists('f');
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
		 * Verifies existence checks reject unsupported engines without querying a catalog.
		 * @return void
		 */
		public function testRoutineExistsUnsupportedEngine(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('sqlite');
			$adapter->expects(self::never())->method('execute');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Routines can't be looked up on 'sqlite'.");
			$adapter->routineExists('f');
		}

		/**
		 * Verifies routine existence is read again after a routine is created or removed.
		 * @return void
		 */
		public function testExistenceMetadataIsRefreshedBetweenLookups(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');
			$statement = $this->createMock(StatementInterface::class);
			$statement->method('fetch')->with('assoc')->willReturnOnConsecutiveCalls(
				['routine_count' => 0],
				['routine_count' => 1]
			);
			$adapter->expects(self::exactly(2))->method('execute')->willReturn($statement);

			self::assertFalse($adapter->routineExists('f'));
			self::assertTrue($adapter->routineExists('f'));
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

		/**
		 * Verifies listRoutines() issues both the routine-listing and the parameter-listing
		 * catalog query, unfiltered by name, with the expected bound parameters.
		 * @param string $engine Database engine
		 * @param string $listSource Expected routine-listing SQL fragment
		 * @param string $paramSource Expected parameter-listing SQL fragment
		 * @param array<string, string> $parameters Expected bound parameters, shared by both queries
		 * @return void
		 */
		#[DataProvider('listCatalogEngines')]
		public function testListRoutinesBuildsCatalogQueries(string $engine, string $listSource, string $paramSource, array $parameters): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'getRoutineSchema', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn($engine);
			$adapter->method('getRoutineSchema')->willReturn('d]bo');
			$statement = $this->createMock(StatementInterface::class);
			$statement->method('fetchAll')->with('assoc')->willReturn([]);

			$calls = [];
			$adapter->expects(self::exactly(2))->method('execute')->willReturnCallback(
				function (string $sql, array $boundParameters = []) use (&$calls, $statement): StatementInterface {
					$calls[] = [$sql, $boundParameters];
					return $statement;
				}
			);

			self::assertSame([], $adapter->listRoutines());

			self::assertTrue(self::anyCallMatches($calls, $listSource, $parameters), "No call matched list query fragment: {$listSource}");
			self::assertTrue(self::anyCallMatches($calls, $paramSource, $parameters), "No call matched parameter query fragment: {$paramSource}");

			foreach ($calls as [$sql]) {
				self::assertStringNotContainsString('ROUTINE_NAME = :name', $sql);
				self::assertStringNotContainsString('proname = :name', $sql);
			}
		}

		/**
		 * @param list<array{string, array<string, string>}> $calls Captured [sql, boundParameters] pairs
		 * @param string $source Expected SQL fragment
		 * @param array<string, string> $parameters Expected bound parameters
		 * @return bool True when some call matches both the SQL fragment and the bound parameters
		 */
		private static function anyCallMatches(array $calls, string $source, array $parameters): bool {
			foreach ($calls as [$sql, $boundParameters]) {
				if (str_contains($sql, $source) && $boundParameters === $parameters) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Provides list-catalog queries and bound parameters for supported engines.
		 * @return list<array{string, string, string, array<string, string>}>
		 */
		public static function listCatalogEngines(): array {
			return [
				['mysql', 'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', 'FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA = DATABASE()', []],
				['mariadb', 'FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()', 'FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA = DATABASE()', []],
				['pgsql', 'FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema()', 'CROSS JOIN LATERAL unnest(p.proargtypes::oid[])', []],
				['sqlsrv', 'FROM sys.objects o JOIN sys.schemas s ON s.schema_id = o.schema_id', 'JOIN sys.parameters p ON p.object_id = o.object_id AND p.parameter_id > 0', ['schema' => 'd]bo']],
			];
		}

		/**
		 * Verifies catalog rows are grouped by name and kind: same-kind overloads merge their
		 * return types, and a name shared between a function and a procedure yields two entries.
		 * @return void
		 */
		public function testListRoutinesGroupsByNameAndKind(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('pgsql');

			$listStatement = $this->createMock(StatementInterface::class);
			$listStatement->method('fetchAll')->willReturn([
				self::namedRow('overloaded', 0, 'integer'),
				self::namedRow('overloaded', 0, 'integer'),
				self::namedRow('mixed_return', 0, 'integer'),
				self::namedRow('mixed_return', 0, 'text'),
				self::namedRow('dual', 0, 'integer'),
				self::namedRow('dual', 1, null),
			]);

			$paramStatement = $this->createMock(StatementInterface::class);
			$paramStatement->method('fetchAll')->willReturn([]);

			$adapter->method('execute')->willReturnCallback(
				fn(string $sql, array $parameters = []): StatementInterface => str_contains($sql, 'unnest') ? $paramStatement : $listStatement
			);

			self::assertSame([
				['name' => 'dual', 'isProcedure' => false, 'returnType' => 'integer', 'parameters' => []],
				['name' => 'dual', 'isProcedure' => true, 'returnType' => null, 'parameters' => []],
				['name' => 'mixed_return', 'isProcedure' => false, 'returnType' => null, 'parameters' => []],
				['name' => 'overloaded', 'isProcedure' => false, 'returnType' => 'integer', 'parameters' => []],
			], $adapter->listRoutines());
		}

		/**
		 * Verifies each routine's parameter list is attached, in declaration order, with
		 * types normalized the same way return types are.
		 * @return void
		 */
		public function testListRoutinesIncludesParameters(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');

			$listStatement = $this->createMock(StatementInterface::class);
			$listStatement->method('fetchAll')->willReturn([self::namedRow('greet', 0, 'int')]);

			$paramStatement = $this->createMock(StatementInterface::class);
			$paramStatement->method('fetchAll')->willReturn([
				['name' => 'greet', 'is_procedure' => 0, 'param_name' => 'who', 'data_type' => 'varchar', 'type_detail' => null, 'max_length' => null],
				['name' => 'greet', 'is_procedure' => 0, 'param_name' => 'times', 'data_type' => 'int', 'type_detail' => null, 'max_length' => null],
			]);

			$adapter->method('execute')->willReturnOnConsecutiveCalls($listStatement, $paramStatement);

			$routines = $adapter->listRoutines();

			self::assertCount(1, $routines);
			self::assertSame([
				['name' => 'who', 'type' => 'string'],
				['name' => 'times', 'type' => 'integer'],
			], $routines[0]['parameters']);
		}

		/**
		 * Verifies a function with no parameters gets an empty list rather than a missing key.
		 * @return void
		 */
		public function testListRoutinesDefaultsToNoParameters(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');

			$listStatement = $this->createMock(StatementInterface::class);
			$listStatement->method('fetchAll')->willReturn([self::namedRow('no_args', 0, 'int')]);

			$paramStatement = $this->createMock(StatementInterface::class);
			$paramStatement->method('fetchAll')->willReturn([]);

			$adapter->method('execute')->willReturnOnConsecutiveCalls($listStatement, $paramStatement);

			$routines = $adapter->listRoutines();

			self::assertSame([], $routines[0]['parameters']);
		}

		/**
		 * Verifies parameter names get the lowering layer's dialect-specific decoration
		 * stripped, so the listing matches the `define function` source instead of the
		 * catalog's internal spelling.
		 * @param string $engine Database engine
		 * @param string $catalogName Parameter name as the catalog stores it
		 * @param string $expected Parameter name as `define function` wrote it
		 * @return void
		 */
		#[DataProvider('decoratedParameterNames')]
		public function testListRoutinesStripsParameterDecoration(string $engine, string $catalogName, string $expected): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'getRoutineSchema', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn($engine);
			$adapter->method('getRoutineSchema')->willReturn('dbo');

			$listStatement = $this->createMock(StatementInterface::class);
			$listStatement->method('fetchAll')->willReturn([self::namedRow('f', 0, 'int')]);

			$paramStatement = $this->createMock(StatementInterface::class);
			$paramStatement->method('fetchAll')->willReturn([
				['name' => 'f', 'is_procedure' => 0, 'param_name' => $catalogName, 'data_type' => 'int', 'type_detail' => null, 'max_length' => null],
			]);

			$adapter->method('execute')->willReturnOnConsecutiveCalls($listStatement, $paramStatement);

			self::assertSame($expected, $adapter->listRoutines()[0]['parameters'][0]['name']);
		}

		/**
		 * Provides catalog-stored parameter names and what `define function` originally wrote.
		 * @return array<string, array{string, string, string}>
		 */
		public static function decoratedParameterNames(): array {
			return [
				'mysql strips _v_ prefix'     => ['mysql', '_v_minId', 'minId'],
				'mariadb strips _v_ prefix'   => ['mariadb', '_v_who', 'who'],
				'sqlsrv strips @ sigil'       => ['sqlsrv', '@minId', 'minId'],
				'pgsql keeps name unchanged'  => ['pgsql', 'minid', 'minid'],
			];
		}

		/**
		 * Verifies a failed parameter-catalog read surfaces as a QuelException, distinct from
		 * the routine-listing query's own failure message.
		 * @return void
		 */
		public function testListRoutinesParameterLookupFailure(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute', 'getLastErrorMessage'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');

			$listStatement = $this->createMock(StatementInterface::class);
			$listStatement->method('fetchAll')->willReturn([self::namedRow('f', 0, 'int')]);

			$adapter->method('execute')->willReturnOnConsecutiveCalls($listStatement, null);
			$adapter->method('getLastErrorMessage')->willReturn('catalog unavailable');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('Failed to list routine parameters: catalog unavailable');
			$adapter->listRoutines();
		}

		/**
		 * Builds a named catalog row for listRoutines().
		 * @param string $name Routine name
		 * @param int $procedure Procedure flag: 1 for procedures, 0 for functions
		 * @param string|null $type Native return type
		 * @return array{name: string, is_procedure: int, data_type: string|null, type_detail: null, max_length: null}
		 */
		private static function namedRow(string $name, int $procedure, ?string $type): array {
			return ['name' => $name] + self::row($procedure, $type);
		}

		/**
		 * Verifies a failed catalog read surfaces as a QuelException.
		 * @return void
		 */
		public function testListRoutinesLookupFailure(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute', 'getLastErrorMessage'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('mysql');
			$adapter->method('execute')->willReturn(null);
			$adapter->method('getLastErrorMessage')->willReturn('catalog unavailable');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('Failed to list routines: catalog unavailable');
			$adapter->listRoutines();
		}

		/**
		 * Verifies unsupported engines fail before querying the catalog.
		 * @return void
		 */
		public function testListRoutinesUnsupportedEngine(): void {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)->disableOriginalConstructor()
				->onlyMethods(['getDatabaseType', 'execute'])->getMock();
			$adapter->method('getDatabaseType')->willReturn('sqlite');
			$adapter->expects(self::never())->method('execute');

			$this->expectException(QuelException::class);
			$this->expectExceptionMessage("Routines can't be listed on 'sqlite'.");
			$adapter->listRoutines();
		}
	}
