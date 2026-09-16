<?php

	/**
	 * Standalone end-to-end smoke test for QuelMigrateCommand, run as its own
	 * PHP process rather than through PHPUnit.
	 *
	 * Why this isn't a PHPUnit test: the shared suite (tests/bootstrap.php)
	 * constructs exactly one EntityManager per process and stores it in
	 * $GLOBALS['test_em']. UnitOfWork registers process-wide signals (e.g.
	 * 'orm.prePersist') on the shared SignalHub with no duplicate-registration
	 * guard, so a second EntityManager anywhere in that same process throws
	 * "Standalone signal 'orm.prePersist' is already registered". QuelMigrateCommand
	 * always builds its own EntityManager (via ServiceProvider::getEntityManager()
	 * inside makeRunner()), so it can never run inside the shared PHPUnit process.
	 * Running as a separate `php` invocation sidesteps this entirely — no
	 * bootstrap.php, no pre-existing EntityManager to collide with.
	 *
	 * Usage:
	 *   php tests/manual/quel-migrate-command-smoke-test.php
	 *
	 * Uses the same TEST_DB_* environment variables (and the same defaults)
	 * as the PHPUnit suite's phpunit.xml. Exits 0 if every check passes, 1
	 * otherwise. Cleans up everything it creates (tables, tracking table,
	 * migration files, temp directories) even on failure.
	 */

	require __DIR__ . '/../../vendor/autoload.php';

	use Quellabs\ObjectQuel\Sculpt\Commands\MakeMigrationCommand;
	use Quellabs\ObjectQuel\Sculpt\Commands\QuelMigrateCommand;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;
	use Quellabs\Sculpt\Contracts\CommandBase;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Console\ConsoleInput;
	use Quellabs\Sculpt\Console\ConsoleOutput;

	// -----------------------------------------------------------------------
	// Tiny assertion harness — no PHPUnit involved.
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

	// -----------------------------------------------------------------------
	// Fixture setup
	// -----------------------------------------------------------------------

	$migrationsDir = sys_get_temp_dir() . '/oq_qmc_smoke_' . uniqueSuffix();
	mkdir($migrationsDir, 0777, true);

	// A dedicated, empty directory — not sys_get_temp_dir() itself, which
	// EntityLocator would otherwise recursively scan in full the moment
	// getEntityManager() triggers entity discovery.
	$entityPath = sys_get_temp_dir() . '/oq_qmc_smoke_entities_' . uniqueSuffix();
	mkdir($entityPath, 0777, true);

	$trackingTable = 'qmc_smoke_track_' . getmypid() . '_' . uniqueSuffix();
	$createdTables = [];

	function nextTableName(): string {
		global $createdTables;
		$name = 'qmc_smoke_' . getmypid() . '_' . uniqueSuffix();
		$createdTables[] = $name;
		return $name;
	}

	function writeMigration(string $migrationsDir, int $version, string $tableName): string {
		$className = 'QmcSmokeMigration_' . uniqueSuffix();

		file_put_contents(
			"{$migrationsDir}/{$version}_{$className}.php",
			"<?php\n" .
			"class {$className} extends \\Quellabs\\ObjectQuel\\Migration\\AbstractMigration {\n" .
			"    public function up(): void {\n" .
			"        \$this->query('create {$tableName} (id = integer identity, primary key (id))');\n" .
			"    }\n" .
			"    public function down(): void {\n" .
			"        \$this->query('destroy {$tableName}');\n" .
			"    }\n" .
			"}\n"
		);

		return $className;
	}

	/**
	 * @param string[] $args
	 * @return array{0: int, 1: string}
	 */
	function runCommand(ServiceProvider $provider, array $args): array {
		return runAnyCommand(QuelMigrateCommand::class, $provider, $args);
	}

	/**
	 * @param class-string<CommandBase> $commandClass
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

	$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
	$port = (int)(getenv('TEST_DB_PORT') ?: 3306);
	$name = getenv('TEST_DB_NAME') ?: 'canvas_blog';
	$user = getenv('TEST_DB_USER') ?: 'root';
	$pass = getenv('TEST_DB_PASS') ?: '';

	$provider = new ServiceProvider();
	$provider->setConfig([
		'driver'           => 'mysql',
		'host'             => $host,
		'port'             => $port,
		'database'         => $name,
		'username'         => $user,
		'password'         => $pass,
		'encoding'         => 'utf8mb4',
		'entity_namespace' => 'App\\Entities',
		'entity_path'      => $entityPath,
		'migrations_path'  => $migrationsDir,
		'migration_table'  => $trackingTable,
	]);

	try {
		$provider->getDatabaseAdapter()->getTables();
	} catch (\Throwable $e) {
		fwrite(STDERR, "No live MySQL server reachable: {$e->getMessage()}\n");
		exit(1);
	}

	try {
		// ---------------------------------------------------------------
		// Scenario 1: no pending migrations
		// ---------------------------------------------------------------
		section('No pending migrations');
		[$exitCode, $out] = runCommand($provider, ['--force']);
		check('exit code is 0', $exitCode === 0);
		check('reports "No pending migrations found."', str_contains($out, 'No pending migrations found.'));

		// ---------------------------------------------------------------
		// Scenario 2: migrate --force applies pending migrations
		// ---------------------------------------------------------------
		section('migrate --force');
		$tableA = nextTableName();
		writeMigration($migrationsDir, 20260101000001, $tableA);

		[$exitCode, $out] = runCommand($provider, ['--force']);
		check('exit code is 0', $exitCode === 0);
		check('reports success', str_contains($out, 'Migration completed successfully'));
		check('table was created', in_array($tableA, $provider->getDatabaseAdapter()->getTables(), true));

		// ---------------------------------------------------------------
		// Scenario 3: --status shows applied and pending migrations
		// ---------------------------------------------------------------
		section('--status');
		$tableB = nextTableName();
		$classNameB = writeMigration($migrationsDir, 20260101000002, $tableB);

		[$exitCode, $out] = runCommand($provider, ['--status']);
		check('exit code is 0', $exitCode === 0);
		check('shows the applied migration\'s version', str_contains($out, '20260101000001'));
		check('shows the pending migration\'s name', str_contains($out, $classNameB));
		check('pending migration was not applied', !in_array($tableB, $provider->getDatabaseAdapter()->getTables(), true));

		// ---------------------------------------------------------------
		// Scenario 4: --dry-run previews without applying
		// ---------------------------------------------------------------
		section('migrate --dry-run');
		[$exitCode, $out] = runCommand($provider, ['--dry-run']);
		check('exit code is 0', $exitCode === 0);
		check('reports dry-run mode', str_contains($out, 'Dry run mode'));
		check('did not create the table', !in_array($tableB, $provider->getDatabaseAdapter()->getTables(), true));

		// ---------------------------------------------------------------
		// Scenario 5: migrate --target stops at the given version
		// ---------------------------------------------------------------
		section('migrate --target');
		$tableC = nextTableName();
		writeMigration($migrationsDir, 20260101000003, $tableC);

		[$exitCode] = runCommand($provider, ['--force', '--target=20260101000002']);
		check('exit code is 0', $exitCode === 0);
		check('target migration applied', in_array($tableB, $provider->getDatabaseAdapter()->getTables(), true));
		check('later migration left pending', !in_array($tableC, $provider->getDatabaseAdapter()->getTables(), true));

		// ---------------------------------------------------------------
		// Scenario 6: rollback --dry-run previews without reverting
		// ---------------------------------------------------------------
		section('rollback --dry-run');
		[$exitCode, $out] = runCommand($provider, ['--rollback', '--dry-run']);
		check('exit code is 0', $exitCode === 0);
		check('reports dry-run mode', str_contains($out, 'Dry run mode'));
		check('table still exists', in_array($tableB, $provider->getDatabaseAdapter()->getTables(), true));

		// ---------------------------------------------------------------
		// Scenario 7: rollback --force reverts the most recent migration
		// ---------------------------------------------------------------
		section('rollback --force');
		[$exitCode, $out] = runCommand($provider, ['--rollback', '--force']);
		check('exit code is 0', $exitCode === 0);
		check('reports success', str_contains($out, 'Rollback completed successfully'));
		check('table was dropped', !in_array($tableB, $provider->getDatabaseAdapter()->getTables(), true));

		// Clean up the migration that migrate --target left pending.
		[$exitCode] = runCommand($provider, ['--force']);
		check('remaining pending migration applies cleanly', $exitCode === 0);

		// ---------------------------------------------------------------
		// Scenario 8: full CLI chain — make:migration -> hand-edit ->
		// quel:migrate -> --rollback, mirroring ForeignKeyMigrationTest's
		// "prove it end to end, not just each piece in isolation" style.
		// This is the one Phase 7 scenario (objectquel-migrations-
		// implementation-plan.md) that can only run here, not as a
		// PHPUnit test — make:migration needs no EntityManager and could
		// be tested there (see MakeMigrationCommandTest), but chaining it
		// into quel:migrate hits the same EntityManager collision this
		// whole script exists to route around.
		// ---------------------------------------------------------------
		section('make:migration -> hand-edit -> quel:migrate -> --rollback');

		[$exitCode, $out] = runAnyCommand(MakeMigrationCommand::class, $provider, ['ChainSmokeMigration']);
		check('make:migration exit code is 0', $exitCode === 0);
		check('make:migration reports success', str_contains($out, 'Success!'));

		$generatedFiles = glob($migrationsDir . '/*_ChainSmokeMigration.php') ?: [];
		check('exactly one file was generated', count($generatedFiles) === 1);

		$tableD = nextTableName();
		$generatedFile = $generatedFiles[0];
		$content = file_get_contents($generatedFile);
		$content = str_replace(
			"public function up(): void {\n        }",
			"public function up(): void {\n            \$this->query('create {$tableD} (id = integer identity, primary key (id))');\n        }",
			$content
		);
		$content = str_replace(
			"public function down(): void {\n        }",
			"public function down(): void {\n            \$this->query('destroy {$tableD}');\n        }",
			$content
		);
		check('hand-edit actually changed the file', $content !== file_get_contents($generatedFile));
		file_put_contents($generatedFile, $content);

		[$exitCode, $out] = runCommand($provider, ['--force']);
		check('quel:migrate exit code is 0', $exitCode === 0);
		check('quel:migrate reports success', str_contains($out, 'Migration completed successfully'));
		check('hand-written up() actually ran', in_array($tableD, $provider->getDatabaseAdapter()->getTables(), true));

		[$exitCode, $out] = runCommand($provider, ['--rollback', '--force']);
		check('quel:migrate --rollback exit code is 0', $exitCode === 0);
		check('quel:migrate --rollback reports success', str_contains($out, 'Rollback completed successfully'));
		check('hand-written down() actually ran', !in_array($tableD, $provider->getDatabaseAdapter()->getTables(), true));
	} finally {
		// -----------------------------------------------------------------
		// Teardown — runs even if a check above threw.
		// -----------------------------------------------------------------
		$adapter = $provider->getDatabaseAdapter();

		foreach ([...$createdTables, $trackingTable] as $tableName) {
			$adapter->execute("DROP TABLE IF EXISTS `{$tableName}`");
		}

		foreach (glob($migrationsDir . '/*.php') ?: [] as $file) {
			unlink($file);
		}

		rmdir($migrationsDir);
		rmdir($entityPath);
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
