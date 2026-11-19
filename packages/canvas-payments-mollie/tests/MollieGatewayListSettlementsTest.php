<?php

	namespace Quellabs\Payments\Mollie\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Contracts\Gateway\GatewayInterface;
	use Quellabs\Payments\Mollie\MollieGateway;

	/**
	 * Replaces the real HTTP layer so listSettlements()'s request-building logic
	 * can be exercised without network access, while capturing the exact
	 * method/action it was called with.
	 *
	 * @phpstan-import-type MollieRawResponse from MollieGateway
	 * @phpstan-import-type GatewayResponse from GatewayInterface
	 */
	class CapturingListSettlementsGatewayFake extends MollieGateway {

		public string $capturedMethod = '';
		public string $capturedAction = '';

		/**
		 * No network configuration needed for this test double.
		 */
		public function __construct() {
		}

		/**
		 * Records the call and returns a canned, empty-but-successful response.
		 * @param string $method
		 * @param string $action
		 * @param array<string, mixed> $data
		 * @param bool $expectNoContent
		 * @return MollieRawResponse
		 */
		protected function callHttpClientRaw(string $method, string $action, array $data = [], bool $expectNoContent = false): array {
			$this->capturedMethod = $method;
			$this->capturedAction = $action;

			return ['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => [], 'statusCode' => 200];
		}
	}

	/**
	 * Confirms MollieGateway::listSettlements() builds the correct request for
	 * each of the three cursor shapes the plan describes: no cursor, a bare
	 * settlement-id cursor, and an absolute next-page URL.
	 */
	class MollieGatewayListSettlementsTest extends TestCase {

		/**
		 * First page (no cursor): the request is built without a `from` parameter.
		 * @return void
		 */
		public function testFirstPageOmitsFromParameter(): void {
			$gateway = new CapturingListSettlementsGatewayFake();
			$gateway->listSettlements(null, 50);

			$this->assertSame('GET', $gateway->capturedMethod);
			$this->assertSame('settlements?limit=50', $gateway->capturedAction);
		}

		/**
		 * A bare settlement-id cursor is sent as Mollie's own `from` parameter.
		 * @return void
		 */
		public function testBareIdCursorAddsFromParameter(): void {
			$gateway = new CapturingListSettlementsGatewayFake();
			$gateway->listSettlements('stl_jDk30akdN', 25);

			$this->assertSame('settlements?limit=25&from=stl_jDk30akdN', $gateway->capturedAction);
		}

		/**
		 * An absolute next-page URL cursor (as returned by a previous call's
		 * nextCursor) is requested directly, with no limit re-appended.
		 * @return void
		 */
		public function testAbsoluteUrlCursorIsRequestedDirectly(): void {
			$gateway = new CapturingListSettlementsGatewayFake();
			$url     = 'https://api.mollie.nl/v2/settlements?from=stl_999&limit=50';
			$gateway->listSettlements($url, 50);

			$this->assertSame($url, $gateway->capturedAction);
		}
	}
