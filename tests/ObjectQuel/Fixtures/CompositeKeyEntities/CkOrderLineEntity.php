<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Owning side of a composite relation to CkOrderEntity's full (tenantId,
	 * number) primary key, declared via the plural localColumns/referencedColumns
	 * form.
	 * @Orm\Table(name="ck_order_lines")
	 */
	class CkOrderLineEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(
		 *     targetEntity=CkOrderEntity::class,
		 *     localColumns={"orderTenantId", "orderNumber"},
		 *     referencedColumns={"tenantId", "number"},
		 *     fetch="LAZY"
		 * )
		 */
		public ?CkOrderEntity $order = null;

		/**
		 * @Orm\Column(name="order_tenant_id", type="integer")
		 */
		protected ?int $orderTenantId = null;

		/**
		 * @Orm\Column(name="order_number", type="string", limit=20)
		 */
		protected ?string $orderNumber = null;
	}
