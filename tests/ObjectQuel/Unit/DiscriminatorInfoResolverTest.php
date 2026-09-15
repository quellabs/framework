<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorColumn;
	use Quellabs\ObjectQuel\Annotations\Orm\DiscriminatorValue;
	use Quellabs\ObjectQuel\EntityStore;
	use Quellabs\ObjectQuel\Exception\QuelException;
	use Quellabs\ObjectQuel\Metadata\DiscriminatorInfoResolver;

	/**
	 * Plain (non-STI) class — no discriminator annotations at all.
	 */
	class DiscriminatorInfoResolverPlainFixture {
	}

	/**
	 * Base of a correctly-configured STI hierarchy: the discriminator
	 * column lives here only, never redeclared on the subclass.
	 * @Orm\DiscriminatorColumn(name="kind")
	 */
	class DiscriminatorInfoResolverBaseFixture {
	}

	/**
	 * @Orm\DiscriminatorValue(value="sub")
	 */
	class DiscriminatorInfoResolverSubFixture extends DiscriminatorInfoResolverBaseFixture {
	}

	/**
	 * Declares a discriminator value with no column anywhere in its
	 * hierarchy — half of STI's contract, a misconfiguration.
	 * @Orm\DiscriminatorValue(value="sub")
	 */
	class DiscriminatorInfoResolverValueOnlyFixture {
	}

	/**
	 * Base of a hierarchy.
	 * @Orm\DiscriminatorColumn(name="")
	 */
	class DiscriminatorInfoResolverEmptyColumnBaseFixture {
	}

	/**
	 * @Orm\DiscriminatorValue(value="sub")
	 */
	class DiscriminatorInfoResolverEmptyColumnSubFixture extends DiscriminatorInfoResolverEmptyColumnBaseFixture {
	}

	/**
	 * Coverage for DiscriminatorInfoResolver. The fixture classes above are
	 * declared in this file, not under src/Entities, so they're plain
	 * annotation-bearing classes only, never scanned as ORM entities.
	 */
	class DiscriminatorInfoResolverTest extends TestCase {

		private function entityStore(): EntityStore {
			return $GLOBALS['test_em']->getEntityStore();
		}

		public function testReturnsNullForAPlainNonStiClass(): void {
			self::assertNull(DiscriminatorInfoResolver::resolve($this->entityStore(), DiscriminatorInfoResolverPlainFixture::class));
		}

		/**
		 * Discriminator column on the base class, value on the subclass —
		 * resolving from the subclass must find both by walking up.
		 */
		public function testResolvesTheColumnDeclaredOnlyOnAnAncestor(): void {
			$info = DiscriminatorInfoResolver::resolve($this->entityStore(), DiscriminatorInfoResolverSubFixture::class);

			self::assertSame(['column' => 'kind', 'value' => 'sub'], $info);
		}

		public function testThrowsWhenOnlyTheValueIsDeclaredAnywhereInTheHierarchy(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('declares only');

			DiscriminatorInfoResolver::resolve($this->entityStore(), DiscriminatorInfoResolverValueOnlyFixture::class);
		}

		public function testThrowsWhenTheResolvedColumnNameIsEmpty(): void {
			$this->expectException(QuelException::class);
			$this->expectExceptionMessage('is empty');

			DiscriminatorInfoResolver::resolve($this->entityStore(), DiscriminatorInfoResolverEmptyColumnSubFixture::class);
		}
	}
