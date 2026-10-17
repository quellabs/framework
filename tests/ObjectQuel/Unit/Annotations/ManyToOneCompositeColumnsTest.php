<?php

	namespace Quellabs\ObjectQuel\Tests\Unit\Annotations;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * @Orm\ManyToOne's plural 'localColumns'/'referencedColumns' parameters:
	 * parameter validation only. OneToOne shares the exact same validation
	 * (AnnotationColumnArrays::validate()), so it isn't duplicated here.
	 */
	class ManyToOneCompositeColumnsTest extends TestCase {

		/**
		 * @param array<string, mixed> $extra
		 */
		private function make(array $extra = []): ManyToOne {
			return new ManyToOne(array_merge(['targetEntity' => 'App\\Entities\\OrderEntity'], $extra));
		}

		public function testDefaultsToNoCompositeColumns(): void {
			$relation = $this->make();

			self::assertFalse($relation->hasCompositeColumns());
			self::assertSame([], $relation->getLocalColumns());
			self::assertSame([], $relation->getReferencedColumns());
		}

		public function testAcceptsMatchingColumnArrays(): void {
			$relation = $this->make([
				'localColumns'      => ['tenantId', 'number'],
				'referencedColumns' => ['tenantId', 'orderNumber'],
			]);

			self::assertTrue($relation->hasCompositeColumns());
			self::assertSame(['tenantId', 'number'], $relation->getLocalColumns());
			self::assertSame(['tenantId', 'orderNumber'], $relation->getReferencedColumns());
		}

		public function testRejectsLocalColumnsWithoutReferencedColumns(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("must both be given together");

			$this->make(['localColumns' => ['tenantId']]);
		}

		public function testRejectsCombiningPluralWithSingularLocalColumn(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage('cannot be combined with');

			$this->make([
				'localColumn'       => 'tenantId',
				'localColumns'      => ['tenantId', 'number'],
				'referencedColumns' => ['tenantId', 'orderNumber'],
			]);
		}

		public function testRejectsMismatchedArrayLengths(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage('same number of elements');

			$this->make([
				'localColumns'      => ['tenantId', 'number'],
				'referencedColumns' => ['tenantId'],
			]);
		}

		public function testRejectsEmptyColumnArrays(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage('must not be empty');

			$this->make(['localColumns' => [], 'referencedColumns' => []]);
		}

		public function testRejectsNonStringEntries(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage('must be non-empty strings');

			$this->make([
				'localColumns'      => ['tenantId', 123],
				'referencedColumns' => ['tenantId', 'number'],
			]);
		}
	}
