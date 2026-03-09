<?php

	// Enforce strict type checking for this file
	declare(strict_types=1);

	// Import the application kernel (core of the framework)
	use Quellabs\Canvas\Kernel;

	// Import Symfony HTTP request abstraction
	use Symfony\Component\HttpFoundation\Request;

	// Load Composer's autoloader to enable class autoloading
	require_once __DIR__ . '/../vendor/autoload.php';

	// Instantiate the application kernel (bootstraps the framework)
	$kernel = new Kernel();

	// Create a Request object from PHP superglobals ($_GET, $_POST, $_SERVER, etc.)
	$request = Request::createFromGlobals();

	// Let the kernel handle the request and produce a Response object
	$response = $kernel->handle($request);

	// Send HTTP headers and content to the client
	$response->send();