<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyRelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;

	/**
	 * Composite primary key (tenantId, number) target for
	 * CkRequiredOrderLineEntity/CkOptionalOrderLineEntity — RangeOptimizer's
	 * required-relation handling for a composite ManyToOne.
	 * @Orm\Table(name="ck_required_orders")
	 */
	class CkRequiredOrderEntity {
		/**
		 * @Orm\Column(name="tenant_id", type="integer", primary_key=true)
		 */
		protected ?int $tenantId = null;

		/**
		 * @Orm\Column(name="number", type="string", limit=20, primary_key=true)
		 */
		protected ?string $number = null;

		/**
		 * @Orm\Column(name="amount", type="integer")
		 */
		protected ?int $amount = null;
	}
