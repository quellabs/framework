<?php
	
	return [
		// Set to true to use the Klarna Playground (sandbox) environment, false for production.
		'test_mode'         => true,
		
		// API credentials from your Klarna merchant portal.
		// Found under Settings > API Credentials in the Klarna merchant portal.
		// Use Playground credentials when test_mode is true.
		// @see https://docs.klarna.com/acquirer/klarna/get-started/integration-resilience/authentication/
		'api_username'      => '',
		'api_password'      => '',
		
		// Where to redirect the buyer after a successfully completed payment.
		// Klarna appends ?order_id=<id>&authorization_token=<token> to this URL on return.
		'return_url'        => 'https://example.com/order/thankyou',
		
		// Where to redirect the buyer after a cancelled, failed, or errored payment.
		'cancel_return_url' => 'https://example.com/order/cancelled',
		
		// ISO 4217 currency code used as the default when none is specified on the request.
		'default_currency'  => 'EUR',
		
		// ISO 3166-1 alpha-2 country code used when no billing address is provided.
		// Klarna uses the purchase country to determine available payment methods.
		'default_country'   => 'NL',
		
		// BCP 47 locale code shown on the Klarna hosted payment page.
		// Controls the language of Klarna's checkout UI.
		// @see https://docs.klarna.com/acquirer/klarna/get-started/data-requirements/puchase-countries-currencies-locales/
		'locale'            => 'nl-NL',
		
		// Controls when the Klarna order is placed and captured after authorisation.
		//
		// CAPTURE_ORDER — Klarna places and captures the order automatically on authorisation.
		//                 Use for digital goods or immediate fulfilment. No further API calls required.
		// PLACE_ORDER   — Klarna places the order on authorisation; you capture manually after shipping.
		//                 Use for physical goods. Call the Order Management API to capture.
		// NONE          — Klarna authorises only; you place and capture the order yourself.
		//
		// @see https://docs.klarna.com/acquirer/klarna/web-payments/integrate-with-klarna-payments/integrate-via-hpp/
		'place_order_mode'  => 'CAPTURE_ORDER',
	];