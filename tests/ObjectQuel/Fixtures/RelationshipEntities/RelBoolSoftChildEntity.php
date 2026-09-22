<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * The "many" side of an optional relation to RelBoolSoftParentEntity — the
	 * FK column is nullable, so a row can have no related parent at all. Used
	 * to reproduce bug-soft-delete-left-join-boolean-null.md: `via
	 * child.parent` compiles to a LEFT JOIN, and a row with parentId = NULL
	 * must survive the injected boolean soft-delete filter on the parent.
	 * @Orm\Table(name="rel_bool_soft_children")
	 */
	class RelBoolSoftChildEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

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
	}
