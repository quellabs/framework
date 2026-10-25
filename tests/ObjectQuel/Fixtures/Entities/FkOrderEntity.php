<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * Full combo: ManyToOne + Cascade (PHP-side removal), plus ForeignKey +
	 * ForeignKeyAction (a real ON DELETE CASCADE constraint) on the scalar
	 * column property backing the relation's local column. Cascade and
	 * ForeignKey/ForeignKeyAction are independent annotations here — this
	 * entity just happens to declare both.
	 * @Orm\Table(name="fk_orders")
	 */
	class FkOrderEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=FkCustomerEntity::class, referencedColumn="id", localColumn="customerId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove"})
		 */
		public ?FkCustomerEntity $customer;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\ForeignKey(target=FkCustomerEntity::class, referencedColumn="id")
		 * @Orm\ForeignKeyAction(onDelete="CASCADE")
		 */
		protected ?int $customerId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
