<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * A property-level ForeignKey declared with the new 'columns' map
	 * instead of 'referencedColumn' — the single entry's key equals the
	 * declaring property's own name, so this behaves identically to the
	 * legacy singular form.
	 * @Orm\Table(name="fk_orders_scalar_columns")
	 */
	class FkOrderScalarColumnsEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\ForeignKey(target=FkCustomerEntity::class, columns={"customerId"="id"})
		 */
		protected ?int $customerId = null;
	}
