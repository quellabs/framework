<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Persistence;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\Version;
	use Quellabs\ObjectQuel\DatabaseAdapter\SqlIdentifierQuoter;
	use Quellabs\ObjectQuel\OrmException;
	use Quellabs\ObjectQuel\Persistence\VersionValueHandler;
	use Quellabs\ObjectQuel\Tests\Support\FakePlatformCapabilities;

	/**
	 * Isolated coverage for VersionValueHandler::buildVersionSetClause() —
	 * the bump logic shared by UpdatePersister (object-persistence UPDATE)
	 * and QuelToSQLReplace (QUEL-level `replace`), moved here specifically
	 * so `replace` doesn't reimplement it (see objectquel-replace-plan.md).
	 *
	 * No fixture entity in this suite carries an @Orm\Version column, so
	 * this exercises the method directly against hand-built Column/Version
	 * annotation instances rather than through a live entity. The other
	 * constructor dependencies (connection, EntityStore, UnitOfWork,
	 * PropertyHandler) are never touched by buildVersionSetClause() itself,
	 * so the handler is built via reflection instead of mocking four
	 * concrete classes just to satisfy the constructor.
	 */
	class VersionValueHandlerTest extends TestCase {

		private function handler(string $dialect = 'mysql'): VersionValueHandler {
			$platform = new FakePlatformCapabilities($dialect);

			$handler = (new \ReflectionClass(VersionValueHandler::class))->newInstanceWithoutConstructor();

			$platformProperty = new \ReflectionProperty(VersionValueHandler::class, 'platformCapabilities');
			$platformProperty->setValue($handler, $platform);

			$quoterProperty = new \ReflectionProperty(VersionValueHandler::class, 'identifierQuoter');
			$quoterProperty->setValue($handler, new SqlIdentifierQuoter($platform));

			return $handler;
		}

		/**
		 * @param array<string, mixed> $columnParams
		 */
		private function versionColumn(string $columnName, array $columnParams): array {
			return [
				'name'    => $columnName,
				'column'  => new Column(array_merge(['name' => $columnName], $columnParams)),
				'version' => new Version([]),
			];
		}

		public function testIntegerVersionColumnIncrementsInPlace(): void {
			$params = [];

			$parts = $this->handler()->buildVersionSetClause(
				['version' => $this->versionColumn('version', ['type' => 'integer'])],
				$params
			);

			self::assertSame(['`version`=`version` + 1'], $parts);
			self::assertSame([], $params);
		}

		public function testBigintVersionColumnIncrementsInPlace(): void {
			$params = [];

			$parts = $this->handler()->buildVersionSetClause(
				['version' => $this->versionColumn('version', ['type' => 'bigint'])],
				$params
			);

			self::assertSame(['`version`=`version` + 1'], $parts);
		}

		public function testDatetimeVersionColumnUsesTheEngineAppropriateCurrentDatetimeExpression(): void {
			$params = [];

			$mysqlParts = $this->handler('mysql')->buildVersionSetClause(
				['updatedAt' => $this->versionColumn('updated_at', ['type' => 'datetime'])],
				$params
			);

			$sqlsrvParts = $this->handler('sqlsrv')->buildVersionSetClause(
				['updatedAt' => $this->versionColumn('updated_at', ['type' => 'datetime'])],
				$params
			);

			// Exact fragment isn't the point here (PlatformCapabilities::
			// getCurrentDatetimeFunction() owns that) — what matters is that
			// the two dialects actually produce different SQL, i.e. this
			// isn't hardcoding MySQL's NOW().
			self::assertNotSame($mysqlParts[0], $sqlsrvParts[0]);
			self::assertStringStartsWith('`updated_at`=', $mysqlParts[0]);
			self::assertStringStartsWith('[updated_at]=', $sqlsrvParts[0]);
		}

		public function testUuidVersionColumnGeneratesAndBindsAFreshValue(): void {
			$params = [];

			$parts = $this->handler()->buildVersionSetClause(
				['token' => $this->versionColumn('token', ['type' => 'uuid'])],
				$params
			);

			self::assertSame(['`token`=:version_token'], $parts);
			self::assertArrayHasKey('version_token', $params);
			self::assertIsString($params['version_token']);
			self::assertNotSame('', $params['version_token']);
		}

		public function testMultipleVersionColumnsProduceOneFragmentEach(): void {
			$params = [];

			$parts = $this->handler()->buildVersionSetClause(
				[
					'version'   => $this->versionColumn('version', ['type' => 'integer']),
					'updatedAt' => $this->versionColumn('updated_at', ['type' => 'datetime']),
				],
				$params
			);

			self::assertCount(2, $parts);
		}

		public function testRejectsAnUnsupportedVersionColumnType(): void {
			$this->expectException(OrmException::class);

			$params = [];

			$this->handler()->buildVersionSetClause(
				['flag' => $this->versionColumn('flag', ['type' => 'boolean'])],
				$params
			);
		}

		public function testEmptyVersionColumnsProduceNoFragments(): void {
			$params = [];

			self::assertSame([], $this->handler()->buildVersionSetClause([], $params));
		}

		// -------------------------------------------------------------------------
		// $qualifyWithAlias — mirrors QuelToSQLReplace::quoteSetTargetColumn()'s
		// dialect split (see its docblock): a standalone `replace` passes its
		// own range alias here; UpdatePersister's unaliased UPDATE never does,
		// which is exactly the default-null behavior every test above covers.
		// -------------------------------------------------------------------------

		public function testIntegerVersionColumnQualifiesBothSidesOnMysql(): void {
			$params = [];

			$parts = $this->handler('mysql')->buildVersionSetClause(
				['version' => $this->versionColumn('version', ['type' => 'integer'])],
				$params,
				'u'
			);

			self::assertSame(['`u`.`version`=`u`.`version` + 1'], $parts);
		}

		public function testIntegerVersionColumnQualifiesOnlyTheRhsOnPostgres(): void {
			// The LHS target stays bare on Postgres (a qualified column there
			// is a syntax error on the LEFT side of SET), but the RHS
			// self-reference is qualified same as anywhere else — matching
			// how a manually-written `count = count + 1` would compile
			// through BuildSqlFromAst.
			$params = [];

			$parts = $this->handler('pgsql')->buildVersionSetClause(
				['version' => $this->versionColumn('version', ['type' => 'integer'])],
				$params,
				'u'
			);

			self::assertSame(['"version"="u"."version" + 1'], $parts);
		}

		public function testIntegerVersionColumnQualifiesOnlyTheRhsOnSqlite(): void {
			$params = [];

			$parts = $this->handler('sqlite')->buildVersionSetClause(
				['version' => $this->versionColumn('version', ['type' => 'integer'])],
				$params,
				'u'
			);

			self::assertSame(['`version`=`u`.`version` + 1'], $parts);
		}

		public function testDatetimeVersionColumnQualifiesTheTargetOnMysqlOnly(): void {
			$params = [];

			$mysqlParts = $this->handler('mysql')->buildVersionSetClause(
				['updatedAt' => $this->versionColumn('updated_at', ['type' => 'datetime'])],
				$params,
				'u'
			);

			$pgsqlParts = $this->handler('pgsql')->buildVersionSetClause(
				['updatedAt' => $this->versionColumn('updated_at', ['type' => 'datetime'])],
				$params,
				'u'
			);

			self::assertStringStartsWith('`u`.`updated_at`=', $mysqlParts[0]);
			self::assertStringStartsWith('"updated_at"=', $pgsqlParts[0]);
		}

		public function testUuidVersionColumnQualifiesTheTargetOnMysqlOnly(): void {
			$params = [];

			$mysqlParts = $this->handler('mysql')->buildVersionSetClause(
				['token' => $this->versionColumn('token', ['type' => 'uuid'])],
				$params,
				'u'
			);

			$pgsqlParts = $this->handler('pgsql')->buildVersionSetClause(
				['token' => $this->versionColumn('token', ['type' => 'uuid'])],
				$params,
				'u'
			);

			self::assertSame('`u`.`token`=:version_token', $mysqlParts[0]);
			self::assertSame('"token"=:version_token', $pgsqlParts[0]);
		}

		public function testNoAliasProducesTheFullyBareFormRegardlessOfDialect(): void {
			// UpdatePersister's call site — its UPDATE has no alias in scope
			// at all, so omitting $qualifyWithAlias must behave exactly as it
			// did before this parameter existed, on every dialect.
			$params = [];

			$parts = $this->handler('mysql')->buildVersionSetClause(
				['version' => $this->versionColumn('version', ['type' => 'integer'])],
				$params
			);

			self::assertSame(['`version`=`version` + 1'], $parts);
		}
	}
