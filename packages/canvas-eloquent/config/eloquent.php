<?php

	return [

		// Name of the connection used when none is specified
		'default'     => 'default',

		// Named database connections, keyed by connection name.
		// Each connection is merged over the driver defaults in ServiceProvider::getConnectionDefaults().
		'connections' => [
			'default' => [
				'driver'    => 'mysql',
				'host'      => 'localhost',
				'database'  => '',
				'username'  => '',
				'password'  => '',
				'port'      => 3306,
				'charset'   => 'utf8mb4',
				'collation' => 'utf8mb4_unicode_ci',
				'prefix'    => '',
			],
		],

	];
