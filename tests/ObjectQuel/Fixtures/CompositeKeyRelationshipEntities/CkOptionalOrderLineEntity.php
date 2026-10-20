<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Control case for CkRequiredOrderLineEntity: the same composite ManyToOne
	 * to CkRequiredOrderEntity, but without the required-relation annotation —
	 * an explicit `via` LEFT JOIN over this relation must remain a LEFT JOIN.
	 * @Orm\Table(name="ck_optional_order_lines")
	 */
	class CkOptionalOrderLineEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(
		 *     targetEntity=CkRequiredOrderEntity::class,
		 *     localColumns={"orderTenantId", "orderNumber"},
		 *     referencedColumns={"tenantId", "number"},
		 *     fetch="LAZY"
		 * )
		 */
		public ?CkRequiredOrderEntity $order = null;

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
	}
