<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Same composite ManyToOne shape as CkiOrderLineEntity, but fetch="LAZY" —
	 * exercises RelationshipLoader's lazy proxy path (findOrCreateProxy()) and
	 * ProxyCodeGenerator's per-identifier-getter exemption for a composite
	 * target, instead of the eager-join path CkiOrderLineEntity covers.
	 * @Orm\Table(name="cki_order_lines_lazy")
	 */
	class CkiOrderLineLazyEntity {
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
		 *     fetch="LAZY"
		 * )
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

		public function getId(): ?int {
			return $this->id;
		}

		public function setOrderTenantId(?int $orderTenantId): void {
			$this->orderTenantId = $orderTenantId;
		}

		public function setOrderNumber(?string $orderNumber): void {
			$this->orderNumber = $orderNumber;
		}
	}
