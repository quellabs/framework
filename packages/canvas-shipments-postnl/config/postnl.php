<?php
	
	return [
		// Your PostNL API key.
		// Obtain this from the PostNL Developer Portal: https://developer.postnl.nl/
		'api_key'             => '',
		
		// Optional sandbox API key.
		// When test_mode is true, this key is used instead of api_key.
		// If left empty, api_key is used in both modes.
		'api_key_test'        => '',
		
		// Set to true to use the PostNL sandbox environment (https://api-sandbox.postnl.nl).
		// When true, api_key_test is used if set, otherwise api_key is used as fallback.
		// Set to false for live production traffic.
		'test_mode'           => false,
		
		// Your PostNL customer code. Found in your PostNL contract.
		// Example: 'DEVC'
		'customer_code'       => '',
		
		// Your PostNL customer number. Found in your PostNL contract.
		// Example: '11223344'
		'customer_number'     => '',
		
		// Collection location code. Provided by PostNL with your account.
		// Required for shipment creation. Example: '1234'
		'collection_location' => '',
		
		// Delivery timeframe options to request when calling getDeliveryOptions().
		// Only include options that are enabled on your PostNL contract.
		// Requesting an option not covered by your contract may cause the API to reject
		// the call or return no results for that option type.
		//
		// Available values: 'Daytime', 'Morning', 'Evening', 'Sunday'
		// Morning, Evening, and Sunday carry an additional PostNL surcharge and require
		// explicit activation by your PostNL account manager.
		'delivery_options'    => ['Daytime'],
		
		// Configure the same value in your PostNL Developer Portal webhook subscription.
		// Leave empty to disable signature verification (not recommended in production).
		'webhook_secret'      => '',
		
		// Default sender address fields pre-filled on every parcel.
		// Override per-request via ShipmentRequest::$extraData if needed.
		'sender_address'      => [
			// 'company'     => 'My Webshop B.V.',
			// 'street'      => 'Keizersgracht',
			// 'houseNumber' => '123',
			// 'postalCode'  => '1015CJ',
			// 'city'        => 'Amsterdam',
			// 'country'     => 'NL',
			// 'email'       => 'logistics@example.com',
			// 'phone'       => '+31201234567',
		],
	];