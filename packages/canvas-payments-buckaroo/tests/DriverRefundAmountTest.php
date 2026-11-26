<?php

	namespace Quellabs\Payments\Buckaroo\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Quellabs\Payments\Buckaroo\BuckarooGateway;
	use Quellabs\Payments\Buckaroo\Driver;
	use Quellabs\Payments\Contracts\RefundRequest;

	/**
	 * Records the refund payload the driver sends and echoes its AmountCredit back.
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class CapturingRefundTransactionGatewayFake extends BuckarooGateway {

		/** @var array<string, mixed> */
		public array $sentPayload = [];

		/**
		 * Skips the parent constructor, which reads network configuration from the driver.
		 */
		public function __construct() {
		}

		/**
		 * Records the payload and returns a refund transaction echoing AmountCredit.
		 * @param array<string, mixed> $payload
		 * @return GatewayResponse
		 */
		public function refundTransaction(array $payload): array {
			$this->sentPayload = $payload;

			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'Key'          => 'refund_1',
					'AmountCredit' => $payload['AmountCredit'] ?? 0
				]
			];
		}
	}

	/**
	 * Confirms the Buckaroo driver converts ISO 4217 minor units to Buckaroo's decimal amounts and back.
	 */
	class DriverRefundAmountTest extends TestCase {

		/**
		 * Returns a driver wired to the given fake gateway.
		 * @param CapturingRefundTransactionGatewayFake $gateway
		 * @return Driver
		 */
		private function driverWithGateway(CapturingRefundTransactionGatewayFake $gateway): Driver {
			$driver   = new Driver();
			$property = new \ReflectionProperty(Driver::class, 'gateway');
			$property->setValue($driver, $gateway);

			return $driver;
		}

		/**
		 * EUR amounts are divided by 100, and the refunded value round-trips.
		 * @return void
		 */
		public function testEurAmountIsDividedByHundred(): void {
			$gateway = new CapturingRefundTransactionGatewayFake();
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('tx_1', 'bkr_ideal', 'EUR', 'Refund', 1050));

			$this->assertSame(10.5, $gateway->sentPayload['AmountCredit']);
			$this->assertSame(1050, $result->value);
		}

		/**
		 * JPY has 0 ISO digits, so 1000 minor units is sent as 1000.
		 * @return void
		 */
		public function testJpyAmountIsNotScaled(): void {
			$gateway = new CapturingRefundTransactionGatewayFake();
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('tx_1', 'bkr_ideal', 'JPY', 'Refund', 1000));

			$this->assertSame(1000.0, $gateway->sentPayload['AmountCredit']);
			$this->assertSame(1000, $result->value);
		}
	}
