<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * A class-level, multi-column constraint with no matching ForeignKeyAction
	 * at all — the safe defaults (RESTRICT / NO ACTION) apply, same as the
	 * property-level case.
	 * @Orm\Table(name="fk_orders_class_level_no_action")
	 * @Orm\ForeignKey(
	 *     target=FkOrderCompositeTargetEntity::class,
	 *     columns={"orderTenantId"="tenantId", "orderNumber"="number"}
	 * )
	 */
	class FkOrderClassLevelNoActionEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="order_tenant_id", type="integer")
		 */
		protected ?int $orderTenantId = null;

		/**
		 * @Orm\Column(name="order_number", type="string", limit=20)
		 */
		protected ?string $orderNumber = null;
	}
