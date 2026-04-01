<?php

return [
	// Your SendCloud API public key.
	// Found in your SendCloud panel under Settings > Integrations > API.
	'public_key' => '',

	// Your SendCloud API secret key.
	// Found alongside the public key in your integration settings.
	'secret_key' => '',

	// Sendcloud Partner ID — identifies this integration in SendCloud's partner analytics.
	// Leave empty if you are not a registered SendCloud partner.
	'partner_id' => '',

	// Webhook signing secret used to verify incoming webhook signatures.
	// Set this in your SendCloud panel under Settings > Integrations > Webhooks.
	// Each webhook endpoint has its own secret.
	'webhook_secret' => '',

	// The URL SendCloud will POST parcel status events to.
	// Must be publicly reachable. Configure this in your SendCloud panel.
	'webhook_url' => 'https://example.com/webhooks/sendcloud',

	// ISO 3166-1 alpha-2 country code of your warehouse / dispatch location.
	// Used to filter available shipping methods by route.
	'from_country' => 'NL',

	// Default sender address fields pre-filled on every parcel.
	// These can be overridden per-request via ShipmentRequest::$extraData if needed.
	'sender_address' => [
		// 'name'         => 'My Webshop',
		// 'company_name' => 'My Webshop B.V.',
		// 'address'      => 'Keizersgracht 123',
		// 'city'         => 'Amsterdam',
		// 'postal_code'  => '1015 CJ',
		// 'country'      => ['iso_2' => 'NL'],
		// 'email'        => 'logistics@example.com',
		// 'phone'        => '+31201234567',
	],
];
