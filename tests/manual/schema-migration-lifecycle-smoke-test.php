<?php

	/**
	 * Standalone end-to-end lifecycle smoke test for the schema-migration
	 * system (`make:migrations` + `quel:migrate`), run as its own PHP process
	 * rather than through PHPUnit — for the same reason
	 * quel-migrate-command-smoke-test.php is: MakeMigrationsCommand needs a
	 * real EntityStore built from real entity files on disk (annotations are
	 * read from actual docblocks, not in-memory strings), and the shared
	 * PHPUnit suite (tests/bootstrap.php) already owns the process-wide
	 * SignalHub/EntityManager.
	 *
	 * What this covers that no existing test does: a full round trip against
	 * one evolving "kitchen sink" entity that declares every column type
	 * TypeMapper/DDLTypeMapper support, every index kind (plain/unique/
	 * fulltext), and a foreign key — created via `make:migrations` +
	 * `quel:migrate`, verified against live introspection (not just the
	 * generated DDL string), then mutated one facet at a time (type change,
	 * nullable toggle, unsigned toggle, limit/precision/scale change,
	 * default-only change, add/drop column, add/drop/modify index, add/
	 * modify/drop foreign key). After every applied migration, `make:migrations`
	 * is run again and must report "No changes detected" — this is the
	 * regression guard for exactly the kind of bug fixed in SchemaComparator/
	 * TypeMapper this session (a comparison that could never converge kept
	 * generating the same no-op migration forever).
	 *
	 * Each mutation is a brand-new PHP class (KitchenSinkV1Entity,
	 * KitchenSinkV2Entity, ...) mapped to the same @Orm\Table(name="kitchen_sink")
	 * — PHP cannot redefine a class within one process, so the entity can't be
	 * edited in place the way a real project would edit it across separate
	 * `php sculpt` invocations. The previous version's file is deleted before
	 * the next is written, so EntityLocator's directory scan only ever sees
	 * one at a time.
	 *
	 * Usage:
	 *   php tests/manual/schema-migration-lifecycle-smoke-test.php
	 *
	 * Uses the same TEST_DB_* environment variables (and defaults) as the
	 * PHPUnit suite's phpunit.xml. Exits 0 if every check passes, 1 otherwise.
	 * Cleans up everything it creates (tables, tracking table, entity/migration
	 * files, temp directories) even on failure.
	 */

	require __DIR__ . '/../../vendor/autoload.php';

	use Quellabs\ObjectQuel\Sculpt\Commands\MakeMigrationsCommand;
	use Quellabs\ObjectQuel\Sculpt\Commands\QuelMigrateCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	// -----------------------------------------------------------------------
	// Tiny assertion harness — mirrors quel-migrate-command-smoke-test.php
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
	// Entity source generation — a "kitchen sink" table built column-by-column
	// from a data-driven description, so each mutation phase is just a small
	// diff against the previous phase's column array instead of a hand
	// written PHP file.
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $opts
	 * @return array<string, mixed>
	 */
	function ksCol(string $prop, string $col, string $type, array $opts = []): array {
		return array_merge([
			'prop'       => $prop,
			'col'        => $col,
			'type'       => $type,
			'unsigned'   => false,
			'nullable'   => false,
			'limit'      => null,
			'precision'  => null,
			'scale'      => null,
			'default'    => null,
			'primaryKey' => false,
			'identity'   => false,
			// PHP property nullability (the `?Type $x = null;` declaration
			// style), independent of the DB-level 'nullable' opt above. Only
			// needed for the identity PK, where every convention in this
			// codebase declares `protected ?int $id = null;` while the
			// annotation itself must stay non-nullable (MySQL forces PK
			// columns NOT NULL regardless, so declaring nullable=true there
			// would desync the entity from what the database can ever report).
			'phpNullable' => null,
			'enumType'   => null,
			// ['target' => ClassName, 'onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION']
			'fk'         => null,
		], $opts);
	}

	function ksPhpType(array $c): string {
		if ($c['enumType'] !== null) {
			return $c['enumType'];
		}

		return match ($c['type']) {
			'tinyinteger', 'smallinteger', 'integer', 'biginteger', 'year' => 'int',
			'float', 'decimal' => 'float',
			'boolean' => 'bool',
			'date', 'datetime', 'time', 'timestamp' => '\DateTime',
			'json' => 'array',
			default => 'string', // string, char, text, binary, blob, uuid
		};
	}

	function renderColumnAnnotationArgs(array $c): string {
		$parts = ["name=\"{$c['col']}\"", "type=\"{$c['type']}\""];

		if ($c['primaryKey']) {
			$parts[] = 'primary_key=true';
		}

		if ($c['unsigned']) {
			$parts[] = 'unsigned=true';
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

	function renderColumnProperty(array $c): string {
		$lines = ["\t\t/**"];
		$lines[] = "\t\t * @Orm\\Column(" . renderColumnAnnotationArgs($c) . ')';

		if ($c['identity']) {
			$lines[] = "\t\t * @Orm\\PrimaryKeyStrategy(strategy=\"identity\")";
		}

		if ($c['fk'] !== null) {
			$lines[] = "\t\t * @Orm\\ForeignKey(target={$c['fk']['target']}::class)";
			$lines[] = "\t\t * @Orm\\ForeignKeyAction(onDelete=\"{$c['fk']['onDelete']}\", onUpdate=\"{$c['fk']['onUpdate']}\")";
		}

		$lines[] = "\t\t */";

		$phpType = ksPhpType($c);
		$phpNullable = $c['phpNullable'] ?? $c['nullable'];
		$nullPrefix = $phpNullable ? '?' : '';
		$decl = "\t\tprotected {$nullPrefix}{$phpType} \${$c['prop']}";

		if ($phpNullable) {
			$decl .= ' = null';
		} elseif ($c['default'] !== null && in_array($c['type'], ['tinyinteger', 'smallinteger', 'integer', 'biginteger', 'year'], true)) {
			$decl .= " = {$c['default']}";
		}

		$lines[] = "{$decl};";
		return implode("\n", $lines);
	}

	/**
	 * @param array<string, array<string, mixed>> $columns Keyed by property name
	 * @param string[] $classIndexAnnotations Full `@Orm\...Index(...)` lines
	 */
	function renderKitchenSinkEntity(string $namespace, string $className, array $columns, array $classIndexAnnotations): string {
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

		$lines[] = "\t * @Orm\\Table(name=\"kitchen_sink\")";
		$lines[] = "\t */";
		$lines[] = "\tclass {$className} {";
		$lines[] = '';

		foreach ($columns as $c) {
			$lines[] = renderColumnProperty($c);
			$lines[] = '';
		}

		$lines[] = "\t}";
		$lines[] = '';

		return implode("\n", $lines);
	}

	// -----------------------------------------------------------------------
	// Fixture setup
	// -----------------------------------------------------------------------

	$namespace = 'OqSchemaLifecycleSmoke\\Entities';
	$root = sys_get_temp_dir() . '/oq_schema_lifecycle_' . uniqueSuffix();
	$entityPath = $root . '/entities';
	$migrationsDir = $root . '/migrations';
	mkdir($entityPath, 0777, true);
	mkdir($migrationsDir, 0777, true);

	$trackingTable = 'ksl_smoke_track_' . getmypid() . '_' . uniqueSuffix();

	$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
	$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
	$name = getenv('TEST_DB_NAME') ?: 'canvas_blog';
	$user = getenv('TEST_DB_USER') ?: 'root';
	$pass = getenv('TEST_DB_PASS') ?: '';

	$provider = new ServiceProvider();
	$provider->setConfig([
		'driver'              => 'mysql',
		'host'                => $host,
		'port'                => $port,
		'database'            => $name,
		'username'            => $user,
		'password'            => $pass,
		'encoding'            => 'utf8mb4',
		'entity_namespace'    => $namespace,
		'entity_path'         => $entityPath,
		'migrations_path'     => $migrationsDir,
		'migration_table'     => $trackingTable,
		// Every phase below writes a brand-new class name and expects its
		// annotations to be read fresh — a cached annotation collection from
		// an earlier run (the production default is
		// storage/annotations/) must never leak in here.
		'metadata_cache_path' => '',
	]);

	/** @var string|null $currentEntityFile Deleted before the next phase writes its replacement */
	$currentEntityFile = null;

	/**
	 * Writes and `require`s a new kitchen-sink entity version. `require`
	 * (not relying on class_exists()'s autoload) is deliberate: this file
	 * lives outside any Composer PSR-4 mapping, so nothing would find it
	 * on the class_exists()-triggered autoload EntityLocator otherwise
	 * relies on — requiring it here is exactly what makes the class
	 * "exist" for that check.
	 */
	function writeKitchenSinkVersion(int $version, array $columns, array $classIndexAnnotations): string {
		global $namespace, $entityPath, $currentEntityFile;

		if ($currentEntityFile !== null && file_exists($currentEntityFile)) {
			unlink($currentEntityFile);
		}

		$className = "KitchenSinkV{$version}Entity";
		$path = "{$entityPath}/{$className}.php";
		file_put_contents($path, renderKitchenSinkEntity($namespace, $className, $columns, $classIndexAnnotations));
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

	// A backing enum for the kitchen sink's `status` column — persists
	// unchanged across every phase.
	writeSupportEntity('KitchenSinkStatus', <<<PHP
	<?php

		namespace {$namespace};

		enum KitchenSinkStatus: string {
			case Active = 'active';
			case Inactive = 'inactive';
			case Archived = 'archived';
		}

	PHP);

	// Two FK targets — persist unchanged across every phase. Two, not one,
	// so a "new foreign key" mutation later (V15) exercises a genuinely new
	// relation rather than just re-adding the same one.
	writeSupportEntity('KitchenSinkParentEntity', <<<PHP
	<?php

		namespace {$namespace};

		use Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;
		use Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;
		use Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;

		/**
		 * @Orm\\Table(name="kitchen_sink_parents")
		 */
		class KitchenSinkParentEntity {
			/**
			 * @Orm\\Column(name="id", type="integer", unsigned=true, primary_key=true)
			 * @Orm\\PrimaryKeyStrategy(strategy="identity")
			 */
			protected ?int \$id = null;
		}

	PHP);

	writeSupportEntity('KitchenSinkParentTwoEntity', <<<PHP
	<?php

		namespace {$namespace};

		use Quellabs\\ObjectQuel\\Annotations\\Orm\\Column;
		use Quellabs\\ObjectQuel\\Annotations\\Orm\\PrimaryKeyStrategy;
		use Quellabs\\ObjectQuel\\Annotations\\Orm\\Table;

		/**
		 * @Orm\\Table(name="kitchen_sink_parents_two")
		 */
		class KitchenSinkParentTwoEntity {
			/**
			 * @Orm\\Column(name="id", type="integer", unsigned=true, primary_key=true)
			 * @Orm\\PrimaryKeyStrategy(strategy="identity")
			 */
			protected ?int \$id = null;
		}

	PHP);

	// -------------------------------------------------------------------------
	// Base ("V1") column set — one column of every DDLTypeMapper-supported
	// type ('set' is deliberately excluded: it has no dedicated DDL rendering
	// and falls through to the same VARCHAR default as 'string', so it
	// exercises nothing distinct).
	// -------------------------------------------------------------------------

	function baseColumns(): array {
		return [
			'id'          => ksCol('id', 'id', 'integer', ['unsigned' => true, 'primaryKey' => true, 'identity' => true, 'phpNullable' => true]),
			'parentId'    => ksCol('parentId', 'parent_id', 'integer', [
				'unsigned' => true, // must match the unsigned PK it references
				'fk'       => ['target' => 'KitchenSinkParentEntity', 'onDelete' => 'RESTRICT', 'onUpdate' => 'NO ACTION'],
			]),
			'levelTiny'   => ksCol('levelTiny', 'level_tiny', 'tinyinteger'),
			'levelSmall'  => ksCol('levelSmall', 'level_small', 'smallinteger', ['unsigned' => true]),
			'levelBig'    => ksCol('levelBig', 'level_big', 'biginteger'),
			'name'        => ksCol('name', 'name', 'string', ['limit' => 150]),
			'code'        => ksCol('code', 'code', 'char', ['limit' => 10]),
			'description' => ksCol('description', 'description', 'text'),
			'price'       => ksCol('price', 'price', 'decimal', ['precision' => 10, 'scale' => 2, 'unsigned' => true]),
			'ratio'       => ksCol('ratio', 'ratio', 'float'),
			'isActive'    => ksCol('isActive', 'is_active', 'boolean'),
			'birthDate'   => ksCol('birthDate', 'birth_date', 'date'),
			'startTime'   => ksCol('startTime', 'start_time', 'time'),
			'createdAt'   => ksCol('createdAt', 'created_at', 'datetime'),
			'updatedAt'   => ksCol('updatedAt', 'updated_at', 'timestamp'),
			'avatar'      => ksCol('avatar', 'avatar', 'binary', ['limit' => 32]),
			'payload'     => ksCol('payload', 'payload', 'blob'),
			'metadata'    => ksCol('metadata', 'metadata', 'json'),
			'externalId'  => ksCol('externalId', 'external_id', 'uuid'),
			'releaseYear' => ksCol('releaseYear', 'release_year', 'year'),
			'status'      => ksCol('status', 'status', 'enum', ['enumType' => 'KitchenSinkStatus']),
			'priority'    => ksCol('priority', 'priority', 'integer', ['nullable' => true, 'default' => 5]),
			'notes'       => ksCol('notes', 'notes', 'string', ['limit' => 255, 'nullable' => true]),
		];
	}

	function baseIndexes(): array {
		return [
			'plain'    => '@Orm\\Index(name="idx_ks_name", columns={"name"})',
			'unique'   => '@Orm\\UniqueIndex(name="uniq_ks_code", columns={"code"})',
			'fulltext' => '@Orm\\FullTextIndex(name="ftx_ks_description", columns={"description"})',
		];
	}

	/**
	 * Inserts one full row (every NOT NULL column, which never changes
	 * across phases — only nullable columns get added/dropped) with $code/
	 * $levelTiny left variable for the unique-index behavioural check.
	 * Returns the adapter's execute() result (null on failure).
	 */
	function insertKitchenSinkRow($adapter, int $parentId, string $code, int $levelTiny) {
		return $adapter->execute(
			'INSERT INTO `kitchen_sink` (' .
			'`parent_id`, `level_tiny`, `level_small`, `level_big`, `name`, `code`, `description`, ' .
			'`price`, `ratio`, `is_active`, `birth_date`, `start_time`, `created_at`, `updated_at`, ' .
			'`avatar`, `payload`, `metadata`, `external_id`, `release_year`, `status`' .
			') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$parentId, $levelTiny, 3, 123456789, 'Widget', $code, 'A sample widget for lifecycle testing',
				19.99, 0.5, 1, '2020-01-01', '08:30:00', '2024-01-01 10:00:00', '2024-01-01 10:00:00',
				'binary-data', 'blob-data', json_encode(['k' => 'v']), '3fa85f64-5717-4562-b3fc-2c963f66afa6', 2024, 'active',
			]
		);
	}

	try {
		$adapter = $provider->getDatabaseAdapter();

		try {
			$adapter->getTables();
		} catch (\Throwable $e) {
			fwrite(STDERR, "No live MySQL server reachable: {$e->getMessage()}\n");
			exit(1);
		}

		// -------------------------------------------------------------
		// Phase 1: create the kitchen-sink table from scratch
		// -------------------------------------------------------------
		section('Phase 1: create — every column type, every index kind, one foreign key');

		$columns = baseColumns();
		$indexes = baseIndexes();
		writeKitchenSinkVersion(1, $columns, array_values($indexes));

		[$exitCode, $out] = runMakeMigrations($provider);
		check('make:migrations exit code is 0', $exitCode === 0);
		check('reports new table', str_contains($out, 'New table: kitchen_sink'));

		foreach ($columns as $c) {
			check("reports new column {$c['col']}", str_contains($out, "New column: kitchen_sink.{$c['col']}"));
		}

		check('reports new plain index', str_contains($out, 'New index: kitchen_sink.idx_ks_name'));
		check('reports new unique index', str_contains($out, 'New index: kitchen_sink.uniq_ks_code'));
		check('reports new fulltext index', str_contains($out, 'New index: kitchen_sink.ftx_ks_description'));
		check('reports new foreign key', str_contains($out, 'New foreign key: kitchen_sink.'));

		[$exitCode, $out] = runQuelMigrate($provider);
		check('quel:migrate exit code is 0', $exitCode === 0);
		check('quel:migrate reports success', str_contains($out, 'Migration completed successfully'));
		check('table now exists', in_array('kitchen_sink', $adapter->getTables(), true));

		// ---- live introspection: every column, exactly as declared ----
		$dbColumns = $adapter->getColumns('kitchen_sink');

		$typeExpectations = [
			'level_tiny'   => 'tinyinteger',
			'level_small'  => 'smallinteger',
			'level_big'    => 'biginteger',
			'name'         => 'string',
			'code'         => 'char',
			'description'  => 'text',
			'price'        => 'decimal',
			'ratio'        => 'float',
			'is_active'    => 'boolean',
			'birth_date'   => 'date',
			'start_time'   => 'time',
			'created_at'   => 'datetime',
			'updated_at'   => 'timestamp',
			'avatar'       => 'binary',
			'payload'      => 'blob',
			'metadata'     => 'json',
			'external_id'  => 'uuid',
			'release_year' => 'year',
		];

		foreach ($typeExpectations as $col => $expectedType) {
			check("column {$col} exists with type {$expectedType}", isset($dbColumns[$col]) && $dbColumns[$col]->type === $expectedType);
		}

		check('level_small is unsigned', $dbColumns['level_small']->unsigned === true);
		check('level_tiny is not unsigned', $dbColumns['level_tiny']->unsigned === false);
		check('price precision/scale is 10,2', $dbColumns['price']->precision === 10 && $dbColumns['price']->scale === 2);
		check('name limit is 150', $dbColumns['name']->limit === 150);
		check('notes is nullable', $dbColumns['notes']->nullable === true);
		check('level_tiny is not nullable', $dbColumns['level_tiny']->nullable === false);
		check('status is an enum with the declared cases', $dbColumns['status']->type === 'enum' && $dbColumns['status']->values !== null && count(array_intersect(['active', 'inactive', 'archived'], $dbColumns['status']->values)) === 3);

		$dbIndexes = $adapter->getIndexes('kitchen_sink');
		check('plain index has expected column', isset($dbIndexes['idx_ks_name']) && $dbIndexes['idx_ks_name']['columns'] === ['name']);
		check('unique index has expected type', isset($dbIndexes['uniq_ks_code']) && $dbIndexes['uniq_ks_code']['type'] === 'unique');
		check('fulltext index has expected type', isset($dbIndexes['ftx_ks_description']) && $dbIndexes['ftx_ks_description']['type'] === 'fulltext');

		$dbForeignKeys = $adapter->getForeignKeys('kitchen_sink');
		$fk = reset($dbForeignKeys);
		check('foreign key references kitchen_sink_parents', $fk !== false && $fk->referencedTable === 'kitchen_sink_parents' && $fk->columns === ['parent_id']);
		check('foreign key action defaults to RESTRICT/NO ACTION', $fk !== false && $fk->onDelete === 'RESTRICT' && $fk->onUpdate === 'NO ACTION');

		[$exitCode, $out] = runMakeMigrations($provider);
		check('idempotent: exit code is 0', $exitCode === 0);
		check('idempotent: no changes detected after create', str_contains($out, 'No changes detected'));

		// ---- data round trip: insert a real row, prove it reads back ----
		$adapter->execute('INSERT INTO `kitchen_sink_parents` (`id`) VALUES (1)');

		$insertResult = insertKitchenSinkRow($adapter, 1, 'row-001', 7);
		check('insert of a full row succeeds', $insertResult !== null);

		$stmt = $adapter->execute('SELECT * FROM `kitchen_sink` WHERE `code` = ?', ['row-001']);
		$row = $stmt?->fetchAssoc();
		check('row round-trips: is_active stored as truthy', $row !== null && (int)$row['is_active'] === 1);
		check('row round-trips: price keeps 2-decimal scale', $row !== null && (string)$row['price'] === '19.99');
		check('row round-trips: status enum value', $row !== null && $row['status'] === 'active');
		check('row round-trips: external_id uuid value', $row !== null && $row['external_id'] === '3fa85f64-5717-4562-b3fc-2c963f66afa6');

		// -------------------------------------------------------------
		// Phase 2 (V2): add a column
		// -------------------------------------------------------------
		section('Phase 2: add column (extra_notes)');

		$columns['extraNotes'] = ksCol('extraNotes', 'extra_notes', 'text', ['nullable' => true]);
		writeKitchenSinkVersion(2, $columns, array_values($indexes));

		[$exitCode, $out] = runMakeMigrations($provider);
		check('reports new column extra_notes', str_contains($out, 'New column: kitchen_sink.extra_notes'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('extra_notes exists and is nullable text', isset($dbColumns['extra_notes']) && $dbColumns['extra_notes']->type === 'text' && $dbColumns['extra_notes']->nullable === true);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after adding a column', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 3 (V3): change a column's type
		// -------------------------------------------------------------
		section('Phase 3: retype column (level_tiny: tinyinteger -> smallinteger)');

		$columns['levelTiny']['type'] = 'smallinteger';
		writeKitchenSinkVersion(3, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		// Retyping to a wider integer also moves TypeMapper's implicit default
		// limit (tinyinteger's is 4, smallinteger's is 6), so the summary
		// reports both facets on the same line — check the type-change
		// clause, not the whole parenthetical.
		check('reports modified column with type change', str_contains($out, 'Modified column: kitchen_sink.level_tiny (type changed to smallinteger'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('level_tiny is now smallinteger', $dbColumns['level_tiny']->type === 'smallinteger');

		[, $out] = runMakeMigrations($provider);
		check('idempotent after a type change', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 4 (V4): toggle nullable
		// -------------------------------------------------------------
		section('Phase 4: toggle nullable (is_active: not null -> nullable)');

		$columns['isActive']['nullable'] = true;
		writeKitchenSinkVersion(4, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports modified column, now nullable', str_contains($out, 'Modified column: kitchen_sink.is_active (now nullable)'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('is_active is now nullable', $dbColumns['is_active']->nullable === true);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after a nullable toggle', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 5 (V5): toggle unsigned
		// -------------------------------------------------------------
		section('Phase 5: toggle unsigned (level_small: unsigned -> signed)');

		$columns['levelSmall']['unsigned'] = false;
		writeKitchenSinkVersion(5, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports modified column for unsigned change', str_contains($out, 'Modified column: kitchen_sink.level_small'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('level_small is no longer unsigned', $dbColumns['level_small']->unsigned === false);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after an unsigned toggle', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 6 (V6): change limit and precision/scale together
		// -------------------------------------------------------------
		section('Phase 6: change limit/precision/scale (name -> 200, price -> 12,4)');

		$columns['name']['limit'] = 200;
		$columns['price']['precision'] = 12;
		$columns['price']['scale'] = 4;
		writeKitchenSinkVersion(6, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports name length change', str_contains($out, 'Modified column: kitchen_sink.name (length changed to 200)'));
		check('reports price modification', str_contains($out, 'Modified column: kitchen_sink.price'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('name limit is now 200', $dbColumns['name']->limit === 200);
		check('price precision/scale is now 12,4', $dbColumns['price']->precision === 12 && $dbColumns['price']->scale === 4);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after limit/precision/scale change', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 7 (V7): change only the declared default — regression
		// guard for this session's SchemaComparator/TypeMapper fix.
		// -------------------------------------------------------------
		section('Phase 7: default-only change (priority: default 5 -> 42) must produce an empty diff');

		$columns['priority']['default'] = 42;
		writeKitchenSinkVersion(7, $columns, array_values($indexes));

		[$exitCode, $out] = runMakeMigrations($provider);
		check('default-only change: exit code is 0', $exitCode === 0);
		check('default-only change produces no migration', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 8 (V8): drop a column
		// -------------------------------------------------------------
		section('Phase 8: drop column (notes)');

		unset($columns['notes']);
		writeKitchenSinkVersion(8, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports dropped column', str_contains($out, 'Dropped column: kitchen_sink.notes'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('notes column is gone', !isset($dbColumns['notes']));

		[, $out] = runMakeMigrations($provider);
		check('idempotent after dropping a column', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 9 (V9): add a plain index
		// -------------------------------------------------------------
		section('Phase 9: add a plain index (idx_ks_level_big)');

		$indexes['extra'] = '@Orm\\Index(name="idx_ks_level_big", columns={"levelBig"})';
		writeKitchenSinkVersion(9, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports new index', str_contains($out, 'New index: kitchen_sink.idx_ks_level_big'));
		runQuelMigrate($provider);

		$dbIndexes = $adapter->getIndexes('kitchen_sink');
		check('new plain index exists', isset($dbIndexes['idx_ks_level_big']) && $dbIndexes['idx_ks_level_big']['columns'] === ['level_big']);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after adding an index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 10 (V10): drop that index
		// -------------------------------------------------------------
		section('Phase 10: drop the plain index (idx_ks_level_big)');

		unset($indexes['extra']);
		writeKitchenSinkVersion(10, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports dropped index', str_contains($out, 'Dropped index: kitchen_sink.idx_ks_level_big'));
		runQuelMigrate($provider);

		$dbIndexes = $adapter->getIndexes('kitchen_sink');
		check('dropped index is gone', !isset($dbIndexes['idx_ks_level_big']));

		[, $out] = runMakeMigrations($provider);
		check('idempotent after dropping an index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 11 (V11): modify a plain index's columns
		// -------------------------------------------------------------
		section("Phase 11: modify plain index columns (idx_ks_name: {name} -> {name, status})");

		$indexes['plain'] = '@Orm\\Index(name="idx_ks_name", columns={"name", "status"})';
		writeKitchenSinkVersion(11, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports modified index', str_contains($out, 'Modified index: kitchen_sink.idx_ks_name'));
		runQuelMigrate($provider);

		$dbIndexes = $adapter->getIndexes('kitchen_sink');
		check('plain index now covers name+status', isset($dbIndexes['idx_ks_name']) && $dbIndexes['idx_ks_name']['columns'] === ['name', 'status']);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after modifying an index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 12 (V12): modify the unique index's columns, then prove
		// the database actually enforces the new composite constraint.
		// -------------------------------------------------------------
		section("Phase 12: modify unique index columns (uniq_ks_code: {code} -> {code, level_tiny}) + enforcement check");

		$indexes['unique'] = '@Orm\\UniqueIndex(name="uniq_ks_code", columns={"code", "levelTiny"})';
		writeKitchenSinkVersion(12, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports modified unique index', str_contains($out, 'Modified index: kitchen_sink.uniq_ks_code'));
		runQuelMigrate($provider);

		$dbIndexes = $adapter->getIndexes('kitchen_sink');
		check('unique index now covers code+level_tiny', isset($dbIndexes['uniq_ks_code']) && $dbIndexes['uniq_ks_code']['type'] === 'unique' && $dbIndexes['uniq_ks_code']['columns'] === ['code', 'level_tiny']);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after modifying the unique index', str_contains($out, 'No changes detected'));

		$sameCodeDifferentLevel = insertKitchenSinkRow($adapter, 1, 'row-001', 9);
		check('composite unique allows same code with a different level_tiny', $sameCodeDifferentLevel !== null);

		$exactDuplicate = insertKitchenSinkRow($adapter, 1, 'row-001', 7);
		check('composite unique rejects an exact code+level_tiny duplicate', $exactDuplicate === null);

		// -------------------------------------------------------------
		// Phase 13 (V13): drop the fulltext index
		// -------------------------------------------------------------
		section('Phase 13: drop the fulltext index');

		unset($indexes['fulltext']);
		writeKitchenSinkVersion(13, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports dropped fulltext index', str_contains($out, 'Dropped index: kitchen_sink.ftx_ks_description'));
		runQuelMigrate($provider);

		$dbIndexes = $adapter->getIndexes('kitchen_sink');
		check('fulltext index is gone', !isset($dbIndexes['ftx_ks_description']));

		[, $out] = runMakeMigrations($provider);
		check('idempotent after dropping the fulltext index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 14 (V14): re-add the fulltext index
		// -------------------------------------------------------------
		section('Phase 14: re-add the fulltext index');

		$indexes['fulltext'] = '@Orm\\FullTextIndex(name="ftx_ks_description", columns={"description"})';
		writeKitchenSinkVersion(14, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports new fulltext index', str_contains($out, 'New index: kitchen_sink.ftx_ks_description'));
		runQuelMigrate($provider);

		$dbIndexes = $adapter->getIndexes('kitchen_sink');
		check('fulltext index exists again', isset($dbIndexes['ftx_ks_description']) && $dbIndexes['ftx_ks_description']['type'] === 'fulltext');

		[, $out] = runMakeMigrations($provider);
		check('idempotent after re-adding the fulltext index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 15 (V15): add a new column with a new foreign key
		// -------------------------------------------------------------
		section('Phase 15: add a new column with a new foreign key (parent_two_id)');

		$adapter->execute('INSERT INTO `kitchen_sink_parents_two` (`id`) VALUES (1)');

		$columns['parentTwoId'] = ksCol('parentTwoId', 'parent_two_id', 'integer', [
			'nullable' => true,
			'unsigned' => true, // must match the unsigned PK it references
			'fk'       => ['target' => 'KitchenSinkParentTwoEntity', 'onDelete' => 'SET NULL', 'onUpdate' => 'NO ACTION'],
		]);
		writeKitchenSinkVersion(15, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports new column parent_two_id', str_contains($out, 'New column: kitchen_sink.parent_two_id'));
		check('reports new foreign key', str_contains($out, 'New foreign key: kitchen_sink.'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('parent_two_id column exists', isset($dbColumns['parent_two_id']));

		$dbForeignKeys = $adapter->getForeignKeys('kitchen_sink');
		$parentTwoFk = null;
		foreach ($dbForeignKeys as $definition) {
			if ($definition->columns === ['parent_two_id']) {
				$parentTwoFk = $definition;
			}
		}
		check('new foreign key references kitchen_sink_parents_two', $parentTwoFk !== null && $parentTwoFk->referencedTable === 'kitchen_sink_parents_two');
		check('new foreign key has the declared SET NULL action', $parentTwoFk !== null && $parentTwoFk->onDelete === 'SET NULL');

		[, $out] = runMakeMigrations($provider);
		check('idempotent after adding a column with a foreign key', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 16 (V16): modify an existing foreign key's action
		// -------------------------------------------------------------
		section('Phase 16: modify foreign key action (parent_id: RESTRICT/NO ACTION -> CASCADE/RESTRICT)');

		$columns['parentId']['fk']['onDelete'] = 'CASCADE';
		$columns['parentId']['fk']['onUpdate'] = 'RESTRICT';
		writeKitchenSinkVersion(16, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports modified foreign key', str_contains($out, 'Modified foreign key: kitchen_sink.'));
		runQuelMigrate($provider);

		$dbForeignKeys = $adapter->getForeignKeys('kitchen_sink');
		$parentFk = null;
		foreach ($dbForeignKeys as $definition) {
			if ($definition->columns === ['parent_id']) {
				$parentFk = $definition;
			}
		}
		check('parent_id foreign key now cascades on delete', $parentFk !== null && $parentFk->onDelete === 'CASCADE');
		check('parent_id foreign key now restricts on update', $parentFk !== null && $parentFk->onUpdate === 'RESTRICT');

		[, $out] = runMakeMigrations($provider);
		check('idempotent after modifying a foreign key action', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 17 (V17): drop a foreign key, keep its column
		// -------------------------------------------------------------
		section('Phase 17: drop foreign key, keep the column (parent_two_id)');

		$columns['parentTwoId']['fk'] = null;
		writeKitchenSinkVersion(17, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports dropped foreign key', str_contains($out, 'Dropped foreign key: kitchen_sink.'));
		check('does not report the column as dropped', !str_contains($out, 'Dropped column: kitchen_sink.parent_two_id'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('parent_two_id column still exists', isset($dbColumns['parent_two_id']));

		$dbForeignKeys = $adapter->getForeignKeys('kitchen_sink');
		$stillHasParentTwoFk = false;
		foreach ($dbForeignKeys as $definition) {
			if ($definition->columns === ['parent_two_id']) {
				$stillHasParentTwoFk = true;
			}
		}
		check('parent_two_id foreign key is gone', !$stillHasParentTwoFk);

		// MySQL auto-creates a supporting index for an FK column (named after
		// the constraint, same convention ForeignKeyConstraintNamer uses) when
		// none already exists — `drop foreign key` only drops the constraint,
		// not that index, so one legitimate follow-up diff is expected here
		// before the schema is fully idempotent again.
		[, $out] = runMakeMigrations($provider);
		check('one follow-up diff to drop the FK\'s now-orphaned supporting index', str_contains($out, 'Dropped index: kitchen_sink.'));
		runQuelMigrate($provider);

		[, $out] = runMakeMigrations($provider);
		check('idempotent after cleaning up the orphaned index', str_contains($out, 'No changes detected'));

		// -------------------------------------------------------------
		// Phase 18 (V18): drop the now-FK-less column too
		// -------------------------------------------------------------
		section('Phase 18: drop the now-FK-less column (parent_two_id)');

		unset($columns['parentTwoId']);
		writeKitchenSinkVersion(18, $columns, array_values($indexes));

		[, $out] = runMakeMigrations($provider);
		check('reports dropped column', str_contains($out, 'Dropped column: kitchen_sink.parent_two_id'));
		runQuelMigrate($provider);

		$dbColumns = $adapter->getColumns('kitchen_sink');
		check('parent_two_id column is gone', !isset($dbColumns['parent_two_id']));

		[$exitCode, $out] = runMakeMigrations($provider);
		check('final idempotency check: exit code is 0', $exitCode === 0);
		check('final idempotency check: no changes detected', str_contains($out, 'No changes detected'));
	} finally {
		// -----------------------------------------------------------------
		// Teardown — runs even if a check above threw. Foreign-key
		// dependency order: the child table first, then both parents.
		// -----------------------------------------------------------------
		$adapter = $provider->getDatabaseAdapter();

		$adapter->execute('DROP TABLE IF EXISTS `kitchen_sink`');
		$adapter->execute('DROP TABLE IF EXISTS `kitchen_sink_parents_two`');
		$adapter->execute('DROP TABLE IF EXISTS `kitchen_sink_parents`');
		$adapter->execute("DROP TABLE IF EXISTS `{$trackingTable}`");

		foreach (glob($migrationsDir . '/*.php') ?: [] as $file) {
			unlink($file);
		}

		foreach (glob($entityPath . '/*.php') ?: [] as $file) {
			unlink($file);
		}

		rmdir($migrationsDir);
		rmdir($entityPath);
		rmdir($root);
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
