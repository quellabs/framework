<?php
	
	use Quellabs\Canvas\Kernel;
	use Symfony\Component\HttpFoundation\Request;
	
	//error_reporting(E_ALL);
	//ini_set('display_errors', 1);
	
	include_once(__DIR__ . "/../vendor/autoload.php");

	$kernel = new Kernel();
	$request = Request::createFromGlobals();
	$response = $kernel->handle($request);
	$response->send();