#!/usr/bin/env php
<?php
	
	/**
	 * check-versions.php
	 *
	 * Compares the current branch against main and reports any packages under
	 * packages/ that have new commits but no version bump in versioning/versions.json.
	 *
	 * Usage:
	 *   php check-versions.php [--base=<branch>]
	 *
	 * Options:
	 *   --base=<branch>   Branch to compare against (default: main)
	 *
	 * Exit codes:
	 *   0  All changed packages have a version bump (or no packages changed)
	 *   1  One or more changed packages are missing a version bump, or a fatal
	 *      error occurred (error message written to stderr)
	 */
	
	// ---------------------------------------------------------------------------
	// Configuration
	// ---------------------------------------------------------------------------
	
	$options = getopt('', ['base:']);
	$baseBranch = $options['base'] ?? 'main';
	$versionsFile = 'versioning/versions.json';
	$packagesDir = 'packages';
	
	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------
	
	/**
	 * Run a git command via proc_open and return stdout on success, null on failure.
	 * Uses an array command to avoid shell quoting issues on Windows.
	 * stderr is drained and discarded to prevent pipe-buffer deadlocks.
	 */
	function gitRun(array $args): ?string {
		$cmd = array_merge(['git'], $args);
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($cmd, $descriptors, $pipes);
		
		if (!is_resource($process)) {
			return null;
		}
		
		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		// Drain stderr so the child process never blocks on a full pipe buffer
		stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$code = proc_close($process);
		
		return $code === 0 ? $stdout : null;
	}
	
	/**
	 * Run a git command and return stdout lines as an array.
	 */
	function gitLines(array $args): array {
		$out = gitRun($args);
		
		if ($out === null) {
			return [];
		}
		
		return array_values(array_filter(array_map('trim', explode("\n", $out))));
	}
	
	/**
	 * Run a git command and return trimmed stdout, or exit with an error.
	 */
	function gitRequired(array $args, string $errorMessage): string {
		$out = gitRun($args);
		
		if ($out === null) {
			fwrite(STDERR, "$errorMessage\n");
			exit(1);
		}
		
		return trim($out);
	}
	
	/**
	 * Compare two semver strings (without 'v' prefix).
	 * Returns true if $a is strictly greater than $b.
	 */
	function semverGt(string $a, string $b): bool {
		return version_compare($a, $b, '>');
	}
	
	/**
	 * Return true if $version matches the expected X.Y.Z[-prerelease] semver
	 * format. Versions without a patch segment (e.g. "1.0") are rejected so
	 * that loose PHP-style version strings do not silently pass comparison.
	 */
	function isValidSemver(string $version): bool {
		return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) === 1;
	}
	
	/**
	 * Resolve a branch name to a git ref, trying local first then origin/.
	 * Returns the resolved ref string, or null if neither exists.
	 */
	function resolveRef(string $branch): ?string {
		foreach ([$branch, "origin/$branch"] as $ref) {
			$out = gitRun(['rev-parse', '--verify', $ref]);
			
			if ($out !== null) {
				return $ref;
			}
		}
		
		return null;
	}
	
	/**
	 * Decode a JSON string and return the result as an array.
	 * Writes a specific error to stderr and exits on failure.
	 */
	function decodeJson(string $json, string $label): array {
		$data = json_decode($json, true);
		
		if (json_last_error() !== JSON_ERROR_NONE) {
			fwrite(STDERR, "Invalid JSON in $label: " . json_last_error_msg() . "\n");
			exit(1);
		}
		
		if (!is_array($data)) {
			fwrite(STDERR, "Expected a JSON object in $label, got a scalar or null.\n");
			exit(1);
		}
		
		return $data;
	}
	
	// ---------------------------------------------------------------------------
	// Sanity checks
	// ---------------------------------------------------------------------------
	
	// Verify we are inside a git repository
	gitRequired(['rev-parse', '--is-inside-work-tree'], 'Not inside a git repository.');
	
	// Resolve base branch — prefer local, fall back to origin/
	$resolvedBase = resolveRef($baseBranch);
	
	if ($resolvedBase === null) {
		fwrite(STDERR, "Branch '$baseBranch' not found locally or as 'origin/$baseBranch'.\n");
		fwrite(STDERR, "Available branches:\n");
		$branches = gitLines(['branch', '-a']);
		
		foreach ($branches as $b) {
			fwrite(STDERR, "  $b\n");
		}
		
		exit(1);
	}
	
	if ($resolvedBase !== $baseBranch) {
		echo "Note: '$baseBranch' not found locally, using '$resolvedBase'.\n";
		$baseBranch = $resolvedBase;
	}
	
	// Resolve the merge base so we only look at commits introduced on this branch
	$mergeBase = gitRequired(
		['merge-base', 'HEAD', $baseBranch],
		"Could not determine merge base between HEAD and '$baseBranch'."
	);
	
	// Read versions.json from the base branch via git show
	$versionsOnBase = gitRun(['show', "$baseBranch:$versionsFile"]);
	
	if ($versionsOnBase === null || trim($versionsOnBase) === '') {
		// List files matching 'version' on the base branch to help find the correct path
		$allFiles = gitLines(['ls-tree', '-r', '--name-only', $baseBranch]);
		$matching = array_filter($allFiles, fn($f) => str_contains($f, 'version'));
		
		fwrite(STDERR, "Could not read '$versionsFile' from '$baseBranch'.\n\n");
		fwrite(STDERR, "Files matching 'version' on '$baseBranch':\n");
		
		if (empty($matching)) {
			fwrite(STDERR, "  (none found)\n");
		} else {
			foreach ($matching as $f) {
				fwrite(STDERR, "  $f\n");
			}
		}
		
		fwrite(STDERR, "\nUpdate \$versionsFile at the top of this script to the correct path.\n");
		exit(1);
	}
	
	$baseVersions = decodeJson($versionsOnBase, "'$versionsFile' from '$baseBranch'");
	
	// Read versions.json from the current working tree
	if (!file_exists($versionsFile)) {
		fwrite(STDERR, "'$versionsFile' not found in working tree.\n");
		exit(1);
	}
	
	$currentJson = file_get_contents($versionsFile);
	
	if ($currentJson === false) {
		fwrite(STDERR, "Failed to read '$versionsFile' from working tree.\n");
		exit(1);
	}
	
	$currentVersions = decodeJson($currentJson, "'$versionsFile' from working tree");
	
	// ---------------------------------------------------------------------------
	// Detect changed packages
	// ---------------------------------------------------------------------------
	
	// Find all files that changed between the merge base and HEAD
	$changedFiles = gitLines(['diff', '--name-only', $mergeBase, 'HEAD']);
	
	// Group changed files by package name. Packages that no longer exist on
	// disk are filtered out later during the report phase, not here.
	$changedPackages = [];
	
	foreach ($changedFiles as $file) {
		// Normalize backslashes (Windows git can output either)
		$file = str_replace('\\', '/', $file);
		
		// Match files under packages/<package-name>/
		if (preg_match('#^' . preg_quote($packagesDir, '#') . '/([^/]+)/#', $file, $m)) {
			$changedPackages[$m[1]] = true;
		}
	}
	
	// Sort for deterministic output order across runs and platforms
	ksort($changedPackages);
	
	// ---------------------------------------------------------------------------
	// Report
	// ---------------------------------------------------------------------------
	
	$currentBranch = gitRequired(['rev-parse', '--abbrev-ref', 'HEAD'], 'Could not determine current branch.');
	
	echo "Branch : $currentBranch\n";
	echo "Base   : $baseBranch (merge base: " . substr($mergeBase, 0, 8) . ")\n";
	echo str_repeat('-', 60) . "\n";
	
	if (empty($changedPackages)) {
		echo "No package changes detected.\n";
		exit(0);
	}
	
	$ok = [];
	$missing = [];
	$unknown = [];
	$deleted = [];
	
	foreach (array_keys($changedPackages) as $package) {
		// Skip packages that were deleted — their diff entries are expected
		if (!is_dir("$packagesDir/$package")) {
			$deleted[] = $package;
			continue;
		}
		
		$baseVersion = $baseVersions[$package] ?? null;
		$currentVersion = $currentVersions[$package] ?? null;
		
		if ($currentVersion === null) {
			// Package has commits but is not listed in versions.json at all
			$unknown[] = $package;
			continue;
		}
		
		// Warn if either version string deviates from X.Y.Z semver format,
		// because version_compare() accepts loose PHP-style strings that may
		// produce surprising results (e.g. "1.0" < "1.0.1" but "1" > "0.9.9").
		// Track warned values per package to avoid repeating the same warning
		// when base and current happen to carry the same invalid string.
		$warnedVersions = [];
		
		foreach ([$baseVersion, $currentVersion] as $v) {
			if ($v !== null && !isValidSemver($v) && !in_array($v, $warnedVersions, true)) {
				echo "⚠  Warning: '$v' for '$package' is not valid X.Y.Z semver — comparison may be unreliable.\n";
				$warnedVersions[] = $v;
			}
		}
		
		if ($baseVersion === null) {
			// New package not previously tracked — any version entry counts as a bump.
			// Note: this relies on the package key being absent from the base versions
			// file, not on filesystem state, so a renamed package could appear here.
			$ok[] = [$package, null, $currentVersion];
			continue;
		}
		
		if (semverGt($currentVersion, $baseVersion)) {
			$ok[] = [$package, $baseVersion, $currentVersion];
		} else {
			$missing[] = [$package, $baseVersion, $currentVersion];
		}
	}
	
	// Packages that were removed from disk — informational only, not an issue
	if (!empty($deleted)) {
		echo "\n🗑  Deleted (skipped):\n";
		
		foreach ($deleted as $package) {
			echo "   $package\n";
		}
	}
	
	// Packages with a version bump — good
	if (!empty($ok)) {
		echo "\n✅ Version bumped:\n";
		
		foreach ($ok as [$package, $from, $to]) {
			$arrow = $from === null ? "(new) → $to" : "$from → $to";
			echo "   $package  $arrow\n";
		}
	}
	
	// Packages with commits but no version bump — needs attention
	if (!empty($missing)) {
		echo "\n⚠️  Changed but NOT bumped:\n";
		
		foreach ($missing as [$package, $from, $to]) {
			$note = $from === $to ? "(still $to)" : "(was $from, still $to)";
			echo "   $package  $note\n";
		}
	}
	
	// Packages not listed in versions.json at all
	if (!empty($unknown)) {
		echo "\n❓ Changed but missing from versions.json:\n";
		
		foreach ($unknown as $package) {
			echo "   $package\n";
		}
	}
	
	echo "\n" . str_repeat('-', 60) . "\n";
	
	$total = count($ok) + count($missing) + count($unknown);
	$issues = count($missing) + count($unknown);
	
	echo "Checked $total package(s). $issues issue(s) found.\n";
	
	exit($issues > 0 ? 1 : 0);