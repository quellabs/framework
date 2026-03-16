<?php
	
	namespace Quellabs\Contracts\Payment;
	
	final readonly class PaymentState {
		public function __construct(
			public string        $provider,
			public string        $transactionId,
			public PaymentStatus $state,
			public ?string       $internalState = null,
			public ?float        $valueRequested,
			public float         $valueRefunded,
			public float         $valueRefundable,
			public ?string       $currency = null,
			public array         $metadata = [],
		) {
		}
	}