<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Security\Policies\BasicSecurityHeaderPolicy;
	use Quellabs\Canvas\Security\Policies\StrictSecurityHeaderPolicy;

	/**
	 * Tests Kernel's resolution of config/app.php's security_header_policy,
	 * in particular that an explicit null (opting out of the default) is
	 * honored and not swallowed by Configuration::get()'s own null-coalescing
	 * default, which can't tell an omitted key from one set to null.
	 */
	class KernelSecurityHeaderPolicyTest extends TestCase {

		public function testFallsBackToTheBasicPolicyWhenTheKeyIsOmitted(): void {
			$kernel = new Kernel();

			$this->assertInstanceOf(BasicSecurityHeaderPolicy::class, $kernel->getDefaultSecurityHeaderPolicy());
		}

		public function testExplicitNullDisablesTheDefault(): void {
			$kernel = new Kernel(['security_header_policy' => null]);

			$this->assertNull($kernel->getDefaultSecurityHeaderPolicy());
		}

		public function testAnExplicitClassOverridesTheDefault(): void {
			$kernel = new Kernel(['security_header_policy' => StrictSecurityHeaderPolicy::class]);

			$this->assertInstanceOf(StrictSecurityHeaderPolicy::class, $kernel->getDefaultSecurityHeaderPolicy());
		}

		public function testAClassNotImplementingTheInterfaceIsRejected(): void {
			$this->expectException(\RuntimeException::class);

			new Kernel(['security_header_policy' => \stdClass::class]);
		}
	}
