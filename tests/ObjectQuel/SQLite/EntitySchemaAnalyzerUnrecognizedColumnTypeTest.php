<?php

	namespace Quellabs\ObjectQuel\Tests\SQLite;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\DatabaseAdapter\Inspector\SqliteSchemaIntrospector;
	use Quellabs\ObjectQuel\DatabaseAdapter\Mapper\TypeMapper;
	use Quellabs\ObjectQuel\Sculpt\Helpers\EntitySchemaAnalyzer;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\UnrecognizedColumnTypeEntity;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;
	use RuntimeException;

	/**
	 * Isolates a real-world failure of `sculpt make:migrations`: it crashed
	 * outright against a SQLite table containing a `DATETIME_TEXT` column —
	 * a declared type never produced by this ORM's own DDL layer, meaning
	 * the table predates (or was never created through) sculpt's migration
	 * tooling, which is common for a project whose schema was set up by hand
	 * before adopting it.
	 *
	 * Three layers, narrowest first, to separate what's working as designed
	 * from what's an actual gap:
	 *
	 *  1. TypeMapper::sqliteType() rejects the type — and is DOCUMENTED to:
	 *     "Only needs to reverse the fixed set DDLTypeMapper::
	 *     sqliteSqlColumnType() itself ever emits — not SQLite's full
	 *     general-purpose type-affinity system." Confirmed against the
	 *     forward mapper (sqliteSqlColumnType(), tested indirectly via
	 *     TypeMapperTest) that 'DATETIME_TEXT' is never emitted for any
	 *     abstract type — this isn't a logic bug, it's correctly scoped.
	 *
	 *  2. SqliteSchemaIntrospector::getColumns() surfaces that same
	 *     rejection while reading a real table — expected, given (1).
	 *
	 *  3. EntitySchemaAnalyzer::analyzeEntityChanges() is the actual
	 *     user-facing entry point (what `sculpt make:migrations` calls).
	 *     ITS catch block wraps every exception from the per-entity loop
	 *     into one generic "Schema analysis failed: ..." message — even
	 *     though $tableName/$className are in scope at the point the
	 *     original exception was thrown, that context never reaches the
	 *     final message. This is the actual gap worth fixing: a developer
	 *     hunting this down in a schema with more than a couple of tables
	 *     gets a type name and nothing telling them which table or column
	 *     it came from, and the whole batch aborts with no partial result.
	 */
	class EntitySchemaAnalyzerUnrecognizedColumnTypeTest extends TestCase {
		use FkTestSupport;

		private DatabaseAdapter $adapter;

		protected function setUp(): void {
			$this->adapter = $this->makeSqliteAdapter();
		}

		/**
		 * Layer 1 — the type mapper alone, no database involved. Pins the
		 * documented, intentional scope: an unrecognized declared type is
		 * rejected rather than guessed at.
		 */
		public function testTypeMapperRejectsDatetimeTextAsDocumented(): void {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage("Unrecognized SQLite column type 'DATETIME_TEXT' — this ORM's DDL layer never produces it");

			TypeMapper::sqliteType('DATETIME_TEXT');
		}

		/**
		 * Layer 2 — a real in-memory SQLite table with a hand-written
		 * DATETIME_TEXT column (exactly how quelize's own `snapshots.
		 * created_at`/`hidden_at` columns are declared — written directly in
		 * SQL, never through this ORM's DDL layer, which is why `migrations/`
		 * is empty for that project: the schema predates adopting sculpt's
		 * migration tooling). Confirms the rejection surfaces during a real
		 * introspection call, not just the isolated mapper call above.
		 */
		public function testIntrospectorThrowsOnRealTableWithUnrecognizedColumnType(): void {
			$this->adapter->execute(
				'CREATE TABLE unrecognized_column_type_fixture (' .
				'id INTEGER PRIMARY KEY, ' .
				'created_at DATETIME_TEXT NOT NULL' .
				')'
			);

			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage("Unrecognized SQLite column type 'DATETIME_TEXT'");

			(new SqliteSchemaIntrospector($this->adapter))->getColumns('unrecognized_column_type_fixture');
		}

		/**
		 * Layer 3 — the actual `sculpt make:migrations` entry point. Proves
		 * the gap: the final exception message names the unrecognized type
		 * but neither the table nor the column it came from, even though
		 * EntitySchemaAnalyzer::compareSchemas() has both in scope at the
		 * point the original exception is thrown — analyzeEntityChanges()'s
		 * catch-all just discards that context on the way out.
		 */
		public function testAnalyzerErrorDropsWhichTableAndColumnFailed(): void {
			$this->adapter->execute(
				'CREATE TABLE unrecognized_column_type_fixture (' .
				'id INTEGER PRIMARY KEY, ' .
				'created_at DATETIME_TEXT NOT NULL' .
				')'
			);

			$entityStore = $this->makeFkEntityStore();
			$analyzer = new EntitySchemaAnalyzer($this->adapter, $entityStore, new PlatformCapabilities($this->adapter));

			try {
				$analyzer->analyzeEntityChanges([
					UnrecognizedColumnTypeEntity::class => 'unrecognized_column_type_fixture'
				]);
				self::fail('Expected a RuntimeException to be thrown.');
			} catch (RuntimeException $e) {
				self::assertStringContainsString("Unrecognized SQLite column type 'DATETIME_TEXT'", $e->getMessage());
				self::assertStringNotContainsString('unrecognized_column_type_fixture', $e->getMessage());
				self::assertStringNotContainsString('created_at', $e->getMessage());
			}
		}
	}
