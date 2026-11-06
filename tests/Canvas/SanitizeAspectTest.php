<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Sanitization\Contracts\SanitizationInterface;
	use Quellabs\Canvas\Sanitization\Rules\PathSafe;
	use Quellabs\Canvas\Sanitization\SanitizeAspect;
	use Quellabs\Contracts\AOP\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Unit tests for SanitizeAspect.
	 *
	 * Sanitized values are exposed only through the dedicated 'sanitized' request
	 * attribute; the request's own POST/GET data is intentionally left untouched,
	 * so callers must explicitly read $request->attributes->get('sanitized').
	 */
	class SanitizeAspectTest extends TestCase {

		private function makeContext(Request $request): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn($request);
			return $context;
		}

		private function makeDi(SanitizationInterface $sanitizer): ContainerInterface {
			$di = $this->createMock(ContainerInterface::class);
			$di->method('make')->willReturn($sanitizer);
			return $di;
		}

		public function testSanitizedAttributeCarriesCleanedValue(): void {
			$sanitizer = new class implements SanitizationInterface {
				public function getRules(): array {
					return ['path' => [new PathSafe()]];
				}
			};

			$request = new Request(['path' => '../etc/passwd'], ['path' => '../etc/passwd']);
			$context = $this->makeContext($request);

			$aspect = new SanitizeAspect($this->makeDi($sanitizer), get_class($sanitizer));
			$aspect->before($context);

			$sanitized = $request->attributes->get('sanitized');
			$this->assertSame('etc/passwd', $sanitized['query']['path']);
			$this->assertSame('etc/passwd', $sanitized['post']['path']);
			$this->assertSame('etc/passwd', $sanitized['merged']['path']);

			// The request's own data is left untouched by design.
			$this->assertSame('../etc/passwd', $request->query->get('path'));
			$this->assertSame('../etc/passwd', $request->request->get('path'));
		}

		public function testFieldsWithoutRulesAreLeftUnchanged(): void {
			$sanitizer = new class implements SanitizationInterface {
				public function getRules(): array {
					return ['path' => [new PathSafe()]];
				}
			};

			$request = new Request(['other' => 'untouched'], []);
			$context = $this->makeContext($request);

			$aspect = new SanitizeAspect($this->makeDi($sanitizer), get_class($sanitizer));
			$aspect->before($context);

			$this->assertSame('untouched', $request->attributes->get('sanitized')['query']['other']);
		}
	}
