<?php
	
	return [
		// Set to true to use Stripe's test environment, false for production.
		// Test mode is automatically implied when secret_key starts with 'sk_test_'.
		'test_mode' => true,
		
		// Your Stripe API keys from the Stripe Dashboard under Developers > API keys.
		// Use test keys (sk_test_*, pk_test_*) when test_mode is true.
		// @see https://dashboard.stripe.com/apikeys
		'secret_key'      => '',
		'publishable_key' => '',
		
		// Webhook signing secret from the Stripe Dashboard under Developers > Webhooks.
		// Required for HMAC signature verification — do not leave empty in production.
		// Each webhook endpoint has its own signing secret (whsec_*).
		// @see https://dashboard.stripe.com/webhooks
		'webhook_secret' => '',
		
		// Whether to verify Stripe's SSL certificate when making API calls.
		// Should always be true in production. Only disable for local debugging.
		'verify_ssl' => true,
		
		// Where to redirect the buyer after a successfully completed payment.
		// Stripe appends ?session_id={CHECKOUT_SESSION_ID} automatically — do not add it manually.
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the buyer if they click "Back" or cancel on the Stripe checkout page.
		'cancel_return_url' => 'https://example.com/order/cancelled',
		
		// The URL Stripe will POST webhook events to.
		// Must be publicly accessible and registered in the Stripe Dashboard under Developers > Webhooks.
		// Localhost will not work — use a tunnel (e.g. Stripe CLI, ngrok) for local development.
		// @see https://stripe.com/docs/stripe-cli for local webhook forwarding
		'webhook_url' => 'https://example.com/webhooks/stripe',
	];