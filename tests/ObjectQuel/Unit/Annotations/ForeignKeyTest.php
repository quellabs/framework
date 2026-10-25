<?php

declare(strict_types=1);

	namespace Quellabs\ObjectQuel\Tests\Unit\Annotations;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * @Orm\ForeignKey annotation: parameter validation and defaults. Pure
	 * structure only (target, referencedColumn) — the ON DELETE/ON UPDATE
	 * behavior lives on the separate @Orm\ForeignKeyAction annotation, see
	 * ForeignKeyActionTest.
	 */
	class ForeignKeyTest extends TestCase {

		public function testTargetIsRequired(): void {
			$this->expectException(\ArgumentCountError::class);

			new ForeignKey();
		}

		public function testTargetMustBeNonEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);

			new ForeignKey(target: '');
		}

		public function testDefaultsAreAppliedWhenOmitted(): void {
			$fk = new ForeignKey(target: 'App\\Entities\\CustomerEntity');

			self::assertSame('App\\Entities\\CustomerEntity', $fk->getTarget());
			self::assertNull($fk->getReferencedColumn());
		}

		public function testExplicitParametersAreHonored(): void {
			$fk = new ForeignKey(target: 'App\\Entities\\CustomerEntity', referencedColumn: 'uuid');

			self::assertSame('App\\Entities\\CustomerEntity', $fk->getTarget());
			self::assertSame('uuid', $fk->getReferencedColumn());
		}

		public function testReferencedColumnMustBeStringOrNull(): void {
			$this->expectException(\TypeError::class);

			new ForeignKey(target: 'App\\Entities\\CustomerEntity', referencedColumn: 123);
		}

		public function testSetTargetOverwritesTargetAndParameters(): void {
			$fk = new ForeignKey(target: 'CustomerEntity');
			$fk->setTarget('App\\Entities\\CustomerEntity');

			self::assertSame('App\\Entities\\CustomerEntity', $fk->getTarget());
			self::assertSame('App\\Entities\\CustomerEntity', $fk->getParameters()['target']);
		}

		public function testGetParametersReturnsOriginalParameterArray(): void {
			$params = ['target' => 'App\\Entities\\CustomerEntity', 'referencedColumn' => 'uuid'];
			$fk = new ForeignKey(...$params);

			self::assertSame($params, $fk->getParameters());
		}

		public function testGetParametersOmitsKeysTheCallerDidNotSupply(): void {
			$fk = new ForeignKey(target: 'App\\Entities\\CustomerEntity');

			self::assertSame(['target' => 'App\\Entities\\CustomerEntity'], $fk->getParameters());
		}

		public function testColumnsCannotBeCombinedWithReferencedColumn(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'columns' cannot be combined with 'referencedColumn'");

			new ForeignKey(target: 'App\\Entities\\CustomerEntity', referencedColumn: 'id', columns: ['customerId' => 'id']);
		}

		public function testColumnsMustBeNonEmpty(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'columns' must be a non-empty map");

			new ForeignKey(target: 'App\\Entities\\CustomerEntity', columns: []);
		}

		public function testColumnsKeysMustBeNonEmptyStrings(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'columns' keys must be non-empty local property names");

			new ForeignKey(target: 'App\\Entities\\CustomerEntity', columns: [0 => 'id']);
		}

		public function testColumnsValuesMustBeNonEmptyStrings(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("'columns' values must be non-empty target property names");

			new ForeignKey(target: 'App\\Entities\\CustomerEntity', columns: ['customerId' => '']);
		}

		public function testColumnsRejectsADuplicateTargetProperty(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessage("target property 'tenantId' is declared more than once");

			new ForeignKey(target: 'App\\Entities\\OrderEntity', columns: ['orderTenantId' => 'tenantId', 'orderNumber' => 'tenantId']);
		}

		public function testSingleEntryColumnsBehavesLikeReferencedColumn(): void {
			$fk = new ForeignKey(target: 'App\\Entities\\CustomerEntity', columns: ['customerId' => 'id']);

			self::assertTrue($fk->hasColumns());
			self::assertSame(['customerId' => 'id'], $fk->getColumns());
			self::assertSame('id', $fk->getReferencedColumn());
		}

		public function testMultiEntryColumnsHasNoSingleReferencedColumn(): void {
			$fk = new ForeignKey(target: 'App\\Entities\\OrderEntity', columns: ['orderTenantId' => 'tenantId', 'orderNumber' => 'number']);

			self::assertTrue($fk->hasColumns());
			self::assertSame(['orderTenantId' => 'tenantId', 'orderNumber' => 'number'], $fk->getColumns());
			self::assertNull($fk->getReferencedColumn());
		}

		public function testNoColumnsAndNoReferencedColumnLeavesBothEmpty(): void {
			$fk = new ForeignKey(target: 'App\\Entities\\CustomerEntity');

			self::assertFalse($fk->hasColumns());
			self::assertSame([], $fk->getColumns());
			self::assertNull($fk->getReferencedColumn());
		}
	}
