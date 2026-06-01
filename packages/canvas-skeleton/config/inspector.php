<?php
	
	return [
		// Show the debug panel
		'enabled' => true,
		
		// Optional panels that are loaded besides the built in Request panel
		'panels' => [
			\Quellabs\Canvas\Inspector\Panels\QueryPanel::class,
			\Quellabs\Canvas\Inspector\Panels\WakaPACPanel::class
		]
	];