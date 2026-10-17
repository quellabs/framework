<?php
	
	// Base source directory relative to config folder
	$srcDir = dirname(__DIR__) . '/src';
	
	return [
		// True to put the framework in debug mode; this mainly affects caching
		'debug_mode'              => true,
		
		// Template engine
		'public_directory'        => 'public',
		
		// Template engine
		'template_engine'         => 'smarty',
		
		// Path to controller classes
		'controller_directory'    => $srcDir . DIRECTORY_SEPARATOR . 'Controllers',
		
		// Path to error handlers
		'error_handler_directory' => $srcDir . DIRECTORY_SEPARATOR . 'Errors',
		
		// Path to aspect-oriented programming files
		'aspect_directory'        => $srcDir . DIRECTORY_SEPARATOR . 'Aspects',
		
		// Path to aspect-oriented programming files
		'entity_path'             => $srcDir . DIRECTORY_SEPARATOR . 'Entities',
		
		// Path to signal listeners
		'signal_listeners_path'   => $srcDir . DIRECTORY_SEPARATOR . 'Listeners',
		
		// Whether to match routes with trailing slashes
		'match_trailing_slashes'  => false,

		// Class implementing SecurityHeaderPolicyInterface, applied by both
		// SecurityHeadersAspect (unless a route overrides it via policyClass)
		// and DefaultErrorHandler. Set to null to disable the default entirely.
		// Canvas ships three tiers: SecurityHeaderPolicy (basic), MediumSecurityHeaderPolicy,
		// and StrictSecurityHeaderPolicy - all in the Quellabs\Canvas\Security namespace.
		'security_header_policy'  => \Quellabs\Canvas\Security\SecurityHeaderPolicy::class
	];