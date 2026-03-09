<?php
	// test-autoload.php in your project root
	
	require_once __DIR__ . '/vendor/autoload.php';
	
	echo "Testing autoload...\n\n";

// Test 1: Does the namespace exist in autoload?
	$loader = require __DIR__ . '/vendor/autoload.php';
	$psr4 = $loader->getPrefixesPsr4();
	
	echo "PSR-4 mappings for CanvasDatabase:\n";
	if (isset($psr4['Quellabs\\CanvasDatabase\\'])) {
		print_r($psr4['Quellabs\\CanvasDatabase\\']);
	} else {
		echo "NOT FOUND in autoload!\n";
	}
	
	echo "\n";

// Test 2: Can we manually require the file?
	echo "Manual require test:\n";
	$path = __DIR__ . '/packages/canvas-database/src/Discovery/ServiceProvider.php';
	echo "File exists: " . (file_exists($path) ? 'YES' : 'NO') . "\n";
	
	if (file_exists($path)) {
		try {
			require_once $path;
			echo "Manual require: SUCCESS\n";
		} catch (Throwable $e) {
			echo "Manual require FAILED: " . $e->getMessage() . "\n";
		}
	}
	
	echo "\n";

// Test 3: Can we autoload it?
	echo "Autoload test:\n";
	if (class_exists('Quellabs\\CanvasDatabase\\Discovery\\ServiceProvider')) {
		echo "Class autoloaded: SUCCESS\n";
		$reflect = new ReflectionClass('Quellabs\\CanvasDatabase\\Discovery\\ServiceProvider');
		echo "File location: " . $reflect->getFileName() . "\n";
	} else {
		echo "Class autoloaded: FAILED\n";
	}