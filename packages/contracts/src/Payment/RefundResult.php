<?php
	
	namespace Quellabs\Contracts\Payment;
	
	final class RefundResult {
		public function __construct(
			public readonly string $provider,
			public readonly string $transactionId,
			public readonly string $refundId,
			public readonly float  $value,
			public readonly string $currency,
		) {
		}
	}
