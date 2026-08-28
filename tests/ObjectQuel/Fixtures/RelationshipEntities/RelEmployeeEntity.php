<?php

	namespace Quellabs\ObjectQuel\Tests\Fixtures\RelationshipEntities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\ManyToOne;

	/**
	 * The owning ("many") side paired with RelDepartmentEntity's InverseOf
	 * collection. Carries no Cascade of its own.
	 * @Orm\Table(name="rel_employees")
	 */
	class RelEmployeeEntity {
		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * @Orm\ManyToOne(targetEntity=RelDepartmentEntity::class, localColumn="departmentId", fetch="EAGER")
		 */
		public ?RelDepartmentEntity $department = null;

		/**
		 * @Orm\Column(name="department_id", type="integer")
		 */
		protected ?int $departmentId = null;

		public function getId(): ?int {
			return $this->id;
		}
	}
