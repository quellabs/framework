<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\RequiredRelation;

	/**
	 * Owning side of a composite ManyToOne to CkRequiredOrderEntity's
	 * (tenantId, number) primary key, marked required below. RangeOptimizer
	 * must promote an explicit `via` LEFT JOIN over this relation to an INNER
	 * JOIN, matched through the resolved RelationColumnMapping pairs rather
	 * than a singular local/referenced column. Contrast with
	 * CkOptionalOrderLineEntity, which carries no such annotation.
	 * @Orm\Table(name="ck_required_order_lines")
	 */
	class CkRequiredOrderLineEntity {
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
		 * @Orm\RequiredRelation
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
