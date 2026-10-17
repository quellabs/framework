<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * A single-column constraint declared at class level via 'columns' instead
	 * of on the scalar property — legal even though it isn't composite, since
	 * nothing about class-level placement requires more than one column.
	 * @Orm\Table(name="fk_orders_class_level_single")
	 * @Orm\ForeignKey(target=FkCustomerEntity::class, columns={"customerId"="id"})
	 */
	class FkOrderClassLevelSingleEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 */
		protected ?int $customerId = null;
	}
