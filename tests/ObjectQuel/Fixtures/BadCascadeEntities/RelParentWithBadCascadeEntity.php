<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\BadCascadeEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * Deliberately invalid: Cascade(remove) on an InverseOf collection.
	 * Cascade-remove is discovered via a DB query on the dependent's FK
	 * column, never by reading Cascade off InverseOf, so this is rejected
	 * at metadata-build time. Cascade(persist) on InverseOf is legitimate —
	 * see RelDepartmentEntity.
	 *
	 * Kept in its own isolated fixture directory (not the shared
	 * tests/ObjectQuel/Fixtures/Entities) because
	 * EntityStore::getOrderedDependentEntities() eagerly builds metadata for
	 * every entity in the registry the first time any cascade-remove path
	 * runs, and this class is meant to fail to build.
	 *
	 * Declares "persist" alongside "remove" to prove it's "remove" itself
	 * that's rejected, not merely persist's absence.
	 * @Orm\Table(name="rel_parents_bad_cascade")
	 */
	class RelParentWithBadCascadeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\InverseOf(targetEntity=RelChildOfBadCascadeEntity::class, relation="parent")
		 * @Orm\Cascade(operations={"remove", "persist"})
		 */
		public CollectionInterface $children;

		public function __construct() {
			$this->children = new Collection();
		}

		public function getId(): ?int {
			return $this->id;
		}
	}
