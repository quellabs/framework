<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Configuration;
	use Quellabs\ObjectQuel\Sculpt\ServiceProvider;

	/**
	 * Unit coverage for Configuration::getMigrationTable()/setMigrationTable()
	 * and the matching ServiceProvider::getDefaults() entry (see Phase 2's
	 * "Config plumbing note" in objectquel-migrations-implementation-plan.md).
	 */
	class ConfigurationMigrationTableTest extends TestCase {

		public function testDefaultsToQuelMigrations(): void {
			$this->assertSame('quel_migrations', (new Configuration())->getMigrationTable());
		}

		public function testSetterOverridesTheDefault(): void {
			$configuration = new Configuration();
			$configuration->setMigrationTable('custom_migrations');

			$this->assertSame('custom_migrations', $configuration->getMigrationTable());
		}

		public function testServiceProviderDefaultsIncludeQuelMigrations(): void {
			$this->assertSame('quel_migrations', ServiceProvider::getDefaults()['migration_table']);
		}
	}
