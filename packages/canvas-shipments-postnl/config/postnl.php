<?php

return [
	// Your PostNL API key.
	// Obtain this from the PostNL Developer Portal: https://developer.postnl.nl/
	'api_key' => '',

	// Optional sandbox API key.
	// When mode is 'sandbox', this key is used instead of api_key.
	// If left empty, api_key is used in both modes.
	'api_key_test' => '',

	// Operation mode.
	//   'live'    — uses api_key, sends real parcels via https://api.postnl.nl
	//   'sandbox' — uses api_key_test (or api_key) via https://api-sandbox.postnl.nl
	'mode' => 'live',

	// Your PostNL customer code. Found in your PostNL contract.
	// Example: 'DEVC'
	'customer_code' => '',

	// Your PostNL customer number. Found in your PostNL contract.
	// Example: '11223344'
	'customer_number' => '',

	// Collection location code. Provided by PostNL with your account.
	// Required for shipment creation. Example: '1234'
	'collection_location' => '',

	// HMAC-SHA256 secret for verifying incoming webhook signatures.
	// Configure the same value in your PostNL Developer Portal webhook subscription.
	// Leave empty to disable signature verification (not recommended in production).
	'webhook_secret' => '',

	// Default sender address fields pre-filled on every parcel.
	// Override per-request via ShipmentRequest::$extraData if needed.
	'sender_address' => [
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
