<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Metadata;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderActionNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderBothNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelActionNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelActionNoLocalColumnsEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelCompositeEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelDuplicateActionEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelDuplicateFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelNoActionEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelReferencedColumnEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelSingleEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderClassLevelUnknownPropertyEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderCompositeTargetEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderNoFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderOrmEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderRelationFkEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderRelationFkActionEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarActionEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarColumnsEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarColumnsWrongKeyEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkOrderScalarEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\Entities\FkCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Support\FkTestSupport;

	/**
	 * EntityMetadataBuilder: ForeignKey (pure structure), ForeignKeyAction (the
	 * ON DELETE/ON UPDATE behavior) and Cascade (PHP-side object-graph behavior
	 * only) are three fully independent annotations. Pure metadata/annotation
	 * work; no database is touched anywhere in this test class.
	 */
	class EntityMetadataBuilderForeignKeyTest extends TestCase {
		use FkTestSupport;

		public function testForeignKeyDeclaredOnScalarColumnBackingARelationIsKeyedByDatabaseColumnName(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderEntity::class);

			// Keyed by the real DB column ('customer_id'), not the PHP property
			// name ('customer' or 'customerId') and not the ManyToOne localColumn
			// convention (which is itself a property name).
			self::assertArrayHasKey('customer_id', $metadata->foreignKeys);
			self::assertArrayNotHasKey('customer', $metadata->foreignKeys);
			self::assertArrayNotHasKey('customerId', $metadata->foreignKeys);

			$fk = $metadata->getForeignKeyForColumn('customer_id');
			self::assertNotNull($fk);
			self::assertSame(FkCustomerEntity::class, $fk->getTarget());
			self::assertSame('id', $fk->getReferencedColumn());
		}

		public function testForeignKeyDeclaredOnTheRelationPropertyItselfIsIgnored(): void {
			// The relation property's ForeignKey is simply ignored: no build-time
			// error, and no entry ends up in the metadata for it either.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderRelationFkEntity::class);

			self::assertSame([], $metadata->foreignKeys);
			self::assertNull($metadata->getForeignKeyForColumn('customer_id'));
		}

		public function testForeignKeyAndForeignKeyActionDeclaredOnTheRelationPropertyAreBothIgnored(): void {
			// Same as above, but with ForeignKeyAction also on the relation
			// property — it's ignored right alongside ForeignKey, not left
			// dangling as an action with no matching key.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderRelationFkActionEntity::class);

			self::assertSame([], $metadata->foreignKeys);
			self::assertNull($metadata->getForeignKeyForColumn('customer_id'));
			self::assertNull($metadata->getForeignKeyActionForColumn('customer_id'));
		}

		public function testForeignKeyDeclaredOnScalarColumnPropertyIsKeyedByDatabaseColumnName(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderScalarEntity::class);

			self::assertArrayHasKey('customer_id', $metadata->foreignKeys);

			$fk = $metadata->getForeignKeyForColumn('customer_id');
			self::assertNotNull($fk);
			self::assertSame(FkCustomerEntity::class, $fk->getTarget());

			// referencedColumn defaults are resolved later, at migration-generation
			// time, not here.
			self::assertNull($fk->getReferencedColumn());

			// No ForeignKeyAction declared at all — safe defaults apply downstream,
			// but there's simply no annotation object here to carry them.
			self::assertNull($metadata->getForeignKeyActionForColumn('customer_id'));
		}

		public function testForeignKeyActionOnAPlainScalarColumnRequiresNoCascadeAtAll(): void {
			// The case the ForeignKey/ForeignKeyAction split exists to support:
			// a real, non-default database action with zero object-relation
			// modeling and zero Cascade annotation anywhere.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderScalarActionEntity::class);

			$action = $metadata->getForeignKeyActionForColumn('customer_id');
			self::assertNotNull($action);
			self::assertSame('CASCADE', $action->getOnDelete());
			self::assertSame('RESTRICT', $action->getOnUpdate());
		}

		public function testForeignKeyActionWithoutMatchingForeignKeyThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('customer_id');

			$this->makeFkEntityStore()->getMetadata(FkOrderActionNoFkEntity::class);
		}

		public function testCascadeWithoutAnyRelationThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/ManyToOne.*OneToOne/');

			$this->makeFkEntityStore()->getMetadata(FkOrderOrmEntity::class);
		}

		public function testCascadeAndForeignKeyOnARelationRequireNoForeignKeyAction(): void {
			// Cascade has no opinion about ForeignKey at all — this builds
			// successfully with the constraint left at its safe defaults.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderNoFkEntity::class);

			self::assertArrayHasKey('customer_id', $metadata->foreignKeys);
			self::assertNull($metadata->getForeignKeyActionForColumn('customer_id'));
		}

		public function testCascadeOnARelationRequiresNoForeignKeyAtAll(): void {
			// Pure PHP-side cascade removal, no database constraint declared for
			// this column at all — also valid, since Cascade and ForeignKey are
			// fully independent.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderBothNoFkEntity::class);

			self::assertArrayNotHasKey('customer_id', $metadata->foreignKeys);
		}

		public function testEntityWithNoForeignKeyAnnotationsAtAllHasEmptyForeignKeysArray(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkCustomerEntity::class);

			self::assertSame([], $metadata->foreignKeys);
			self::assertNull($metadata->getForeignKeyForColumn('id'));
		}

		public function testPropertyLevelForeignKeyAcceptsColumnsMapKeyedByItsOwnProperty(): void {
			// 'columns' restates the single-column case — functionally
			// identical to the legacy 'referencedColumn' form.
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderScalarColumnsEntity::class);

			$fk = $metadata->getForeignKeyForColumn('customer_id');
			self::assertNotNull($fk);
			self::assertSame(FkCustomerEntity::class, $fk->getTarget());
			self::assertSame('id', $fk->getReferencedColumn());
			self::assertSame([], $metadata->classForeignKeys);
		}

		public function testPropertyLevelForeignKeyRejectsAColumnsKeyThatDoesNotMatchItsOwnProperty(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage("don't match it");

			$this->makeFkEntityStore()->getMetadata(FkOrderScalarColumnsWrongKeyEntity::class);
		}

		public function testClassLevelForeignKeyWithASingleColumnsEntryIsLegal(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderClassLevelSingleEntity::class);

			// Not keyed into the legacy per-column array — class-level
			// declarations live in classForeignKeys regardless of column count.
			self::assertSame([], $metadata->foreignKeys);
			self::assertCount(1, $metadata->classForeignKeys);

			$declaration = $metadata->classForeignKeys[0];
			self::assertFalse($declaration->isComposite());
			self::assertSame(['customerId'], $declaration->localProperties);
			self::assertSame(['customer_id'], $declaration->localColumns);
			self::assertSame(FkCustomerEntity::class, $declaration->targetEntity);
			self::assertSame(['id'], $declaration->targetProperties);
			self::assertSame('RESTRICT', $declaration->onDelete);
			self::assertSame('NO ACTION', $declaration->onUpdate);
		}

		public function testClassLevelForeignKeyWithMultipleColumnsIsMatchedToItsAction(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderClassLevelCompositeEntity::class);

			self::assertCount(1, $metadata->classForeignKeys);

			$declaration = $metadata->classForeignKeys[0];
			self::assertTrue($declaration->isComposite());
			self::assertSame(['orderTenantId', 'orderNumber'], $declaration->localProperties);
			self::assertSame(['order_tenant_id', 'order_number'], $declaration->localColumns);
			self::assertSame(FkOrderCompositeTargetEntity::class, $declaration->targetEntity);
			self::assertSame(['tenantId', 'number'], $declaration->targetProperties);
			self::assertSame('CASCADE', $declaration->onDelete);
			self::assertSame('RESTRICT', $declaration->onUpdate);
		}

		public function testClassLevelForeignKeyWithNoMatchingActionGetsSafeDefaults(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkOrderClassLevelNoActionEntity::class);

			$declaration = $metadata->classForeignKeys[0];
			self::assertSame('RESTRICT', $declaration->onDelete);
			self::assertSame('NO ACTION', $declaration->onUpdate);
		}

		public function testClassLevelForeignKeyActionWithNoMatchingForeignKeyThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('match no class-level');

			$this->makeFkEntityStore()->getMetadata(FkOrderClassLevelActionNoFkEntity::class);
		}

		public function testClassLevelForeignKeyUsingReferencedColumnInsteadOfColumnsThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage("no 'columns' map");

			$this->makeFkEntityStore()->getMetadata(FkOrderClassLevelReferencedColumnEntity::class);
		}

		public function testClassLevelForeignKeyActionWithNoLocalColumnsThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage("no 'localColumns' list");

			$this->makeFkEntityStore()->getMetadata(FkOrderClassLevelActionNoLocalColumnsEntity::class);
		}

		public function testClassLevelForeignKeyNamingAnUndeclaredPropertyThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('doesNotExist');

			$this->makeFkEntityStore()->getMetadata(FkOrderClassLevelUnknownPropertyEntity::class);
		}

		public function testDuplicateClassLevelForeignKeysForTheSameColumnsThrowAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('more than one class-level');

			$this->makeFkEntityStore()->getMetadata(FkOrderClassLevelDuplicateFkEntity::class);
		}

		public function testDuplicateClassLevelForeignKeyActionsForTheSameLocalColumnsThrowAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('more than one class-level');

			$this->makeFkEntityStore()->getMetadata(FkOrderClassLevelDuplicateActionEntity::class);
		}

		public function testEntityWithNoClassLevelForeignKeysHasEmptyClassForeignKeysArray(): void {
			$metadata = $this->makeFkEntityStore()->getMetadata(FkCustomerEntity::class);

			self::assertSame([], $metadata->classForeignKeys);
		}
	}
