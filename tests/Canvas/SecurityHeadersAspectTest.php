<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeadersAspect;
	use Quellabs\DependencyInjection\Container;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Unit tests for SecurityHeadersAspect's policy resolution: a $policyClass
	 * override, the kernel's application-wide default, or a fallback to the
	 * basic built-in BasicSecurityHeaderPolicy when neither is set.
	 */
	class SecurityHeadersAspectTest extends TestCase {

		private function makeContext(): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn(Request::create('/'));
			return $context;
		}

		public function testUsesTheKernelsApplicationWideDefaultWhenNoOverrideIsGiven(): void {
			$policy = new BasicSecurityHeaderPolicy(frameOptions: 'DENY');

			$kernel = $this->createStub(Kernel::class);
			$kernel->method('getDefaultSecurityHeaderPolicy')->willReturn($policy);

			$aspect = new SecurityHeadersAspect($kernel);
			$response = new Response();
			$aspect->after($this->makeContext(), $response);

			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
		}

		public function testFallsBackToTheBasicPolicyWhenTheKernelHasNoDefault(): void {
			$kernel = $this->createStub(Kernel::class);
			$kernel->method('getDefaultSecurityHeaderPolicy')->willReturn(null);

			$aspect = new SecurityHeadersAspect($kernel);
			$response = new Response();
			$aspect->after($this->makeContext(), $response);

			$this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
		}

		public function testPolicyClassOverridesTheKernelsDefault(): void {
			$override = new BasicSecurityHeaderPolicy(frameOptions: 'ALLOW-FROM');

			$container = $this->createStub(Container::class);
			$container->method('make')->with(BasicSecurityHeaderPolicy::class)->willReturn($override);

			$kernel = $this->createStub(Kernel::class);
			$kernel->method('getDependencyInjector')->willReturn($container);
			$kernel->method('getDefaultSecurityHeaderPolicy')->willReturn(new BasicSecurityHeaderPolicy(frameOptions: 'DENY'));

			$aspect = new SecurityHeadersAspect($kernel, BasicSecurityHeaderPolicy::class);
			$response = new Response();
			$aspect->after($this->makeContext(), $response);

			$this->assertSame('ALLOW-FROM', $response->headers->get('X-Frame-Options'));
		}

		public function testPolicyClassNotImplementingTheInterfaceIsRejected(): void {
			$container = $this->createStub(Container::class);
			$container->method('make')->willReturn(new \stdClass());

			$kernel = $this->createStub(Kernel::class);
			$kernel->method('getDependencyInjector')->willReturn($container);

			$this->expectException(\InvalidArgumentException::class);

			new SecurityHeadersAspect($kernel, \stdClass::class);
		}
	}
