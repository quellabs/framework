<?php

	namespace Quellabs\Canvas\Tests\Exceptions;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\HttpException;
	use Quellabs\Canvas\Exceptions\MethodNotAllowedException;

	/**
	 * Unit tests for MethodNotAllowedException.
	 */
	class MethodNotAllowedExceptionTest extends TestCase {

		public function testDefaultsToStatus405(): void {
			$exception = new MethodNotAllowedException(['GET', 'POST']);

			$this->assertSame(405, $exception->getCode());
		}

		public function testExposesTheAllowedMethods(): void {
			$exception = new MethodNotAllowedException(['GET', 'HEAD', 'OPTIONS', 'POST']);

			$this->assertSame(['GET', 'HEAD', 'OPTIONS', 'POST'], $exception->getAllowedMethods());
		}

		public function testIsAnHttpException(): void {
			$exception = new MethodNotAllowedException(['GET']);

			$this->assertInstanceOf(HttpException::class, $exception);
		}
	}
