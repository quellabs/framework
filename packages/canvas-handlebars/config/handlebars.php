<?php
	
	return [
		// Directory where Handlebars template files (.hbs) are stored
		'template_dir' => dirname(__FILE__) . '/../templates/',
		
		// Directory where LightnCandy stores compiled PHP renderers.
		// These are PHP files (not output cache) and are reused across requests.
		'compile_dir'  => dirname(__FILE__) . '/../storage/handlebars/compile/',
		
		// Strict mode: throw an exception on missing variables rather than
		// silently rendering an empty string. Useful during development.
		'strict_mode'  => false,
		
		// Standalone mode: compiled closures embed all helpers inline and have
		// no runtime dependency on LightnCandy. Slightly faster at render time,
		// but helpers are baked in at compile time — changes require cache clear.
		'standalone'   => false,
		
		// Global variables available in all templates
		'globals' => [],
		
		// Named Handlebars helpers available in all templates.
		// Format: 'helper_name' => callable
		// Example:
		// 'helpers' => [
		//     'uppercase' => fn($str) => strtoupper($str),
		//     'formatDate' => fn($ts) => date('Y-m-d', $ts),
		// ],
		'helpers' => [],
		
		// Named partial templates (inline strings, not files).
		// Loaded from files at runtime via {{> partialName}} is handled automatically
		// when the partial shares a filename in template_dir. Use this for
		// programmatically registered partials.
		// Format: 'partial_name' => '<p>{{content}}</p>'
		'partials' => [],
	];