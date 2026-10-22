<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Security\Policies\NullSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\StrictSecurityHeaderPolicy;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Tests Kernel's blanket application of the application-wide default security
	 * header policy to every response (Kernel::handle()'s single choke point),
	 * regardless of whether the route carries a SecurityHeadersAspect. Exercised
	 * directly via reflection, same as KernelHeadResponseTest, since driving a
	 * real request through handle() would require controller discovery.
	 */
	class KernelDefaultSecurityHeaderApplicationTest extends TestCase {

		private function apply(Kernel $kernel, Request $request, Response $response): void {
			$method = new \ReflectionMethod($kernel, 'applyDefaultSecurityHeaderPolicyUnlessAlreadyApplied');
			$method->setAccessible(true);
			$method->invokeArgs($kernel, [$request, $response]);
		}

		public function testAPlainResponseWithNoAspectGetsTheApplicationWideDefault(): void {
			$kernel = new Kernel(['security_header_policy' => StrictSecurityHeaderPolicy::class]);
			$response = new Response();

			$this->apply($kernel, Request::create('/'), $response);

			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
		}

		public function testARouteThatAlreadyRanSecurityHeadersAspectIsLeftUntouched(): void {
			$kernel = new Kernel(['security_header_policy' => StrictSecurityHeaderPolicy::class]);
			$response = new Response();
			$response->headers->set('X-Frame-Options', 'SAMEORIGIN');

			$request = Request::create('/');
			$request->attributes->set('security_headers_applied', true);

			$this->apply($kernel, $request, $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
		}

		public function testAnExplicitPerRouteNullOptOutIsNotOverriddenByTheApplicationWideDefault(): void {
			$kernel = new Kernel(['security_header_policy' => StrictSecurityHeaderPolicy::class]);
			$response = new Response();

			// Simulates SecurityHeadersAspect having run with a NullSecurityHeaderPolicy
			// override: no headers set, but the route's choice must still be respected.
			$request = Request::create('/');
			(new NullSecurityHeaderPolicy())->apply($request, $response);
			$request->attributes->set('security_headers_applied', true);

			$this->apply($kernel, $request, $response);

			$this->assertFalse($response->headers->has('X-Frame-Options'));
		}

		public function testTheApplicationWideNullPolicyAddsNoHeadersToAPlainResponse(): void {
			$kernel = new Kernel(['security_header_policy' => NullSecurityHeaderPolicy::class]);
			$response = new Response();

			$this->apply($kernel, Request::create('/'), $response);

			$this->assertFalse($response->headers->has('X-Frame-Options'));
		}

		public function testAnApplicationWideOrderedArrayDefaultIsAppliedAsOneComposite(): void {
			$kernel = new Kernel(['security_header_policy' => [
				StrictSecurityHeaderPolicy::class,
				NullSecurityHeaderPolicy::class,
			]]);

			$response = new Response();

			$this->apply($kernel, Request::create('/'), $response);

			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
		}

		public function testARouteThatAlreadyRanSecurityHeadersAspectWithAnArrayOverrideIsLeftUntouched(): void {
			$kernel = new Kernel(['security_header_policy' => StrictSecurityHeaderPolicy::class]);
			$response = new Response();

			// Simulates SecurityHeadersAspect having run with a route-specific array
			// override that replaced the application-wide default entirely.
			$request = Request::create('/');
			(new NullSecurityHeaderPolicy())->apply($request, $response);
			$request->attributes->set('security_headers_applied', true);

			$this->apply($kernel, $request, $response);

			$this->assertFalse($response->headers->has('X-Frame-Options'));
		}
	}
