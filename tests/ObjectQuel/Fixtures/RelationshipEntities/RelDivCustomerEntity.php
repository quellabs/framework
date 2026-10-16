<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * Owner side of the relational-division fixtures (customer -> orders -> items).
	 * @Orm\Table(name="rel_div_customers")
	 */
	class RelDivCustomerEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="name", type="string", limit=100)
		 */
		protected ?string $name = null;

		/**
		 * @Orm\InverseOf(targetEntity=RelDivOrderEntity::class, relation="customer")
		 */
		public CollectionInterface $orders;

		/**
		 * RelDivCustomerEntity constructor
		 */
		public function __construct() {
			$this->orders = new Collection();
		}

		/**
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}

		/**
		 * @return string|null
		 */
		public function getName(): ?string {
			return $this->name;
		}
	}
