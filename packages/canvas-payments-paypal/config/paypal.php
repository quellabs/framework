<?php
	
	return [
		// Determines which PayPal environment to use.
		// Set to "Live" for production, anything else runs in sandbox mode.
		'transaction_server' => 'Sandbox',
		
		// NVP/SOAP API credentials from your PayPal account.
		// Found under Account Settings > API Access > NVP/SOAP API integration.
		// Use sandbox credentials when transaction_server is not "Live".
		'api_username'  => '',
		'api_password'  => '',
		'api_signature' => '',
		
		// Whether to verify PayPal's SSL certificate when making API calls.
		// Should always be true in production. Only disable for local debugging.
		'verify_ssl' => true,
		
		// When true, buyers can check out as a guest without a PayPal account (Sole solution type).
		// When false, buyers must log in to a PayPal account to complete payment (Mark solution type).
		'account_optional' => true,
		
		// Your store or company name as displayed on the PayPal checkout page.
		// Leave empty to use your PayPal account name.
		'brand_name' => '',
		
		// Where to redirect the buyer after a successfully completed payment.
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the buyer after they cancel the payment at PayPal.
		'cancel_return_url' => 'https://example.com/order/cancelled',
	];