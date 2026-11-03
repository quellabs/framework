<?php

	// Single database connection, merged over the driver defaults
	// in ServiceProvider::getConnectionDefaults(), plus the migration
	// settings used by the Sculpt eloquent:migrate* commands.
	return [
		'driver'           => 'mysql',
		'host'             => 'localhost',
		'database'         => '',
		'username'         => '',
		'password'         => '',
		'port'             => 3306,
		'charset'          => 'utf8mb4',
		'collation'        => 'utf8mb4_unicode_ci',
		'prefix'           => '',
		'migrations_path'  => __DIR__ . '/../migrations',
		'migrations_table' => 'eloquent_migrations'
	];
