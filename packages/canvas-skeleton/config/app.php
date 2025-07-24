<?php
	
	// Base source directory relative to config folder
	$srcDir = dirname(__DIR__) . '/src';
	
	return [
		// True to put the framework in debug mode; this mainly affects caching
		'debug_mode'             => true,
		
		// Path to controller classes
		'controller_directory'   => $srcDir . '/Controllers',
		
		// Path to aspect-oriented programming files
		'aspect_directory'       => $srcDir . '/Aspects',
		
		// Whether to match routes with trailing slashes
		'match_trailing_slashes' => false
	];