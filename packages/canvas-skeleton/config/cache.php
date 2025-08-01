<?php
	
	return [
		'default' => 'file',
		'drivers' => [
			'file'  => [
				'class' => \Quellabs\Cache\FileCache::class,
			],
			'redis' => [
				'class'        => \Quellabs\Cache\RedisCache::class,
				'host'         => '127.0.0.1',
				'port'         => 6379,
				'timeout'      => 2.5,
				'read_timeout' => 2.5,
				'database'     => 0,
				'password'     => null,
			]
		],
	];