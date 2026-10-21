<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;

	/**
	 * A class-level ForeignKey naming a local property in 'columns' that
	 * isn't declared with a Column annotation on this entity at all — must
	 * fail at build time rather than silently skipping it.
	 * @Orm\Table(name="fk_orders_class_level_unknown_property")
	 * @Orm\ForeignKey(target=FkCustomerEntity::class, columns={"doesNotExist"="id"})
	 */
	class FkOrderClassLevelUnknownPropertyEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;
	}
