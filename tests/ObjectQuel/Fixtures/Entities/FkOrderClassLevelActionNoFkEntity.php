<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKeyAction;

	/**
	 * A class-level ForeignKeyAction whose 'localColumns' match no
	 * class-level ForeignKey at all — must fail at build time.
	 * @Orm\Table(name="fk_orders_class_level_action_no_fk")
	 * @Orm\ForeignKeyAction(localColumns={"orderTenantId", "orderNumber"}, onDelete="CASCADE")
	 */
	class FkOrderClassLevelActionNoFkEntity {
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
