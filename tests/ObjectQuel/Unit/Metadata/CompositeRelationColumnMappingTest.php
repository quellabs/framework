<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Metadata;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkCustomerRefEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkOrderLineEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkOrderLinePartialEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkOrderRefEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkSelfRefEntity;
	use Quellabs\ObjectQuel\Tests\Support\CompositeKeyTestSupport;

	/**
	 * EntityMetadataBuilder's resolved RelationColumnMapping: normalizing both
	 * the singular (localColumn/referencedColumn) and plural
	 * (localColumns/referencedColumns) ManyToOne/OneToOne forms into one ordered
	 * pair list, and validating composite-target coverage. Pure metadata/
	 * annotation work; no database is touched anywhere in this class.
	 */
	class CompositeRelationColumnMappingTest extends TestCase {
		use CompositeKeyTestSupport;

		public function testPluralFormResolvesOrderedPairsMatchingTheFullTargetPrimaryKey(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderLineEntity::class);
			$mapping = $metadata->getRelationColumnMapping('order');

			self::assertNotNull($mapping);
			self::assertTrue($mapping->isComposite());
			self::assertSame(['orderTenantId', 'orderNumber'], $mapping->localProperties());
			self::assertSame(['order_tenant_id', 'order_number'], $mapping->localColumns());
			self::assertSame(['tenantId', 'number'], $mapping->targetProperties());
			self::assertSame(['tenant_id', 'number'], $mapping->targetColumns());
		}

		public function testPluralFormRejectsPartialCoverageOfACompositeTargetPrimaryKey(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/do not exactly match the complete primary key/');

			$this->makeCompositeKeyEntityStore()->getMetadata(CkOrderLinePartialEntity::class);
		}

		public function testSingularFormOmittingReferencedColumnAgainstACompositeTargetIsAmbiguous(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/composite primary key/');

			$this->makeCompositeKeyEntityStore()->getMetadata(CkOrderRefEntity::class);
		}

		public function testSingularFormStillSupportsATargetedNonPrimaryKeyProperty(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkCustomerRefEntity::class);
			$mapping = $metadata->getRelationColumnMapping('customer');

			self::assertNotNull($mapping);
			self::assertFalse($mapping->isComposite());
			self::assertSame(['customerEmail'], $mapping->localProperties());
			self::assertSame(['email'], $mapping->targetProperties());
			self::assertSame(['email'], $mapping->targetColumns());
		}

		public function testSelfReferencingCompositeRelationResolvesWithoutRecursion(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkSelfRefEntity::class);
			$mapping = $metadata->getRelationColumnMapping('parent');

			self::assertNotNull($mapping);
			self::assertTrue($mapping->isComposite());
			self::assertSame(['parentGroupId', 'parentId'], $mapping->localProperties());
			self::assertSame(['groupId', 'id'], $mapping->targetProperties());
		}

		public function testRelationColumnMappingIsNullForANonRelationProperty(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			self::assertNull($metadata->getRelationColumnMapping('tenantId'));
		}
	}
