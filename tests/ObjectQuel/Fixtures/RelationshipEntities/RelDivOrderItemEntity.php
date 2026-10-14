<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Matching row of the relational-division fixtures: one product on an order.
	 * @Orm\Table(name="rel_div_order_items")
	 */
	class RelDivOrderItemEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelDivOrderEntity::class, localColumn="orderId")
		 */
		public ?RelDivOrderEntity $order = null;

		/**
		 * @Orm\Column(name="order_id", type="integer")
		 */
		protected ?int $orderId = null;

		/**
		 * @Orm\Column(name="product_id", type="integer")
		 */
		protected ?int $productId = null;

		/**
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}
	}
