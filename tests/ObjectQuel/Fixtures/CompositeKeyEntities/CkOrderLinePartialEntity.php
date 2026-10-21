<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Invalid: references only one column ('tenantId') of CkOrderEntity's full
	 * composite primary key (tenantId, number) via the plural relation form.
	 * Must be rejected at metadata build time.
	 * @Orm\Table(name="ck_order_lines_partial")
	 */
	class CkOrderLinePartialEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(
		 *     targetEntity=CkOrderEntity::class,
		 *     localColumns={"orderTenantId"},
		 *     referencedColumns={"tenantId"},
		 *     fetch="LAZY"
		 * )
		 */
		public ?CkOrderEntity $order = null;

		/**
		 * @Orm\Column(name="order_tenant_id", type="integer")
		 */
		protected ?int $orderTenantId = null;
	}
