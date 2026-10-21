<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Metadata;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkCustomerEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkOrderEntity;
	use Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities\CkOrderWithExplicitIdentityEntity;
	use Quellabs\ObjectQuel\Tests\Support\CompositeKeyTestSupport;

	/**
	 * EntityMetadataRecord's identifier helper: validating/normalizing a
	 * primary-key lookup value, canonicalizing it to each column's mapped PHP
	 * type, and encoding it into a collision-safe Unit of Work key. Pure
	 * metadata/annotation work; no database is touched anywhere in this class.
	 */
	class CompositeIdentifierTest extends TestCase {
		use CompositeKeyTestSupport;

		public function testCompositeKeyWithNoStrategyAnnotationsIsAssignedNotIdentity(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			self::assertSame(['tenantId', 'number'], $metadata->identifierKeys);
			self::assertNull($metadata->autoIncrementColumn);
			self::assertFalse($metadata->hasAutoIncrementPrimaryKey());
		}

		public function testExplicitIdentityStrategyOnACompositeComponentThrowsAtBuildTime(): void {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessageMatches('/composite primary key/');

			$this->makeCompositeKeyEntityStore()->getMetadata(CkOrderWithExplicitIdentityEntity::class);
		}

		public function testSingleColumnKeyStillDefaultsToIdentity(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkCustomerEntity::class);

			self::assertSame('id', $metadata->autoIncrementColumn);
			self::assertTrue($metadata->hasAutoIncrementPrimaryKey());
		}

		public function testNormalizeIdentifierAcceptsAScalarOnlyForASingleColumnKey(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkCustomerEntity::class);

			self::assertSame(['id' => 5], $metadata->normalizeIdentifier(5));
			self::assertSame(['id' => 5], $metadata->normalizeIdentifier(['id' => 5]));
		}

		public function testNormalizeIdentifierRejectsAScalarForACompositeKey(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/composite primary key/');

			$metadata->normalizeIdentifier(5);
		}

		public function testNormalizeIdentifierIgnoresInputArrayOrder(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			self::assertSame(
				['tenantId' => 7, 'number' => 'A-42'],
				$metadata->normalizeIdentifier(['number' => 'A-42', 'tenantId' => 7])
			);
		}

		public function testNormalizeIdentifierRejectsAnUnknownProperty(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/unknown/');

			$metadata->normalizeIdentifier(['tenantId' => 7, 'number' => 'A-42', 'bogus' => 1]);
		}

		public function testNormalizeIdentifierRejectsAMissingComponent(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/missing/');

			$metadata->normalizeIdentifier(['tenantId' => 7]);
		}

		public function testNormalizeIdentifierRejectsNull(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$this->expectException(\InvalidArgumentException::class);

			$metadata->normalizeIdentifier(['tenantId' => 7, 'number' => null]);
		}

		public function testNormalizeIdentifierRejectsFalse(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$this->expectException(\InvalidArgumentException::class);

			$metadata->normalizeIdentifier(['tenantId' => 7, 'number' => false]);
		}

		public function testNormalizeIdentifierRejectsANonScalarValue(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$this->expectException(\InvalidArgumentException::class);

			$metadata->normalizeIdentifier(['tenantId' => 7, 'number' => ['A-42']]);
		}

		public function testCanonicalizeIdentifierCollapsesEquivalentIntegerRepresentations(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$withIntZero = $metadata->canonicalizeIdentifier(['tenantId' => 0, 'number' => 'A-42']);
			$withStringZero = $metadata->canonicalizeIdentifier(['tenantId' => '0', 'number' => 'A-42']);

			self::assertSame($withIntZero, $withStringZero);
			self::assertSame(
				$metadata->encodeIdentity($withIntZero),
				$metadata->encodeIdentity($withStringZero)
			);
		}

		public function testEncodeIdentityKeepsZeroEmptyAndSpecialCharactersDistinctForAStringColumn(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$values = ['0', '', 'A-42', 'A|42', 'A:42', "A\tB"];
			$keys = [];

			foreach ($values as $value) {
				$keys[] = $metadata->resolveIdentityKey(['tenantId' => 1, 'number' => $value]);
			}

			self::assertSame(count($values), count(array_unique($keys)));
		}

		public function testEncodeIdentityDistinguishesDifferentComponentValues(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$keyA = $metadata->resolveIdentityKey(['tenantId' => 1, 'number' => 'A-42']);
			$keyB = $metadata->resolveIdentityKey(['tenantId' => 2, 'number' => 'A-42']);
			$keyC = $metadata->resolveIdentityKey(['tenantId' => 1, 'number' => 'A-43']);

			self::assertNotSame($keyA, $keyB);
			self::assertNotSame($keyA, $keyC);
			self::assertNotSame($keyB, $keyC);
		}

		public function testResolveIdentityKeyIsStableRegardlessOfInputOrder(): void {
			$metadata = $this->makeCompositeKeyEntityStore()->getMetadata(CkOrderEntity::class);

			$keyA = $metadata->resolveIdentityKey(['tenantId' => 7, 'number' => 'A-42']);
			$keyB = $metadata->resolveIdentityKey(['number' => 'A-42', 'tenantId' => 7]);

			self::assertSame($keyA, $keyB);
		}
	}
