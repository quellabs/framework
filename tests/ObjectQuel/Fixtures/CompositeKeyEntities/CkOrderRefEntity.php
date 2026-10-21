<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Invalid: a singular ManyToOne (no localColumns/referencedColumns) pointing
	 * at CkOrderEntity, which has a composite primary key. Omitting
	 * 'referencedColumn' is ambiguous here — there is no single "the" primary
	 * key property to fall back to. Must be rejected at metadata build time.
	 * @Orm\Table(name="ck_order_refs")
	 */
	class CkOrderRefEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=CkOrderEntity::class, localColumn="orderId", fetch="LAZY")
		 */
		public ?CkOrderEntity $order = null;

		/**
		 * @Orm\Column(name="order_id", type="integer")
		 */
		protected ?int $orderId = null;
	}
