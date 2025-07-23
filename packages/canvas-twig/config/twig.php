<?php
	
	return [
		// Required: Directory where Twig template files are stored
		'template_dir'     => dirname(__FILE__) . '/../templates/',
		
		// Directory where Twig stores cached compiled templates
		'cache_dir'        => dirname(__FILE__) . '/../storage/cache/twig/',
		
		// Enable/disable template caching for better performance
		'caching'          => true,
		
		// Enable/disable Twig's debug mode
		'debugging'        => false,
		
		// Auto-reload templates when they change (useful in development)
		'auto_reload'      => true,
		
		// Throw errors when undefined variables are accessed
		'strict_variables' => false,
		
		// Character set for template output
		'charset'          => 'UTF-8',
		
		// Enable/disable automatic HTML escaping
		'autoescape'       => 'html',
		
		// Additional template directories with optional namespaces
		'paths'            => [
			//'admin' => '/path/to/admin/templates',  // Namespaced path
			//'/path/to/shared/templates'             // Non-namespaced path
		],
		
		// Custom Twig extensions to load
		'extensions'       => [
			//'App\\Twig\\MyCustomExtension'
		],
		
		// Custom functions available in templates
		'functions'        => [
			//'asset' => 'App\\Helpers\\AssetHelper::url',
			//'route' => 'App\\Helpers\\RouteHelper::generate'
		],
		
		// Custom filters available in templates
		'filters'          => [
			//'money'    => 'App\\Helpers\\FormatHelper::money',
			//'truncate' => 'App\\Helpers\\StringHelper::truncate'
		],
		
		// Global variables available in all templates
		'globals'          => [
			'site_name'   => 'My Website',
			'version'     => '1.0.0',
			'environment' => 'production'
		]
	];