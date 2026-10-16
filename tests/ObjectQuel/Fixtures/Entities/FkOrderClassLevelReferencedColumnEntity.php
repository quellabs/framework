<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * A class-level ForeignKey using the legacy singular 'referencedColumn'
	 * form instead of 'columns' — invalid, since there is no single declaring
	 * property at class level to default the local column to.
	 * @Orm\Table(name="fk_orders_class_level_referenced_column")
	 * @Orm\ForeignKey(target=FkCustomerEntity::class, referencedColumn="id")
	 */
	class FkOrderClassLevelReferencedColumnEntity {
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
