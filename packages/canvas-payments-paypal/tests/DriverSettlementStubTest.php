<?php

	namespace Quellabs\Payments\Paypal\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Payments\Paypal\Driver;
	use Quellabs\Payments\Contracts\PaymentSettlementException;

	/**
	 * Confirms the PayPal driver's settlement stubs reject with the same
	 * not_supported shape as its existing mandate stubs.
	 */
	class DriverSettlementStubTest extends TestCase {

		/**
		 * getSettlement() throws PaymentSettlementException with errorId 'not_supported'.
		 * @return void
		 */
		public function testGetSettlementThrowsNotSupported(): void {
			$driver = new Driver();

			try {
				$driver->getSettlement('stl_123');
				$this->fail('Expected PaymentSettlementException to be thrown.');
			} catch (PaymentSettlementException $e) {
				$this->assertSame('paypal', $e->getProvider());
				$this->assertSame('not_supported', $e->getErrorId());
			}
		}

		/**
		 * getSettlementPayments() throws PaymentSettlementException with errorId 'not_supported'.
		 * @return void
		 */
		public function testGetSettlementPaymentsThrowsNotSupported(): void {
			$driver = new Driver();

			try {
				$driver->getSettlementPayments('stl_123');
				$this->fail('Expected PaymentSettlementException to be thrown.');
			} catch (PaymentSettlementException $e) {
				$this->assertSame('paypal', $e->getProvider());
				$this->assertSame('not_supported', $e->getErrorId());
			}
		}

		/**
		 * listSettlements() throws PaymentSettlementException with errorId 'not_supported'.
		 * @return void
		 */
		public function testListSettlementsThrowsNotSupported(): void {
			$driver = new Driver();

			try {
				$driver->listSettlements();
				$this->fail('Expected PaymentSettlementException to be thrown.');
			} catch (PaymentSettlementException $e) {
				$this->assertSame('paypal', $e->getProvider());
				$this->assertSame('not_supported', $e->getErrorId());
			}
		}
	}
