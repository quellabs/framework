<?php

	namespace Quellabs\ObjectQuel\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\ObjectQuel\Ast\AstAlterTable;
	use Quellabs\ObjectQuel\ObjectQuel\Lexer;
	use Quellabs\ObjectQuel\ObjectQuel\Parser;
	use Quellabs\ObjectQuel\ObjectQuel\QuelToSQLAlter;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * Pins the `backfill` SQLite carve-out (see
	 * objectquel-migrations-implementation-plan.md, Phase 0.2) end-to-end
	 * against a real SQLite connection — SQLite is the only engine available
	 * in this environment without a live external database server (see
	 * DatabaseAdapterForeignKeyTest). Unlike MySQL/PostgreSQL/SQL Server,
	 * SQLite has no ALTER COLUMN of any kind to drop the transient DEFAULT
	 * afterward, so it's deliberately left in place — this test asserts both
	 * that the backfill actually happened AND that the default clause
	 * survives, rather than leaving the carve-out to rot untested.
	 *
	 * Assertions read the schema back via `PRAGMA table_info` executed
	 * through the same DatabaseAdapter connection, rather than
	 * DatabaseAdapter::getColumns() — that method's SQLite branch goes
	 * through the Phinx adapter, which opens its own separate PDO connection
	 * from the CakePHP connection config; for a `:memory:` database that's a
	 * distinct, empty database, so it can never see tables created through
	 * this adapter's own execute().
	 */
	class AlterTableBackfillSqliteTest extends TestCase {
		use FkTestSupport;

		/**
		 * Compiles and executes an `alter` statement against a real SQLite
		 * adapter, mirroring how AlterTableExecutor drives QuelToSQLAlter's
		 * output — but without needing a full EntityManager, since this
		 * suite's only live connection is MySQL (see FkTestSupport).
		 */
		private function alter(\Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter $adapter, string $quel): void {
			$platform = new PlatformCapabilities($adapter);
			$ast = (new Parser(new Lexer($quel), $this->makeFkEntityStore()))->parse();
			self::assertInstanceOf(AstAlterTable::class, $ast);

			foreach ((new QuelToSQLAlter($platform))->convertToSQL($ast) as $statement) {
				$adapter->execute($statement);
			}
		}

		/**
		 * @return array{notnull: int, dflt_value: string|null}
		 */
		private function columnInfo(\Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter $adapter, string $table, string $column): array {
			foreach ($adapter->execute("PRAGMA table_info({$table})")->fetchAll('assoc') as $row) {
				if ($row['name'] === $column) {
					return $row;
				}
			}

			self::fail("Column '{$column}' not found on '{$table}'");
		}

		public function testBackfilledColumnIsNotNullAndOldRowsGetTheLiteralValue(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, message TEXT NOT NULL)');
			$adapter->execute("INSERT INTO orders (id, message) VALUES (1, 'first'), (2, 'second')");

			$this->alter($adapter, "alter orders (add status = string(20) backfill 'pending')");

			$statusColumn = $this->columnInfo($adapter, 'orders', 'status');
			$this->assertSame(1, (int)$statusColumn['notnull']);

			$rows = $adapter->execute('SELECT status FROM orders ORDER BY id')->fetchAll('assoc');
			$this->assertSame('pending', $rows[0]['status']);
			$this->assertSame('pending', $rows[1]['status']);
		}

		/**
		 * The accepted, documented carve-out: SQLite's DEFAULT clause is
		 * left in place after the backfill (no cleanup step exists), unlike
		 * every other supported engine. Harmless — append() never reads a
		 * column's DB-level default (see the plan doc's "Current state"
		 * note) — but must stay pinned by a test rather than silently rot.
		 */
		public function testTheTransientDefaultIsLeftInPlaceOnSqlite(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, message TEXT NOT NULL)');

			$this->alter($adapter, "alter orders (add status = string(20) backfill 'pending')");

			$statusColumn = $this->columnInfo($adapter, 'orders', 'status');
			$this->assertSame("'pending'", $statusColumn['dflt_value']);
		}
	}
