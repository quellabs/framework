<?php

	declare(strict_types=1);

	namespace Quellabs\ObjectQuel\Tests\Unit\DatabaseAdapter;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\NativeColumnTypeMapper;

	/**
	 * Pure string-mapping unit tests for NativeColumnTypeMapper — no database
	 * connection needed. See objectquel-phinx-removal-plan.md's per-engine
	 * research section for the mapping tables these pin.
	 */
	class NativeColumnTypeMapperTest extends TestCase {

		// ==================== MySQL/MariaDB ====================

		public function testMysqlVarcharMapsToString(): void {
			self::assertSame('string', NativeColumnTypeMapper::mysqlType('varchar', 'varchar(255)', 255));
		}

		public function testMysqlCharMapsToChar(): void {
			self::assertSame('char', NativeColumnTypeMapper::mysqlType('char', 'char(10)', 10));
		}

		public function testMysqlChar36MapsToUuid(): void {
			self::assertSame('uuid', NativeColumnTypeMapper::mysqlType('char', 'char(36)', 36));
		}

		public function testMysqlTinyint1MapsToBoolean(): void {
			self::assertSame('boolean', NativeColumnTypeMapper::mysqlType('tinyint', 'tinyint(1)', null));
		}

		public function testMysqlTinyintOtherWidthMapsToTinyinteger(): void {
			self::assertSame('tinyinteger', NativeColumnTypeMapper::mysqlType('tinyint', 'tinyint(4)', null));
		}

		public function testMysqlEnumMapsToEnum(): void {
			self::assertSame('enum', NativeColumnTypeMapper::mysqlType('enum', "enum('a','b')", null));
		}

		/**
		 * @dataProvider mysqlSimpleTypeMappings
		 */
		public function testMysqlSimpleTypeMappings(string $dataType, string $expected): void {
			self::assertSame($expected, NativeColumnTypeMapper::mysqlType($dataType, $dataType, null));
		}

		public static function mysqlSimpleTypeMappings(): array {
			return [
				'text' => ['text', 'text'],
				'smallint' => ['smallint', 'smallinteger'],
				'int' => ['int', 'integer'],
				'bigint' => ['bigint', 'biginteger'],
				'decimal' => ['decimal', 'decimal'],
				'numeric' => ['numeric', 'decimal'],
				'float' => ['float', 'float'],
				'double' => ['double', 'float'],
				'date' => ['date', 'date'],
				'datetime' => ['datetime', 'datetime'],
				'time' => ['time', 'time'],
				'timestamp' => ['timestamp', 'timestamp'],
				'blob' => ['blob', 'blob'],
				'tinyblob' => ['tinyblob', 'blob'],
				'mediumblob' => ['mediumblob', 'blob'],
				'longblob' => ['longblob', 'blob'],
				'varbinary' => ['varbinary', 'binary'],
				'binary' => ['binary', 'binary'],
				'json' => ['json', 'json'],
				'year' => ['year', 'year'],
			];
		}

		public function testMysqlUnrecognizedTypeThrows(): void {
			$this->expectException(\RuntimeException::class);
			NativeColumnTypeMapper::mysqlType('geometry', 'geometry', null);
		}

		// ==================== PostgreSQL ====================

		public function testPostgresCharacterVaryingMapsToString(): void {
			self::assertSame('string', NativeColumnTypeMapper::postgresType('character varying'));
		}

		public function testPostgresJsonbMapsToJson(): void {
			self::assertSame('json', NativeColumnTypeMapper::postgresType('jsonb'));
		}

		public function testPostgresTimestampWithoutTimeZoneMapsToDatetime(): void {
			self::assertSame('datetime', NativeColumnTypeMapper::postgresType('timestamp without time zone'));
		}

		public function testPostgresTimeWithoutTimeZoneMapsToTime(): void {
			self::assertSame('time', NativeColumnTypeMapper::postgresType('time without time zone'));
		}

		/**
		 * @dataProvider postgresSimpleTypeMappings
		 */
		public function testPostgresSimpleTypeMappings(string $dataType, string $expected): void {
			self::assertSame($expected, NativeColumnTypeMapper::postgresType($dataType));
		}

		public static function postgresSimpleTypeMappings(): array {
			return [
				'character' => ['character', 'char'],
				'text' => ['text', 'text'],
				'json' => ['json', 'json'],
				'smallint' => ['smallint', 'smallinteger'],
				'integer' => ['integer', 'integer'],
				'bigint' => ['bigint', 'biginteger'],
				'numeric' => ['numeric', 'decimal'],
				'real' => ['real', 'float'],
				'double precision' => ['double precision', 'float'],
				'bytea' => ['bytea', 'binary'],
				'date' => ['date', 'date'],
				'boolean' => ['boolean', 'boolean'],
				'uuid' => ['uuid', 'uuid'],
			];
		}

		public function testPostgresUnrecognizedTypeThrows(): void {
			$this->expectException(\RuntimeException::class);
			NativeColumnTypeMapper::postgresType('USER-DEFINED');
		}

		// ==================== SQLite ====================

		public function testSqliteVarcharWithLengthMapsToString(): void {
			self::assertSame('string', NativeColumnTypeMapper::sqliteType('VARCHAR(255)'));
		}

		public function testSqliteNumericWithPrecisionMapsToDecimal(): void {
			self::assertSame('decimal', NativeColumnTypeMapper::sqliteType('NUMERIC(10,2)'));
		}

		public function testSqliteLowercaseDeclaredTypeIsAccepted(): void {
			self::assertSame('integer', NativeColumnTypeMapper::sqliteType('integer'));
		}

		/**
		 * @dataProvider sqliteSimpleTypeMappings
		 */
		public function testSqliteSimpleTypeMappings(string $declaredType, string $expected): void {
			self::assertSame($expected, NativeColumnTypeMapper::sqliteType($declaredType));
		}

		public static function sqliteSimpleTypeMappings(): array {
			return [
				'INTEGER' => ['INTEGER', 'integer'],
				'REAL' => ['REAL', 'float'],
				'BOOLEAN' => ['BOOLEAN', 'boolean'],
				'DATE' => ['DATE', 'date'],
				'DATETIME' => ['DATETIME', 'datetime'],
				'TIME' => ['TIME', 'time'],
				'TIMESTAMP' => ['TIMESTAMP', 'timestamp'],
				'TEXT' => ['TEXT', 'text'],
				'BLOB' => ['BLOB', 'blob'],
				'CHAR(36)' => ['CHAR(36)', 'char'],
			];
		}

		public function testSqliteUnrecognizedTypeThrows(): void {
			$this->expectException(\RuntimeException::class);
			NativeColumnTypeMapper::sqliteType('NVARCHAR(50)');
		}

		// ==================== SQL Server ====================

		public function testSqlServerNvarcharMaxMapsToText(): void {
			self::assertSame('text', NativeColumnTypeMapper::sqlServerType('nvarchar', -1));
		}

		public function testSqlServerOrdinaryNvarcharMapsToString(): void {
			self::assertSame('string', NativeColumnTypeMapper::sqlServerType('nvarchar', 255));
		}

		public function testSqlServerDatetime2MapsToDatetime(): void {
			self::assertSame('datetime', NativeColumnTypeMapper::sqlServerType('datetime2', null));
		}

		/**
		 * @dataProvider sqlServerSimpleTypeMappings
		 */
		public function testSqlServerSimpleTypeMappings(string $dataType, string $expected): void {
			self::assertSame($expected, NativeColumnTypeMapper::sqlServerType($dataType, null));
		}

		public static function sqlServerSimpleTypeMappings(): array {
			return [
				'varchar' => ['varchar', 'string'],
				'char' => ['char', 'char'],
				'nchar' => ['nchar', 'char'],
				'text' => ['text', 'text'],
				'ntext' => ['ntext', 'text'],
				'int' => ['int', 'integer'],
				'decimal' => ['decimal', 'decimal'],
				'numeric' => ['numeric', 'decimal'],
				'tinyint' => ['tinyint', 'tinyinteger'],
				'smallint' => ['smallint', 'smallinteger'],
				'bigint' => ['bigint', 'biginteger'],
				'real' => ['real', 'float'],
				'float' => ['float', 'float'],
				'binary' => ['binary', 'binary'],
				'varbinary' => ['varbinary', 'binary'],
				'time' => ['time', 'time'],
				'date' => ['date', 'date'],
				'bit' => ['bit', 'boolean'],
				'uniqueidentifier' => ['uniqueidentifier', 'uuid'],
			];
		}

		public function testSqlServerUnrecognizedTypeThrows(): void {
			$this->expectException(\RuntimeException::class);
			NativeColumnTypeMapper::sqlServerType('geography', null);
		}
	}
