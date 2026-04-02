<?php
	
	return [
		// Your DHL API user ID.
		// Generated in the My DHL Parcel portal under Settings > API Keys.
		// This is a UUID, e.g. 'f36abdfa-9894-4d1f-bb6e-e471a953c04d'.
		// Note: generating a new key invalidates the previous one.
		'user_id'        => '',
		
		// Your DHL API key (paired with user_id above).
		// This is also a UUID, e.g. '1c8545e1-767f-4531-9c6b-5f5f80737562'.
		'api_key'        => '',
		
		// Optional test API key (user_id + key pair for the DHL acceptance environment).
		// When mode is 'test', these are used instead of the production credentials.
		// The DHL acceptance environment base URL is: https://api-gw-accept.dhlparcel.nl
		'user_id_test'   => '',
		'api_key_test'   => '',
		
		// Your DHL account number, as provided by DHL sales.
		// Required on every shipment payload. Multiple account numbers may be present
		// on a single API key; specify the one to charge for each shipment here.
		'account_id'     => '',
		
		// Test mode.
		// When true, the driver targets the DHL acceptance environment (api-gw-accept.dhlparcel.nl)
		// and uses user_id_test / api_key_test instead of the live credentials.
		// Falls back to live credentials if test credentials are not configured.
		'test_mode'      => false,
		
		// Sender address — required on every DHL shipment.
		// DHL prints the shipper address on the label and uses it for returns.
		// If left empty, shipment creation will throw a ShipmentCreationException.
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