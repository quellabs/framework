<?php
	
	namespace Quellabs\Contracts\Payment;
	
	interface PaymentProviderInterface {
		
		public function initiate(PaymentRequest $request): PaymentResponse;
		
		public function refund(RefundRequest $refundRequest): PaymentResponse;
		
		public function exchange(string $transactionId, array $extraData = []): PaymentResponse;
		
		public function getSupportedModules(): array;
		
		public function getPaymentOptions(string $paymentModule): array;
		
		public function getRefunds(string $transactionId): PaymentResponse;
		
	}