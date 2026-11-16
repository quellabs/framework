<?php

	namespace Quellabs\Payments\Mollie\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Payments\Mollie\MollieGateway;

	/**
	 * Exposes MollieGateway's protected pagination helper and replaces the real
	 * HTTP layer with a queue of canned raw responses, so pagination logic can
	 * be exercised without network access.
	 */
	class PaginatingGatewayFake extends MollieGateway {

		/** @var array<int, array{request: array{result: int, errorId: int|string, errorMessage: string}, response: array<string, mixed>, statusCode: int}> */
		private array $responses;

		/** @var int */
		private int $callIndex = 0;

		/**
		 * Stores the canned raw responses, returned one per call in order.
		 * @param array<int, array{request: array{result: int, errorId: int|string, errorMessage: string}, response: array<string, mixed>, statusCode: int}> $responses
		 */
		public function __construct(array $responses) {
			$this->responses = $responses;
		}

		/**
		 * Returns the next canned response in order, ignoring the real request arguments.
		 * @param string $method
		 * @param string $action
		 * @param array<string, mixed> $data
		 * @param bool $expectNoContent
		 * @return array{request: array{result: int, errorId: int|string, errorMessage: string}, response: array<string, mixed>, statusCode: int}
		 */
		protected function callHttpClientRaw(string $method, string $action, array $data = [], bool $expectNoContent = false): array {
			$response = $this->responses[$this->callIndex] ?? [
				'request'    => ['result' => 0, 'errorId' => 'no_fixture', 'errorMessage' => 'No fixture configured for call ' . $this->callIndex],
				'response'   => [],
				'statusCode' => 0
			];

			$this->callIndex++;

			return $response;
		}

		/**
		 * Exposes the protected callHttpClientPaginated() for testing.
		 * @param string $method
		 * @param string $action
		 * @return array{request: array{result: int, errorId: int|string, errorMessage: string}, response?: array<int, mixed>}
		 */
		public function paginate(string $method, string $action): array {
			return $this->callHttpClientPaginated($method, $action);
		}
	}

	/**
	 * Covers MollieGateway::callHttpClientPaginated() across zero, one, and
	 * three-plus pages, and confirms a failed later page returns what was
	 * already accumulated instead of discarding it.
	 */
	class MollieGatewayPaginationTest extends TestCase {

		/**
		 * A single page with an empty _embedded list accumulates no items.
		 * @return void
		 */
		public function testSinglePageWithNoItemsReturnsEmptyArray(): void {
			$gateway = new PaginatingGatewayFake([
				['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => ['_embedded' => ['payments' => []]], 'statusCode' => 200]
			]);

			$result = $gateway->paginate('GET', 'settlements/stl_1/payments');

			$this->assertSame(1, $result['request']['result']);
			$this->assertSame([], $result['response'] ?? null);
		}

		/**
		 * A single page with no next link returns exactly its own items.
		 * @return void
		 */
		public function testSinglePageReturnsItsItems(): void {
			$gateway = new PaginatingGatewayFake([
				['request' => ['result' => 1, 'errorId' => '', 'errorMessage' => ''], 'response' => ['_embedded' => ['payments' => [['id' => 'tr_1'], ['id' => 'tr_2']]]], 'statusCode' => 200]
			]);

			$result = $gateway->paginate('GET', 'settlements/stl_1/payments');

			$this->assertSame(1, $result['request']['result']);
			$this->assertSame(['tr_1', 'tr_2'], array_column($result['response'] ?? [], 'id'));
		}

		/**
		 * Three pages, each linking to the next, are followed and their items merged in order.
		 * @return void
		 */
		public function testThreePagesAreAccumulatedInOrder(): void {
			$gateway = new PaginatingGatewayFake([
				[
					'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
					'response'   => [
						'_embedded' => ['payments' => [['id' => 'tr_1'], ['id' => 'tr_2']]],
						'_links'    => ['next' => ['href' => 'https://api.mollie.nl/v2/settlements/stl_1/payments?from=tr_3']]
					],
					'statusCode' => 200
				],
				[
					'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
					'response'   => [
						'_embedded' => ['payments' => [['id' => 'tr_3'], ['id' => 'tr_4']]],
						'_links'    => ['next' => ['href' => 'https://api.mollie.nl/v2/settlements/stl_1/payments?from=tr_5']]
					],
					'statusCode' => 200
				],
				[
					'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
					'response'   => ['_embedded' => ['payments' => [['id' => 'tr_5']]]],
					'statusCode' => 200
				]
			]);

			$result = $gateway->paginate('GET', 'settlements/stl_1/payments');

			$this->assertSame(1, $result['request']['result']);
			$this->assertSame(['tr_1', 'tr_2', 'tr_3', 'tr_4', 'tr_5'], array_column($result['response'] ?? [], 'id'));
		}

		/**
		 * A later page failing after the first succeeded returns the items already accumulated, not an error.
		 * @return void
		 */
		public function testFailedLaterPageReturnsPagesAlreadyAccumulated(): void {
			$gateway = new PaginatingGatewayFake([
				[
					'request'    => ['result' => 1, 'errorId' => '', 'errorMessage' => ''],
					'response'   => [
						'_embedded' => ['payments' => [['id' => 'tr_1']]],
						'_links'    => ['next' => ['href' => 'https://api.mollie.nl/v2/settlements/stl_1/payments?from=tr_2']]
					],
					'statusCode' => 200
				],
				[
					'request'    => ['result' => 0, 'errorId' => '500', 'errorMessage' => 'Timeout'],
					'response'   => [],
					'statusCode' => 0
				]
			]);

			$result = $gateway->paginate('GET', 'settlements/stl_1/payments');

			$this->assertSame(1, $result['request']['result']);
			$this->assertSame(['tr_1'], array_column($result['response'] ?? [], 'id'));
		}

		/**
		 * The first page failing with nothing accumulated yet returns the error as-is.
		 * @return void
		 */
		public function testFailedFirstPageReturnsTheError(): void {
			$gateway = new PaginatingGatewayFake([
				['request' => ['result' => 0, 'errorId' => '500', 'errorMessage' => 'Timeout'], 'response' => [], 'statusCode' => 0]
			]);

			$result = $gateway->paginate('GET', 'settlements/stl_1/payments');

			$this->assertSame(0, $result['request']['result']);
			$this->assertSame('Timeout', $result['request']['errorMessage']);
			$this->assertArrayNotHasKey('response', $result);
		}
	}
