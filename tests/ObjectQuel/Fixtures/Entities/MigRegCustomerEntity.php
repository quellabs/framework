<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;

	/**
	 * FK target for the QuelMigrationBuilder regression fixtures (see
	 * QuelMigrationBuilderRegressionTest) — mirrors FkCustomerEntity's shape,
	 * kept as its own class so the regression test's table names never
	 * collide with the ForeignKeyMigrationTest fixtures.
	 * @Orm\Table(name="mig_reg_customers")
	 */
	class MigRegCustomerEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;
	}
