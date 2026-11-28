<?php

	namespace Quellabs\Payments\Mollie\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Quellabs\Payments\Contracts\PaymentStatus;
	use Quellabs\Payments\Mollie\Driver;
	use Quellabs\Payments\Mollie\MollieGateway;

	/**
	 * Stub gateway returning a canned getPaymentInfo() response, injected into
	 * Driver via reflection so exchange()'s mapping logic can be tested without
	 * performing real HTTP calls.
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class FakeExchangeGateway extends MollieGateway {

		/** @var GatewayResponse */
		private array $paymentInfoResponse = ['request' => ['result' => 0, 'errorId' => '', 'errorMessage' => '']];

		/**
		 * No network configuration needed for this test double.
		 */
		public function __construct() {
		}

		/**
		 * Sets the canned response returned by getPaymentInfo().
		 * @param GatewayResponse $response
		 * @return void
		 */
		public function setPaymentInfoResponse(array $response): void {
			$this->paymentInfoResponse = $response;
		}

		/**
		 * Returns the canned response set via setPaymentInfoResponse().
		 * @param string $transactionId
		 * @return GatewayResponse
		 */
		public function getPaymentInfo(string $transactionId): array {
			return $this->paymentInfoResponse;
		}
	}

	/**
	 * Confirms Driver::exchange() maps Mollie's failure-detail fields
	 * (details.failureReason, mandateId) onto PaymentState.
	 */
	class DriverExchangeTest extends TestCase {

		/**
		 * Builds a Driver whose private gateway property is replaced with the given fake, via reflection.
		 * @param FakeExchangeGateway $gateway
		 * @return Driver
		 */
		private function driverWithGateway(FakeExchangeGateway $gateway): Driver {
			$driver = new Driver();

			$property = new \ReflectionProperty(Driver::class, 'gateway');
			$property->setAccessible(true);
			$property->setValue($driver, $gateway);

			return $driver;
		}

		/**
		 * exchange() surfaces details.failureReason and mandateId on a failed recurring charge.
		 * @return void
		 */
		public function testExchangeMapsFailureReasonAndMandateIdOnFailure(): void {
			$gateway = new FakeExchangeGateway();
			$gateway->setPaymentInfoResponse([
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'resource'  => 'payment',
					'status'    => 'failed',
					'amount'    => ['value' => '19.00', 'currency' => 'EUR'],
					'mandateId' => 'mdt_h3xHfbqqkB',
					'details'   => ['failureReason' => 'insufficient_funds']
				]
			]);

			$state = $this->driverWithGateway($gateway)->exchange('tr_WDqYK6vllg');

			$this->assertSame(PaymentStatus::Failed, $state->state);
			$this->assertSame('insufficient_funds', $state->failureReason);
			$this->assertSame('mdt_h3xHfbqqkB', $state->mandateId);
		}

		/**
		 * exchange() leaves failureReason/mandateId null when Mollie's response doesn't carry them.
		 * @return void
		 */
		public function testExchangeLeavesFailureReasonAndMandateIdNullWhenAbsent(): void {
			$gateway = new FakeExchangeGateway();
			$gateway->setPaymentInfoResponse([
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'resource' => 'payment',
					'status'   => 'paid',
					'amount'   => ['value' => '19.00', 'currency' => 'EUR']
				]
			]);

			$state = $this->driverWithGateway($gateway)->exchange('tr_7UhSN1zuXS');

			$this->assertSame(PaymentStatus::Paid, $state->state);
			$this->assertNull($state->failureReason);
			$this->assertNull($state->mandateId);
			$this->assertSame(0, $state->valueChargedBack, 'Mollie omits amountChargedBack while nothing is charged back');
		}

		/**
		 * exchange() reports amountChargedBack on a payment that keeps its paid status.
		 * @return void
		 */
		public function testExchangeMapsTheChargedBackAmount(): void {
			$gateway = new FakeExchangeGateway();
			$gateway->setPaymentInfoResponse([
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'resource'          => 'payment',
					'status'            => 'paid',
					'amount'            => ['value' => '19.00', 'currency' => 'EUR'],
					'amountChargedBack' => ['value' => '19.00', 'currency' => 'EUR']
				]
			]);

			$state = $this->driverWithGateway($gateway)->exchange('tr_7UhSN1zuXS');

			$this->assertSame(PaymentStatus::Paid, $state->state);
			$this->assertSame(1900, $state->valuePaid);
			$this->assertSame(1900, $state->valueChargedBack);
			$this->assertSame(0, $state->valueRefunded);
		}
	}
