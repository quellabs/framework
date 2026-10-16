<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\CompositeKeyEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * Composite primary key (groupId, id) with a self-referencing composite
	 * ManyToOne back to its own (groupId, id). Exercises the self-reference
	 * shortcut in EntityMetadataBuilder::resolveTargetColumnInfo() — resolving
	 * the target's primary key must not recurse into building this entity's
	 * own metadata a second time.
	 * @Orm\Table(name="ck_self_ref")
	 */
	class CkSelfRefEntity {
		/**
		 * @Orm\Column(name="group_id", type="integer", primary_key=true)
		 */
		protected ?int $groupId = null;

		/**
		 * @Orm\Column(name="id", type="integer", primary_key=true)
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(
		 *     targetEntity=CkSelfRefEntity::class,
		 *     localColumns={"parentGroupId", "parentId"},
		 *     referencedColumns={"groupId", "id"},
		 *     fetch="LAZY"
		 * )
		 */
		public ?CkSelfRefEntity $parent = null;

		/**
		 * @Orm\Column(name="parent_group_id", type="integer", nullable=true)
		 */
		protected ?int $parentGroupId = null;

		/**
		 * @Orm\Column(name="parent_id", type="integer", nullable=true)
		 */
		protected ?int $parentId = null;
	}
