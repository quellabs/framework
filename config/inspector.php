<?php
	
	return [
		// True to put the framework in debug mode; this mainly affects caching
		'enabled' => true,
		'panels' => [
			\Quellabs\Canvas\Inspector\Panels\QueryPanel::class,
			\Quellabs\Canvas\Inspector\Panels\WakaPACPanel::class
		]
	];