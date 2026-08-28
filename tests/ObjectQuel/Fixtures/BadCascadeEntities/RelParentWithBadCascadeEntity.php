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
	 * Deliberately invalid, by design: Cascade(remove) placed directly on an
	 * InverseOf collection property. Cascade(persist) is legitimate here — see
	 * RelDepartmentEntity/RelEmployeeEntity — because a new, not-yet-saved child
	 * only exists in this in-memory collection, so
	 * UnitOfWork::processCascadingInverseOfPersists() has to walk it. But
	 * cascade-*remove* is discovered by UnitOfWork::handleDependentEntityClass()
	 * querying the dependent entity's foreign key column directly — it never
	 * reads Cascade off InverseOf and never will, since it doesn't need a loaded
	 * collection to work. Declaring "remove" here would silently do nothing, so
	 * EntityMetadataBuilder::validateCascadeRequiresRelation() rejects it at
	 * metadata-build time with a RuntimeException instead.
	 *
	 * Kept in its own isolated fixture directory
	 * (tests/ObjectQuel/Fixtures/BadCascadeEntities, not the shared
	 * tests/ObjectQuel/Fixtures/Entities used by every other FK/cascade test)
	 * because any cascade-remove test that shares an EntityStore with this class
	 * would trip over it too: EntityStore::getOrderedDependentEntities() eagerly
	 * builds metadata for every entity in the registry the first time any
	 * cascade-remove path runs, not just the one entity being removed.
	 *
	 * Declares both "remove" and "persist" together (not just "remove" alone)
	 * to prove rejection isn't merely a side effect of "persist" being absent —
	 * "remove" is what's actually invalid here, regardless of what else rides
	 * along with it in the same annotation.
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
