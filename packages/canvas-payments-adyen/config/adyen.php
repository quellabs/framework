<?php
	
	return [
		// Set to true to use the Adyen test environment, false for production.
		'test_mode' => true,
		
		// Your Adyen API key.
		// Found in your Customer Area under Developers > API credentials.
		// Use a test credential when test_mode is true.
		'api_key' => '',
		
		// Your Adyen merchant account name (not the company account).
		// Found in your Customer Area — the value shown next to the account switcher.
		'merchant_account' => '',
		
		// HMAC key used to verify incoming webhook signatures.
		// Generated in your Customer Area under Developers > Webhooks > Edit webhook > HMAC key.
		// Each webhook endpoint has its own HMAC key.
		'hmac_key' => '',
		
		// Live endpoint prefix (required for production only, ignored in test mode).
		// Found in your Customer Area under Developers > API URLs.
		// Format: <random>-<merchantAccount>
		'live_endpoint_prefix' => '',
		
		// Your store or company name — used for display purposes where relevant.
		'brand_name' => '',
		
		// Where to redirect the shopper after a completed payment (including async-pending).
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the shopper after a cancelled payment.
		'cancel_return_url' => 'https://example.com/order/cancelled',
		
		// The URL Adyen will POST Standard webhook notifications to.
		// Must be publicly reachable. Configure this in Customer Area under Developers > Webhooks.
		'webhook_url' => 'https://example.com/webhooks/adyen',
		
		// Default country code (ISO 3166-1 alpha-2) and currency (ISO 4217) used when calling
		// getPaymentOptions() without a specific transaction context (e.g. for rendering a static
		// payment method picker). Adyen uses these to filter the available methods list.
		// For NL merchants this should almost always be 'NL' + 'EUR'.
		'default_country'  => 'NL',
		'default_currency' => 'EUR',
	];