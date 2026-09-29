<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Quellabs\ObjectQuel\Configuration;
use Quellabs\ObjectQuel\EntityManager;

if (!extension_loaded('pdo_sqlite')) {
	throw new RuntimeException('The SQLite test suite requires pdo_sqlite.');
}

$databaseFile = tempnam(sys_get_temp_dir(), 'objectquel-sqlite-');
if ($databaseFile === false) {
	throw new RuntimeException('Unable to create the SQLite test database.');
}

$connection = new Connection([
	'driver' => Sqlite::class,
	'database' => $databaseFile,
]);
$connection->execute('PRAGMA foreign_keys = ON');
$connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, banned BOOLEAN NOT NULL DEFAULT 0)');
$connection->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(255) NOT NULL, content TEXT NOT NULL DEFAULT \'\', published BOOLEAN NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, test_enum VARCHAR(50), test_json TEXT, user_id INTEGER, deleted_at DATETIME NULL)');
$connection->execute('CREATE TABLE versioned_entities (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR(255) NOT NULL, version INTEGER NOT NULL)');
$connection->execute('CREATE TABLE uuid_versioned_entities (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR(255) NOT NULL, token VARCHAR(36) NOT NULL)');
$connection->execute('CREATE TABLE sti_vehicles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL, vehicle_type VARCHAR(50) NOT NULL)');
$connection->execute('CREATE TABLE default_column_test (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL, priority INTEGER NOT NULL DEFAULT 99)');
$connection->execute('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, label VARCHAR(255) NOT NULL)');
$connection->execute('CREATE TABLE credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, secret VARCHAR(255) NOT NULL, account_id INTEGER UNIQUE REFERENCES accounts(id))');
$relationshipTables = [
	'rel_customers' => '',
	'rel_orders_cascade' => ', customer_id INTEGER',
	'rel_orders_no_cascade' => ', customer_id INTEGER',
	'rel_users' => '',
	'rel_profiles' => ', user_id INTEGER',
	'rel_profiles_unidirectional' => ', user_id INTEGER',
	'rel_profiles_unidirectional_persist' => ', user_id INTEGER',
	'rel_profiles_no_cascade' => ', user_id INTEGER',
	'rel_departments' => '',
	'rel_employees' => ', department_id INTEGER',
	'rel_posts' => '',
	'rel_tags' => '',
	'rel_categories' => '',
	'rel_post_tags' => ', post_id INTEGER, tag_id INTEGER, category_id INTEGER',
	'rel_audit_logs' => ', post_tag_id INTEGER, lazy_post_tag_id INTEGER',
	'rel_friend_users' => '',
	'rel_friendships' => ', user_a_id INTEGER, user_b_id INTEGER',
	'rel_soft_parents' => ', deleted_at DATETIME NULL',
	'rel_plain_child_of_soft_parent' => ', parent_id INTEGER',
	'rel_soft_child_of_soft_parent' => ', parent_id INTEGER, deleted_at DATETIME NULL',
	'rel_plain_parents' => '',
	'rel_soft_child_of_plain_parent' => ', parent_id INTEGER, deleted_at DATETIME NULL',
	'rel_bool_soft_parents' => ', is_deleted BOOLEAN NOT NULL DEFAULT 0',
	'rel_bool_soft_children' => ', parent_id INTEGER',
	'rel_bool_soft_children_own_delete' => ', is_deleted BOOLEAN NOT NULL DEFAULT 0, parent_id INTEGER',
];
foreach ($relationshipTables as $table => $columns) {
	$connection->execute("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT{$columns})");
}

$configuration = new Configuration();
$configuration->setEntityNamespace('App\\Entities');
$configuration->setEntityPath(__DIR__ . '/../src/Entities');
$configuration->setProxyDir(sys_get_temp_dir() . '/objectquel-sqlite-phpunit-proxies');
$configuration->addAdditionalEntityPath(__DIR__ . '/ObjectQuel/Fixtures/RelationshipEntities');
$GLOBALS['test_em'] = new EntityManager($configuration, $connection);
$GLOBALS['test_connection'] = $connection;

register_shutdown_function(static function () use ($connection, $databaseFile): void {
	$connection->getDriver()->disconnect();
	@unlink($databaseFile);
});
