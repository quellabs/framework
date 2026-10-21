<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Sculpt;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Capabilities\PlatformCapabilities;
	use Quellabs\ObjectQuel\Sculpt\Helpers\ForeignKeyComparator;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelCompositeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelSingleEntity;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * ForeignKeyComparator::getEntityForeignKeys()/compareForeignKeys() for
	 * class-level (possibly composite) @Orm\ForeignKey declarations — the
	 * lazy target-property-to-column resolution FkOrderCompositeTargetEntity's
	 * own docblock defers to this class.
	 */
	class ForeignKeyComparatorTest extends TestCase {
		use FkTestSupport;

		public function testResolvesAClassLevelCompositeForeignKeyToRealTargetColumnsInOrder(): void {
			$entityStore = $this->makeFkEntityStore();
			$comparator = new ForeignKeyComparator($this->makeSqliteAdapter(), $entityStore);

			$foreignKeys = $comparator->getEntityForeignKeys(FkOrderClassLevelCompositeEntity::class);

			self::assertArrayHasKey('fk_fk_orders_class_level_composite_order_tenant_id_order_number', $foreignKeys);

			$fk = $foreignKeys['fk_fk_orders_class_level_composite_order_tenant_id_order_number'];
			self::assertSame(['order_tenant_id', 'order_number'], $fk->columns);
			self::assertSame('fk_composite_targets', $fk->referencedTable);
			self::assertSame(['tenant_id', 'number'], $fk->referencedColumns);
			self::assertSame('CASCADE', $fk->onDelete);
			self::assertSame('RESTRICT', $fk->onUpdate);
		}

		/**
		 * A class-level declaration with exactly one column pair resolves
		 * the same way a composite one does — through 'columns', not the
		 * legacy property-level 'referencedColumn' path.
		 */
		public function testResolvesAClassLevelSingleColumnForeignKey(): void {
			$entityStore = $this->makeFkEntityStore();
			$comparator = new ForeignKeyComparator($this->makeSqliteAdapter(), $entityStore);

			$foreignKeys = $comparator->getEntityForeignKeys(FkOrderClassLevelSingleEntity::class);

			self::assertCount(1, $foreignKeys);
			$fk = reset($foreignKeys);
			self::assertCount(1, $fk->columns);
			self::assertCount(1, $fk->referencedColumns);
		}

		/**
		 * With a real PlatformCapabilities reporting SQLite support,
		 * compareForeignKeys() must show a fresh composite constraint as
		 * "added" against a table with none yet — the same real-diff
		 * behavior DatabaseAdapterForeignKeyPostgresTest verifies for
		 * Postgres, here for the composite path specifically.
		 */
		public function testCompareForeignKeysShowsAFreshCompositeConstraintAsAdded(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE fk_composite_targets (tenant_id INTEGER, number TEXT, PRIMARY KEY (tenant_id, number))');
			$adapter->execute('CREATE TABLE fk_orders_class_level_composite (id INTEGER PRIMARY KEY, order_tenant_id INTEGER NOT NULL, order_number TEXT NOT NULL)');

			$entityStore = $this->makeFkEntityStore();
			$comparator = new ForeignKeyComparator($adapter, $entityStore, new PlatformCapabilities($adapter));

			$diff = $comparator->compareForeignKeys(FkOrderClassLevelCompositeEntity::class);

			self::assertArrayHasKey('fk_fk_orders_class_level_composite_order_tenant_id_order_number', $diff['added']);
			self::assertSame([], $diff['modified']);
			self::assertSame([], $diff['deleted']);
		}

		/**
		 * The same composite constraint, already present on the live table
		 * in matching column order, must diff as unchanged — not "modified"
		 * from independently-sorted column comparison, and not "added"/
		 * "deleted" from a name mismatch.
		 */
		public function testCompareForeignKeysShowsNoDiffWhenTheLiveCompositeConstraintAlreadyMatches(): void {
			$adapter = $this->makeSqliteAdapter();
			$adapter->execute('CREATE TABLE fk_composite_targets (tenant_id INTEGER, number TEXT, PRIMARY KEY (tenant_id, number))');
			$adapter->execute(
				'CREATE TABLE fk_orders_class_level_composite (' .
				'id INTEGER PRIMARY KEY, order_tenant_id INTEGER NOT NULL, order_number TEXT NOT NULL, ' .
				'FOREIGN KEY (order_tenant_id, order_number) REFERENCES fk_composite_targets(tenant_id, number) ON DELETE CASCADE ON UPDATE RESTRICT' .
				')'
			);

			$entityStore = $this->makeFkEntityStore();
			$comparator = new ForeignKeyComparator($adapter, $entityStore, new PlatformCapabilities($adapter));

			$diff = $comparator->compareForeignKeys(FkOrderClassLevelCompositeEntity::class);

			self::assertSame([], $diff['added']);
			self::assertSame([], $diff['modified']);
			self::assertSame([], $diff['deleted']);
		}
	}
