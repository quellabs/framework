<?php

	// Single database connection, merged over the driver defaults
	// in ServiceProvider::getConnectionDefaults().
	return [
		'driver'    => 'mysql',
		'host'      => 'localhost',
		'database'  => '',
		'username'  => '',
		'password'  => '',
		'port'      => 3306,
		'charset'   => 'utf8mb4',
		'collation' => 'utf8mb4_unicode_ci',
		'prefix'    => '',
	];
