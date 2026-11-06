<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Legacy\LegacyHandler;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * $__canvas_headers is a process-wide global that LegacyHelper.php (require_once'd
	 * by the preprocessed file) only initializes once per process. LegacyHandler must
	 * reset it at the start of every executeLegacyFile() call - otherwise, under a
	 * persistent worker that reuses the process across requests, one request's headers
	 * (e.g. a redirect Location) would leak into an unrelated later request's response.
	 */
	class LegacyHandlerHeaderLeakTest extends TestCase {
		private string $legacyPath;
		private LegacyHandler $handler;

		protected function setUp(): void {
			$this->legacyPath = sys_get_temp_dir() . '/legacy-header-leak-' . uniqid('', true);
			mkdir($this->legacyPath, 0777, true);

			file_put_contents(
				$this->legacyPath . '/redirect.php',
				"<?php header('Location: /evil'); exit;"
			);
			file_put_contents(
				$this->legacyPath . '/plain.php',
				'<?php echo "plain page";'
			);

			$this->handler = new LegacyHandler($this->legacyPath);
		}

		protected function tearDown(): void {
			$this->handler->clearCache();
			$this->removeDirectory($this->legacyPath);
		}

		private function removeDirectory(string $directory): void {
			foreach (glob($directory . '/*') ?: [] as $path) {
				is_dir($path) ? $this->removeDirectory($path) : unlink($path);
			}

			rmdir($directory);
		}

		public function testHeadersDoNotLeakIntoANextRequestOnTheSameProcess(): void {
			$redirectResponse = $this->handler->handle(Request::create('/redirect'));
			$this->assertSame(302, $redirectResponse->getStatusCode());
			$this->assertSame('/evil', $redirectResponse->headers->get('Location'));

			// A second, unrelated request on the same process must not inherit
			// the previous request's Location header or redirect status.
			$plainResponse = $this->handler->handle(Request::create('/plain'));
			$this->assertSame('plain page', $plainResponse->getContent());
			$this->assertSame(200, $plainResponse->getStatusCode());
			$this->assertNull($plainResponse->headers->get('Location'));
		}
	}
