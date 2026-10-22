<?php
	
	return [
		// True to put the framework in debug mode; this mainly affects caching
		'debug_mode'              => true,
		
		// Public directory (where CSS, etc. is stored)
		'public_directory'        => 'public',
		
		// Template engine
		'template_engine'         => 'smarty',
		
		// Path to controller classes
		'controller_directory'    => dirname(__FILE__) . '/../src/Controllers',
		
		// Path to error handlers
		'error_handler_directory' => dirname(__FILE__) . '/../src/Errors',
		
		// Path to aspect-oriented programming files
		'aspect_directory'        => dirname(__FILE__) . '/../src/Aspects',
		
		// Path to tasks (Scheduler)
		'scheduler_directory'     => dirname(__FILE__) . '/../src/Tasks',
		
		// Default file for Monolog logging
		'monolog_file'            => dirname(__FILE__) . '/storage/logs/canvas.log',
		
		// Whether to match routes with trailing slashes
		'match_trailing_slashes'  => false,
		
		// Default SecurityHeaderPolicyInterface implementation(s) used by SecurityHeadersAspect
		// (unless overridden per route) and DefaultErrorHandler. A single class behaves as
		// below; an ordered list applies each to the same response (e.g. add
		// CorsSecurityHeaderPolicy::class to opt into CORS - see config/cors.php via
		// `php sculpt cors:init`). NullSecurityHeaderPolicy::class opts out of headers
		// entirely; the key itself must not be null.
		// Built-in policies: Basic, Medium, Strict, Cors, Null (Quellabs\Canvas\Security\Policies).
		'security_header_policy'  => \Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy::class
	];