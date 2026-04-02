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
		
		// Operation mode.
		//   'live' — uses api_key / user_id, sends real parcels via api-gw.dhlparcel.nl
		//   'test' — uses api_key_test / user_id_test if set, otherwise falls back to live credentials
		//
		// Note: DHL does provide a dedicated acceptance environment (api-gw-accept.dhlparcel.nl).
		// The driver currently targets production only. To switch base URLs for acceptance
		// testing, override DHLGateway::BASE_URL or extend the gateway class.
		'mode'           => 'live',
		
		// Default parcel type applied to every shipment unless overridden via ShipmentRequest::$extraData.
		// Available types (from the DHL ParcelTypes endpoint):
		//   SMALL  — up to 2 kg,  max 38 × 26 × 10 cm
		//   MEDIUM — up to 10 kg, max 58 × 38 × 37 cm
		//   LARGE  — up to 20 kg, max 100 × 50 × 50 cm
		//   XL     — up to 31.5 kg
		// Always verify against the capabilities endpoint for your destination country,
		// as available types vary per route.
		'parcel_type'    => 'MEDIUM',
		
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
