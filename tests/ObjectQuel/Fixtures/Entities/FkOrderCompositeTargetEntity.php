<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;

	/**
	 * Target entity for a class-level, multi-column ForeignKey's 'columns'
	 * map. Target-property resolution to real columns is lazy (see
	 * ForeignKeyComparator), so this fixture only needs to exist as a class
	 * name — nothing here reads its own Column properties at this layer.
	 * @Orm\Table(name="fk_composite_targets")
	 */
	class FkOrderCompositeTargetEntity {
		/**
		 * @Orm\Column(name="tenant_id", type="integer", primary_key=true)
		 */
		protected ?int $tenantId = null;

		/**
		 * @Orm\Column(name="number", type="string", limit=20, primary_key=true)
		 */
		protected ?string $number = null;
	}
