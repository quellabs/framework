<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\NullSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\StrictSecurityHeaderPolicy;

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
	}
