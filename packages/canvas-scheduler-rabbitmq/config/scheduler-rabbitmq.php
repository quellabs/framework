<?php
	
	return [
		'host'              => '127.0.0.1',
		'port'              => 5672,
		'user'              => 'guest',
		'password'          => 'guest',
		'vhost'             => '/',
		'queue_name'        => 'default',
		'queue_max_jobs'    => 500,
		'queue_timeout'     => 5,
		'exchange_name'     => '',
		'prefetch_count'    => 1,
	];