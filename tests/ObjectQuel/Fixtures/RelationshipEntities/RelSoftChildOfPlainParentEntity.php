<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Annotations\Orm\SoftDelete;

	/**
	 * Cascade(remove) child of RelPlainParentEntity (no @SoftDelete) that is
	 * itself @SoftDelete. When the parent is really removed, this must be
	 * really removed too rather than left as a soft-deleted row pointing at
	 * a parent that no longer exists.
	 * @Orm\Table(name="rel_soft_child_of_plain_parent")
	 */
	class RelSoftChildOfPlainParentEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelPlainParentEntity::class, localColumn="parentId", fetch="EAGER")
		 * @Orm\Cascade(operations={"remove"})
		 */
		public ?RelPlainParentEntity $parent = null;

		/**
		 * @Orm\Column(name="parent_id", type="integer")
		 */
		protected ?int $parentId = null;

		/**
		 * @Orm\Column(name="deleted_at", type="datetime", nullable=true)
		 * @Orm\SoftDelete
		 */
		protected ?\DateTime $deletedAt = null;

		public function getId(): ?int {
			return $this->id;
		}

		public function getDeletedAt(): ?\DateTime {
			return $this->deletedAt;
		}
	}
