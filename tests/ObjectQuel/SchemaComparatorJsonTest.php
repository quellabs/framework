<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\ObjectQuel\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\NullPlatformCapabilities;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\DatabaseAdapter;
	use Quellabs\ObjectQuel\Sculpt\Helpers\SchemaComparator;
	
	/**
	 * Unit tests for SchemaComparator's JSON type normalization.
	 *
	 * The key invariant: when an entity declares type='json' and the database
	 * returns 'jsonb' (PostgreSQL), the comparator must treat them as identical
	 * and produce no spurious modification entry. On MySQL/MariaDB, both sides
	 * use 'json' and the comparison is straightforward.
	 */
	class SchemaComparatorJsonTest extends TestCase {
		
		/**
		 * Build a DatabaseAdapter mock that reports it does not support native enums
		 * (irrelevant to JSON tests but required by normalizeColumnDefinition).
		 */
		private function makeAdapter(): DatabaseAdapter {
			$adapter = $this->createMock(DatabaseAdapter::class);
			$adapter->method('supportsNativeEnums')->willReturn(false);
			return $adapter;
		}
		
		/**
		 * Build a platform mock that returns the given native JSON type.
		 */
		private function makePlatform(string $nativeJsonType): PlatformCapabilitiesInterface {
			$platform = $this->createMock(PlatformCapabilitiesInterface::class);
			$platform->method('getNativeJsonType')->willReturn($nativeJsonType);
			return $platform;
		}
		
		/**
		 * Minimal column definition for a JSON column on the entity side.
		 * @return array<string, mixed>
		 */
		private function entityJsonColumn(): array {
			return [
				'type'    => 'json',
				'null'    => true,
				'default' => null,
			];
		}
		
		// -------------------------------------------------------------------------
		// MySQL / MariaDB — both sides report 'json'
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 * On MySQL both entity and database use 'json'; no change should be detected.
		 */
		public function noChangeDetectedWhenBothSidesAreJsonOnMysql(): void {
			$adapter = $this->makeAdapter();
			$platform = $this->makePlatform('json');   // MySQL/MariaDB
			
			$comparator = new SchemaComparator($adapter, $platform);
			
			$entityColumns = ['data' => $this->entityJsonColumn()];
			$tableColumns = ['data' => ['type' => 'json', 'null' => true, 'default' => null]];
			
			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);
			
			$this->assertEmpty($result['modified'], 'No modification expected when both sides use json on MySQL');
			$this->assertEmpty($result['added']);
			$this->assertEmpty($result['deleted']);
		}
		
		// -------------------------------------------------------------------------
		// PostgreSQL — entity says 'json', database returns 'jsonb'
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 * On PostgreSQL the database returns 'jsonb' but the entity declares 'json'.
		 * After normalization both sides must be equal — no modification generated.
		 */
		public function noChangeDetectedWhenEntityIsJsonAndDatabaseIsJsonbOnPostgres(): void {
			$adapter = $this->makeAdapter();
			$platform = $this->makePlatform('jsonb');   // PostgreSQL
			
			$comparator = new SchemaComparator($adapter, $platform);
			
			$entityColumns = ['data' => $this->entityJsonColumn()];
			$tableColumns = ['data' => ['type' => 'jsonb', 'null' => true, 'default' => null]];
			
			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);
			
			$this->assertEmpty($result['modified'], 'json vs jsonb must not generate a spurious modification on PostgreSQL');
			$this->assertEmpty($result['added']);
			$this->assertEmpty($result['deleted']);
		}
		
		/**
		 * @test
		 * A real change (nullable toggled) on a JSON column must still be detected
		 * even when the type names differ between entity and database.
		 */
		public function realChangeOnJsonColumnIsStillDetectedOnPostgres(): void {
			$adapter = $this->makeAdapter();
			$platform = $this->makePlatform('jsonb');
			
			$comparator = new SchemaComparator($adapter, $platform);
			
			// Entity: nullable = true. Database: nullable = false (someone changed it manually).
			$entityColumns = ['data' => ['type' => 'json', 'null' => true, 'default' => null]];
			$tableColumns = ['data' => ['type' => 'jsonb', 'null' => false, 'default' => null]];
			
			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);
			
			$this->assertArrayHasKey('data', $result['modified'], 'A nullable change must still be detected');
			$this->assertArrayHasKey('null', $result['modified']['data']['changes']);
		}
		
		// -------------------------------------------------------------------------
		// NullPlatformCapabilities default (no explicit platform)
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 * When constructed without an explicit platform, NullPlatformCapabilities
		 * returns 'json', so a json/json comparison produces no spurious change.
		 */
		public function noChangeWithDefaultPlatformWhenBothSidesAreJson(): void {
			$adapter = $this->makeAdapter();
			
			// No platform argument → NullPlatformCapabilities default.
			$comparator = new SchemaComparator($adapter);
			
			$entityColumns = ['data' => $this->entityJsonColumn()];
			$tableColumns = ['data' => ['type' => 'json', 'null' => true, 'default' => null]];
			
			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);
			
			$this->assertEmpty($result['modified']);
		}
		
		// -------------------------------------------------------------------------
		// Column detection — added / deleted
		// -------------------------------------------------------------------------
		
		/**
		 * @test
		 * A JSON column present in the entity but absent from the database is reported as added.
		 */
		public function jsonColumnMissingFromDatabaseIsReportedAsAdded(): void {
			$adapter = $this->makeAdapter();
			$platform = $this->makePlatform('json');
			
			$comparator = new SchemaComparator($adapter, $platform);
			
			$entityColumns = ['data' => $this->entityJsonColumn()];
			$tableColumns = [];
			
			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);
			
			$this->assertArrayHasKey('data', $result['added']);
			$this->assertEmpty($result['modified']);
			$this->assertEmpty($result['deleted']);
		}
		
		/**
		 * @test
		 * A JSON column present in the database but absent from the entity is reported as deleted.
		 */
		public function jsonColumnMissingFromEntityIsReportedAsDeleted(): void {
			$adapter = $this->makeAdapter();
			$platform = $this->makePlatform('jsonb');
			
			$comparator = new SchemaComparator($adapter, $platform);
			
			$entityColumns = [];
			$tableColumns = ['data' => ['type' => 'jsonb', 'null' => true, 'default' => null]];
			
			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);
			
			$this->assertArrayHasKey('data', $result['deleted']);
			$this->assertEmpty($result['modified']);
			$this->assertEmpty($result['added']);
		}
	}