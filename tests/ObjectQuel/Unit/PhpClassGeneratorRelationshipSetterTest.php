<?php

	namespace Quellabs\ObjectQuel\Tests\Unit;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\Sculpt\Helpers\PhpClassGenerator;

	/**
	 * Regression test: a ManyToOne's generated setter must derive its bidirectional
	 * sync methods from the matching @InverseOf collection property
	 * (RelationProperty::inverseProperty), never from referencedColumn — which
	 * names the target's scalar FK column (e.g. "id") and has nothing to do with
	 * an inverse collection name. Before the fix, referencedColumn="id" produced
	 * nonsensical addId()/removeId() calls.
	 */
	class PhpClassGeneratorRelationshipSetterTest extends TestCase {

		public function testManyToOneWithInversePropertySyncsViaAddRemove(): void {
			$generator = new PhpClassGenerator();

			$setter = $generator->generateSetter([
				'name'             => 'customer',
				'type'             => 'CustomerEntity',
				'relationshipType' => 'ManyToOne',
				'targetEntity'     => 'Customer',
				'referencedColumn' => 'id',
				'inverseProperty'  => 'orders',
			]);

			$this->assertStringContainsString('addOrder($this)', $setter);
			$this->assertStringContainsString('removeOrder($this)', $setter);
		}

		public function testManyToOneWithScalarReferencedColumnButNoInversePropertyGeneratesPlainSetter(): void {
			$generator = new PhpClassGenerator();

			// referencedColumn is a scalar target column ("id"), not a collection
			// name — without a known inverseProperty there is nothing to sync.
			$setter = $generator->generateSetter([
				'name'             => 'customer',
				'type'             => 'CustomerEntity',
				'relationshipType' => 'ManyToOne',
				'targetEntity'     => 'Customer',
				'referencedColumn' => 'id',
				'inverseProperty'  => null,
			]);

			$this->assertStringNotContainsString('addId', $setter);
			$this->assertStringNotContainsString('removeId', $setter);
			$this->assertStringContainsString('$this->customer = $customer;', $setter);
		}
	}
