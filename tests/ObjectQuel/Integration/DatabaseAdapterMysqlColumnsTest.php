<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Mysql;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * DatabaseAdapter::getColumns()'s MySQL/MariaDB branch (getMysqlColumns()),
	 * against a real MySQL server — the native replacement for Phinx-backed
	 * introspection (see objectquel-phinx-removal-plan.md). Mirrors
	 * DatabaseAdapterForeignKeyMySqlTest's connection setup, exercising raw
	 * CREATE TABLE statements directly rather than going through this ORM's
	 * own DDL layer, so every type-mapping edge case in the plan's MySQL
	 * research section can be pinned precisely (uuid-via-char(36), enum
	 * values, the charset-quirk default strip, etc.) independent of whether
	 * DDLTypeMapper itself would ever render that exact shape.
	 */
	class DatabaseAdapterMysqlColumnsTest extends TestCase {

		private ?DatabaseAdapter $adapter = null;

		protected function setUp(): void {
			$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
			$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
			$name = getenv('TEST_DB_NAME') ?: 'canvas_blog';
			$user = getenv('TEST_DB_USER') ?: 'root';
			$pass = getenv('TEST_DB_PASS') ?: '';

			try {
				$connection = new Connection([
					'driver'   => Mysql::class,
					'host'     => $host,
					'port'     => $port,
					'database' => $name,
					'username' => $user,
					'password' => $pass,
					'encoding' => 'utf8mb4',
				]);
				$connection->getDriver()->connect();
			} catch (\Throwable $e) {
				self::markTestSkipped('No live MySQL server reachable for this test: ' . $e->getMessage());
			}

			$this->adapter = new DatabaseAdapter($connection);
			$this->adapter->execute('DROP TABLE IF EXISTS oq_col_test');
		}

		protected function tearDown(): void {
			if ($this->adapter !== null) {
				$this->adapter->execute('DROP TABLE IF EXISTS oq_col_test');
			}
		}

		public function testIntegerSubtypesAndUnsignedAreReportedCorrectly(): void {
			$this->adapter->execute('
				CREATE TABLE oq_col_test (
					a TINYINT,
					b TINYINT UNSIGNED,
					c SMALLINT,
					d INT,
					e BIGINT,
					f INT UNSIGNED NOT NULL AUTO_INCREMENT,
					PRIMARY KEY (f)
				) ENGINE=InnoDB
			');

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertSame('tinyinteger', $columns['a']->type);
			self::assertFalse($columns['a']->unsigned);
			self::assertTrue($columns['b']->unsigned);
			self::assertSame('smallinteger', $columns['c']->type);
			self::assertSame('integer', $columns['d']->type);
			self::assertSame('biginteger', $columns['e']->type);
			self::assertTrue($columns['f']->identity);
			self::assertTrue($columns['f']->primary_key);
			self::assertTrue($columns['f']->unsigned);
		}

		public function testTinyint1MapsToBooleanButWiderTinyintDoesNot(): void {
			$this->adapter->execute('
				CREATE TABLE oq_col_test (
					flag TINYINT(1),
					small_num TINYINT(4)
				) ENGINE=InnoDB
			');

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertSame('boolean', $columns['flag']->type);
			self::assertSame('tinyinteger', $columns['small_num']->type);
		}

		public function testChar36MapsToUuidButOtherCharWidthsDoNot(): void {
			$this->adapter->execute('
				CREATE TABLE oq_col_test (
					id CHAR(36),
					code CHAR(10)
				) ENGINE=InnoDB
			');

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertSame('uuid', $columns['id']->type);
			self::assertSame('char', $columns['code']->type);
			self::assertSame(10, $columns['code']->limit);
		}

		public function testVarcharLimitIsReportedVerbatim(): void {
			$this->adapter->execute('CREATE TABLE oq_col_test (name VARCHAR(120)) ENGINE=InnoDB');

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertSame('string', $columns['name']->type);
			self::assertSame(120, $columns['name']->limit);
		}

		public function testDecimalPrecisionAndScaleAreReadBackButPlainFloatHasNone(): void {
			$this->adapter->execute('
				CREATE TABLE oq_col_test (
					price DECIMAL(10,2),
					ratio FLOAT
				) ENGINE=InnoDB
			');

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertSame('decimal', $columns['price']->type);
			self::assertSame(10, $columns['price']->precision);
			self::assertSame(2, $columns['price']->scale);

			// A plain FLOAT with no declared width must not report MySQL's
			// internal storage precision (12) — DDLTypeMapper never renders
			// one, and the entity side never declares one either, so
			// reporting it here would be a spurious diff forever.
			self::assertSame('float', $columns['ratio']->type);
			self::assertNull($columns['ratio']->precision);
		}

		public function testEnumTypeAndValuesAreParsedFromColumnType(): void {
			$this->adapter->execute("CREATE TABLE oq_col_test (status ENUM('active','inactive','it''s complicated')) ENGINE=InnoDB");

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertSame('enum', $columns['status']->type);
			self::assertSame(['active', 'inactive', "it's complicated"], $columns['status']->values);
		}

		public function testJsonAndBlobVariantsMapCorrectly(): void {
			$this->adapter->execute('
				CREATE TABLE oq_col_test (
					payload JSON,
					data BLOB,
					big_data LONGBLOB,
					raw VARBINARY(50)
				) ENGINE=InnoDB
			');

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertSame('json', $columns['payload']->type);
			self::assertSame('blob', $columns['data']->type);
			self::assertSame('blob', $columns['big_data']->type);
			self::assertSame('binary', $columns['raw']->type);
			self::assertSame(50, $columns['raw']->limit);
		}

		public function testDefaultValueChasetQuirkIsStrippedForTextColumn(): void {
			$this->adapter->execute("CREATE TABLE oq_col_test (note TEXT DEFAULT ('hello world')) ENGINE=InnoDB");

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertSame('hello world', $columns['note']->default);
		}

		public function testNullableFlagIsReadBackCorrectly(): void {
			$this->adapter->execute('
				CREATE TABLE oq_col_test (
					required_field VARCHAR(10) NOT NULL,
					optional_field VARCHAR(10) NULL
				) ENGINE=InnoDB
			');

			$columns = $this->adapter->getColumns('oq_col_test');

			self::assertFalse($columns['required_field']->nullable);
			self::assertTrue($columns['optional_field']->nullable);
		}
	}
