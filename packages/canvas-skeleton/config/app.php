<?php
	
	return [
		// True to put the framework in debug mode; this mainly affects caching
		'debug_mode'               => true,
		
		// Public directory (where CSS, etc. is stored)
		'public_directory'         => 'public',
		
		// Template engine
		'template_engine'          => 'smarty',
		
		// Path to controller classes
		'controller_directory'     => dirname(__FILE__) . '/../src/Controllers',
		
		// Path to error handlers
		'error_handler_directory'  => dirname(__FILE__) . '/../src/Errors',
		
		// Path to aspect-oriented programming files
		'aspect_directory'         => dirname(__FILE__) . '/../src/Aspects',
		
		// Path to tasks (TaskScheduler)
		'task_scheduler_directory' => dirname(__FILE__) . '/../src/Tasks',
		
		// Whether to match routes with trailing slashes
		'match_trailing_slashes'   => false
	];