<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\AnnotationReader\AnnotationReader;
	use Quellabs\AnnotationReader\Configuration;
	use Quellabs\Canvas\AOP\AspectResolver;
	use Quellabs\Canvas\Controllers\SecureController;
	use Quellabs\Canvas\Security\Policies\MediumSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\SecurityHeadersAspect;

	/**
	 * Verifies that SecureController's @InterceptWith pins policyClass to
	 * MediumSecurityHeaderPolicy, rather than relying on SecurityHeadersAspect's
	 * fallback to whatever config/app.php's security_header_policy happens to be -
	 * SecureController's whole point is a guaranteed baseline, not "the app default".
	 */
	class SecureControllerTest extends TestCase {

		public function testInterceptWithPinsTheMediumPolicyClass(): void {
			$controller = new class extends SecureController {
				public function __construct() {
				}

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
			$this->assertSame(MediumSecurityHeaderPolicy::class, $securityHeaderAspects[0]['parameters']['policyClass'] ?? null);
		}
	}
