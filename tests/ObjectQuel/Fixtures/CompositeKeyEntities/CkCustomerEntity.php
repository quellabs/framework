<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * Single-column primary key plus an additional non-PK column ('email'),
	 * used to prove a singular relation may still target a non-PK property.
	 * @Orm\Table(name="ck_customers")
	 */
	class CkCustomerEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="email", type="string", limit=255)
		 */
		protected ?string $email = null;
	}
