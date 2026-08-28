<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\InverseOf;
	use Quellabs\ObjectQuel\Annotations\Orm\Cascade;
	use Quellabs\ObjectQuel\Collections\Collection;
	use Quellabs\ObjectQuel\Collections\CollectionInterface;

	/**
	 * The "one" side of a ManyToOne+InverseOf one-to-many pair — paired with
	 * RelEmployeeEntity's owning ManyToOne side. Carries Cascade(persist) on the
	 * InverseOf collection itself: a newly-created, not-yet-saved RelEmployeeEntity
	 * only exists in $employees in memory, so that's the only property
	 * UnitOfWork::processCascadingInverseOfPersists() can walk to find it.
	 * Deliberately separate from RelCustomerEntity/RelOrderCascadeEntity, which
	 * exercise Cascade(remove) on the owning ManyToOne side instead — the two
	 * cascade operations are discovered through different mechanisms and this
	 * keeps each fixture pair focused on one of them.
	 * @Orm\Table(name="rel_departments")
	 */
	class RelDepartmentEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\InverseOf(targetEntity=RelEmployeeEntity::class, relation="department")
		 * @Orm\Cascade(operations={"persist"})
		 */
		public CollectionInterface $employees;

		public function __construct() {
			$this->employees = new Collection();
		}

		public function getId(): ?int {
			return $this->id;
		}
	}
