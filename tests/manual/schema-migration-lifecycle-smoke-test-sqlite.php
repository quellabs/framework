<?php

	/**
	 * Standalone end-to-end lifecycle smoke test for the schema-migration
	 * system (`make:migrations` + `quel:migrate`) against a real SQLite
	 * file — the SQLite counterpart to schema-migration-lifecycle-smoke-
	 * test.php, which covers MySQL. Run as its own process for the same
	 * reason that one is: a real EntityStore from real entity files on
	 * disk, outside the shared PHPUnit suite's process-wide EntityManager.
	 *
	 * Not a re-run of the MySQL script's type matrix (already unit
	 * tested) — this covers what's genuinely different on SQLite and was
	 * never exercised live before: a fulltext index (an FTS5 virtual
	 * table + sync triggers, not a real index) created inline, modified,
	 * and dropped; the backfill divergence in QuelToSQLAlter (no ALTER
	 * COLUMN, so a backfilled column's DEFAULT is deliberately left in
	 * place, unlike MySQL/PostgreSQL); and the three operations SQLite's
	 * ALTER TABLE structurally can't do — retype, primary-key change,
	 * adding a foreign key to an existing table — each proven to fail
	 * loudly, leave the schema untouched, and recover cleanly.
	 *
	 * Building this test found and fixed seven pre-existing bugs — see
	 * this branch's git history for each one.
	 *
	 * Usage:
	 *   php tests/manual/schema-migration-lifecycle-smoke-test-sqlite.php
	 *
	 * Needs no live server. Exits 0 if every check passes, 1 otherwise.
	 * Cleans up everything it creates even on failure.
	 */

	require __DIR__ . '/../../vendor/autoload.php';

	use Quellabs\ObjectQuel\Sculpt\Commands\MakeMigrationsCommand;
	use Quellabs\ObjectQuel\Sculpt\Commands\QuelMigrateCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	// -----------------------------------------------------------------------
	// Tiny assertion harness — mirrors schema-migration-lifecycle-smoke-test.php
	// -----------------------------------------------------------------------

	$failures = [];
	$checks = 0;
	$useColor = function_exists('stream_isatty') && stream_isatty(STDOUT);

	function color(string $code, string $text): string {
		global $useColor;
		return $useColor ? "\033[{$code}m{$text}\033[0m" : $text;
	}

	function check(string $label, bool $condition): void {
		global $failures, $checks;
		$checks++;

		if ($condition) {
			echo '  ' . color('32', '✓') . " {$label}\n";
		} else {
			echo '  ' . color('31', '✗') . " {$label}\n";
			$failures[] = $label;
		}
	}

	function section(string $title): void {
		echo "\n{$title}\n";
	}

	function uniqueSuffix(): string {
		return str_replace('.', '', uniqid('', true));
	}

	/**
	 * @param string[] $args
	 * @return array{0: int, 1: string}
	 */
	function runAnyCommand(string $commandClass, ServiceProvider $provider, array $args): array {
		$stream = fopen('php://memory', 'w+');
		$output = new ConsoleOutput($stream);
		$input = new ConsoleInput($output, fopen('php://memory', 'r+'));
		$command = new $commandClass($input, $output, $provider);

		$exitCode = $command->execute(new ConfigurationManager($args));

		rewind($stream);
		return [$exitCode, stream_get_contents($stream)];
	}

	function runMakeMigrations(ServiceProvider $provider): array {
		return runAnyCommand(MakeMigrationsCommand::class, $provider, []);
	}

	function runQuelMigrate(ServiceProvider $provider, array $args = ['--force']): array {
		return runAnyCommand(QuelMigrateCommand::class, $provider, $args);
	}

	// -----------------------------------------------------------------------
	// Entity source generation — a small, data-driven column set (far
	// smaller than the MySQL kitchen sink, since the point here is SQLite-
	// specific behavior, not re-proving the type matrix).
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $opts
	 * @return array<string, mixed>
	 */
	function scol(string $prop, string $col, string $type, array $opts = []): array {
		return array_merge([
			'prop'        => $prop,
			'col'         => $col,
			'type'        => $type,
			'nullable'    => false,
			'limit'       => null,
			'precision'   => null,
			'scale'       => null,
			'default'     => null,
			'primaryKey'  => false,
			'identity'    => false,
			'phpNullable' => null,
			'enumType'    => null,
			// ['target' => ClassName, 'onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION']
			'fk'          => null,
		], $opts);
	}

	function sPhpType(array $c): string {
		if ($c['enumType'] !== null) {
			return $c['enumType'];
		}

		return match ($c['type']) {
			'integer' => 'int',
			'decimal' => 'float',
			'datetime' => '\DateTime',
			'json' => 'array',
			default => 'string', // string, text
		};
	}

	function renderSColAnnotationArgs(array $c): string {
		$parts = ["name=\"{$c['col']}\"", "type=\"{$c['type']}\""];

		if ($c['primaryKey']) {
			$parts[] = 'primary_key=true';
		}

		if ($c['nullable']) {
			$parts[] = 'nullable=true';
		}

		if ($c['limit'] !== null) {
			$parts[] = "limit={$c['limit']}";
		}

		if ($c['precision'] !== null) {
			$parts[] = "precision={$c['precision']}";
		}

		if ($c['scale'] !== null) {
			$parts[] = "scale={$c['scale']}";
		}

		if ($c['enumType'] !== null) {
			$parts[] = "enumType={$c['enumType']}::class";
		}

		if ($c['default'] !== null) {
			$parts[] = "default={$c['default']}";
		}

		return implode(', ', $parts);
	}

	function renderSColProperty(array $c): string {
		$lines = ["\t\t/**"];
		$lines[] = "\t\t * @Orm\\Column(" . renderSColAnnotationArgs($c) . ')';

		if ($c['identity']) {
			$lines[] = "\t\t * @Orm\\PrimaryKeyStrategy(strategy=\"identity\")";
		}

		if ($c['fk'] !== null) {
			$lines[] = "\t\t * @Orm\\ForeignKey(target={$c['fk']['target']}::class)";
			$lines[] = "\t\t * @Orm\\ForeignKeyAction(onDelete=\"{$c['fk']['onDelete']}\", onUpdate=\"{$c['fk']['onUpdate']}\")";
		}

		$lines[] = "\t\t */";

		$phpType = sPhpType($c);
		$phpNullable = $c['phpNullable'] ?? $c['nullable'];
		$nullPrefix = $phpNullable ? '?' : '';
		$decl = "\t\tprotected {$nullPrefix}{$phpType} \${$c['prop']}";

		if ($phpNullable) {
			$decl .= ' = null';
		}

		$lines[] = "{$decl};";
		return implode("\n", $lines);
	}

	/**
	 * @param array<string, array<string, mixed>> $columns Keyed by property name
	 * @param string[] $classIndexAnnotations Full `@Orm\...Index(...)` lines
	 */
	function renderSqliteSmokeEntity(string $namespace, string $className, array $columns, array $classIndexAnnotations): string {
		$lines = ['<?php', '', "\tnamespace {$namespace};", ''];
		$lines[] = "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;";
		$lines[] = "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;";
		$lines[] = "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;";
		$lines[] = "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\ForeignKey;";
		$lines[] = "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\ForeignKeyAction;";
		$lines[] = "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\Index;";
		$lines[] = "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\UniqueIndex;";
		$lines[] = "\tuse Quellabs\\ObjectQuel\\Annotations\\Orm\\FullTextIndex;";
		$lines[] = '';
		$lines[] = "\t/**";

		foreach ($classIndexAnnotations as $indexLine) {
			$lines[] = "\t * {$indexLine}";
		}

		$lines[] = "\t * @Orm\\Table(name=\"smoke_main\")";
		$lines[] = "\t */";
		$lines[] = "\tclass {$className} {";
		$lines[] = '';

		foreach ($columns as $c) {
			$lines[] = renderSColProperty($c);
			$lines[] = '';
		}

		$lines[] = "\t}";
		$lines[] = '';

		return implode("\n", $lines);
	}

	// -----------------------------------------------------------------------
	// Fixture setup
	// -----------------------------------------------------------------------

	$namespace = 'OqSchemaLifecycleSmokeSqlite\\Entities';
	$root = sys_get_temp_dir() . '/oq_schema_lifecycle_sqlite_' . uniqueSuffix();
	$entityPath = $root . '/entities';
	$migrationsDir = $root . '/migrations';
	mkdir($entityPath, 0777, true);
	mkdir($migrationsDir, 0777, true);

	// File-based, not `:memory:` — DatabaseAdapter's schema introspection and
	// its DDL-executing connection must see the same physical database, and
	// a file guarantees that regardless of how CakePHP's Connection manages
	// its underlying PDO handle (see QuelMigrationBuilderRegressionTest's
	// own docblock for the identical reasoning).
	$dbFile = $root . '/smoke.sqlite';
	$trackingTable = 'sql_smoke_track_' . getmypid() . '_' . uniqueSuffix();

	$provider = new ServiceProvider();
	$provider->setConfig([
		'driver'              => 'sqlite',
		'database'            => $dbFile,
		'entity_namespace'    => $namespace,
		'entity_path'         => $entityPath,
		'migrations_path'     => $migrationsDir,
		'migration_table'     => $trackingTable,
		'metadata_cache_path' => '',
	]);

	/** @var string|null $currentEntityFile Deleted before the next phase writes its replacement */
	$currentEntityFile = null;

	function writeSqliteSmokeVersion(int $version, array $columns, array $classIndexAnnotations): string {
		global $namespace, $entityPath, $currentEntityFile;

		if ($currentEntityFile !== null && file_exists($currentEntityFile)) {
			unlink($currentEntityFile);
		}

		$className = "SqliteSmokeV{$version}Entity";
		$path = "{$entityPath}/{$className}.php";
		file_put_contents($path, renderSqliteSmokeEntity($namespace, $className, $columns, $classIndexAnnotations));
		require $path;

		$currentEntityFile = $path;
		return "{$namespace}\\{$className}";
	}

	function writeSupportEntity(string $className, string $source): void {
		global $entityPath;
		$path = "{$entityPath}/{$className}.php";
		file_put_contents($path, $source);
		require $path;
	}

	writeSupportEntity('SqliteSmokeStatus', <<<PHP
	<?php

		namespace {$namespace};

		enum SqliteSmokeStatus: string {
			case Active = 'active';
			case Inactive = 'inactive';
			case Archived = 'archived';
		}

	PHP);

	writeSupportEntity('SqliteSmokeParentEntity', <<<PHP
	<?php

		namespace {$namespace};

		use Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;
		use Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;
		use Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;

		/**
		 * @Orm\\Table(name="smoke_parents")
		 */
		class SqliteSmokeParentEntity {
			/**
			 * @Orm\\Column(name="id", type="integer", primary_key=true)
			 * @Orm\\PrimaryKeyStrategy(strategy="identity")
			 */
			protected ?int \$id = null;
		}

	PHP);

	// A second, unrelated FK target — used only by the "add a foreign key to
	// an existing table via alter" failure phase, so that migration's own
	// `add foreign key` is the only thing in it (see that phase's comment).
	writeSupportEntity('SqliteSmokeParentTwoEntity', <<<PHP
	<?php

		namespace {$namespace};

		use Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;
		use Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;
		use Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;

		/**
		 * @Orm\\Table(name="smoke_parents_two")
		 */
		class SqliteSmokeParentTwoEntity {
			/**
			 * @Orm\\Column(name="id", type="integer", primary_key=true)
			 * @Orm\\PrimaryKeyStrategy(strategy="identity")
			 */
			protected ?int \$id = null;
		}

	PHP);

	// -----------------------------------------------------------------------
	// Base ("V1") column set.
	// -----------------------------------------------------------------------

	function baseColumns(): array {
		return [
			'id'        => scol('id', 'id', 'integer', ['primaryKey' => true, 'identity' => true, 'phpNullable' => true]),
			'parentId'  => scol('parentId', 'parent_id', 'integer', [
				'nullable' => true,
				'fk'       => ['target' => 'SqliteSmokeParentEntity', 'onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION'],
			]),
			'title'     => scol('title', 'title', 'string', ['limit' => 150]),
			'body'      => scol('body', 'body', 'text'),
			'price'     => scol('price', 'price', 'decimal', ['precision' => 8, 'scale' => 2]),
			'createdAt' => scol('createdAt', 'created_at', 'datetime'),
			'payload'   => scol('payload', 'payload', 'json', ['nullable' => true]),
			'status'    => scol('status', 'status', 'enum', ['enumType' => 'SqliteSmokeStatus', 'nullable' => true]),
		];
	}

	function insertSmokeRow($adapter, ?int $parentId, string $title, string $body): ?object {
		return $adapter->execute(
			'INSERT INTO "smoke_main" ("parent_id", "title", "body", "price", "created_at") VALUES (?, ?, ?, ?, ?)',
			[$parentId, $title, $body, 19.99, '2024-01-01 10:00:00']
		);
	}

	try {
		$adapter = $provider->getDatabaseAdapter();

		try {
			$adapter->getTables();
		} catch (\Throwable $e) {
			fwrite(STDERR, "Could not open the SQLite test file: {$e->getMessage()}\n");
			exit(1);
		}

		// -------------------------------------------------------------
		// Phase 1: create — an inline FK, a unique index, and a fulltext
		// index, all embedded directly in the `create` statement. The
		// fulltext index specifically exercises the bug found while
		// building this test: compiling SQLite's FTS5 virtual table
		// needs the base table's primary key, which used to be looked up
		// before the table existed when embedded this way.
		// -------------------------------------------------------------
		section('Phase 1: create smoke_main with an inline FK, unique index, and fulltext index');

		$columns = baseColumns();
		$indexes = [
			'unique'   => '@Orm\\UniqueIndex(name="uniq_smoke_title", columns={"title"})',
			'fulltext' => '@Orm\\FullTextIndex(name="ftx_smoke_body", columns={"body"})',
		];

		writeSqliteSmokeVersion(1, $columns, array_values($indexes));

		[$exitCode, $out] = runMakeMigrations($provider);
		check('make:migrations succeeds', $exitCode === 0);
		check('reports the new table', str_contains($out, 'New table: smoke_main'));
		check('reports the new unique index', str_contains($out, 'New index: smoke_main.uniq_smoke_title'));
		check('reports the new fulltext index', str_contains($out, 'New index: smoke_main.ftx_smoke_body'));

		[$exitCode, $out] = runQuelMigrate($provider);
		check('quel:migrate succeeds', $exitCode === 0 && str_contains($out, 'Migration completed successfully'));

		$dbColumns = $adapter->getColumns('smoke_main');
		check('id is identity and primary key', $dbColumns['id']->identity && $dbColumns['id']->primary_key);
		check('id is not nullable', !$dbColumns['id']->nullable);
		check('title is not nullable', !$dbColumns['title']->nullable);
		check('payload is nullable', $dbColumns['payload']->nullable);
		check('primary key is exactly [id]', $adapter->getPrimaryKeyColumns('smoke_main') === ['id']);

		$liveIndexes = $adapter->getIndexes('smoke_main');
		check('unique index exists and is marked unique', ($liveIndexes['uniq_smoke_title']['type'] ?? '') === 'unique');

		$liveForeignKeys = $adapter->getForeignKeys('smoke_main');
		$fkToParent = array_filter($liveForeignKeys, fn($fk) => $fk->referencedTable === 'smoke_parents');
		check('foreign key to smoke_parents exists', $fkToParent !== []);

		$adapter->execute('INSERT INTO "smoke_parents" DEFAULT VALUES');
		$parentId = (int)$adapter->execute('SELECT last_insert_rowid() AS id')?->fetchAssoc()['id'];

		check('inserting a row with a valid parent succeeds', insertSmokeRow($adapter, $parentId, 'Hello world', 'the quick brown fox') !== null);
		check('inserting a row with a nonexistent parent fails (FK enforced)', insertSmokeRow($adapter, 999999, 'Orphan row', 'no such parent') === null);
		check('inserting a duplicate title fails (unique index enforced)', insertSmokeRow($adapter, $parentId, 'Hello world', 'a different body') === null);

		$ftsHit = $adapter->execute("SELECT rowid FROM \"ftx_smoke_body\" WHERE \"ftx_smoke_body\" MATCH 'quick'")?->fetchAssoc();
		check('fulltext index finds the inserted row via FTS5 MATCH', $ftsHit !== null && $ftsHit !== false);

		[$exitCode, $out] = runMakeMigrations($provider);
		check('idempotent after creation (both introspection bugs fixed)', $exitCode === 0 && str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 2: add a nullable column.
		// -------------------------------------------------------------
		section('Phase 2: add a nullable column');

		$columns['notes'] = scol('notes', 'notes', 'string', ['limit' => 255, 'nullable' => true]);
		writeSqliteSmokeVersion(2, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the new notes column', str_contains($out, 'New column: smoke_main.notes'));
		runQuelMigrate($provider);

		check('notes column exists and is nullable', $adapter->getColumns('smoke_main')['notes']->nullable);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after adding notes', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 3: add a NOT NULL column with a default to a populated
		// table (backfill) — and confirm the SQLite-specific divergence
		// documented in QuelToSQLAlter::compileAddColumnWithBackfill():
		// no ALTER COLUMN exists to drop the DEFAULT afterward, so it's
		// deliberately left in place. A fresh insert omitting the column
		// must therefore SUCCEED and pick up that default — the opposite
		// of MySQL/PostgreSQL, where the same omission fails on purpose.
		// -------------------------------------------------------------
		section('Phase 3: add a NOT NULL column with a default to a populated table (backfill)');

		$columns['region'] = scol('region', 'region', 'string', ['limit' => 50, 'default' => '"unknown"']);
		writeSqliteSmokeVersion(3, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the new region column', str_contains($out, 'New column: smoke_main.region'));
		runQuelMigrate($provider);

		$existingRow = $adapter->execute('SELECT "region" FROM "smoke_main" WHERE "title" = ?', ['Hello world'])?->fetchAssoc();
		check('the pre-existing row was backfilled with the declared default', ($existingRow['region'] ?? null) === 'unknown');

		$freshInsert = $adapter->execute(
			'INSERT INTO "smoke_main" ("parent_id", "title", "body", "price", "created_at") VALUES (?, ?, ?, ?, ?)',
			[$parentId, 'No region supplied', 'body text', 5.00, '2024-02-01 10:00:00']
		);
		check('a fresh insert omitting region SUCCEEDS on SQLite (lingering DB-level default)', $freshInsert !== null);

		$freshRow = $adapter->execute('SELECT "region" FROM "smoke_main" WHERE "title" = ?', ['No region supplied'])?->fetchAssoc();
		check('the omitted region column picked up the lingering default', ($freshRow['region'] ?? null) === 'unknown');

		[, $out] = runMakeMigrations($provider);
		check('idempotent after the backfill', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 4: drop a column.
		// -------------------------------------------------------------
		section('Phase 4: drop a column');

		unset($columns['notes']);
		writeSqliteSmokeVersion(4, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the dropped notes column', str_contains($out, 'Dropped column: smoke_main.notes'));
		runQuelMigrate($provider);

		check('notes column is gone', !isset($adapter->getColumns('smoke_main')['notes']));

		[, $out] = runMakeMigrations($provider);
		check('idempotent after dropping notes', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 5: add a plain index via alter (an existing table — no
		// introspection-timing risk here, unlike a brand-new table).
		// -------------------------------------------------------------
		section('Phase 5: add a plain index via alter');

		$indexes['plain'] = '@Orm\\Index(name="idx_smoke_created", columns={"createdAt"})';
		writeSqliteSmokeVersion(5, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the new plain index', str_contains($out, 'New index: smoke_main.idx_smoke_created'));
		runQuelMigrate($provider);

		check('plain index exists', isset($adapter->getIndexes('smoke_main')['idx_smoke_created']));

		[, $out] = runMakeMigrations($provider);
		check('idempotent after adding the plain index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 6: modify that index (extend to a composite).
		// -------------------------------------------------------------
		section('Phase 6: modify the plain index to a composite');

		$indexes['plain'] = '@Orm\\Index(name="idx_smoke_created", columns={"createdAt", "status"})';
		writeSqliteSmokeVersion(6, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the modified index', str_contains($out, 'Modified index: smoke_main.idx_smoke_created'));
		runQuelMigrate($provider);

		check('index is now composite', count($adapter->getIndexes('smoke_main')['idx_smoke_created']['columns'] ?? []) === 2);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after modifying the plain index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 7: drop that plain index.
		// -------------------------------------------------------------
		section('Phase 7: drop the plain index');

		unset($indexes['plain']);
		writeSqliteSmokeVersion(7, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the dropped index', str_contains($out, 'Dropped index: smoke_main.idx_smoke_created'));
		runQuelMigrate($provider);

		check('plain index is gone', !isset($adapter->getIndexes('smoke_main')['idx_smoke_created']));

		[, $out] = runMakeMigrations($provider);
		check('idempotent after dropping the plain index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 8: modify the fulltext index (extend to a second
		// column) — proves the FTS5 virtual table is genuinely rebuilt,
		// not just diffed as unchanged.
		// -------------------------------------------------------------
		section('Phase 8: modify the fulltext index to cover a second column');

		$indexes['fulltext'] = '@Orm\\FullTextIndex(name="ftx_smoke_body", columns={"body", "title"})';
		writeSqliteSmokeVersion(8, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the modified fulltext index', str_contains($out, 'Modified index: smoke_main.ftx_smoke_body'));
		runQuelMigrate($provider);

		$titleOnlyHit = $adapter->execute("SELECT rowid FROM \"ftx_smoke_body\" WHERE \"ftx_smoke_body\" MATCH 'world'")?->fetchAssoc();
		check('fulltext index now also matches on the newly-added title column', $titleOnlyHit !== null && $titleOnlyHit !== false);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after modifying the fulltext index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 9: drop the fulltext index entirely.
		// -------------------------------------------------------------
		section('Phase 9: drop the fulltext index');

		unset($indexes['fulltext']);
		writeSqliteSmokeVersion(9, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the dropped fulltext index', str_contains($out, 'Dropped index: smoke_main.ftx_smoke_body'));
		runQuelMigrate($provider);

		check('FTS5 virtual table is gone', $adapter->getSqliteFts5BaseTable('ftx_smoke_body') === null);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after dropping the fulltext index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 10: retype — SQLite has no ALTER COLUMN at all. The
		// migration is still generated (dialect-agnostic diffing), but
		// applying it must fail loudly, leave the live column genuinely
		// unchanged, and leave nothing marked applied.
		// -------------------------------------------------------------
		section('Phase 10: retype a column must fail cleanly (SQLite has no ALTER COLUMN)');

		$filesBefore = glob($migrationsDir . '/*.php') ?: [];

		$columnsWithRetype = $columns;
		$columnsWithRetype['price'] = scol('price', 'price', 'integer');
		writeSqliteSmokeVersion(10, $columnsWithRetype, array_values($indexes));

		[$exitCode, $out] = runMakeMigrations($provider);
		check('make:migrations still generates the (unsafe) migration', $exitCode === 0 && str_contains($out, 'Modified column: smoke_main.price'));

		[$exitCode, $out] = runQuelMigrate($provider);
		check('quel:migrate fails', $exitCode === 1);
		check('the error explains SQLite has no ALTER COLUMN', str_contains($out, 'ALTER COLUMN'));

		check('the price column type is unchanged', $adapter->getColumns('smoke_main')['price']->type === 'decimal');

		[, $out] = runMakeMigrations($provider);
		check('the same change is still pending — nothing silently applied', str_contains($out, 'Modified column: smoke_main.price'));

		// Recovery: delete the migration file that can never succeed, and
		// revert to the last working entity version.
		foreach (array_diff(glob($migrationsDir . '/*.php') ?: [], $filesBefore) as $badFile) {
			unlink($badFile);
		}

		writeSqliteSmokeVersion(11, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reverting to the last valid entity shows no diff', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 11: primary-key change — SQLite's ALTER TABLE also has
		// no support for changing the primary key. Same failure/recovery
		// shape as the retype phase above.
		// -------------------------------------------------------------
		section('Phase 11: primary-key change must fail cleanly (SQLite has no ALTER TABLE PK support)');

		$filesBefore = glob($migrationsDir . '/*.php') ?: [];

		$columnsWithExtraPk = $columns;
		$columnsWithExtraPk['title'] = scol('title', 'title', 'string', ['limit' => 150, 'primaryKey' => true]);
		writeSqliteSmokeVersion(12, $columnsWithExtraPk, array_values($indexes));

		[$exitCode, $out] = runMakeMigrations($provider);
		check('make:migrations still generates the (unsafe) migration', $exitCode === 0 && str_contains($out, 'Primary key changed: smoke_main'));

		[$exitCode, $out] = runQuelMigrate($provider);
		check('quel:migrate fails', $exitCode === 1);
		check('the error explains SQLite has no primary-key ALTER support', str_contains($out, 'primary key'));

		check('the primary key is unchanged', $adapter->getPrimaryKeyColumns('smoke_main') === ['id']);

		[, $out] = runMakeMigrations($provider);
		check('the same change is still pending — nothing silently applied', str_contains($out, 'Primary key changed: smoke_main'));

		foreach (array_diff(glob($migrationsDir . '/*.php') ?: [], $filesBefore) as $badFile) {
			unlink($badFile);
		}

		writeSqliteSmokeVersion(13, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reverting to the last valid entity shows no diff', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 12: adding a foreign key to an EXISTING table via alter
		// — SQLite's ALTER TABLE cannot add or drop a foreign key at
		// all (only inline, at CREATE time, which Phase 1 already
		// covers). The column is added safely first, in its own
		// migration, so the failing migration's only content is the
		// `add foreign key` itself — isolating exactly what fails.
		// -------------------------------------------------------------
		section('Phase 12: adding a foreign key via alter must fail cleanly (SQLite has no ALTER TABLE FK support)');

		$columnsWithFkColumn = $columns;
		$columnsWithFkColumn['parentTwoId'] = scol('parentTwoId', 'parent_two_id', 'integer', ['nullable' => true]);
		writeSqliteSmokeVersion(14, $columnsWithFkColumn, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports the new parent_two_id column', str_contains($out, 'New column: smoke_main.parent_two_id'));
		runQuelMigrate($provider);
		check('parent_two_id column exists, unconstrained', isset($adapter->getColumns('smoke_main')['parent_two_id']));

		$filesBefore = glob($migrationsDir . '/*.php') ?: [];

		$columnsWithFk = $columnsWithFkColumn;
		$columnsWithFk['parentTwoId'] = scol('parentTwoId', 'parent_two_id', 'integer', [
			'nullable' => true,
			'fk'       => ['target' => 'SqliteSmokeParentTwoEntity', 'onDelete' => 'SET NULL', 'onUpdate' => 'NO ACTION'],
		]);
		writeSqliteSmokeVersion(15, $columnsWithFk, array_values($indexes));

		[$exitCode, $out] = runMakeMigrations($provider);
		check('make:migrations still generates the (unsafe) migration', $exitCode === 0 && str_contains($out, 'New foreign key'));

		[$exitCode, $out] = runQuelMigrate($provider);
		check('quel:migrate fails', $exitCode === 1);
		check('the error explains SQLite has no ALTER TABLE FK support', str_contains($out, 'foreign key'));

		check('no foreign key was added', array_filter(
			$adapter->getForeignKeys('smoke_main'),
			fn($fk) => $fk->referencedTable === 'smoke_parents_two'
		) === []);

		[, $out] = runMakeMigrations($provider);
		check('the same change is still pending — nothing silently applied', str_contains($out, 'New foreign key'));

		foreach (array_diff(glob($migrationsDir . '/*.php') ?: [], $filesBefore) as $badFile) {
			unlink($badFile);
		}

		writeSqliteSmokeVersion(16, $columnsWithFkColumn, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reverting to the last valid entity shows no diff', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Rollback: undo the entire generated migration history (every
		// migration that ever actually succeeded — the three failure
		// phases above each deleted their own never-applied file), most
		// recent first, down to nothing, then reapply and confirm the
		// reconstructed schema matches. Transactional DDL on SQLite
		// means each individual migration's down()/up() is already
		// proven atomic by the failure phases above; this instead
		// proves the down() DDL itself is correct across the whole
		// history, including the FTS5 rebuild.
		// -------------------------------------------------------------
		section('Rollback: undoing the entire generated migration history, then reapplying it');

		[$exitCode, $out] = runAnyCommand(QuelMigrateCommand::class, $provider, ['--rollback', '--force', '--target=0']);
		check('full rollback exit code is 0', $exitCode === 0);
		check('full rollback reports success', str_contains($out, 'Rollback completed successfully'));

		check('smoke_main no longer exists after full rollback', !in_array('smoke_main', $adapter->getTables(), true));
		check('smoke_parents no longer exists after full rollback', !in_array('smoke_parents', $adapter->getTables(), true));
		check('smoke_parents_two no longer exists after full rollback', !in_array('smoke_parents_two', $adapter->getTables(), true));

		$appliedCount = $adapter->execute("SELECT COUNT(*) AS c FROM \"{$trackingTable}\"")?->fetchAssoc()['c'] ?? null;
		check('tracking table shows zero applied migrations after full rollback', $appliedCount !== null && (int)$appliedCount === 0);

		[$exitCode, $out] = runQuelMigrate($provider);
		check('reapplying after full rollback exit code is 0', $exitCode === 0);
		check('reapplying after full rollback succeeds', str_contains($out, 'Migration completed successfully'));
		check('smoke_main exists again after reapplying', in_array('smoke_main', $adapter->getTables(), true));

		$dbColumns = $adapter->getColumns('smoke_main');
		check('reapplied schema has the region column added during backfill testing', isset($dbColumns['region']));
		check('reapplied schema has the parent_two_id column', isset($dbColumns['parent_two_id']));
		check('reapplied schema does not have the never-applied notes-then-dropped column back', !isset($dbColumns['notes']));
		check('reapplied schema has no fulltext index (dropped in phase 9, before rollback)', $adapter->getSqliteFts5BaseTable('ftx_smoke_body') === null);
		check('reapplied schema has no lingering foreign key to smoke_parents_two', array_filter(
			$adapter->getForeignKeys('smoke_main'),
			fn($fk) => $fk->referencedTable === 'smoke_parents_two'
		) === []);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after the full rollback + reapply round trip', $exitCode === 0 && str_contains($out, 'No changes detected'));
	} finally {
		// -----------------------------------------------------------------
		// Teardown — runs even if a check above threw. @-suppressed:
		// SQLite's own file lock can still be held briefly after the last
		// PDO connection to it goes out of scope, racing this cleanup on
		// Windows — harmless, since it's a per-run temp file nothing else
		// depends on and the OS temp directory cleans it up regardless
		// (same rationale as QuelMigrationBuilderRegressionTest's own
		// tearDown()).
		// -----------------------------------------------------------------
		$adapter = $provider->getDatabaseAdapter();

		foreach (glob($migrationsDir . '/*.php') ?: [] as $file) {
			unlink($file);
		}

		foreach (glob($entityPath . '/*.php') ?: [] as $file) {
			unlink($file);
		}

		@rmdir($migrationsDir);
		@rmdir($entityPath);

		if (file_exists($dbFile)) {
			@unlink($dbFile);
		}

		@rmdir($root);
	}

	// -----------------------------------------------------------------------
	// Summary
	// -----------------------------------------------------------------------

	echo "\n" . str_repeat('-', 40) . "\n";

	if ($failures === []) {
		echo color('32', "All {$checks} checks passed.") . "\n";
		exit(0);
	}

	$failCount = count($failures);
	echo color('31', "{$failCount} of {$checks} checks failed:") . "\n";

	foreach ($failures as $label) {
		echo "  - {$label}\n";
	}

	exit(1);
