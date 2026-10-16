<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;

	/**
	 * Owning side of a composite ManyToOne to CkiOrderEntity's (tenantId, number)
	 * primary key, declared via the plural localColumns/referencedColumns form.
	 * Eager-fetched so EntityManager::find() exercises RewriteViaRelationToJoinCondition's
	 * direct-path AND-of-equalities JOIN against a real SQLite database; the
	 * InverseOf side on CkiOrderEntity::$lines exercises the inverse path.
	 * Cascade(remove) exercises UnitOfWork::cascadeDeleteDependentObjects()'s
	 * composite (full-tuple) matching when the parent order is deleted.
	 * @Orm\Table(name="cki_order_lines")
	 */
	class CkiOrderLineEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(
		 *     targetEntity=CkiOrderEntity::class,
		 *     localColumns={"orderTenantId", "orderNumber"},
		 *     referencedColumns={"tenantId", "number"},
		 *     fetch="EAGER"
		 * )
		 * @Orm\Cascade(operations={"remove"})
		 */
		public ?CkiOrderEntity $order = null;

		/**
		 * @Orm\Column(name="order_tenant_id", type="integer")
		 */
		protected ?int $orderTenantId = null;

		/**
		 * @Orm\Column(name="order_number", type="string", limit=20)
		 */
		protected ?string $orderNumber = null;

		/**
		 * @Orm\Column(name="line_amount", type="integer")
		 */
		protected ?int $lineAmount = null;

		public function getId(): ?int {
			return $this->id;
		}

		public function setOrderTenantId(?int $orderTenantId): void {
			$this->orderTenantId = $orderTenantId;
		}

		public function setOrderNumber(?string $orderNumber): void {
			$this->orderNumber = $orderNumber;
		}

		public function getLineAmount(): ?int {
			return $this->lineAmount;
		}

		public function setLineAmount(?int $lineAmount): void {
			$this->lineAmount = $lineAmount;
		}
	}
