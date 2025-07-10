<?php
	
	declare(strict_types=1);
	
	use Quellabs\Canvas\Kernel;
	use Symfony\Component\HttpFoundation\Request;
	
	require_once __DIR__ . '/../vendor/autoload.php';
	
	$kernel = new Kernel();
	$request = Request::createFromGlobals();
	$response = $kernel->handle($request);
	
	$response->send();