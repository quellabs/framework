<?php

	declare(strict_types=1);

	namespace Quellabs\ObjectQuel\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilitiesInterface;
	use Quellabs\ObjectQuel\DatabaseAdapter\ColumnDefinition;
	use Quellabs\ObjectQuel\Sculpt\Helpers\SchemaComparator;

	/**
	 * Unit tests for SchemaComparator's enum handling — the Phase 0.1
	 * "companion fix" to getModifiedColumns() (see
	 * objectquel-migrations-implementation-plan.md, "Companion fix").
	 *
	 * Column-definition shapes here mirror what DatabaseAdapter::getColumns()
	 * actually reports for the table side: on a non-native-enum engine, a
	 * column originally declared `enum(...)` was rendered as a plain VARCHAR
	 * fallback (see DDLTypeMapper), so live introspection reports it back as
	 * type='string' with no real values list — the database has no way to
	 * recall it was ever an enum. Only the entity side ever carries the
	 * `values` list on those engines.
	 */
	class SchemaComparatorEnumTest extends TestCase {

		private function makePlatform(bool $supportsNativeEnums): PlatformCapabilitiesInterface {
			$platform = $this->createMock(PlatformCapabilitiesInterface::class);
			$platform->method('supportsNativeEnums')->willReturn($supportsNativeEnums);
			$platform->method('getNativeJsonType')->willReturn('json');
			return $platform;
		}

		/**
		 * @param array<string, mixed> $overrides
		 */
		private function makeColumn(array $overrides = []): ColumnDefinition {
			$merged = array_merge([
				'type'        => 'string',
				'php_type'    => 'string',
				'limit'       => null,
				'default'     => null,
				'nullable'    => false,
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
		 * @test
		 * A values-list change on a non-native-enum connection is only
		 * detectable when it moves the derived VARCHAR limit (see
		 * TypeMapper::enumFallbackLimit()) — the fallback column itself
		 * carries no record of the old values, only its length. When it
		 * does move, the modification entry's 'to' must carry the original
		 * 'enum' type and full values list, not the normalized 'string'
		 * collapse used only for the equality check — otherwise the
		 * generated migration permanently loses the column's enum-ness, even
		 * when later run against MySQL. 'from' correctly stays 'string' with
		 * no values list — that really is everything the live table can
		 * report on this engine.
		 */
		public function enumTypeAndValuesArePreservedInModificationEntryWhenDerivedLimitChanges(): void {
			$platform = $this->makePlatform(false); // e.g. a SQLite/PostgreSQL connection

			$comparator = new SchemaComparator($platform);

			$longValue = str_repeat('a', 300);
			$entityColumns = [
				'status' => $this->makeColumn(['type' => 'enum', 'values' => ['active', $longValue]]),
			];
			// Realistic live introspection of the existing VARCHAR(255) fallback column.
			$tableColumns = [
				'status' => $this->makeColumn(['type' => 'string', 'limit' => 255]),
			];

			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);

			$this->assertArrayHasKey('status', $result['modified']);
			$this->assertSame('enum', $result['modified']['status']['to']->type);
			$this->assertSame(['active', $longValue], $result['modified']['status']['to']->values);
			$this->assertSame('string', $result['modified']['status']['from']->type);
		}

		/**
		 * @test
		 * An unchanged enum column on a non-native-enum connection must not
		 * produce a spurious modification — the derived limit matches the
		 * live column's actual limit, and (per Step 2 of
		 * normalizeColumnDefinition()) the entity's 'enum' collapses to
		 * 'string' for the comparison, matching the introspected type.
		 */
		public function noSpuriousModificationWhenEnumIsUnchangedOnNonNativeEnumConnection(): void {
			$platform = $this->makePlatform(false);

			$comparator = new SchemaComparator($platform);

			$entityColumns = [
				'status' => $this->makeColumn(['type' => 'enum', 'values' => ['active', 'inactive', 'banned']]),
			];
			$tableColumns = [
				'status' => $this->makeColumn(['type' => 'string', 'limit' => 255]),
			];

			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);

			$this->assertEmpty($result['modified']);
		}

		/**
		 * @test
		 * On a native-enum connection (MySQL/MariaDB), live introspection
		 * (DatabaseAdapter::getColumns()) reports the real 'enum' type and
		 * values list back — an unchanged enum column must not produce a
		 * spurious modification there either.
		 */
		public function noSpuriousModificationWhenEnumIsUnchangedOnNativeEnumConnection(): void {
			$platform = $this->makePlatform(true);

			$comparator = new SchemaComparator($platform);

			$column = $this->makeColumn(['type' => 'enum', 'values' => ['active', 'inactive', 'banned']]);

			$result = $comparator->analyzeSchemaChanges(['status' => $column], ['status' => $column]);

			$this->assertEmpty($result['modified']);
		}

		/**
		 * @test
		 * On a native-enum connection, a real values-list change is detected
		 * directly (the live table genuinely reports its current values),
		 * and both 'from' and 'to' preserve type='enum' with their
		 * respective values lists.
		 */
		public function enumValuesChangeIsDetectedOnNativeEnumConnection(): void {
			$platform = $this->makePlatform(true);

			$comparator = new SchemaComparator($platform);

			$entityColumns = [
				'status' => $this->makeColumn(['type' => 'enum', 'values' => ['active', 'inactive', 'banned']]),
			];
			$tableColumns = [
				'status' => $this->makeColumn(['type' => 'enum', 'values' => ['active', 'inactive']]),
			];

			$result = $comparator->analyzeSchemaChanges($entityColumns, $tableColumns);

			$this->assertArrayHasKey('status', $result['modified']);
			$this->assertSame(['active', 'inactive', 'banned'], $result['modified']['status']['to']->values);
			$this->assertSame(['active', 'inactive'], $result['modified']['status']['from']->values);
		}
	}
