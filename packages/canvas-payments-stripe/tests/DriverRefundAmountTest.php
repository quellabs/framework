<?php

	namespace Quellabs\Payments\Stripe\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Quellabs\Payments\Contracts\RefundRequest;
	use Quellabs\Payments\Stripe\Driver;
	use Quellabs\Payments\Stripe\StripeGateway;

	/**
	 * Records the refund amount the driver sends and echoes it back as Stripe would.
	 *
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class CapturingStripeRefundGatewayFake extends StripeGateway {

		public ?int $sentAmount = null;

		private string $currency;

		/**
		 * Skips the parent constructor, which reads network configuration from the driver.
		 * @param string $currency Lowercase currency Stripe reports on the refund
		 */
		public function __construct(string $currency) {
			$this->currency = $currency;
		}

		/**
		 * Records the amount and returns a refund object echoing it.
		 * @param string $paymentIntentId
		 * @param int|null $amount
		 * @param string $reason
		 * @param string $idempotencyKey
		 * @return GatewayResponse
		 */
		public function refund(string $paymentIntentId, ?int $amount, string $reason, string $idempotencyKey): array {
			$this->sentAmount = $amount;

			return [
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'id'       => 're_1',
					'amount'   => $amount ?? 0,
					'currency' => $this->currency,
					'status'   => 'succeeded'
				]
			];
		}
	}

	/**
	 * Confirms the Stripe driver adds and removes the "00" Stripe expects for ISK and UGX only.
	 */
	class DriverRefundAmountTest extends TestCase {

		/**
		 * Returns a driver wired to the given fake gateway.
		 * @param CapturingStripeRefundGatewayFake $gateway
		 * @return Driver
		 */
		private function driverWithGateway(CapturingStripeRefundGatewayFake $gateway): Driver {
			$driver   = new Driver();
			$property = new \ReflectionProperty(Driver::class, 'gateway');
			$property->setValue($driver, $gateway);

			return $driver;
		}

		/**
		 * EUR minor units pass through unchanged.
		 * @return void
		 */
		public function testEurAmountPassesThrough(): void {
			$gateway = new CapturingStripeRefundGatewayFake('eur');
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('pi_1', 'stripe', 'EUR', 'Refund', 1050));

			$this->assertSame(1050, $gateway->sentAmount);
			$this->assertSame(1050, $result->value);
		}

		/**
		 * JPY is zero-decimal in both ISO 4217 and Stripe, so ¥1000 is sent as 1000.
		 * @return void
		 */
		public function testJpyAmountPassesThrough(): void {
			$gateway = new CapturingStripeRefundGatewayFake('jpy');
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('pi_1', 'stripe', 'JPY', 'Refund', 1000));

			$this->assertSame(1000, $gateway->sentAmount);
			$this->assertSame(1000, $result->value);
		}

		/**
		 * ISK has 0 ISO digits but Stripe takes it as two-decimal, so 500 ISK is sent as 50000 and read back as 500.
		 * @return void
		 */
		public function testIskAmountGetsTrailingZeros(): void {
			$gateway = new CapturingStripeRefundGatewayFake('isk');
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('pi_1', 'stripe', 'ISK', 'Refund', 500));

			$this->assertSame(50000, $gateway->sentAmount);
			$this->assertSame(500, $result->value);
		}

		/**
		 * UGX follows the same rule as ISK.
		 * @return void
		 */
		public function testUgxAmountGetsTrailingZeros(): void {
			$gateway = new CapturingStripeRefundGatewayFake('ugx');
			$result  = $this->driverWithGateway($gateway)->refund(new RefundRequest('pi_1', 'stripe', 'UGX', 'Refund', 5000));

			$this->assertSame(500000, $gateway->sentAmount);
			$this->assertSame(5000, $result->value);
		}

		/**
		 * A full refund sends no amount.
		 * @return void
		 */
		public function testFullRefundSendsNoAmount(): void {
			$gateway = new CapturingStripeRefundGatewayFake('isk');
			$this->driverWithGateway($gateway)->refund(new RefundRequest('pi_1', 'stripe', 'ISK', 'Refund'));

			$this->assertNull($gateway->sentAmount);
		}
	}
