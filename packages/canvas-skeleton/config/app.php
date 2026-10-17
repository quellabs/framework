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

		// Class implementing SecurityHeaderPolicyInterface, applied by both
		// SecurityHeadersAspect (unless a route overrides it via policyClass)
		// and DefaultErrorHandler. Set to null to disable the default entirely.
		// Canvas ships three tiers: SecurityHeaderPolicy (basic), MediumSecurityHeaderPolicy,
		// and StrictSecurityHeaderPolicy - all in the Quellabs\Canvas\Security namespace.
		'security_header_policy'  => \Quellabs\Canvas\Security\SecurityHeaderPolicy::class
	];