<?php

	namespace Quellabs\Payments\Mollie\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Payments\Mollie\MollieGateway;

	/**
	 * Replaces the real HTTP layer so the settlement refund/chargeback
	 * requests can be checked without network access, capturing the action
	 * of the first page requested and returning the given embedded collection.
	 *
	 * @phpstan-import-type MollieRawResponse from MollieGateway
	 */
	class CapturingSettlementDeductionsGatewayFake extends MollieGateway {

		public string $capturedAction = '';

		/** @var array<string, mixed> */
		private array $embedded;

		/**
		 * @param array<string, mixed> $embedded The `_embedded` block of the single page returned
		 */
		public function __construct(array $embedded) {
			$this->embedded = $embedded;
		}

		/**
		 * Records the action and returns one page with no next link.
		 * @param string $method
		 * @param string $action
		 * @param array<string, mixed> $data
		 * @param bool $expectNoContent
		 * @return MollieRawResponse
		 */
		protected function callHttpClientRaw(string $method, string $action, array $data = [], bool $expectNoContent = false): array {
			$this->capturedAction = $action;

			return [
				'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
				'response'   => ['_embedded' => $this->embedded, '_links' => []],
				'statusCode' => 200
			];
		}
	}

	/**
	 * Confirms MollieGateway requests the settlement refunds and chargebacks
	 * endpoints and returns the items of their `_embedded` collections.
	 */
	class MollieGatewaySettlementDeductionsTest extends TestCase {

		/**
		 * listSettlementRefunds() requests settlements/{id}/refunds and returns `_embedded.refunds`.
		 * @return void
		 */
		public function testListSettlementRefundsRequestsTheRefundsEndpoint(): void {
			$gateway = new CapturingSettlementDeductionsGatewayFake(['refunds' => [['id' => 're_1'], ['id' => 're_2']]]);
			$result = $gateway->listSettlementRefunds('stl_1');

			$this->assertSame('settlements/stl_1/refunds', $gateway->capturedAction);
			$this->assertSame([['id' => 're_1'], ['id' => 're_2']], $result['response'] ?? null);
		}

		/**
		 * listSettlementChargebacks() requests settlements/{id}/chargebacks and returns `_embedded.chargebacks`.
		 * @return void
		 */
		public function testListSettlementChargebacksRequestsTheChargebacksEndpoint(): void {
			$gateway = new CapturingSettlementDeductionsGatewayFake(['chargebacks' => [['id' => 'chb_1']]]);
			$result = $gateway->listSettlementChargebacks('stl_1');

			$this->assertSame('settlements/stl_1/chargebacks', $gateway->capturedAction);
			$this->assertSame([['id' => 'chb_1']], $result['response'] ?? null);
		}
	}
