<?php
	
	require_once __DIR__ . '/../vendor/autoload.php';
	
	use Cake\Database\Connection;
	use Cake\Database\Driver\Mysql;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\EntityManager;
	
	$connection = new Connection([
		'driver'   => Mysql::class,
		'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
		'port'     => getenv('TEST_DB_PORT') ?: 3306,
		'username' => getenv('TEST_DB_USER') ?: 'root',
		'password' => getenv('TEST_DB_PASS') ?: '',
		'database' => getenv('TEST_DB_NAME') ?: 'canvas_blog',
	]);
	
	// Proxy directory — runtime-generated proxies are written here and reused
	// across tests, avoiding repeated eval() calls and the uniqid() collision risk.
	$proxyDir = __DIR__ . '/../storage/unit_test_proxies';
	
	if (!is_dir($proxyDir)) {
		mkdir($proxyDir, 0755, true);
	}
	
	$config = new Configuration();
	$config->setEntityNamespace('App\\Entities');
	$config->setEntityPath(__DIR__ . '/../src/Entities');
	$config->setProxyDir($proxyDir);
	
	// Single EntityManager for the entire test suite. Creating one per test class
	// causes SignalHub to throw on duplicate signal registration.
	$GLOBALS['test_em'] = new EntityManager($config, $connection);