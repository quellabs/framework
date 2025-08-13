<?php
	
	return [
		'enabled' => true,
		
		'panels' => [
			\Quellabs\Canvas\Inspector\Panels\RequestPanel::class,
			\Quellabs\Canvas\Inspector\Panels\QueryPanel::class,
			\App\Inspector\GeoLocationPanel::class,
		]
	];