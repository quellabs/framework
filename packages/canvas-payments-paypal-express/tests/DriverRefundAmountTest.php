<?php

	namespace Quellabs\Payments\PaypalExpress\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Quellabs\Payments\Contracts\PaymentRefundException;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Payments\PaypalExpress\Driver;
	use Quellabs\Payments\PaypalExpress\PaypalGateway;

	/**
	 * Records the AMT the driver sends and echoes it back as GROSSREFUNDAMT.
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class CapturingPartialRefundGatewayFake extends PaypalGateway {

		public ?string $sentValue = null;

		public bool $called = false;

		/**
		 * Skips the parent constructor, which reads network configuration from the driver.
		 */
		public function __construct() {
		}

		/**
		 * Records the amount and returns a RefundTransaction response echoing it.
		 * @param string $transactionId
		 * @param string $value
		 * @param string $currencyType
		 * @param string $note
		 * @return GatewayResponse
		 */
		public function partialRefund(string $transactionId, string $value, string $currencyType, string $note): array {
			$this->called    = true;
			$this->sentValue = $value;

			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'REFUNDTRANSACTIONID' => 'refund_1',
					'GROSSREFUNDAMT'      => $value,
					'CURRENCYCODE'        => $currencyType,
					'CORRELATIONID'       => 'corr_1',
					'REFUNDSTATUS'        => 'Instant',
					'PENDINGREASON'       => 'None'
				]
			];
		}
	}

	/**
	 * Confirms the PayPal Express driver converts ISO 4217 minor units to PayPal's AMT format and back.
	 */
	class DriverRefundAmountTest extends TestCase {

		/**
		 * Returns a driver wired to the given fake gateway.
		 * @param CapturingPartialRefundGatewayFake $gateway
		 * @return Driver
		 */
		private function driverWithGateway(CapturingPartialRefundGatewayFake $gateway): Driver {
			$driver   = new Driver();
			$property = new \ReflectionProperty(Driver::class, 'gateway');
			$property->setValue($driver, $gateway);

			return $driver;
		}

		/**
		 * Two-decimal currencies keep their decimals, and the refunded value round-trips.
		 * @return void
		 */
		public function testEurAmountUsesTwoDecimals(): void {
			$gateway = new CapturingPartialRefundGatewayFake();
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('tx_1', 'paypal', 'EUR', 'Refund', 1050));

			$this->assertSame('10.50', $gateway->sentValue);
			$this->assertSame(1050, $result->value);
		}

		/**
		 * JPY has 0 ISO digits, so 1000 minor units is ¥1000.
		 * @return void
		 */
		public function testJpyAmountHasNoDecimals(): void {
			$gateway = new CapturingPartialRefundGatewayFake();
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('tx_1', 'paypal', 'JPY', 'Refund', 1000));

			$this->assertSame('1000', $gateway->sentValue);
			$this->assertSame(1000, $result->value);
		}

		/**
		 * TWD has 2 ISO digits but PayPal takes it without decimals, so 50000 minor units is sent as "500".
		 * @return void
		 */
		public function testTwdWholeAmountDropsDecimals(): void {
			$gateway = new CapturingPartialRefundGatewayFake();
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('tx_1', 'paypal', 'TWD', 'Refund', 50000));

			$this->assertSame('500', $gateway->sentValue);
			$this->assertSame(50000, $result->value);
		}

		/**
		 * A fractional HUF amount is rejected before PayPal is called.
		 * @return void
		 */
		public function testHufFractionalAmountIsRejected(): void {
			$gateway = new CapturingPartialRefundGatewayFake();

			try {
				$this->driverWithGateway($gateway)->refund(new RefundRequest('tx_1', 'paypal', 'HUF', 'Refund', 100050));
				$this->fail('Expected PaymentRefundException to be thrown.');
			} catch (PaymentRefundException $e) {
				$this->assertSame('invalid_amount', $e->getErrorId());
				$this->assertFalse($gateway->called);
			}
		}
	}
