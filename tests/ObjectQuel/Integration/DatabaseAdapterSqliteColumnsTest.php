<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use Cake\Database\Connection;
	use Cake\Database\Driver\Sqlite;
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;

	/**
	 * DatabaseAdapter::getColumns()'s SQLite branch (getSqliteColumns()),
	 * against a real (file-based, not :memory: — see
	 * project-objectquel-test-writing-gotchas memory / the class docblock on
	 * AlterTableBackfillSqliteTest for why :memory: was historically
	 * unusable here under the old Phinx-backed implementation) SQLite
	 * connection.
	 *
	 * The identity-detection algorithm (resolveSqliteIdentity()) is the
	 * highest-risk part of the whole native-getColumns() replacement — see
	 * objectquel-phinx-removal-plan.md's SQLite section — so this pins every
	 * edge case called out there explicitly, not just the happy path.
	 */
	class DatabaseAdapterSqliteColumnsTest extends TestCase {

		private ?string $dbFile = null;
		private ?DatabaseAdapter $adapter = null;

		protected function setUp(): void {
			$this->dbFile = sys_get_temp_dir() . '/oq_sqlite_columns_' . str_replace('.', '', uniqid('', true)) . '.sqlite';

			$connection = new Connection([
				'driver'   => Sqlite::class,
				'database' => $this->dbFile,
			]);

			$this->adapter = new DatabaseAdapter($connection);
		}

		protected function tearDown(): void {
			// @-suppressed: on Windows, SQLite's own file lock can still be
			// held briefly after the last PDO connection to it goes out of
			// scope, racing this delete — harmless, since it's a per-test
			// temp file nothing else depends on; the OS temp directory
			// cleans it up regardless.
			if ($this->dbFile !== null && file_exists($this->dbFile)) {
				@unlink($this->dbFile);
			}
		}

		public function testBareIntegerPrimaryKeyIsDetectedAsIdentity(): void {
			$this->adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');

			$columns = $this->adapter->getColumns('t');

			self::assertTrue($columns['id']->identity);
			self::assertTrue($columns['id']->primary_key);
		}

		public function testExplicitAutoincrementIsDetectedAsIdentity(): void {
			$this->adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

			$columns = $this->adapter->getColumns('t');

			self::assertTrue($columns['id']->identity);
		}

		public function testCompositePrimaryKeyHasNoIdentityColumn(): void {
			$this->adapter->execute('CREATE TABLE t (a INTEGER, b INTEGER, name TEXT, PRIMARY KEY (a, b))');

			$columns = $this->adapter->getColumns('t');

			self::assertFalse($columns['a']->identity);
			self::assertFalse($columns['b']->identity);
			self::assertTrue($columns['a']->primary_key);
			self::assertTrue($columns['b']->primary_key);
		}

		public function testSingleColumnPrimaryKeyOfNonIntegerTypeIsNotIdentity(): void {
			$this->adapter->execute('CREATE TABLE t (id TEXT PRIMARY KEY, name TEXT)');

			$columns = $this->adapter->getColumns('t');

			self::assertFalse($columns['id']->identity);
			self::assertTrue($columns['id']->primary_key);
		}

		public function testWithoutRowidTableHasNoIdentityColumnEvenWithIntegerPk(): void {
			$this->adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT) WITHOUT ROWID');

			$columns = $this->adapter->getColumns('t');

			self::assertFalse($columns['id']->identity);
			self::assertTrue($columns['id']->primary_key);
		}

		public function testTableWithNoPrimaryKeyHasNoIdentityColumn(): void {
			$this->adapter->execute('CREATE TABLE t (name TEXT, amount INTEGER)');

			$columns = $this->adapter->getColumns('t');

			self::assertFalse($columns['name']->identity);
			self::assertFalse($columns['amount']->identity);
			self::assertFalse($columns['name']->primary_key);
		}

		public function testTypeMappingForFixedDdlSet(): void {
			$this->adapter->execute("
				CREATE TABLE t (
					a INTEGER,
					b REAL,
					c NUMERIC(10,2),
					d BOOLEAN,
					e DATE,
					f DATETIME,
					g TIME,
					h TIMESTAMP,
					i TEXT,
					j BLOB,
					k VARCHAR(50),
					l CHAR(5)
				)
			");

			$columns = $this->adapter->getColumns('t');

			self::assertSame('integer', $columns['a']->type);
			self::assertSame('float', $columns['b']->type);
			self::assertSame('decimal', $columns['c']->type);
			self::assertSame(10, $columns['c']->precision);
			self::assertSame(2, $columns['c']->scale);
			self::assertSame('boolean', $columns['d']->type);
			self::assertSame('date', $columns['e']->type);
			self::assertSame('datetime', $columns['f']->type);
			self::assertSame('time', $columns['g']->type);
			self::assertSame('timestamp', $columns['h']->type);
			self::assertSame('text', $columns['i']->type);
			self::assertSame('blob', $columns['j']->type);
			self::assertSame('string', $columns['k']->type);
			self::assertSame(50, $columns['k']->limit);
			self::assertSame('char', $columns['l']->type);
			self::assertSame(5, $columns['l']->limit);
		}

		public function testDefaultValueQuotedStringIsUnwrappedAndUndoubled(): void {
			$this->adapter->execute("CREATE TABLE t (name TEXT DEFAULT 'it''s pending')");

			$columns = $this->adapter->getColumns('t');

			self::assertSame("it's pending", $columns['name']->default);
		}

		public function testNullableFlagIsReadBackCorrectly(): void {
			$this->adapter->execute('CREATE TABLE t (required_field TEXT NOT NULL, optional_field TEXT)');

			$columns = $this->adapter->getColumns('t');

			self::assertFalse($columns['required_field']->nullable);
			self::assertTrue($columns['optional_field']->nullable);
		}
	}
