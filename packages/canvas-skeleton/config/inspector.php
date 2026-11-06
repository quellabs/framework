<?php
	
	return [
		// Shows the debug panel (POST data, cookies, SQL with bound parameter values)
		// on every HTML response. Enable only for local development - never in production.
		'enabled' => false,
		
		// Optional panels that are loaded besides the built in Request panel
		'panels' => [
			\Quellabs\Canvas\Inspector\Panels\QueryPanel::class,
			\Quellabs\Canvas\Inspector\Panels\WakaPACPanel::class
		]
	];