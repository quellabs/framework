<?php
	
	return [
		// Set to true to use the PayPal sandbox environment, false for production.
		'test_mode' => true,
		
		// REST API credentials from your PayPal Developer Dashboard.
		// Found under Apps & Credentials > your app > Client ID / Secret.
		// Use sandbox credentials when test_mode is true.
		// @see https://developer.paypal.com/dashboard/
		'client_id'     => '',
		'client_secret' => '',
		
		// Webhook ID from your PayPal app settings.
		// Required for signature-based webhook verification — do not leave empty in production.
		// Found under Apps & Credentials > your app > Webhooks.
		// @see https://developer.paypal.com/dashboard/
		'webhook_id' => '',
		
		// Whether to verify PayPal's SSL certificate when making API calls.
		// Should always be true in production. Only disable for local debugging.
		'verify_ssl' => true,
		
		// When true, buyers can check out as a guest without a PayPal account.
		// When false, buyers must log in to a PayPal account to complete payment.
		'account_optional' => true,
		
		// Your store or company name as displayed on the PayPal checkout page.
		// Leave empty to use your PayPal account name.
		'brand_name' => '',
		
		// Where to redirect the buyer after a successfully completed payment.
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the buyer after they cancel the payment at PayPal.
		'cancel_return_url' => 'https://example.com/order/cancelled',
		
		// The URL PayPal will POST webhook notifications to.
		// Must be publicly accessible and match the URL registered in your PayPal app settings.
		// Localhost will not work — use a tunnel (e.g. ngrok) for local development.
		'webhook_url' => 'https://example.com/webhooks/paypal',
	];