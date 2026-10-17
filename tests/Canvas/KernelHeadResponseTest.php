<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Response;
	use Symfony\Component\HttpFoundation\StreamedResponse;

	/**
	 * Tests Kernel's HEAD body suppression, applied once at the single point every
	 * response passes through (Kernel::handle()) regardless of which route or
	 * error path produced it. Exercised directly via reflection against a handful
	 * of response shapes, since driving a real request through handle() would
	 * require controller discovery, which is exercised elsewhere.
	 */
	class KernelHeadResponseTest extends TestCase {

		private function suppress(Response $response): void {
			$kernel = new Kernel();
			$method = new \ReflectionMethod($kernel, 'suppressBodyForHeadRequest');
			$method->setAccessible(true);
			$method->invokeArgs($kernel, [$response]);
		}

		public function testStripsBodyAndSetsContentLengthFromTheOriginalBody(): void {
			$response = new Response('hello world', 200);

			$this->suppress($response);

			$this->assertSame('', $response->getContent());
			$this->assertSame('11', $response->headers->get('Content-Length'));
			$this->assertSame(200, $response->getStatusCode());
		}

		public function testPreservesAnExplicitlySetContentLength(): void {
			$response = new Response('abc', 200);
			$response->headers->set('Content-Length', '999');

			$this->suppress($response);

			$this->assertSame('999', $response->headers->get('Content-Length'));
		}

		public function testWorksOnJsonResponse(): void {
			$response = new JsonResponse(['a' => 1]);

			$this->suppress($response);

			$this->assertSame('', $response->getContent());
			$this->assertSame((string)strlen('{"a":1}'), $response->headers->get('Content-Length'));
		}

		public function testDoesNotInventAContentLengthForAnAlreadyEmptyBody(): void {
			$response = new Response('', 204);

			$this->suppress($response);

			$this->assertFalse($response->headers->has('Content-Length'));
		}

		public function testStreamedResponseCallbackNeverRuns(): void {
			$ran = false;
			$response = new StreamedResponse(function () use (&$ran) {
				$ran = true;
			});

			$this->suppress($response);
			$response->sendContent();

			$this->assertFalse($ran);
		}
	}
