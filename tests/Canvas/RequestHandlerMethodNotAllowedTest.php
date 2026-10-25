<?php

	namespace Quellabs\Canvas\Tests\Routing;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Exceptions\MethodNotAllowedException;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Routing\RequestHandler;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Tests how RequestHandler reacts once a MethodNotAllowedException is already
	 * in hand: legacy (file-based, method-agnostic) gets first refusal, and only
	 * once legacy also has nothing does Canvas act on its own match — either by
	 * synthesizing an OPTIONS response or by surfacing the 405 for any other method.
	 *
	 * The exception is constructed directly and fed to the private resolver method
	 * via reflection, rather than routed in through a real annotated controller:
	 * controller discovery is exercised elsewhere, this isolates the legacy/405/
	 * OPTIONS decision itself. The legacy side is real (actual files on disk),
	 * since that part of the behavior has nothing to do with controller discovery.
	 */
	class RequestHandlerMethodNotAllowedTest extends TestCase {

		private string $legacyDir;

		protected function setUp(): void {
			$this->legacyDir = sys_get_temp_dir() . '/canvas_legacy_test_' . bin2hex(random_bytes(8));
			mkdir($this->legacyDir, 0755, true);
		}

		protected function tearDown(): void {
			foreach (glob($this->legacyDir . '/*') ?: [] as $file) {
				unlink($file);
			}

			rmdir($this->legacyDir);
		}

		/**
		 * @param list<string> $args
		 */
		private function invokeResolveMethodNotAllowed(RequestHandler $handler, array $args): mixed {
			$method = new \ReflectionMethod($handler, 'resolveMethodNotAllowed');
			$method->setAccessible(true);
			return $method->invokeArgs($handler, $args);
		}

		public function testLegacyFileWinsOverTheCanvas405(): void {
			file_put_contents($this->legacyDir . '/ping.php', "<?php echo 'legacy';");

			$kernel = new Kernel(['legacy_enabled' => true, 'legacy_path' => $this->legacyDir]);
			$handler = new RequestHandler($kernel);
			$exception = new MethodNotAllowedException(['GET', 'HEAD', 'OPTIONS', 'POST']);

			$response = $this->invokeResolveMethodNotAllowed($handler, [Request::create('/ping', 'DELETE'), $exception]);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('legacy', $response->getContent());
		}

		public function testCanvas405SurfacesWhenLegacyHasNoMatchingFileEither(): void {
			$kernel = new Kernel(['legacy_enabled' => true, 'legacy_path' => $this->legacyDir]);
			$handler = new RequestHandler($kernel);
			$exception = new MethodNotAllowedException(['GET', 'HEAD', 'OPTIONS', 'POST']);

			$this->expectException(MethodNotAllowedException::class);
			$this->invokeResolveMethodNotAllowed($handler, [Request::create('/ping', 'DELETE'), $exception]);
		}

		public function testOptionsIsSynthesizedWhenLegacyHasNoMatchingFile(): void {
			$kernel = new Kernel(['legacy_enabled' => true, 'legacy_path' => $this->legacyDir]);
			$handler = new RequestHandler($kernel);
			$exception = new MethodNotAllowedException(['GET', 'HEAD', 'OPTIONS', 'POST']);

			$response = $this->invokeResolveMethodNotAllowed($handler, [Request::create('/ping', 'OPTIONS'), $exception]);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('', $response->getContent());
			$this->assertSame('GET, HEAD, OPTIONS, POST', $response->headers->get('Allow'));
		}

		public function testOptionsIsSynthesizedImmediatelyWhenLegacyIsDisabled(): void {
			$kernel = new Kernel(['legacy_enabled' => false]);
			$handler = new RequestHandler($kernel);
			$exception = new MethodNotAllowedException(['GET', 'HEAD', 'OPTIONS', 'POST']);

			$response = $this->invokeResolveMethodNotAllowed($handler, [Request::create('/ping', 'OPTIONS'), $exception]);

			$this->assertSame(200, $response->getStatusCode());
			$this->assertSame('GET, HEAD, OPTIONS, POST', $response->headers->get('Allow'));
		}

		public function testCanvas405SurfacesImmediatelyWhenLegacyIsDisabled(): void {
			$kernel = new Kernel(['legacy_enabled' => false]);
			$handler = new RequestHandler($kernel);
			$exception = new MethodNotAllowedException(['GET', 'HEAD', 'OPTIONS', 'POST']);

			$this->expectException(MethodNotAllowedException::class);
			$this->invokeResolveMethodNotAllowed($handler, [Request::create('/ping', 'DELETE'), $exception]);
		}
	}
