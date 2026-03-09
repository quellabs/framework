<?php
	
	return [
		// Absolute path to the directory containing Smarty template files
		'template_dir'   => dirname(__FILE__) . '/../templates/',
		
		// Directory where Smarty stores compiled PHP versions of templates
		'compile_dir'    => dirname(__FILE__) . '/../storage/smarty/compile/',
		
		// Directory used by Smarty to store cached template output
		'cache_dir'      => dirname(__FILE__) . '/../storage/smarty/cache/',
		
		// Enables Smarty debugging console when set to true
		'debugging'      => false,
		
		// Enables template output caching when set to true
		'caching'        => false,
		
		// Clears compiled templates on each request (useful during development)
		'clear_compiled' => true,
	];
