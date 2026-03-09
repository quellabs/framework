<?php
	
	return [
		// The default cache driver to use when none is explicitly specified
		'default' => 'file',
		
		// Available cache driver configurations
		'drivers' => [
			// File-based cache driver configuration
			'file' => [
				// Fully qualified class name of the file cache driver
				'class' => \Quellabs\Canvas\Cache\Drivers\FileCache::class,
			],
			
			// Redis cache driver configuration
			'redis' => [
				// Fully qualified class name of the Redis cache driver
				'class'        => \Quellabs\Canvas\Cache\Drivers\RedisCache::class,
				
				// Redis server host
				'host'         => '127.0.0.1',
				
				// Redis server port
				'port'         => 6379,
				
				// Connection timeout in seconds
				'timeout'      => 2.5,
				
				// Read timeout in seconds
				'read_timeout' => 2.5,
				
				// Redis database index to use
				'database'     => 0,
				
				// Password for Redis authentication (null if not required)
				'password'     => null,
			],
			
			// Memcached cache driver configuration
			'memcached' => [
				// Fully qualified class name of the Memcached cache driver
				'class' => \Quellabs\Canvas\Cache\Drivers\MemcachedCache::class,
				
				// List of Memcached servers: [host, port, weight]
				'servers' => [
					['127.0.0.1', 11211, 100],
				],
				
				// Persistent connection identifier
				'persistent_id' => 'cache_pool',
				
				// Enable value compression
				'compression' => true,
				
				// Minimum data size (in bytes) before compression is applied
				'compression_threshold' => 2000,
			],
		],
	];