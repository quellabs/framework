<?php

	namespace Quellabs\Payments\Mollie\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Payments\Contracts\PaymentSettlementException;
	use Quellabs\Payments\Contracts\SettlementStatus;
	use Quellabs\Payments\Mollie\Driver;
	use Quellabs\Payments\Mollie\MollieGateway;

	/**
	 * Stub gateway returning a canned listSettlements() response, injected into
	 * Driver via reflection so its mapping/cursor logic can be tested without
	 * performing real HTTP calls.
	 * @phpstan-import-type MollieRawResponse from MollieGateway
	 */
	class FakeListSettlementsGateway extends MollieGateway {

		/** @var MollieRawResponse */
		private array $response = ['request' => ['result' => 0, 'errorId' => '', 'errorMessage' => ''], 'response' => [], 'statusCode' => 0];

		/**
		 * No network configuration needed for this test double.
		 */
		public function __construct() {
		}

		/**
		 * Sets the canned response returned by listSettlements().
		 * @param MollieRawResponse $response
		 * @return void
		 */
		public function setResponse(array $response): void {
			$this->response = $response;
		}

		/**
		 * Returns the canned response set via setResponse().
		 * @param ?string $cursor
		 * @param int $limit
		 * @return MollieRawResponse
		 */
		public function listSettlements(?string $cursor, int $limit): array {
			return $this->response;
		}
	}

	/**
	 * Confirms Driver::listSettlements() maps a page of Mollie's confirmed
	 * list-settlements response shape to SettlementListResult/SettlementInfo
	 * correctly, including cursor extraction and the unrecognized-status fallback.
	 */
	class DriverListSettlementsTest extends TestCase {

		/**
		 * Builds a Driver whose private gateway property is replaced with the given fake, via reflection.
		 * @param FakeListSettlementsGateway $gateway
		 * @return Driver
		 */
		private function driverWithGateway(FakeListSettlementsGateway $gateway): Driver {
			$driver = new Driver();

			$property = new \ReflectionProperty(Driver::class, 'gateway');
			$property->setAccessible(true);
			$property->setValue($driver, $gateway);

			return $driver;
		}

		/**
		 * A page with items and a next link maps every item and carries the
		 * next-page href through as nextCursor, unchanged.
		 * @return void
		 */
		public function testMapsItemsAndNextCursor(): void {
			$gateway = new FakeListSettlementsGateway();
			$gateway->setResponse([
				'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response'   => [
					'_embedded' => [
						'settlements' => [
							[
								'id'        => 'stl_jDk30akdN',
								'reference' => '1234567.2404.03',
								'status'    => 'paidout',
								'amount'    => ['value' => '39.75', 'currency' => 'EUR'],
								'settledAt' => '2024-04-03T10:00:00+00:00'
							],
							[
								'id'        => 'stl_a1b2c3',
								'reference' => '1234567.2404.10',
								'status'    => 'open',
								'amount'    => ['value' => '10.00', 'currency' => 'EUR']
							]
						]
					],
					'_links'    => ['next' => ['href' => 'https://api.mollie.nl/v2/settlements?from=stl_a1b2c3&limit=50']]
				],
				'statusCode' => 200
			]);

			$result = $this->driverWithGateway($gateway)->listSettlements();

			$this->assertCount(2, $result->settlements);
			$this->assertSame('stl_jDk30akdN', $result->settlements[0]->settlementId);
			$this->assertSame(SettlementStatus::PaidOut, $result->settlements[0]->status);
			$this->assertSame(3975, $result->settlements[0]->amount);
			$this->assertSame('stl_a1b2c3', $result->settlements[1]->settlementId);
			$this->assertSame(SettlementStatus::Open, $result->settlements[1]->status);
			$this->assertSame('https://api.mollie.nl/v2/settlements?from=stl_a1b2c3&limit=50', $result->nextCursor);
		}

		/**
		 * An unrecognized status string in a list item falls back to
		 * SettlementStatus::Unknown, same as getSettlement()'s mapping.
		 * @return void
		 */
		public function testUnrecognizedStatusFallsBackToUnknown(): void {
			$gateway = new FakeListSettlementsGateway();
			$gateway->setResponse([
				'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response'   => [
					'_embedded' => [
						'settlements' => [
							['id' => 'stl_1', 'reference' => 'ref', 'status' => 'some-future-status', 'amount' => ['value' => '1.00', 'currency' => 'EUR']]
						]
					]
				],
				'statusCode' => 200
			]);

			$result = $this->driverWithGateway($gateway)->listSettlements();

			$this->assertSame(SettlementStatus::Unknown, $result->settlements[0]->status);
		}

		/**
		 * A response with no _links.next gives a null nextCursor.
		 * @return void
		 */
		public function testNoNextLinkGivesNullCursor(): void {
			$gateway = new FakeListSettlementsGateway();
			$gateway->setResponse([
				'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response'   => ['_embedded' => ['settlements' => []]],
				'statusCode' => 200
			]);

			$result = $this->driverWithGateway($gateway)->listSettlements();

			$this->assertNull($result->nextCursor);
		}

		/**
		 * An empty settlements page returns an empty array, not an error.
		 * @return void
		 */
		public function testEmptyPageReturnsEmptyArray(): void {
			$gateway = new FakeListSettlementsGateway();
			$gateway->setResponse([
				'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response'   => ['_embedded' => ['settlements' => []]],
				'statusCode' => 200
			]);

			$result = $this->driverWithGateway($gateway)->listSettlements();

			$this->assertSame([], $result->settlements);
		}

		/**
		 * listSettlements() throws PaymentSettlementException when the gateway reports an error.
		 * @return void
		 */
		public function testThrowsOnGatewayError(): void {
			$gateway = new FakeListSettlementsGateway();
			$gateway->setResponse([
				'request'    => ['result' => 0, 'errorId' => '500', 'errorMessage' => 'Timeout'],
				'response'   => [],
				'statusCode' => 0
			]);

			$this->expectException(PaymentSettlementException::class);
			$this->driverWithGateway($gateway)->listSettlements();
		}
	}
