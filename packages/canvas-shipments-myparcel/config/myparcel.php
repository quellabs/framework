<?php

return [
	// Your MyParcel API key.
	// Found in your MyParcel panel under Settings > Shop settings > API key.
	// For BE (sendmyparcel.be): found in the same location within your bpost panel.
	'api_key' => '',

	// Optional test API key.
	// When mode is 'test', this key is used instead of api_key.
	// If left empty, api_key is used in both modes.
	'api_key_test' => '',

	// API region. Determines which endpoint is used:
	//   'nl' → https://api.myparcel.nl  (Netherlands, PostNL and others)
	//   'be' → https://api.sendmyparcel.be  (Belgium, bpost and others)
	'region' => 'nl',

	// Operation mode.
	//   'live' — uses api_key, sends real parcels
	//   'test' — uses api_key_test if set, otherwise falls back to api_key
	// Note: MyParcel does not have a dedicated sandbox environment. Test shipments
	// created in live mode can be cancelled manually from the panel before carrier pickup.
	'mode' => 'live',

	// Default package type applied to every shipment unless overridden via ShipmentRequest::$extraData.
	//   1 = Package (standaard pakket)
	//   2 = Mailbox package (brievenbuspakje)
	//   3 = Letter
	//   4 = Digital stamp (ongefrankeerde brief)
	'package_type' => 1,

	// Default sender address fields pre-filled on every parcel.
	// These are used when the shipment payload requires a sender address.
	// Override per-request via ShipmentRequest::$extraData if needed.
	'sender_address' => [
		// 'company'       => 'My Webshop B.V.',
		// 'person'        => 'Logistics Dept',
		// 'street'        => 'Keizersgracht',
		// 'number'        => '123',
		// 'postal_code'   => '1015CJ',
		// 'city'          => 'Amsterdam',
		// 'cc'            => 'NL',
		// 'email'         => 'logistics@example.com',
		// 'phone'         => '+31201234567',
	],
];
