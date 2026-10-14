<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * Intermediate range of the relational-division fixtures: a customer's order.
	 * @Orm\Table(name="rel_div_orders")
	 */
	class RelDivOrderEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelDivCustomerEntity::class, localColumn="customerId")
		 */
		public ?RelDivCustomerEntity $customer = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 */
		protected ?int $customerId = null;

		/**
		 * @Orm\InverseOf(targetEntity=RelDivOrderItemEntity::class, relation="order")
		 */
		public CollectionInterface $items;

		/**
		 * RelDivOrderEntity constructor
		 */
		public function __construct() {
			$this->items = new Collection();
		}

		/**
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}
	}
