<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;

	/**
	 * Composite primary key (tenantId, number), both assigned by the application —
	 * neither component carries a @PrimaryKeyStrategy, which is the composite
	 * default ('assigned'), not 'identity'.
	 * @Orm\Table(name="ck_orders")
	 */
	class CkOrderEntity {
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
