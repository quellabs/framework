<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Composite primary key where one component explicitly declares the
	 * 'identity' strategy — must be rejected at metadata build time, since a
	 * composite key can never use database identity generation.
	 * @Orm\Table(name="ck_orders_bad_identity")
	 */
	class CkOrderWithExplicitIdentityEntity {
		/**
		 * @Orm\Column(name="tenant_id", type="integer", primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $tenantId = null;

		/**
		 * @Orm\Column(name="number", type="string", limit=20, primary_key=true)
		 */
		protected ?string $number = null;
	}
