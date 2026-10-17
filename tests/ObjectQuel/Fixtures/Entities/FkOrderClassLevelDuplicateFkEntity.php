<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * Two class-level ForeignKey declarations naming the exact same local
	 * columns — a duplicate/conflicting declaration, rejected at build time.
	 * @Orm\Table(name="fk_orders_class_level_duplicate_fk")
	 * @Orm\ForeignKey(target=FkOrderCompositeTargetEntity::class, columns={"orderTenantId"="tenantId"})
	 * @Orm\ForeignKey(target=FkCustomerEntity::class, columns={"orderTenantId"="id"})
	 */
	class FkOrderClassLevelDuplicateFkEntity {
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
