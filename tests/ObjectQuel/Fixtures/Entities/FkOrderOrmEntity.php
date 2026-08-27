<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;

	/**
	 * Invalid case: @Orm\Cascade on a plain scalar column with no ManyToOne/OneToOne
	 * relation anywhere on the property. Cascade only governs PHP-side behavior for
	 * an object relation — there's nothing for it to walk here. Metadata build must
	 * throw.
	 * @Orm\Table(name="fk_orders_orm")
	 */
	class FkOrderOrmEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\Cascade(operations={"remove"})
		 */
		protected ?int $customerId = null;
	}
