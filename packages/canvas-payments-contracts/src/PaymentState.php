<?php
	
	namespace Quellabs\Contracts\Payment;
	
	final readonly class PaymentState {
		public function __construct(
			public string        $provider,
			public string        $transactionId,
			public PaymentStatus $state,
			public int           $valueRequested,
			public int           $valueRefunded,
			public int           $valueRefundable,
			public ?string       $internalState = null,
			public ?string       $currency = null,
			public array         $metadata = [],
		) {
		}
	}