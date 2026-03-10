<?php
	
	return [
		// Required: Directory where Blade template files are stored
		'template_dir'  => dirname(__FILE__) . '/../templates/',
		
		// Directory where Blade stores compiled PHP templates
		'cache_dir'     => dirname(__FILE__) . '/../storage/cache/blade/',
		
		// Enable/disable template caching for better performance
		'caching'       => true,
		
		// Additional template directories with optional namespaces
		'paths'         => [],
		
		// Custom directives (name => callable returning PHP code)
		'directives'    => [],

		// Custom @if-directives (name => callable returning bool)
		// Example: 'admin' => fn() => currentUser()->isAdmin()
		'if_directives' => [],

		// Global variables available in all templates
		'globals'       => [],
	];