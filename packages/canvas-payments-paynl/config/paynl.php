<?php
	
	return [
		// Set to true to use Pay.nl test mode, false for production.
		// Test mode is signaled per-order via integration.test=true in the request body.
		'test_mode' => true,
		
		// Your Pay.nl token code (AT-xxxx-xxxx format).
		// Found in the Pay.nl admin panel under Merchant → Company information.
		// Used as the Basic Auth username.
		// @see https://developer.pay.nl/docs/getting-the-credentials
		'token_code' => '',
		
		// Your Pay.nl API token (40-character hash).
		// Found in the Pay.nl admin panel under Merchant → Company information.
		// Used as the Basic Auth password.
		// @see https://developer.pay.nl/docs/getting-the-credentials
		'api_token' => '',
		
		// Your Pay.nl service ID / sales location ID (SL-xxxx-xxxx format).
		// Found in the Pay.nl admin panel under Settings → Sales locations.
		// Each sales location has its own ID and enabled payment methods.
		// @see https://developer.pay.nl/docs/getting-the-credentials
		'service_id' => '',
		
		// Where to redirect the shopper after a completed or pending payment.
		// Pay.nl appends ?id={uuid}&orderId={legacyId} to this URL.
		// Use the 'id' parameter (UUID) for all subsequent API calls — not orderId.
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the shopper after a cancelled payment.
		// Pay.nl appends ?id={uuid}&orderId={legacyId} to this URL.
		'cancel_return_url' => 'https://example.com/order/cancelled',
		
		// The URL Pay.nl will POST exchange notifications to (server-to-server webhook).
		// Must be publicly reachable. Pay.nl POSTs form-encoded data with action= and order_id=.
		// Pay.nl retries on non-"TRUE|" responses according to its retry scheme.
		// @see https://developer.pay.nl/docs/handling-the-exchange-calls
		'exchange_url' => 'https://example.com/webhooks/paynl',
		
		// Default currency (ISO 4217). For NL merchants this is almost always 'EUR'.
		'default_currency' => 'EUR',
	];