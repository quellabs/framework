<?php

	namespace Quellabs\SignalHub\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\SignalHub\SignalHub;
	use Quellabs\SignalHub\SignalHubLocator;

	/**
	 * Unit tests for SignalHubLocator: the static accessor around a
	 * process-wide SignalHub singleton.
	 */
	class SignalHubLocatorTest extends TestCase {

		/**
		 * Resets the locator's static instance before each test, since it
		 * is process-wide state that would otherwise leak between tests.
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();
			SignalHubLocator::setInstance(null);
		}

		/**
		 * Leaves no overridden instance behind for tests outside this file
		 * that may run in the same PHPUnit process.
		 * @return void
		 */
		protected function tearDown(): void {
			SignalHubLocator::setInstance(null);
			parent::tearDown();
		}

		/**
		 * getInstance() lazily creates a SignalHub on first access.
		 * @return void
		 */
		public function testGetInstanceLazilyCreatesAHub(): void {
			$this->assertInstanceOf(SignalHub::class, SignalHubLocator::getInstance());
		}

		/**
		 * Repeated calls to getInstance() return the exact same hub.
		 * @return void
		 */
		public function testGetInstanceReturnsTheSameHubOnRepeatedCalls(): void {
			$this->assertSame(SignalHubLocator::getInstance(), SignalHubLocator::getInstance());
		}

		/**
		 * setInstance() overrides the shared hub with a caller-supplied
		 * instance, which getInstance() then returns.
		 * @return void
		 */
		public function testSetInstanceOverridesTheSharedHub(): void {
			$hub = new SignalHub();

			SignalHubLocator::setInstance($hub);

			$this->assertSame($hub, SignalHubLocator::getInstance());
		}

		/**
		 * setInstance(null) clears the override, so the next getInstance()
		 * call lazily creates a fresh hub distinct from the cleared one.
		 * @return void
		 */
		public function testSetInstanceNullClearsTheOverride(): void {
			$original = SignalHubLocator::getInstance();

			SignalHubLocator::setInstance(null);

			$this->assertNotSame($original, SignalHubLocator::getInstance());
		}
	}
