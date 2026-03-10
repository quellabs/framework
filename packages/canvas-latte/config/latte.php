<?php
	
	return [
		// Directory where Latte template files are stored
		'template_dir' => dirname(__FILE__) . '/../templates/',
		
		// Directory where Latte stores compiled PHP templates
		'cache_dir'    => dirname(__FILE__) . '/../storage/cache/latte/',
		
		// Enable/disable caching
		'caching'      => true,
		
		// Additional template directories, keyed by namespace (e.g. 'admin' => '/path')
		'paths'        => [],
		
		// Latte\Extension instances to register
		'extensions'   => [],
		
		// Custom functions: used as {functionName()} in templates
		'functions'    => [],
		
		// Custom filters: used as {$value|filterName} in templates
		'filters'      => [],
		
		// Global variables available in all templates
		'globals'      => []
	];