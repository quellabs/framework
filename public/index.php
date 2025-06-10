<?php
	
	declare(strict_types=1);
	
	use Quellabs\Canvas\Kernel;
	use Symfony\Component\HttpFoundation\Request;
	
	require_once __DIR__ . '/../vendor/autoload.php';
	
	if (file_exists(__DIR__ . '/../config/app.php')) {
		$options = require __DIR__ . '/../config/app.php';
	} else {
		$options = [];
	}
	
	$kernel = new Kernel($options);
	$request = Request::createFromGlobals();
	$response = $kernel->handle($request);
	
	$response->send();