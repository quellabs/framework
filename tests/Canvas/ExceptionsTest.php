<?php
	
	namespace Quellabs\Canvas\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\CsrfTokenException;
	use Quellabs\Canvas\Exceptions\HttpException;
	use Quellabs\Canvas\Exceptions\RateLimitException;
	use Quellabs\Canvas\Exceptions\RouteNotFoundException;
	use Quellabs\Canvas\Exceptions\UploadException;
	
	/**
	 * Unit tests for Canvas HTTP exception types.
	 *
	 * Verifies inheritance contracts, constructor parameters, and accessor methods.
	 */
	class ExceptionsTest extends TestCase {
		
		// -------------------------------------------------------------------------
		// HttpException (base)
		// -------------------------------------------------------------------------
		
		public function testHttpExceptionIsRuntimeException(): void {
			$e = new HttpException('Something went wrong');
			$this->assertInstanceOf(\RuntimeException::class, $e);
		}
		
		public function testHttpExceptionCarriesMessage(): void {
			$e = new HttpException('Bad request');
			$this->assertSame('Bad request', $e->getMessage());
		}
		
		public function testHttpExceptionCarriesCode(): void {
			$e = new HttpException('Forbidden', 403);
			$this->assertSame(403, $e->getCode());
		}
		
		// -------------------------------------------------------------------------
		// CsrfTokenException
		// -------------------------------------------------------------------------
		
		public function testCsrfTokenExceptionExtendsHttpException(): void {
			$e = new CsrfTokenException('Invalid CSRF token');
			$this->assertInstanceOf(HttpException::class, $e);
		}
		
		public function testCsrfTokenExceptionCarriesMessage(): void {
			$e = new CsrfTokenException('Token mismatch');
			$this->assertSame('Token mismatch', $e->getMessage());
		}
		
		// -------------------------------------------------------------------------
		// RouteNotFoundException
		// -------------------------------------------------------------------------
		
		public function testRouteNotFoundExceptionExtendsHttpException(): void {
			$e = new RouteNotFoundException('Route not found');
			$this->assertInstanceOf(HttpException::class, $e);
		}
		
		public function testRouteNotFoundExceptionCarriesMessage(): void {
			$e = new RouteNotFoundException('/missing/path');
			$this->assertSame('/missing/path', $e->getMessage());
		}
		
		// -------------------------------------------------------------------------
		// RateLimitException
		// -------------------------------------------------------------------------
		
		public function testRateLimitExceptionExtendsHttpException(): void {
			$e = $this->buildRateLimitException();
			$this->assertInstanceOf(HttpException::class, $e);
		}
		
		public function testRateLimitExceptionDefaultCode429(): void {
			$e = $this->buildRateLimitException();
			$this->assertSame(429, $e->getCode());
		}
		
		public function testRateLimitExceptionGetLimit(): void {
			$e = $this->buildRateLimitException(limit: 100);
			$this->assertSame(100, $e->getLimit());
		}
		
		public function testRateLimitExceptionGetRemaining(): void {
			$e = $this->buildRateLimitException(remaining: 0);
			$this->assertSame(0, $e->getRemaining());
		}
		
		public function testRateLimitExceptionGetRetryAfter(): void {
			$e = $this->buildRateLimitException(retryAfter: 60);
			$this->assertSame(60, $e->getRetryAfter());
		}
		
		public function testRateLimitExceptionGetResetTime(): void {
			$reset = time() + 3600;
			$e     = $this->buildRateLimitException(resetTime: $reset);
			$this->assertSame($reset, $e->getResetTime());
		}
		
		public function testRateLimitExceptionGetStrategy(): void {
			$e = $this->buildRateLimitException(strategy: 'sliding_window');
			$this->assertSame('sliding_window', $e->getStrategy());
		}
		
		public function testRateLimitExceptionGetScope(): void {
			$e = $this->buildRateLimitException(scope: 'ip');
			$this->assertSame('ip', $e->getScope());
		}
		
		public function testRateLimitExceptionGetHeaders(): void {
			$headers = ['X-RateLimit-Limit' => 100, 'Retry-After' => 60];
			$e       = $this->buildRateLimitException(headers: $headers);
			$this->assertSame($headers, $e->getHeaders());
		}
		
		public function testRateLimitExceptionGetHeadersDefaultsToEmptyArray(): void {
			$e = $this->buildRateLimitException();
			$this->assertSame([], $e->getHeaders());
		}
		
		public function testRateLimitExceptionCustomMessageIsUsed(): void {
			$e = new RateLimitException(
				limit: 10,
				remaining: 0,
				retryAfter: 30,
				resetTime: time() + 30,
				strategy: 'fixed',
				scope: 'user',
				headers: [],
				message: 'Too many requests, calm down.'
			);
			$this->assertSame('Too many requests, calm down.', $e->getMessage());
		}
		
		// -------------------------------------------------------------------------
		// UploadException
		// -------------------------------------------------------------------------
		
		public function testUploadExceptionExtendsHttpException(): void {
			$e = new UploadException('Upload failed');
			$this->assertInstanceOf(HttpException::class, $e);
		}
		
		public function testUploadExceptionCarriesMessage(): void {
			$e = new UploadException('File too large');
			$this->assertSame('File too large', $e->getMessage());
		}
		
		public function testUploadExceptionGetProcessedFilesDefaultsToEmptyArray(): void {
			$e = new UploadException('Failed');
			$this->assertSame([], $e->getProcessedFiles());
		}
		
		public function testUploadExceptionGetProcessedFilesReturnsProvidedData(): void {
			$files = [
				'avatar' => [
					0 => ['success' => false, 'errors' => ['File exceeds maximum size']],
				],
			];
			$e = new UploadException('Failed', $files);
			$this->assertSame($files, $e->getProcessedFiles());
		}
		
		public function testUploadExceptionGetBatchErrorDefaultsToNull(): void {
			$e = new UploadException('Failed');
			$this->assertNull($e->getBatchError());
		}
		
		public function testUploadExceptionGetBatchErrorReturnsProvidedString(): void {
			$e = new UploadException('Failed', [], 'Too many files uploaded');
			$this->assertSame('Too many files uploaded', $e->getBatchError());
		}
		
		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------
		
		private function buildRateLimitException(
			int    $limit = 100,
			int    $remaining = 0,
			int    $retryAfter = 60,
			int    $resetTime = 0,
			string $strategy = 'fixed',
			string $scope = 'ip',
			array  $headers = [],
		): RateLimitException {
			return new RateLimitException(
				limit: $limit,
				remaining: $remaining,
				retryAfter: $retryAfter,
				resetTime: $resetTime ?: time() + 3600,
				strategy: $strategy,
				scope: $scope,
				headers: $headers,
			);
		}
	}