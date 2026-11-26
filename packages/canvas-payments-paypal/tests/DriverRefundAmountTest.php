<?php

	namespace Quellabs\Payments\Paypal\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Quellabs\Payments\Contracts\PaymentRefundException;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Payments\Paypal\Driver;
	use Quellabs\Payments\Paypal\PaypalGateway;

	/**
	 * Records the refund amount the driver sends and echoes it back as PayPal would.
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class CapturingRefundGatewayFake extends PaypalGateway {

		public ?string $sentValue = null;

		public bool $called = false;

		/**
		 * Skips the parent constructor, which reads network configuration from the driver.
		 */
		public function __construct() {
		}

		/**
		 * Records the amount and returns a refund echoing it.
		 * @param string $captureId
		 * @param string|null $value
		 * @param string|null $currencyType
		 * @param string $note
		 * @param string $idempotencyKey
		 * @return GatewayResponse
		 */
		public function refund(string $captureId, ?string $value, ?string $currencyType, string $note, string $idempotencyKey): array {
			$this->called    = true;
			$this->sentValue = $value;

			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'id'     => 'refund_1',
					'amount' => ['value' => $value ?? '0', 'currency_code' => $currencyType ?? '']
				]
			];
		}
	}

	/**
	 * Confirms the PayPal driver converts ISO 4217 minor units to PayPal's amount format and back.
	 */
	class DriverRefundAmountTest extends TestCase {

		/**
		 * Returns a driver wired to the given fake gateway.
		 * @param CapturingRefundGatewayFake $gateway
		 * @return Driver
		 */
		private function driverWithGateway(CapturingRefundGatewayFake $gateway): Driver {
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
			$gateway = new CapturingRefundGatewayFake();
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('cap_1', 'paypal', 'EUR', 'Refund', 1050));

			$this->assertSame('10.50', $gateway->sentValue);
			$this->assertSame(1050, $result->value);
		}

		/**
		 * JPY has 0 ISO digits, so 1000 minor units is ¥1000.
		 * @return void
		 */
		public function testJpyAmountHasNoDecimals(): void {
			$gateway = new CapturingRefundGatewayFake();
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('cap_1', 'paypal', 'JPY', 'Refund', 1000));

			$this->assertSame('1000', $gateway->sentValue);
			$this->assertSame(1000, $result->value);
		}

		/**
		 * HUF has 2 ISO digits but PayPal takes it without decimals, so 100000 minor units is sent as "1000".
		 * @return void
		 */
		public function testHufWholeAmountDropsDecimals(): void {
			$gateway = new CapturingRefundGatewayFake();
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('cap_1', 'paypal', 'HUF', 'Refund', 100000));

			$this->assertSame('1000', $gateway->sentValue);
			$this->assertSame(100000, $result->value);
		}

		/**
		 * A fractional HUF amount is rejected before PayPal is called.
		 * @return void
		 */
		public function testHufFractionalAmountIsRejected(): void {
			$gateway = new CapturingRefundGatewayFake();

			try {
				$this->driverWithGateway($gateway)->refund(new RefundRequest('cap_1', 'paypal', 'HUF', 'Refund', 100050));
				$this->fail('Expected PaymentRefundException to be thrown.');
			} catch (PaymentRefundException $e) {
				$this->assertSame('invalid_amount', $e->getErrorId());
				$this->assertFalse($gateway->called);
			}
		}
	}
