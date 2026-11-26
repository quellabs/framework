<?php

	namespace Quellabs\Payments\Mollie\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Quellabs\Payments\Contracts\PaymentSettlementException;
	use Quellabs\Payments\Contracts\SettlementStatus;
	use Quellabs\Payments\Mollie\Driver;
	use Quellabs\Payments\Mollie\MollieGateway;

	/**
	 * Stub gateway returning canned getSettlement()/listSettlementPayments()
	 * responses, injected into Driver via reflection so its mapping logic can
	 * be tested without performing real HTTP calls.
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class FakeSettlementGateway extends MollieGateway {

		/** @var GatewayResponse */
		private array $settlementResponse = ['request' => ['result' => 0, 'errorId' => '', 'errorMessage' => '']];

		/** @var GatewayResponse */
		private array $settlementPaymentsResponse = ['request' => ['result' => 0, 'errorId' => '', 'errorMessage' => '']];

		/** @var GatewayResponse */
		private array $settlementRefundsResponse = ['request' => ['result' => 0, 'errorId' => '', 'errorMessage' => '']];

		/** @var GatewayResponse */
		private array $settlementChargebacksResponse = ['request' => ['result' => 0, 'errorId' => '', 'errorMessage' => '']];

		/**
		 * No network configuration needed for this test double.
		 */
		public function __construct() {
		}

		/**
		 * Sets the canned response returned by getSettlement().
		 * @param GatewayResponse $response
		 * @return void
		 */
		public function setSettlementResponse(array $response): void {
			$this->settlementResponse = $response;
		}

		/**
		 * Sets the canned response returned by listSettlementPayments().
		 * @param GatewayResponse $response
		 * @return void
		 */
		public function setSettlementPaymentsResponse(array $response): void {
			$this->settlementPaymentsResponse = $response;
		}

		/**
		 * Returns the canned settlement response set via setSettlementResponse().
		 * @param string $settlementId
		 * @return GatewayResponse
		 */
		public function getSettlement(string $settlementId): array {
			return $this->settlementResponse;
		}

		/**
		 * Returns the canned settlement-payments response set via setSettlementPaymentsResponse().
		 * @param string $settlementId
		 * @return GatewayResponse
		 */
		public function listSettlementPayments(string $settlementId): array {
			return $this->settlementPaymentsResponse;
		}

		/**
		 * Sets the canned response returned by listSettlementRefunds().
		 * @param GatewayResponse $response
		 * @return void
		 */
		public function setSettlementRefundsResponse(array $response): void {
			$this->settlementRefundsResponse = $response;
		}

		/**
		 * Sets the canned response returned by listSettlementChargebacks().
		 * @param GatewayResponse $response
		 * @return void
		 */
		public function setSettlementChargebacksResponse(array $response): void {
			$this->settlementChargebacksResponse = $response;
		}

		/**
		 * Returns the canned settlement-refunds response set via setSettlementRefundsResponse().
		 * @param string $settlementId
		 * @return GatewayResponse
		 */
		public function listSettlementRefunds(string $settlementId): array {
			return $this->settlementRefundsResponse;
		}

		/**
		 * Returns the canned settlement-chargebacks response set via setSettlementChargebacksResponse().
		 * @param string $settlementId
		 * @return GatewayResponse
		 */
		public function listSettlementChargebacks(string $settlementId): array {
			return $this->settlementChargebacksResponse;
		}
	}

	/**
	 * Confirms Driver::getSettlement()/getSettlementPayments() map Mollie's
	 * confirmed response shapes to SettlementInfo/SettlementPaymentInfo correctly,
	 * including an unrecognized status string falling back to SettlementStatus::Unknown.
	 */
	class DriverSettlementTest extends TestCase {

		/**
		 * Builds a Driver whose private gateway property is replaced with the given fake, via reflection.
		 * @param FakeSettlementGateway $gateway
		 * @return Driver
		 */
		private function driverWithGateway(FakeSettlementGateway $gateway): Driver {
			$driver = new Driver();

			$property = new \ReflectionProperty(Driver::class, 'gateway');
			$property->setAccessible(true);
			$property->setValue($driver, $gateway);

			return $driver;
		}

		/**
		 * getSettlement() maps a known Mollie status to the matching SettlementStatus and fields.
		 * @return void
		 */
		public function testGetSettlementMapsKnownStatus(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementResponse([
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'resource'  => 'settlement',
					'id'        => 'stl_jDk30akdN',
					'reference' => '1234567.2404.03',
					'status'    => 'paidout',
					'amount'    => ['value' => '39.75', 'currency' => 'EUR'],
					'settledAt' => '2024-04-03T10:00:00+00:00'
				]
			]);

			$settlement = $this->driverWithGateway($gateway)->getSettlement('stl_jDk30akdN');

			$this->assertSame('mollie', $settlement->provider);
			$this->assertSame('stl_jDk30akdN', $settlement->settlementId);
			$this->assertSame('1234567.2404.03', $settlement->reference);
			$this->assertSame(SettlementStatus::PaidOut, $settlement->status);
			$this->assertSame(3975, $settlement->amount);
			$this->assertSame('EUR', $settlement->currency);
			$this->assertSame('2024-04-03T10:00:00+00:00', $settlement->settledAt?->format('Y-m-d\TH:i:sP'));
			$this->assertSame('paidout', $settlement->internalState);
		}

		/**
		 * getSettlement() falls back to SettlementStatus::Unknown for an unrecognized status string.
		 * @return void
		 */
		public function testGetSettlementMapsUnrecognizedStatusToUnknown(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementResponse([
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					'resource'  => 'settlement',
					'id'        => 'stl_jDk30akdN',
					'reference' => '1234567.2404.03',
					'status'    => 'some-future-status',
					'amount'    => ['value' => '10.00', 'currency' => 'EUR']
				]
			]);

			$settlement = $this->driverWithGateway($gateway)->getSettlement('stl_jDk30akdN');

			$this->assertSame(SettlementStatus::Unknown, $settlement->status);
			$this->assertSame('some-future-status', $settlement->internalState);
			$this->assertNull($settlement->settledAt);
		}

		/**
		 * getSettlement() throws PaymentSettlementException when the gateway reports an error.
		 * @return void
		 */
		public function testGetSettlementThrowsOnGatewayError(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementResponse([
				'request' => ['result' => 0, 'errorId' => '404', 'errorMessage' => 'Settlement not found']
			]);

			$this->expectException(PaymentSettlementException::class);
			$this->driverWithGateway($gateway)->getSettlement('stl_unknown');
		}

		/**
		 * getSettlementPayments() maps each payment in the response to a SettlementPaymentInfo.
		 * @return void
		 */
		public function testGetSettlementPaymentsMapsPayments(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementPaymentsResponse([
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					[
						'id'          => 'tr_7UhSN1zuXS',
						'amount'      => ['value' => '20.00', 'currency' => 'EUR'],
						'description' => 'Order #1',
						'createdAt'   => '2024-04-01T10:00:00+00:00'
					],
					[
						'id'          => 'tr_WDqYK6vllg',
						'amount'      => ['value' => '19.75', 'currency' => 'EUR'],
						'description' => 'Order #2',
						'createdAt'   => '2024-04-02T10:00:00+00:00'
					]
				]
			]);

			$payments = $this->driverWithGateway($gateway)->getSettlementPayments('stl_jDk30akdN');

			$this->assertCount(2, $payments);
			$this->assertSame('tr_7UhSN1zuXS', $payments[0]->paymentReference);
			$this->assertSame(2000, $payments[0]->amount);
			$this->assertSame('EUR', $payments[0]->currency);
			$this->assertSame('Order #1', $payments[0]->description);
			$this->assertSame('stl_jDk30akdN', $payments[0]->settlementId);
			$this->assertSame(1975, $payments[1]->amount);
		}

		/**
		 * getSettlementPayments() throws PaymentSettlementException when the gateway reports an error.
		 * @return void
		 */
		public function testGetSettlementPaymentsThrowsOnGatewayError(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementPaymentsResponse([
				'request' => ['result' => 0, 'errorId' => '404', 'errorMessage' => 'Settlement not found']
			]);

			$this->expectException(PaymentSettlementException::class);
			$this->driverWithGateway($gateway)->getSettlementPayments('stl_unknown');
		}

		/**
		 * getSettlementRefunds() maps each refund to a SettlementRefundInfo, using
		 * the refunded `amount` (positive) and the refunded payment's id.
		 * @return void
		 */
		public function testGetSettlementRefundsMapsRefunds(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementRefundsResponse([
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					[
						'resource'         => 'refund',
						'id'               => 're_4qqhO89gsT',
						'amount'           => ['value' => '5.00', 'currency' => 'EUR'],
						'settlementAmount' => ['value' => '-5.00', 'currency' => 'EUR'],
						'description'      => 'Partial refund',
						'status'           => 'refunded',
						'createdAt'        => '2024-04-03T10:00:00+00:00',
						'paymentId'        => 'tr_7UhSN1zuXS',
						'settlementId'     => 'stl_jDk30akdN',
						'metadata'         => ['order' => '1']
					],
					[
						'resource'    => 'refund',
						'id'          => 're_9aXpK1zuWQ',
						'amount'      => ['value' => '19.75', 'currency' => 'EUR'],
						'description' => null,
						'status'      => 'failed',
						'createdAt'   => '2024-04-04T10:00:00+00:00',
						'paymentId'   => 'tr_WDqYK6vllg',
						'metadata'    => null
					]
				]
			]);

			$refunds = $this->driverWithGateway($gateway)->getSettlementRefunds('stl_jDk30akdN');

			$this->assertCount(2, $refunds);
			$this->assertSame('re_4qqhO89gsT', $refunds[0]->refundId);
			$this->assertSame('tr_7UhSN1zuXS', $refunds[0]->paymentReference);
			$this->assertSame(500, $refunds[0]->amount);
			$this->assertSame('EUR', $refunds[0]->currency);
			$this->assertSame('Partial refund', $refunds[0]->description);
			$this->assertSame('refunded', $refunds[0]->status);
			$this->assertSame('stl_jDk30akdN', $refunds[0]->settlementId);
			$this->assertSame(['order' => '1'], $refunds[0]->metadata);
			$this->assertSame('2024-04-03', $refunds[0]->createdAt?->format('Y-m-d'));
			$this->assertSame(1975, $refunds[1]->amount);
			$this->assertSame('', $refunds[1]->description);
			$this->assertSame('failed', $refunds[1]->status);
			$this->assertSame([], $refunds[1]->metadata);
		}

		/**
		 * getSettlementRefunds() throws PaymentSettlementException when the gateway reports an error.
		 * @return void
		 */
		public function testGetSettlementRefundsThrowsOnGatewayError(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementRefundsResponse([
				'request' => ['result' => 0, 'errorId' => '404', 'errorMessage' => 'Settlement not found']
			]);

			$this->expectException(PaymentSettlementException::class);
			$this->driverWithGateway($gateway)->getSettlementRefunds('stl_unknown');
		}

		/**
		 * getSettlementChargebacks() maps each chargeback, including the SEPA
		 * reason when present and reversedAt when the chargeback was reversed.
		 * @return void
		 */
		public function testGetSettlementChargebacksMapsChargebacks(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementChargebacksResponse([
				'request'  => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response' => [
					[
						'resource'         => 'chargeback',
						'id'               => 'chb_n9z0tp',
						'amount'           => ['value' => '19.00', 'currency' => 'EUR'],
						'settlementAmount' => ['value' => '-19.00', 'currency' => 'EUR'],
						'reason'           => ['code' => 'AC01', 'description' => 'Account identifier incorrect'],
						'createdAt'        => '2024-04-05T10:00:00+00:00',
						'reversedAt'       => null,
						'paymentId'        => 'tr_7UhSN1zuXS',
						'settlementId'     => 'stl_jDk30akdN'
					],
					[
						'resource'   => 'chargeback',
						'id'         => 'chb_x81kq',
						'amount'     => ['value' => '43.38', 'currency' => 'USD'],
						'reason'     => null,
						'createdAt'  => '2024-04-06T10:00:00+00:00',
						'reversedAt' => '2024-04-09T10:00:00+00:00',
						'paymentId'  => 'tr_WDqYK6vllg'
					]
				]
			]);

			$chargebacks = $this->driverWithGateway($gateway)->getSettlementChargebacks('stl_jDk30akdN');

			$this->assertCount(2, $chargebacks);
			$this->assertSame('chb_n9z0tp', $chargebacks[0]->chargebackId);
			$this->assertSame('tr_7UhSN1zuXS', $chargebacks[0]->paymentReference);
			$this->assertSame(1900, $chargebacks[0]->amount);
			$this->assertSame('AC01', $chargebacks[0]->reasonCode);
			$this->assertSame('Account identifier incorrect', $chargebacks[0]->reasonDescription);
			$this->assertNull($chargebacks[0]->reversedAt);
			$this->assertSame('stl_jDk30akdN', $chargebacks[0]->settlementId);
			$this->assertSame(4338, $chargebacks[1]->amount);
			$this->assertSame('USD', $chargebacks[1]->currency);
			$this->assertNull($chargebacks[1]->reasonCode);
			$this->assertSame('2024-04-09', $chargebacks[1]->reversedAt?->format('Y-m-d'));
		}

		/**
		 * getSettlementChargebacks() throws PaymentSettlementException when the gateway reports an error.
		 * @return void
		 */
		public function testGetSettlementChargebacksThrowsOnGatewayError(): void {
			$gateway = new FakeSettlementGateway();
			$gateway->setSettlementChargebacksResponse([
				'request' => ['result' => 0, 'errorId' => '404', 'errorMessage' => 'Settlement not found']
			]);

			$this->expectException(PaymentSettlementException::class);
			$this->driverWithGateway($gateway)->getSettlementChargebacks('stl_unknown');
		}
	}
