<?php

	// config/shipment_packing.php
	// All dimensions in millimetres, all weights in grams.
	// A .local.php override (config/shipment_packing.local.php) is merged automatically
	// by Canvas's ConfigProvider if present.
	
	return [
		
		// Global weight ceiling applied to every box, regardless of its own max_weight.
		// Set to 0 to rely solely on each box's own max_weight.
		'max_weight_per_box' => 20000,
		
		'boxes' => [
			[
				'reference'    => 'small',
				'width'        => 300,
				'length'       => 200,
				'depth'        => 150,
				'empty_weight' => 150,
				'max_weight'   => 10000,
			],
			[
				'reference'    => 'medium',
				'width'        => 450,
				'length'       => 350,
				'depth'        => 250,
				'empty_weight' => 300,
				'max_weight'   => 20000,
			],
			[
				'reference'    => 'large',
				'width'        => 600,
				'length'       => 400,
				'depth'        => 350,
				'empty_weight' => 500,
				'max_weight'   => 30000,
			],
		],
	
	];