<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;

	/**
	 * Cascade(remove) child of RelSoftParentEntity with no @SoftDelete of its
	 * own. When the parent is only soft-deleted, this must be left untouched
	 * rather than really deleted — otherwise restoring the parent later
	 * can't bring it back. When the parent is genuinely removed, this must
	 * follow it for real.
	 * @Orm\Table(name="rel_plain_child_of_soft_parent")
	 */
	class RelPlainChildOfSoftParentEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelSoftParentEntity::class, localColumn="parentId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove"})
		 */
		public ?RelSoftParentEntity $parent = null;

		/**
		 * @Orm\Column(name="parent_id", type="integer")
		 */
		protected ?int $parentId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
