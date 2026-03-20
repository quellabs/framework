<?php
	
	return [
		// Set to true to use the Buckaroo test environment, false for production.
		// Test: https://testcheckout.buckaroo.nl
		// Live: https://checkout.buckaroo.nl
		'test_mode' => true,
		
		// Your Buckaroo website key.
		// Found in Buckaroo Plaza under My Buckaroo > Websites > {your site} (first row in the table).
		// @see https://docs.buckaroo.io/docs/integration-hmac
		'website_key' => '',
		
		// Your Buckaroo secret key, used to sign HMAC requests.
		// Generate one in Buckaroo Plaza under Configuration > Secret Key.
		// @see https://docs.buckaroo.io/docs/integration-hmac
		'secret_key' => '',
		
		// Where to redirect the shopper after a completed or pending payment.
		// Buckaroo appends BRQ_TRANSACTIONS=<key> and BRQ_INVOICENUMBER to this URL.
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the shopper after they cancel payment.
		'return_url_cancel' => 'https://example.com/order/cancelled',
		
		// Where to redirect the shopper when a technical error occurs during payment.
		// Falls back to return_url_cancel if left empty.
		'return_url_error' => 'https://example.com/order/error',
		
		// Where to redirect the shopper when the payment is rejected by the acquirer.
		// Falls back to return_url_cancel if left empty.
		'return_url_reject' => 'https://example.com/order/rejected',
		
		// The URL Buckaroo will POST push (webhook) notifications to.
		// Must be publicly reachable. Configure per-site in Buckaroo Plaza under
		// My Buckaroo > Websites > {your site} > Push Settings.
		// The per-request PushURL field takes precedence over the Plaza configuration.
		// @see https://docs.buckaroo.io/docs/integration-push-messages
		'webhook_url' => 'https://example.com/webhooks/buckaroo',
		
		// BCP 47 culture tag sent to Buckaroo for hosted page language and email templates.
		// Supported values: nl-NL, en-US, de-DE, fr-FR, etc.
		// @see https://docs.buckaroo.io/docs/apis (Culture field)
		'default_culture' => 'nl-NL',
	];