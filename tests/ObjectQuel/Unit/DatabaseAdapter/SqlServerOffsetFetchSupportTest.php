<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\DatabaseAdapter;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Tests\Support\FakeRowStatement;

	/**
	 * SQL Server OFFSET/FETCH requires both SQL Server 2012+ and compatibility level 110+.
	 */
	class SqlServerOffsetFetchSupportTest extends TestCase {

		private function adapter(string $version, ?int $compatibilityLevel): DatabaseAdapter {
			$adapter = $this->getMockBuilder(DatabaseAdapter::class)
				->disableOriginalConstructor()
				->onlyMethods(['execute', 'getDatabaseType', 'getServerVersion'])
				->getMock();

			$adapter->method('getDatabaseType')->willReturn('sqlsrv');
			$adapter->method('getServerVersion')->willReturn($version);
			$adapter->method('execute')->willReturn(
				new FakeRowStatement($compatibilityLevel === null ? null : ['compat_level' => $compatibilityLevel])
			);

			return $adapter;
		}

		/**
		 * @return void
		 */
		public function testSupportsOffsetFetchAtMinimumServerAndCompatibilityLevels(): void {
			self::assertTrue($this->adapter('11.0.2100.60', 110)->supportsSqlServerOffsetFetch());
		}

		/**
		 * @return void
		 */
		public function testRejectsCompatibilityLevelsBelow110(): void {
			self::assertFalse($this->adapter('16.0.1000.6', 100)->supportsSqlServerOffsetFetch());
		}

		/**
		 * @return void
		 */
		public function testRejectsServersOlderThan2012(): void {
			self::assertFalse($this->adapter('10.50.6000.34', 110)->supportsSqlServerOffsetFetch());
		}

		/**
		 * @return void
		 */
		public function testRejectsUnknownCompatibilityLevel(): void {
			self::assertFalse($this->adapter('16.0.1000.6', null)->supportsSqlServerOffsetFetch());
		}
	}
