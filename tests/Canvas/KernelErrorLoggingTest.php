<?php

	namespace Quellabs\Canvas\Tests;

	use Monolog\Logger;
	use PHPUnit\Framework\TestCase;
	use Psr\Log\LoggerInterface;
	use Quellabs\Canvas\Exceptions\RouteNotFoundException;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Tests\Fixtures\ErrorHandlers\AnsweringErrorHandler;
	use Quellabs\Canvas\Tests\Fixtures\ErrorHandlers\RethrowingErrorHandler;
	use Quellabs\Canvas\Tests\Fixtures\ErrorHandlers\ThrowingErrorHandler;
	use Quellabs\Contracts\Http\HttpException;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Which exceptions Kernel::createErrorResponse() logs, and where.
	 */
	class KernelErrorLoggingTest extends TestCase {

		private string $workDir;
		private string $logFile;
		private string|false $previousErrorLog;

		/** @var list<Kernel> Kernels built by this test, whose log files are closed in tearDown() */
		private array $kernels = [];

		/**
		 * Points the Kernel's log file and PHP's error_log at a scratch directory.
		 * @return void
		 */
		protected function setUp(): void {
			$this->workDir = sys_get_temp_dir() . '/canvas-error-logging-' . bin2hex(random_bytes(4));
			mkdir($this->workDir, 0755, true);
			$this->logFile = $this->workDir . '/canvas.log';
			$this->previousErrorLog = ini_set('error_log', $this->workDir . '/php-error.log');
		}

		/**
		 * Restores error_log and removes the scratch directory.
		 * @return void
		 */
		protected function tearDown(): void {
			// An open log file cannot be deleted on Windows
			foreach ($this->kernels as $kernel) {
				$logger = $kernel->getDependencyInjector()->get(LoggerInterface::class);

				if ($logger instanceof Logger) {
					$logger->close();
				}
			}

			ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);

			foreach (glob($this->workDir . '/*') ?: [] as $file) {
				if (is_file($file)) {
					unlink($file);
				}
			}

			rmdir($this->workDir);
		}

		/**
		 * A Kernel logging to $monologFile, with $errorHandlers as its custom handlers.
		 * @param string $monologFile
		 * @param list<class-string> $errorHandlers
		 * @return Kernel
		 */
		private function kernel(string $monologFile, array $errorHandlers = []): Kernel {
			$kernel = new Kernel(['monolog_file' => $monologFile, 'debug_mode' => false]);

			$property = new \ReflectionProperty($kernel, 'errorHandlers');
			$property->setValue($kernel, $errorHandlers);
			$this->kernels[] = $kernel;

			return $kernel;
		}

		/**
		 * The contents of $file, or '' when it was never written.
		 * @param string $file
		 * @return string
		 */
		private function read(string $file): string {
			return is_file($file) ? (string)file_get_contents($file) : '';
		}

		/**
		 * An unhandled exception is logged with its message, the request and its stack trace.
		 * @return void
		 */
		public function testAnUnhandledServerErrorIsLoggedWithTheRequestAndTrace(): void {
			$response = $this->kernel($this->logFile)->createErrorResponse(new \RuntimeException('Tally exploded'), Request::create('/billing/checkout', 'POST'));

			$log = $this->read($this->logFile);
			$this->assertSame(500, $response->getStatusCode());
			$this->assertStringContainsString('ERROR: Unhandled exception', $log);
			$this->assertStringContainsString('Tally exploded', $log);
			$this->assertStringContainsString('"method":"POST"', $log);
			$this->assertStringContainsString('"uri":"/billing/checkout"', $log);
			$this->assertStringContainsString('#0 ', $log, 'The stack trace is included.');
		}

		/**
		 * A client error such as a missing route is not logged.
		 * @return void
		 */
		public function testAClientErrorIsNotLogged(): void {
			$response = $this->kernel($this->logFile)->createErrorResponse(new RouteNotFoundException('No route'), Request::create('/nowhere'));

			$this->assertSame(404, $response->getStatusCode());
			$this->assertSame('', $this->read($this->logFile));
		}

		/**
		 * An HttpException with a 4xx code is a client error and is not logged.
		 * @return void
		 */
		public function testAnHttpExceptionWithAClientErrorCodeIsNotLogged(): void {
			$response = $this->kernel($this->logFile)->createErrorResponse(new HttpException('Forbidden', 403), Request::create('/admin'));

			$this->assertSame(403, $response->getStatusCode());
			$this->assertSame('', $this->read($this->logFile));
		}

		/**
		 * An HttpException with a 5xx code is a server error and is logged.
		 * @return void
		 */
		public function testAnHttpExceptionWithAServerErrorCodeIsLogged(): void {
			$response = $this->kernel($this->logFile)->createErrorResponse(new HttpException('Store down', 503), Request::create('/'));

			$this->assertSame(503, $response->getStatusCode());
			$this->assertStringContainsString('Store down', $this->read($this->logFile));
		}

		/**
		 * Another exception's 4xx code says nothing about who is at fault, so it is
		 * logged even though the response takes that code as its status.
		 * @return void
		 */
		public function testAnInternalExceptionWithAClientErrorCodeIsLogged(): void {
			$response = $this->kernel($this->logFile)->createErrorResponse(new \RuntimeException('Upstream API failed', 404), Request::create('/'));

			$this->assertSame(404, $response->getStatusCode());
			$this->assertStringContainsString('Upstream API failed', $this->read($this->logFile));
		}

		/**
		 * The query string, which can carry tokens, is left out of the logged URI.
		 * @return void
		 */
		public function testTheQueryStringIsNotLogged(): void {
			$this->kernel($this->logFile)->createErrorResponse(new \RuntimeException('Reset failed'), Request::create('/password/reset?token=abc123'));

			$log = $this->read($this->logFile);
			$this->assertStringContainsString('"uri":"/password/reset"', $log);
			$this->assertStringNotContainsString('abc123', $log);
		}

		/**
		 * An exception a custom handler answers is left to that handler.
		 * @return void
		 */
		public function testAnExceptionACustomHandlerAnswersIsNotLogged(): void {
			$response = $this->kernel($this->logFile, [AnsweringErrorHandler::class])
				->createErrorResponse(new \RuntimeException('handled elsewhere'), Request::create('/'));

			$this->assertSame(503, $response->getStatusCode());
			$this->assertSame('', $this->read($this->logFile));
		}

		/**
		 * A failing custom handler is logged, and so is the exception it failed on.
		 * @return void
		 */
		public function testAFailingCustomHandlerIsLoggedAlongWithTheOriginalException(): void {
			$this->kernel($this->logFile, [ThrowingErrorHandler::class])
				->createErrorResponse(new \RuntimeException('original error'), Request::create('/'));

			$log = $this->read($this->logFile);
			$this->assertStringContainsString('ERROR: Error handler failed', $log);
			$this->assertStringContainsString('Broken custom error handler', $log);
			$this->assertStringContainsString('ERROR: Unhandled exception', $log);
			$this->assertStringContainsString('original error', $log);
		}

		/**
		 * A custom handler rethrowing the exception it was given yields one entry,
		 * for the unhandled exception, not a second one for the handler.
		 * @return void
		 */
		public function testARethrowingCustomHandlerIsLoggedOnce(): void {
			$this->kernel($this->logFile, [RethrowingErrorHandler::class])
				->createErrorResponse(new \RuntimeException('passed on'), Request::create('/'));

			$log = $this->read($this->logFile);
			$this->assertSame(1, substr_count($log, 'passed on'));
			$this->assertStringContainsString('ERROR: Unhandled exception', $log);
			$this->assertStringNotContainsString('Error handler failed', $log);
		}

		/**
		 * When the log file cannot be written, the entry goes to PHP's error_log
		 * and the error page is still produced.
		 * @return void
		 */
		public function testFallsBackToErrorLogWhenTheLogFileCannotBeWritten(): void {
			// A path below a regular file can never be created
			$blocker = $this->workDir . '/not-a-directory';
			file_put_contents($blocker, '');

			$response = $this->kernel($blocker . '/canvas.log')->createErrorResponse(new \RuntimeException('no log file'), Request::create('/x', 'GET'));

			$fallback = $this->read($this->workDir . '/php-error.log');
			$this->assertSame(500, $response->getStatusCode());
			$this->assertStringContainsString('Unhandled exception: RuntimeException: no log file', $fallback);
			$this->assertStringContainsString('(GET /x)', $fallback);
		}
	}
