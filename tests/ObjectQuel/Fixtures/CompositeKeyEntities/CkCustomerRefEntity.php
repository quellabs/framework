<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Singular ManyToOne explicitly targeting a non-PK property ('email') of
	 * CkCustomerEntity — the existing single-column-to-non-PK-target support
	 * must keep working unchanged alongside the new composite relation form.
	 * @Orm\Table(name="ck_customer_refs")
	 */
	class CkCustomerRefEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=CkCustomerEntity::class, referencedColumn="email", localColumn="customerEmail", fetch="LAZY")
		 */
		public ?CkCustomerEntity $customer = null;

		/**
		 * @Orm\Column(name="customer_email", type="string", limit=255)
		 */
		protected ?string $customerEmail = null;
	}
