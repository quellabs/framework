<?php
	
	namespace Quellabs\Contracts\Payment;
	
	final readonly class InitiateResponse {
		public function __construct(
			public string $provider,
			public string $transactionId,
			public string $redirectUrl,
		) {
		}
	}