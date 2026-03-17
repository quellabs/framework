<?php
	
	namespace Quellabs\Payments\Contracts;
	
	final class RefundResult {
		public function __construct(
			public readonly string $provider,
			public readonly string $transactionId,
			public readonly string $refundId,
			public readonly int    $value,
			public readonly string $currency,
		) {
		}
	}
