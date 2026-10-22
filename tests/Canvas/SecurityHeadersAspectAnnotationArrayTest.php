<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\AOP\AspectResolver;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\StrictSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeadersAspect;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * End-to-end test for the documented
	 * @InterceptWith(SecurityHeadersAspect::class, policyClass={...}) array syntax:
	 * verifies the annotation reader parses it into an ordered list of class-strings,
	 * and that SecurityHeadersAspect resolves that list into a working composite,
	 * rather than only testing array construction directly.
	 */
	class SecurityHeadersAspectAnnotationArrayTest extends TestCase {

		public function testArraySyntaxParsesIntoAnOrderedListAndResolvesToAWorkingComposite(): void {
			$controller = new class {
				/**
				 * @InterceptWith(SecurityHeadersAspect::class, policyClass={StrictSecurityHeaderPolicy::class, BasicSecurityHeaderPolicy::class})
				 */
				public function index(): void {
				}
			};

			$resolver = new AspectResolver(new AnnotationReader(new Configuration()));
			$aspects = $resolver->resolve($controller, 'index');

			$securityHeaderAspects = array_values(array_filter(
				$aspects,
				fn(array $aspect) => $aspect['class'] === SecurityHeadersAspect::class
			));

			$this->assertCount(1, $securityHeaderAspects);

			$policyClass = $securityHeaderAspects[0]['parameters']['policyClass'] ?? null;

			$this->assertSame([
				StrictSecurityHeaderPolicy::class,
				BasicSecurityHeaderPolicy::class,
			], $policyClass);

			$aspect = new SecurityHeadersAspect(new Kernel(), $policyClass);
			$response = new Response();
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn(Request::create('/'));
			$aspect->after($context, $response);

			$this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
		}
	}
