<?php
	
	return [
		// Directory where Smarty template files are stored
		'template_dir'   => dirname(__FILE__) . '/../templates/',
		
		// Directory where Smarty stores compiled PHP templates
		'compile_dir'    => dirname(__FILE__) . '/../storage/smarty/compile/',
		
		// Directory where Smarty stores cached template output
		'cache_dir'      => dirname(__FILE__) . '/../storage/smarty/cache/',
		
		// Enable/disable Smarty's debugging console
		'debugging'      => false,
		
		// Enable/disable template caching for better performance
		'caching'        => true,
		
		// Clear the compiled directory when cache is flushed
		'clear_compiled' => true,
		
		// Global variables available in all templates
		'globals'        => [],
	];