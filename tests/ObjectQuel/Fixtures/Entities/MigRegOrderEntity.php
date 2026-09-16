<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ForeignKey;
	use Quellabs\ObjectQuel\Annotations\Orm\Index;

	/**
	 * The "target" schema for QuelMigrationBuilderRegressionTest's combined
	 * diff — deliberately touches every facet Phase 7 of
	 * objectquel-migrations-implementation-plan.md asks for in one pass:
	 * a retyped column (price), a dropped column (the pre-existing
	 * legacy_note isn't declared here), an added non-nullable column with a
	 * declared default that needs backfilling (status), an added nullable
	 * enum column (priority), an added index (on quantity) alongside a
	 * pre-existing one this entity doesn't declare (deleted), and an added
	 * foreign key (customer_id).
	 * @Orm\Index(name="idx_mig_reg_orders_quantity", columns={"quantity"})
	 * @Orm\Table(name="mig_reg_orders")
	 */
	class MigRegOrderEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="customer_id", type="integer")
		 * @Orm\ForeignKey(target=MigRegCustomerEntity::class)
		 */
		protected ?int $customerId = null;

		/**
		 * Pre-existing column, declared as `integer` in the live table —
		 * retyped here to `decimal(10,2)`.
		 * @Orm\Column(name="price", type="decimal", precision=10, scale=2)
		 */
		protected ?float $price = null;

		/**
		 * Pre-existing column, unchanged in type — only the class-level
		 * index annotation above is new.
		 * @Orm\Column(name="quantity", type="integer")
		 */
		protected ?int $quantity = null;

		/**
		 * A brand-new non-nullable column with a declared default, added to
		 * a table the test seeds with existing rows first — must be
		 * backfilled.
		 * @Orm\Column(name="status", type="string", limit=20, default="pending")
		 */
		protected string $status = 'pending';

		/**
		 * A brand-new nullable enum column — no backfill needed regardless
		 * of existing rows.
		 * @Orm\Column(name="priority", type="enum", enumType=Quellabs\ObjectQuel\Tests\Fixtures\Entities\MigRegPriority::class, nullable=true)
		 */
		protected ?MigRegPriority $priority = null;

		// legacy_note is deliberately not declared here — it exists on the
		// live table this test seeds and must be reported as a deleted column.
	}
