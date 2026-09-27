<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeRowStatement;

	/**
	 * PlatformCapabilities checks SQL Server version and compatibility for OFFSET/FETCH.
	 */
	class PlatformCapabilitiesSqlServerOffsetFetchTest extends TestCase {

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
		public function testSupportsOffsetFetchAtMinimumServerAndCompatibilityLevels(): void {
			self::assertTrue($this->platform('11.0.2100.60', 110)->supportsSqlServerOffsetFetch());
		}

		/**
		 * @return void
		 */
		public function testRejectsCompatibilityLevelsBelow110(): void {
			self::assertFalse($this->platform('16.0.1000.6', 100)->supportsSqlServerOffsetFetch());
		}

		/**
		 * @return void
		 */
		public function testRejectsServersOlderThan2012(): void {
			self::assertFalse($this->platform('10.50.6000.34', 110)->supportsSqlServerOffsetFetch());
		}

		/**
		 * @return void
		 */
		public function testRejectsUnknownCompatibilityLevel(): void {
			self::assertFalse($this->platform('16.0.1000.6', null)->supportsSqlServerOffsetFetch());
		}

		/**
		 * @return void
		 */
		public function testReturnsFalseForOtherDatabaseTypes(): void {
			self::assertFalse($this->platform('16.0.1000.6', 110, 'mysql')->supportsSqlServerOffsetFetch());
		}
	}
