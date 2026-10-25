<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * The "new table with PK+FK" facet of QuelMigrationBuilderRegressionTest's
	 * combined diff — this table doesn't exist yet in the live database.
	 * @Orm\Table(name="mig_reg_line_items")
	 */
	class MigRegLineItemEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="order_id", type="integer")
		 * @Orm\ForeignKey(target=MigRegOrderEntity::class)
		 */
		protected ?int $orderId = null;
	}
