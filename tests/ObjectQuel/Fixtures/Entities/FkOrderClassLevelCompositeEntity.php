<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * A two-column constraint, declared at class level since no single
	 * property can carry both halves. The matching ForeignKeyAction is
	 * also class level, paired to this ForeignKey by its ordered
	 * 'localColumns' list.
	 * @Orm\Table(name="fk_orders_class_level_composite")
	 * @Orm\ForeignKey(
	 *     target=FkOrderCompositeTargetEntity::class,
	 *     columns={"orderTenantId"="tenantId", "orderNumber"="number"}
	 * )
	 * @Orm\ForeignKeyAction(
	 *     localColumns={"orderTenantId", "orderNumber"},
	 *     onDelete="CASCADE",
	 *     onUpdate="RESTRICT"
	 * )
	 */
	class FkOrderClassLevelCompositeEntity {
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
