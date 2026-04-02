<?php
	
	return [
		// Your MyParcel API key.
		// Found in your MyParcel panel under Settings > Shop settings > API key.
		// For BE (sendmyparcel.be): found in the same location within your bpost panel.
		'api_key'        => '',
		
		// Optional test API key.
		// When test_mode is true, this key is used instead of api_key.
		// If left empty, api_key is used in both modes.
		'api_key_test'   => '',
		
		// API region. Determines which endpoint is used:
		//   'nl' → https://api.myparcel.nl  (Netherlands, PostNL and others)
		//   'be' → https://api.sendmyparcel.be  (Belgium, bpost and others)
		'region'         => 'nl',
		
		// Test mode.
		// When true, api_key_test is used if set, otherwise falls back to api_key.
		// Note: MyParcel does not have a dedicated sandbox environment. Test shipments
		// created while in test mode can be cancelled manually from the panel before carrier pickup.
		'test_mode'      => false,
		
		// Default package type applied to every shipment unless overridden via ShipmentRequest::$extraData.
		//   1 = Package (standaard pakket)
		//   2 = Mailbox package (brievenbuspakje)
		//   3 = Letter
		//   4 = Digital stamp (ongefrankeerde brief)
		'package_type'   => 1,
		
		// URL path for the MyParcel webhook endpoint, used by MyParcelController::handleWebhook().
		// Register this URL in your MyParcel panel under Settings > Webhooks.
		// MyParcel does not sign webhook requests — restrict by IP or use a non-guessable path.
		'webhook_url'    => '/webhooks/myparcel',
		
		// URL path for the manual status refresh endpoint, used by MyParcelController::handleRefresh().
		// Override to place the endpoint behind a non-guessable path.
		'refresh_url'    => '/shipments/myparcel/refresh/{shipmentId}',
		
		// Default sender address fields pre-filled on every parcel.
		// These are used when the shipment payload requires a sender address.
		// Override per-request via ShipmentRequest::$extraData if needed.
		'sender_address' => [
			'company'     => 'My Webshop B.V.',
			'person'      => 'Logistics Dept',
			'street'      => 'Keizersgracht',
			'number'      => '123',
			'postal_code' => '1015CJ',
			'city'        => 'Amsterdam',
			'cc'          => 'NL',
			'email'       => 'logistics@example.com',
			'phone'       => '+31201234567',
		],
	];