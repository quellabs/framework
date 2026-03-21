<?php
	
	return [
		// Set to true to use the Rabo Smart Pay sandbox environment, false for production.
		'test_mode'         => true,
		
		// Long-lived refresh token from your Rabo Smart Pay dashboard.
		// Used to obtain short-lived access tokens for each order announcement.
		// Found under Settings > Integration in the Rabo Smart Pay merchant portal.
		// @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
		'refresh_token'     => '',
		
		// Base64-encoded signing key from your Rabo Smart Pay dashboard.
		// Used to verify HMAC-SHA512 signatures on webhook notifications and return URLs.
		// Found under Settings > Integration in the Rabo Smart Pay merchant portal.
		// @see https://developer.rabobank.nl/rabo-smart-pay-online-payment-api
		'signing_key'       => '',
		
		// Where to redirect the buyer after a successfully completed or in-progress payment.
		// Rabo Smart Pay appends ?order_id=<id>&status=<status> to this URL on return.
		'return_url'        => 'https://example.com/order/thankyou',
		
		// Where to redirect the buyer after a cancelled, expired, or failed payment.
		'cancel_return_url' => 'https://example.com/order/cancelled',
		
		// The URL Rabo Smart Pay will POST webhook notifications to.
		// Must be publicly accessible and match the URL registered in your merchant portal.
		// Localhost will not work — use a tunnel (e.g. ngrok) for local development.
		// Rabo Smart Pay does NOT retry failed webhook deliveries — ensure the endpoint is reliable.
		'webhook_url'       => 'https://example.com/webhooks/rabosmartpay',
		
		// ISO 4217 currency code for payments. Rabo Smart Pay only supports EUR.
		'default_currency'  => 'EUR',
		
		// Language code shown on the Rabo Smart Pay hosted checkout page.
		// Supported values: NL, EN, DE, FR (check dashboard for current list).
		'language'          => 'NL',
		
		// When true, the Rabo Smart Pay result page (showing payment outcome) is skipped
		// and the buyer is redirected immediately to your return or cancel URL.
		'skip_result_page'  => true,
	];