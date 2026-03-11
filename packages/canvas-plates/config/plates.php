<?php
	
	return [
		// Required: Directory where Plates template files are stored
		'template_dir' => dirname(__FILE__) . '/../templates/',
		
		// File extension used for template files
		'extension'    => 'php',
		
		// Additional template directories with optional namespaces (Plates "folders")
		'paths'        => [],
		
		// Custom functions available in templates as $this->functionName(...)
		'functions'    => [],
		
		// Global variables available in all templates
		'globals'      => []
	];