<?php

	declare(strict_types=1);

	namespace Quellabs\ObjectQuel\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
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
		 * Build a platform mock that returns the given native JSON type.
		 * supportsNativeEnums() is left unstubbed (defaults to false), which is
		 * irrelevant to JSON tests but required by normalizeColumnDefinition.
		 */
		private function makePlatform(string $nativeJsonType): PlatformCapabilitiesInterface {
			$platform = $this->createMock(PlatformCapabilitiesInterface::class);
			$platform->method('getNativeJsonType')->willReturn($nativeJsonType);
			return $platform;
		}

		/**
		 * @param array<string, mixed> $overrides
		 */
		private function makeColumn(array $overrides = []): ColumnDefinition {
			$merged = array_merge([
				'type'        => 'json',
				'php_type'    => 'array',
				'limit'       => null,
				'default'     => null,
				'nullable'    => true,
				'precision'   => null,
				'scale'       => null,
				'unsigned'    => false,
				'generated'   => null,
				'identity'    => false,
				'primary_key' => false,
				'values'      => null,
			], $overrides);

			return new ColumnDefinition(...$merged);
		}

		/**
		 * Minimal column definition for a JSON column on the entity side.
		 */
		private function entityJsonColumn(): ColumnDefinition {
			return $this->makeColumn();
		}

		// -------------------------------------------------------------------------
		// MySQL / MariaDB — both sides report 'json'
		// -------------------------------------------------------------------------

		/**
		 * @test
		 * On MySQL both entity and database use 'json'; no change should be detected.
		 */
		public function noChangeDetectedWhenBothSidesAreJsonOnMysql(): void {
			$platform = $this->makePlatform('json');   // MySQL/MariaDB

			$comparator = new SchemaComparator($platform);

			$entityColumns = ['data' => $this->entityJsonColumn()];
			$tableColumns = ['data' => $this->makeColumn(['type' => 'json'])];

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
			$platform = $this->makePlatform('jsonb');   // PostgreSQL

			$comparator = new SchemaComparator($platform);

			$entityColumns = ['data' => $this->entityJsonColumn()];
			$tableColumns = ['data' => $this->makeColumn(['type' => 'jsonb'])];

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
			$platform = $this->makePlatform('jsonb');

			$comparator = new SchemaComparator($platform);

			// Entity: nullable = true. Database: nullable = false (someone changed it manually).
			$entityColumns = ['data' => $this->makeColumn(['type' => 'json', 'nullable' => true])];
			$tableColumns = ['data' => $this->makeColumn(['type' => 'jsonb', 'nullable' => false])];

			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);

			$this->assertArrayHasKey('data', $result['modified'], 'A nullable change must still be detected');
			$this->assertArrayHasKey('nullable', $result['modified']['data']['changes']);
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
			// No platform argument → NullPlatformCapabilities default.
			$comparator = new SchemaComparator();

			$entityColumns = ['data' => $this->entityJsonColumn()];
			$tableColumns = ['data' => $this->makeColumn(['type' => 'json'])];

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
			$platform = $this->makePlatform('json');

			$comparator = new SchemaComparator($platform);

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
			$platform = $this->makePlatform('jsonb');

			$comparator = new SchemaComparator($platform);

			$entityColumns = [];
			$tableColumns = ['data' => $this->makeColumn(['type' => 'jsonb'])];

			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);

			$this->assertArrayHasKey('data', $result['deleted']);
			$this->assertEmpty($result['modified']);
			$this->assertEmpty($result['added']);
		}
	}
