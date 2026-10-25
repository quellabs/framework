<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * The "many" side of an optional (nullable FK) relation to RelBoolSoftParentEntity.
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
