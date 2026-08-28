<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * The owning ("many") side of a ManyToOne+InverseOf one-to-many pair,
	 * paired with RelCustomerEntity's InverseOf collection. Carries
	 * ManyToOne + Cascade(remove, persist) + ForeignKey/ForeignKeyAction
	 * (a real ON DELETE CASCADE constraint) together.
	 * @Orm\Table(name="rel_orders_cascade")
	 */
	class RelOrderCascadeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelCustomerEntity::class, localColumn="customerId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove", "persist"})
		 */
		public ?RelCustomerEntity $customer = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\ForeignKey(target=RelCustomerEntity::class, referencedColumn="id")
		 * @Orm\ForeignKeyAction(onDelete="CASCADE")
		 */
		protected ?int $customerId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
