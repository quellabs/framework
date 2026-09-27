<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeRowStatement;

	/**
	 * PlatformCapabilities reports native offset-pagination support by engine.
	 */
	class PlatformCapabilitiesOffsetPaginationTest extends TestCase {

		private function platform(string $version, ?int $compatibilityLevel, string $databaseType = 'sqlsrv'): PlatformCapabilities {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType', 'getServerVersion'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn($databaseType);
			$adapter->method('getServerVersion')->willReturn($version);
			$adapter->method('execute')->willReturn(
				new FakeRowStatement($compatibilityLevel === null ? null : ['compat_level' => $compatibilityLevel])
			);

			return new PlatformCapabilities($adapter);
		}

		/**
		 * @return void
		 */
		public function testSqlServerSupportsOffsetPaginationAtMinimumVersionAndCompatibilityLevels(): void {
			self::assertTrue($this->platform('11.0.2100.60', 110)->supportsOffsetPagination());
		}

		/**
		 * @return void
		 */
		public function testRejectsCompatibilityLevelsBelow110(): void {
			self::assertFalse($this->platform('16.0.1000.6', 100)->supportsOffsetPagination());
		}

		/**
		 * @return void
		 */
		public function testRejectsServersOlderThan2012(): void {
			self::assertFalse($this->platform('10.50.6000.34', 110)->supportsOffsetPagination());
		}

		/**
		 * @return void
		 */
		public function testRejectsUnknownCompatibilityLevel(): void {
			self::assertFalse($this->platform('16.0.1000.6', null)->supportsOffsetPagination());
		}

		/**
		 * @return void
		 */
		public function testReturnsTrueForOtherDatabaseTypes(): void {
			self::assertTrue($this->platform('16.0.1000.6', 110, 'unknown')->supportsOffsetPagination());
		}

		/**
		 * @return void
		 */
		public function testOtherSupportedEnginesAllowOffsetPagination(): void {
			foreach (['mysql', 'mariadb', 'pgsql', 'sqlite'] as $databaseType) {
				self::assertTrue($this->platform('1.0', null, $databaseType)->supportsOffsetPagination());
			}
		}
	}
