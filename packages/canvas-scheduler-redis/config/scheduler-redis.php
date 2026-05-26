<?php
	
	return [
		'scheme'         => 'tcp',
		'host'           => '127.0.0.1',
		'port'           => 6379,
		'queue_name'     => 'default',
		'queue_prefix'   => 'canvas',
		'queue_max_jobs' => 500,
		'queue_timeout'  => 5,
	];