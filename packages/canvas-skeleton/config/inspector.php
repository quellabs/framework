<?php
	
	return [
		// Enable the inspector
		'enabled' => true,
		
		// Show these panels
		'panels'  => [
			\Quellabs\Canvas\Inspector\Panels\RequestPanel::class,
			\Quellabs\Canvas\Inspector\Panels\QueryPanel::class
		]
	];