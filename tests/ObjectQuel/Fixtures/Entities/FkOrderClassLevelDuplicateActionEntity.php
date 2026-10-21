<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * Two class-level ForeignKeyAction declarations with the exact same
	 * 'localColumns' — both match the same ForeignKey, which is
	 * ambiguous and rejected at build time.
	 * @Orm\Table(name="fk_orders_class_level_duplicate_action")
	 * @Orm\ForeignKey(target=FkOrderCompositeTargetEntity::class, columns={"orderTenantId"="tenantId"})
	 * @Orm\ForeignKeyAction(localColumns={"orderTenantId"}, onDelete="CASCADE")
	 * @Orm\ForeignKeyAction(localColumns={"orderTenantId"}, onDelete="SET NULL")
	 */
	class FkOrderClassLevelDuplicateActionEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="order_tenant_id", type="integer")
		 */
		protected ?int $orderTenantId = null;
	}
