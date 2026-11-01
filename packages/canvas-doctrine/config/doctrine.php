<?php

	return [
		'entity_paths' => [__DIR__ . '/../src/Entities'],
		'entity_namespace' => 'App\\Entities',
		'development_mode' => false,
		'connection' => [
			'driver' => 'pdo_mysql',
			'host' => 'localhost',
			'port' => 3306,
			'dbname' => '',
			'user' => '',
			'password' => '',
			'charset' => 'utf8mb4',
		],
	];
