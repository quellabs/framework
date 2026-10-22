<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\CompositeSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\CorsSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\NullSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\StrictSecurityHeaderPolicy;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Tests Kernel's resolution of config/app.php's security_header_policy:
	 * the key must resolve to a real SecurityHeaderPolicyInterface, so an
	 * explicit null, a nonexistent class, and a class not implementing the
	 * interface are all rejected rather than treated as "disabled".
	 */
	class KernelSecurityHeaderPolicyTest extends TestCase {

		public function testFallsBackToTheBasicPolicyWhenTheKeyIsOmitted(): void {
			$kernel = new Kernel();

			$this->assertInstanceOf(BasicSecurityHeaderPolicy::class, $kernel->getDefaultSecurityHeaderPolicy());
		}

		public function testAnExplicitClassOverridesTheDefault(): void {
			$kernel = new Kernel(['security_header_policy' => StrictSecurityHeaderPolicy::class]);

			$this->assertInstanceOf(StrictSecurityHeaderPolicy::class, $kernel->getDefaultSecurityHeaderPolicy());
		}

		public function testNullSecurityHeaderPolicyOptsOutExplicitly(): void {
			$kernel = new Kernel(['security_header_policy' => NullSecurityHeaderPolicy::class]);

			$this->assertInstanceOf(NullSecurityHeaderPolicy::class, $kernel->getDefaultSecurityHeaderPolicy());
		}

		public function testExplicitNullIsRejected(): void {
			$this->expectException(\RuntimeException::class);

			new Kernel(['security_header_policy' => null]);
		}

		public function testANonexistentClassIsRejected(): void {
			$this->expectException(\RuntimeException::class);

			new Kernel(['security_header_policy' => 'NonexistentSecurityHeaderPolicy']);
		}

		public function testAClassNotImplementingTheInterfaceIsRejected(): void {
			$this->expectException(\RuntimeException::class);

			new Kernel(['security_header_policy' => \stdClass::class]);
		}

		public function testAnOrderedArrayAppliesEachPolicyInDeclaredOrder(): void {
			// CorsSecurityHeaderPolicy requires ConfigProviderInterface, bound by the
			// kernel's own container, so including it also proves array entries are
			// resolved through the container rather than instantiated with `new`.
			$kernel = new Kernel(['security_header_policy' => [
				StrictSecurityHeaderPolicy::class,
				BasicSecurityHeaderPolicy::class,
				CorsSecurityHeaderPolicy::class,
			]]);

			$policy = $kernel->getDefaultSecurityHeaderPolicy();
			$this->assertInstanceOf(CompositeSecurityHeaderPolicy::class, $policy);

			$response = new Response();
			$policy->apply(Request::create('/'), $response);

			// Strict runs first and sets DENY; Basic's own addFrameOptions() only sets
			// a header when one isn't already present, so declaration order wins.
			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
		}

		public function testAnEmptyArrayIsRejectedAndNamesTheSettingWithoutAnIndex(): void {
			try {
				new Kernel(['security_header_policy' => []]);
				$this->fail('Expected a RuntimeException.');
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString("config/app.php's security_header_policy", $e->getMessage());
				$this->assertStringContainsString('nonempty list', $e->getMessage());
				$this->assertStringNotContainsString('[', $e->getMessage());
			}
		}

		public function testAnAssociativeArrayIsRejected(): void {
			$this->expectException(\RuntimeException::class);

			new Kernel(['security_header_policy' => ['basic' => BasicSecurityHeaderPolicy::class]]);
		}

		public function testANestedArrayEntryIsRejected(): void {
			$this->expectException(\RuntimeException::class);

			new Kernel(['security_header_policy' => [[BasicSecurityHeaderPolicy::class]]]);
		}

		public function testAnArrayEntryThatIsAMissingClassIsRejected(): void {
			try {
				new Kernel(['security_header_policy' => [BasicSecurityHeaderPolicy::class, 'NonexistentSecurityHeaderPolicy']]);
				$this->fail('Expected a RuntimeException.');
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString('[1]', $e->getMessage());
			}
		}

		public function testAnArrayEntryNotImplementingTheInterfaceIsRejected(): void {
			try {
				new Kernel(['security_header_policy' => [\stdClass::class]]);
				$this->fail('Expected a RuntimeException.');
			} catch (\RuntimeException $e) {
				$this->assertStringContainsString('[0]', $e->getMessage());
			}
		}

		public function testNullSecurityHeaderPolicyAloneInAnArrayAppliesNoHeaders(): void {
			$kernel = new Kernel(['security_header_policy' => [NullSecurityHeaderPolicy::class]]);
			$response = new Response();

			$kernel->getDefaultSecurityHeaderPolicy()->apply(Request::create('/'), $response);

			$this->assertFalse($response->headers->has('X-Frame-Options'));
		}

		public function testNullSecurityHeaderPolicyAlongsideAnotherDoesNotSuppressIt(): void {
			$kernel = new Kernel(['security_header_policy' => [
				NullSecurityHeaderPolicy::class,
				BasicSecurityHeaderPolicy::class,
			]]);

			$response = new Response();
			$kernel->getDefaultSecurityHeaderPolicy()->apply(Request::create('/'), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
		}
	}
