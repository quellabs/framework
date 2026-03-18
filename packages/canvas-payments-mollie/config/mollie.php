<?php
	
	return [
		// Your Mollie API key. Use a test key (test_...) for development, live key (live_...) for production.
		'api_key' => '',
		
		// Set to true to use Mollie test mode, false for production.
		// Note: test mode is determined by the API key prefix, not this flag alone.
		'test_mode' => false,
		
		// URL Mollie POSTs payment status updates to.
		// Must be publicly accessible — localhost will not work.
		'webhook_url' => 'https://example.com/webhooks/mollie',
		
		// URL Mollie redirects the buyer to after completing checkout.
		// Handled by the package — emits the payment_exchange signal before redirecting.
		'redirect_url' => 'https://example.com/payment/return/mollie',
		
		// URL Mollie redirects the buyer to when they cancel at checkout.
		// Handled by the package — emits the payment_exchange signal before redirecting.
		'cancel_url' => 'https://example.com/payment/cancel/mollie',
		
		// Where to redirect the buyer after the package handles the return.
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the buyer after the package handles the cancel.
		'cancel_return_url' => 'https://example.com/order/cancelled',
	];