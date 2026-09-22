<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;
	use Quellabs\ObjectQuel\Annotations\Orm\SoftDelete;

	/**
	 * Like RelBoolSoftChildEntity, but itself boolean-soft-deletable too —
	 * regression fixture for a query where the primary (FROM) range and a
	 * joined range are both soft-deletable at once: the primary range's
	 * filter still excludes it via WHERE (querying the deleted thing
	 * directly), independent of whatever the joined range's own filter does
	 * via its ON clause.
	 * @Orm\Table(name="rel_bool_soft_children_own_delete")
	 */
	class RelBoolSoftChildWithOwnDeleteEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\Column(name="is_deleted", type="boolean", nullable=false, default=false)
		 * @Orm\SoftDelete
		 */
		protected bool $isDeleted = false;

		/**
		 * @Orm\ManyToOne(targetEntity=RelBoolSoftParentEntity::class, localColumn="parentId", fetch="EAGER")
		 */
		public ?RelBoolSoftParentEntity $parent = null;

		/**
		 * @Orm\Column(name="parent_id", type="integer", nullable=true)
		 */
		protected ?int $parentId = null;

		public function getId(): ?int {
			return $this->id;
		}

		public function setIsDeleted(bool $isDeleted): self {
			$this->isDeleted = $isDeleted;
			return $this;
		}
	}
