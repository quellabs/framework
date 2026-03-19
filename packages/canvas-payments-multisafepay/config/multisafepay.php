<?php
	
	return [
		// Set to true to use the MultiSafepay test environment, false for production.
		// Test and live use entirely separate base URLs — this is not a flag on one endpoint.
		// Test: https://testapi.multisafepay.com/v1
		// Live: https://api.multisafepay.com/v1
		'test_mode' => true,
		
		// Your MultiSafepay API key.
		// Found in your MultiSafepay dashboard under Settings > API keys.
		// Use a test site API key when test_mode is true.
		// @see https://docs.multisafepay.com/docs/sites#generate-an-api-key
		'api_key' => '',
		
		// Where to redirect the shopper after a completed or pending payment.
		// MSP appends ?transactionid=<order_id> to this URL.
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the shopper after a cancelled payment.
		// MSP appends ?transactionid=<order_id> to this URL.
		'cancel_return_url' => 'https://example.com/order/cancelled',
		
		// The URL MultiSafepay will POST webhook notifications to.
		// Must be publicly reachable. Configure this per site in your MSP dashboard
		// under Settings > Sites > [your site] > Notification URL, or pass it per-order
		// in payment_options.notification_url (the per-order value takes precedence).
		// @see https://docs.multisafepay.com/docs/notification-url
		'notification_url' => 'https://example.com/webhooks/multisafepay',
		
		// Default country code (ISO 3166-1 alpha-2) used when calling getPaymentOptions()
		// without a specific transaction context.
		'default_country' => 'NL',
		
		// Default currency (ISO 4217) used for payment option queries.
		// For NL merchants this should almost always be 'EUR'.
		'default_currency' => 'EUR',
		
		// Default locale passed to MSP for the hosted payment page language and formatting.
		// Format: <language>_<COUNTRY>, e.g. 'nl_NL', 'en_US', 'de_DE'.
		// @see https://docs.multisafepay.com/docs/localization
		'default_locale' => 'nl_NL',
	];