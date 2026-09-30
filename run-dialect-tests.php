#!/usr/bin/env php
<?php

	/**
	 * run-dialect-tests.php
	 *
	 * Discovers every phpunit*.xml config file in the repo root and runs each
	 * through vendor/bin/phpunit, one process per file. Each config wires its
	 * own bootstrap and <testsuites> block (see phpunit.xml, phpunit.postgres.xml,
	 * phpunit.sqlite.xml, phpunit.sqlserver.xml), so there is no hardcoded list
	 * of engines to keep in sync here — a new phpunit.<engine>.xml dropped in
	 * the repo root is picked up automatically the next time this runs.
	 *
	 * Runs are sequential, not parallel: phpunit.xml's own MySQL bootstrap uses
	 * a single shared, non-isolated database (unlike the Postgres/SQLite
	 * bootstraps, which each get their own schema/temp file per process), so
	 * two configs running at once could interleave writes to it. Sequential
	 * output is also far easier to read than interleaved progress dots from
	 * several phpunit processes at once.
	 *
	 * Usage:
	 *   php run-dialect-tests.php
	 *
	 * Exit codes:
	 *   0  every discovered config passed
	 *   1  one or more configs failed, or no config files / phpunit binary was found
	 */

	$root = __DIR__;
	$configs = glob($root . '/phpunit*.xml');
	sort($configs);

	if (empty($configs)) {
		fwrite(STDERR, "No phpunit*.xml config files found in $root.\n");
		exit(1);
	}

	$phpunitBin = $root . '/vendor/bin/phpunit';

	if (!is_file($phpunitBin)) {
		fwrite(STDERR, "phpunit not found at $phpunitBin — run composer install first.\n");
		exit(1);
	}

	$results = [];

	foreach ($configs as $config) {
		$label = basename($config);

		echo str_repeat('=', 70) . "\n";
		echo "Running: $label\n";
		echo str_repeat('=', 70) . "\n";

		// Array command + PHP_BINARY avoids shell quoting/extension differences
		// (.bat vs no extension) between Windows and Unix — same as check-versions.php's
		// own proc_open usage. Streams straight to this process's own STDOUT/STDERR
		// so progress dots show live instead of being buffered until the run ends.
		$process = proc_open(
			[PHP_BINARY, $phpunitBin, '-c', $config],
			[1 => STDOUT, 2 => STDERR],
			$pipes,
			$root
		);

		if (!is_resource($process)) {
			fwrite(STDERR, "Failed to start phpunit for $label.\n");
			$results[$label] = false;
			continue;
		}

		$results[$label] = proc_close($process) === 0;
		echo "\n";
	}

	echo str_repeat('-', 70) . "\n";
	echo "Summary:\n";

	$failed = 0;

	foreach ($results as $label => $passed) {
		echo '  ' . ($passed ? '✅' : '❌') . " $label\n";

		if (!$passed) {
			$failed++;
		}
	}

	echo str_repeat('-', 70) . "\n";
	echo count($results) . ' config(s) run, ' . $failed . " failed.\n";

	exit($failed > 0 ? 1 : 0);
