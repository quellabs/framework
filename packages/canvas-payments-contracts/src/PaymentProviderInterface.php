<?php
	
	namespace Quellabs\Contracts\Payment;
	
	interface PaymentProviderInterface {
		
		public function getSupportedModules(): array;
		
		public function initiate(PaymentRequest $request): PaymentResponse;
		
		public function refund(RefundRequest $refundRequest): PaymentResponse;
		
		public function exchange(string $transactionId, array $extraData = []): PaymentResponse;
		
		public function getPaymentOptions(string $paymentModule): PaymentResponse;
		
		public function getRefunds(string $transactionId): PaymentResponse;
		
	}