<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * Composite primary key (tenantId, number), used by the SQLite integration
	 * suite to exercise EntityManager::find()/findOrFail() end to end against a
	 * real database and the managed identity map, plus eager loading of a
	 * composite ManyToOne/InverseOf pair via CkiOrderLineEntity. Deliberately
	 * the only pair of entities in this fixture directory — unlike
	 * tests/ObjectQuel/Fixtures/CompositeKeyEntities, this one is registered on
	 * the shared $GLOBALS['test_em'] (see tests/objectquel-sqlite-bootstrap.php),
	 * which eagerly builds metadata for every entity under its configured
	 * paths, so nothing here may be deliberately invalid.
	 * @Orm\Table(name="cki_orders")
	 */
	class CkiOrderEntity {
		/**
		 * @Orm\Column(name="tenant_id", type="integer", primary_key=true)
		 */
		protected ?int $tenantId = null;

		/**
		 * @Orm\Column(name="number", type="string", limit=20, primary_key=true)
		 */
		protected ?string $number = null;

		/**
		 * @Orm\Column(name="amount", type="integer")
		 */
		protected ?int $amount = null;

		/**
		 * @Orm\InverseOf(targetEntity=CkiOrderLineEntity::class, relation="order")
		 */
		public CollectionInterface $lines;

		public function __construct() {
			$this->lines = new Collection();
		}

		public function getTenantId(): ?int {
			return $this->tenantId;
		}

		public function setTenantId(?int $tenantId): void {
			$this->tenantId = $tenantId;
		}

		public function getNumber(): ?string {
			return $this->number;
		}

		public function setNumber(?string $number): void {
			$this->number = $number;
		}

		public function getAmount(): ?int {
			return $this->amount;
		}

		public function setAmount(?int $amount): void {
			$this->amount = $amount;
		}
	}
