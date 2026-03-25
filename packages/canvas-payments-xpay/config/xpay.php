<?php
	
	return [
		// Your XPay API key. Separate keys are issued for test and live environments.
		// Generate one in the XPay Back Office under Admin > APIKey > Add new APIKey.
		// Use the test key for development; generate a live key for production.
		// @see https://developer.nexigroup.com/xpayglobal/en-EU/docs/getting-started/
		'api_key' => '',
		
		// Where to redirect the shopper after a completed or pending payment.
		// XPay appends orderId, operationId, channel, securityToken and esito as query parameters.
		'return_url' => 'https://example.com/order/thankyou',
		
		// Where to redirect the shopper after they cancel payment.
		'return_url_cancel' => 'https://example.com/order/cancelled',
		
		// Where to redirect the shopper when a technical error occurs during payment.
		// Falls back to return_url_cancel if left empty.
		'return_url_error' => 'https://example.com/order/error',
		
		// The URL XPay will POST push (webhook) notifications to after each status change.
		// Must be publicly reachable. Configure per-request via the notificationUrl field;
		// this value is sent with every order creation request.
		// @see https://developer.nexigroup.com/xpayglobal/en-EU/api/notification-api-v1/
		'webhook_url' => 'https://example.com/webhooks/xpay',
		
		// BCP 47 / ISO 639-2 language code for the hosted payment page.
		// XPay accepts 3-letter codes: ENG, ITA, DEU, FRA, SPA, POR, etc.
		// @see https://developer.nexigroup.com/xpayglobal/en-EU/api/payment-api-v1/
		'default_language' => 'ENG',
	];