<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * A property-level ForeignKey whose 'columns' key doesn't match the
	 * declaring property — must fail at build time rather than silently
	 * applying to the wrong column.
	 * @Orm\Table(name="fk_orders_scalar_columns_wrong_key")
	 */
	class FkOrderScalarColumnsWrongKeyEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\ForeignKey(target=FkCustomerEntity::class, columns={"wrongProperty"="id"})
		 */
		protected ?int $customerId = null;
	}
