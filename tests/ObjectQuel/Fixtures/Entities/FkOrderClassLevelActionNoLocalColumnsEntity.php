<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * A class-level ForeignKeyAction with no 'localColumns' at all —
	 * invalid, since there is no colocated property to match it to the
	 * class-level ForeignKey by.
	 * @Orm\Table(name="fk_orders_class_level_action_no_local_columns")
	 * @Orm\ForeignKey(
	 *     target=FkOrderCompositeTargetEntity::class,
	 *     columns={"orderTenantId"="tenantId", "orderNumber"="number"}
	 * )
	 * @Orm\ForeignKeyAction(onDelete="CASCADE")
	 */
	class FkOrderClassLevelActionNoLocalColumnsEntity {
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
