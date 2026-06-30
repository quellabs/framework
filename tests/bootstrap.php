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

	// Shared connection for Recommender integration tests.
	// Reuses the same credentials as the ObjectQuel/Canvas suite.
	// Creates the recommender tables if they do not yet exist.
	$GLOBALS['test_connection'] = $connection;
	
	$connection->execute(
		'CREATE TABLE IF NOT EXISTS `vogoo_ratings` (
		    `member_id`  INT UNSIGNED NOT NULL,
		    `product_id` INT UNSIGNED NOT NULL,
		    `category`   INT UNSIGNED NOT NULL DEFAULT 1,
		    `rating`     FLOAT        NOT NULL,
		    `ts`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		    PRIMARY KEY (`member_id`, `product_id`, `category`),
		    INDEX `idx_product` (`product_id`, `category`),
		    INDEX `idx_member`  (`member_id`,  `category`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	');
	
	$connection->execute('
		CREATE TABLE IF NOT EXISTS `vogoo_links` (
		    `item_id1`   INT UNSIGNED NOT NULL,
		    `item_id2`   INT UNSIGNED NOT NULL,
		    `category`   INT UNSIGNED NOT NULL DEFAULT 1,
		    `cnt`        INT          NOT NULL DEFAULT 0,
		    `diff_slope` FLOAT        NOT NULL DEFAULT 0.0,
		    PRIMARY KEY (`item_id1`, `item_id2`, `category`),
		    INDEX `idx_item2` (`item_id2`, `category`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	');
	
	// Test connection
	$GLOBALS['test_connection'] = $connection;