<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\AOP\AspectResolver;
	use Quellabs\Canvas\Controllers\SecureController;
	use Quellabs\Canvas\Security\Policies\StrictSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeadersAspect;

	/**
	 * Verifies that a method-level @InterceptWith for a NonStackableAspectInterface
	 * aspect (SecurityHeadersAspect) replaces the class-level declaration inherited
	 * from SecureController, rather than both applying.
	 */
	class AspectResolverNonStackableTest extends TestCase {

		public function testMethodLevelPolicyOverridesInheritedClassLevelPolicy(): void {
			$controller = new class extends SecureController {
				public function __construct() {
				}

				/**
				 * @InterceptWith(SecurityHeadersAspect::class, policyClass=StrictSecurityHeaderPolicy::class)
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
			$this->assertSame(StrictSecurityHeaderPolicy::class, $securityHeaderAspects[0]['parameters']['policyClass'] ?? null);
		}
	}
