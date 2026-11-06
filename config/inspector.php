<?php
	
	return [
		// Shows the debug panel (POST data, cookies, SQL with bound parameter values)
		// on every HTML response. Never enable this in production.
		'enabled' => false,
		'panels' => [
			\Quellabs\Canvas\Inspector\Panels\QueryPanel::class,
			\Quellabs\Canvas\Inspector\Panels\WakaPACPanel::class
		]
	];