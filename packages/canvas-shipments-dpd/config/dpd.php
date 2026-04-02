<?php
	
	return [
		// Your DPD Delis ID, provided by DPD when your account is set up.
		// Format: 6–10 character string, e.g. 'KD12345'.
		'delis_id'      => '',
		
		// Your DPD password, provided alongside your Delis ID.
		'password'      => '',
		
		// Your DPD sending depot code, returned in the login response and shown in
		// your DPD contract. Used in every shipment request as <sendingDepot>.
		// Example: '0522'
		'sending_depot' => '',
		
		// Test mode.
		// When true, the driver targets the DPD stage environment
		// (shipperadmintest.dpd.nl) using the staging credentials below.
		// Stage and live environments require separate credentials from DPD.
		// Labels generated in stage are not valid and must not be used for real shipments.
		'test_mode' => false,
		
		// Staging credentials — only used when test_mode is true.
		// Provided separately by DPD alongside your live credentials.
		'test_delis_id'      => '',
		'test_password'      => '',
		'test_sending_depot' => '',
		
		// Directory where label PDF files are cached between requests.
		// A relative path is resolved from the project root (via ComposerUtils::getProjectRoot()).
		// The directory is created automatically on first use.
		'cache_path'           => 'storage/dpd/labels',
		
		// Number of days to retain cached label files before they are purged.
		// Cleanup runs opportunistically on each getLabelUrl() call.
		// Set to 0 to keep label files indefinitely (no expiry, no cleanup).
		'label_cache_ttl_days' => 30,
		
		// URL path for the manual status refresh endpoint, used by DPDController::handleRefresh().
		// Override this to place the endpoint behind a non-guessable path.
		// DPD has no webhook support — polling via this endpoint is the only way to refresh status.
		'refresh_url' => '/shipments/dpd/refresh/{shipmentId}',
		
		// Sender address — required on every DPD shipment.
		// DPD prints the sender address on the label and uses it for returns.
		// If left empty, shipment creation will throw a ShipmentCreationException.
		'sender_address' => [
			'company'     => 'My Webshop B.V.',
			'name'        => 'Logistics Dept',
			'street'      => 'Keizersgracht',
			'number'      => '123',
			'postal_code' => '1015CJ',
			'city'        => 'Amsterdam',
			'cc'          => 'NL',
			'email'       => 'logistics@example.com',
			'phone'       => '+31201234567',
		],
	];