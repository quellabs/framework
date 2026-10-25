<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * Case: @Orm\ForeignKey declared on the ManyToOne relation property itself
	 * rather than on the scalar @Orm\Column property backing its local column.
	 * The canonical place for it is the scalar column, so this one is ignored —
	 * metadata build succeeds with no foreign key recorded for this column.
	 * @Orm\Table(name="fk_orders_relation_fk")
	 */
	class FkOrderRelationFkEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=FkCustomerEntity::class, referencedColumn="id", localColumn="customerId", fetch="EAGER")
		 * @Orm\ForeignKey(target=FkCustomerEntity::class, referencedColumn="id")
		 */
		public ?FkCustomerEntity $customer;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 */
		protected ?int $customerId = null;
	}
